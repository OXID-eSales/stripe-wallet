<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\AuthorizePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Response\PaymentResponse;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentBase\Adapter\Response\AuthorizationResponse;
use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Helper for PaymentIntent operations.
 *
 * Sprint 46: Extracted from StripeAdapter to reduce ECC.
 * Sprint 46: Idempotency for capturePaymentIntent (moved from IdempotentStripeAdapter).
 *
 * @since 2.0.0
 */
class PaymentIntentHelper
{
    private const DEFAULT_TTL_SECONDS = 86400;

    private readonly ?IdempotentExecutor $idempotentExecutor;

    public function __construct(
        private readonly ?IdempotencyRepositoryInterface $idempotencyRepository = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
        $this->idempotentExecutor = $idempotencyRepository !== null
            ? new IdempotentExecutor($idempotencyRepository, $ttlSeconds)
            : null;
    }

    public function createPaymentIntent(StripeClient $client, CreatePaymentRequest $request): PaymentResponse
    {
        try {
            $amountInCents = AmountConverter::toMinorUnits($request->amount, $request->currency);
            $captureMethod = $request->directCapture ? StripeDefinitions::CAPTURE_MODE_AUTOMATIC : StripeDefinitions::CAPTURE_MODE_MANUAL;

            $params = $this->buildPaymentIntentParams($amountInCents, $request->currency, $captureMethod, $request);

            /** @var array{amount: int, currency: string, capture_method: 'automatic'|'manual', metadata: array<string, string>, payment_method?: string, confirm?: true, customer?: string, return_url?: string} $params */
            $paymentIntent = $client->paymentIntents->create($params);

            /** @var array<string, mixed> $providerData */
            $providerData = $paymentIntent->toArray();

            return new PaymentResponse(
                providerPaymentId: $paymentIntent->id,
                status: StripeStatusMapper::toNormalized($paymentIntent->status),
                amount: $request->amount,
                currency: $request->currency,
                requiresAction: StripeStatusMapper::requiresAction($paymentIntent->status),
                clientSecret: $paymentIntent->client_secret,
                redirectUrl: $paymentIntent->next_action->redirect_to_url->url ?? null,
                providerData: $providerData,
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    public function capturePaymentIntent(StripeClient $client, CapturePaymentRequest $request): CaptureResponse
    {
        if ($this->idempotencyRepository !== null) {
            return $this->captureWithIdempotency($client, $request);
        }

        return $this->executeCapturePaymentIntent($client, $request);
    }

    public function getPaymentDetails(StripeClient $client, string $providerPaymentId): PaymentDetailsResponse
    {
        try {
            $paymentIntent = $client->paymentIntents->retrieve(
                $providerPaymentId,
                ['expand' => ['latest_charge']]
            );

            $piCurrency = strtoupper($paymentIntent->currency);
            $amount = AmountConverter::toMajorUnits($paymentIntent->amount, $piCurrency);
            $amountCaptured = AmountConverter::toMajorUnits($paymentIntent->amount_received, $piCurrency);

            $amountRefunded = 0.0;
            if ($paymentIntent->latest_charge) {
                $amountRefunded = AmountConverter::toMajorUnits(
                    (int) ($paymentIntent->latest_charge->amount_refunded ?? 0),
                    $piCurrency
                );
            }

            $capturedAt = null;
            if ($paymentIntent->latest_charge && isset($paymentIntent->latest_charge->created)) {
                $capturedAt = new DateTimeImmutable('@' . $paymentIntent->latest_charge->created);
            }

            /** @var array<string, mixed> $providerData */
            $providerData = $paymentIntent->toArray();

            return new PaymentDetailsResponse(
                providerPaymentId: $paymentIntent->id,
                status: StripeStatusMapper::toNormalized($paymentIntent->status),
                amount: $amount,
                currency: $piCurrency,
                amountCaptured: $amountCaptured,
                amountRefunded: $amountRefunded,
                isCaptured: StripeStatusMapper::isCaptured($paymentIntent->status),
                isRefunded: $amountRefunded > 0,
                isCancelled: StripeStatusMapper::isCancelled($paymentIntent->status),
                createdAt: new DateTimeImmutable('@' . $paymentIntent->created),
                capturedAt: $capturedAt,
                providerData: $providerData
            );
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    public function authorizePayment(StripeClient $client, AuthorizePaymentRequest $request): AuthorizationResponse
    {
        try {
            $amountInCents = AmountConverter::toMinorUnits($request->amount, $request->currency);

            $params = $this->buildPaymentIntentParams($amountInCents, $request->currency, StripeDefinitions::CAPTURE_MODE_MANUAL, $request);

            /** @phpstan-ignore argument.type (dynamic params built by buildPaymentIntentParams) */
            $paymentIntent = $client->paymentIntents->create($params);

            $expiresAt = new DateTimeImmutable('+7 days');

            /** @var array<string, mixed> $providerData */
            $providerData = $paymentIntent->toArray();

            return new AuthorizationResponse(
                authorizationId: $paymentIntent->id,
                providerPaymentId: $paymentIntent->id,
                status: StripeStatusMapper::toNormalized($paymentIntent->status),
                amount: $request->amount,
                currency: $request->currency,
                authorizedAt: new DateTimeImmutable('@' . $paymentIntent->created),
                expiresAt: $expiresAt,
                requiresAction: StripeStatusMapper::requiresAction($paymentIntent->status),
                clientSecret: $paymentIntent->client_secret,
                redirectUrl: $paymentIntent->next_action->redirect_to_url->url ?? null,
                providerData: $providerData,
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    /**
     * @param array<string> $expand
     */
    public function retrievePaymentIntent(StripeClient $client, string $paymentIntentId, array $expand = []): PaymentIntent
    {
        try {
            $options = [];
            if (!empty($expand)) {
                $options['expand'] = $expand;
            }
            return $client->paymentIntents->retrieve($paymentIntentId, $options);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    public function cancelPaymentIntent(StripeClient $client, string $paymentIntentId, ?string $cancellationReason = null): PaymentIntent
    {
        try {
            $params = [];
            if ($cancellationReason !== null) {
                $params['cancellation_reason'] = match ($cancellationReason) {
                    'requested_by_customer', 'fraudulent', 'duplicate', 'abandoned' => $cancellationReason,
                    default => 'requested_by_customer',
                };
            }
            return $client->paymentIntents->cancel($paymentIntentId, $params);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    public function getRiskScore(StripeClient $client, string $paymentIntentId): ?float
    {
        try {
            $paymentIntent = $client->paymentIntents->retrieve(
                $paymentIntentId,
                ['expand' => ['latest_charge']]
            );

            if ($paymentIntent->latest_charge === null) {
                return null;
            }

            $outcome = $paymentIntent->latest_charge->outcome ?? null;
            $riskScore = $outcome->risk_score ?? null;

            return $riskScore !== null ? $riskScore / 100.0 : null;
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    /**
     * Build params array for PaymentIntent creation.
     *
     * @param CreatePaymentRequest|AuthorizePaymentRequest $request
     * @return array<string, mixed>
     */
    private function buildPaymentIntentParams(int $amountInCents, string $currency, string $captureMethod, object $request): array
    {
        $params = [
            'amount' => $amountInCents,
            'currency' => strtolower($currency),
            'capture_method' => $captureMethod,
            'metadata' => array_merge($request->metadata, [
                'order_id' => $request->orderId,
                'shop_id' => $request->shopId,
            ]),
        ];

        $willConfirm = $request->paymentMethodId !== null;
        if ($request->paymentMethodId !== null) {
            $params['payment_method'] = $request->paymentMethodId;
            $params['confirm'] = true;

            if ($request->returnUrl === null) {
                throw new PaymentAdapterException(
                    providerName: StripeDefinitions::PROVIDER,
                    errorCode: 'missing_return_url',
                    message: 'return_url is required when confirming a PaymentIntent with a saved payment method',
                    context: [
                        'payment_method_id' => $request->paymentMethodId,
                        'order_id' => $request->orderId,
                    ]
                );
            }
        }

        if ($request->customerId !== null) {
            $params['customer'] = $request->customerId;
        }

        if ($request->returnUrl !== null && $willConfirm) {
            $params['return_url'] = $request->returnUrl;
        }

        return $params;
    }

    private function captureWithIdempotency(StripeClient $client, CapturePaymentRequest $request): CaptureResponse
    {
        /** @var IdempotentExecutor $executor */
        $executor = $this->idempotentExecutor;
        $result = $executor->execute(
            key: 'capture:' . $request->providerPaymentId,
            referenceId: $request->providerPaymentId,
            operation: 'capture',
            callable: fn () => $this->executeCapturePaymentIntent($client, $request),
            serialize: function (mixed $r): string {
                assert($r instanceof CaptureResponse);
                return $this->serializeCaptureResponse($r);
            },
            deserialize: fn (string $j) => $this->deserializeCaptureResponse($j)
        );
        /** @var CaptureResponse $result */
        return $result;
    }

    private function executeCapturePaymentIntent(StripeClient $client, CapturePaymentRequest $request): CaptureResponse
    {
        try {
            $params = [];
            if ($request->amount !== null) {
                // Sprint 114.10a (§6.2): CapturePaymentRequest now carries an optional currency;
                // use it for correct minor-unit conversion (zero-decimal currencies like JPY
                // must NOT be multiplied by 100). Null/empty falls back to 2-decimal behaviour.
                $params['amount_to_capture'] = AmountConverter::toMinorUnits($request->amount, $request->currency ?? '');
            }
            if (!empty($request->metadata)) {
                $params['metadata'] = $request->metadata;
            }

            /** @phpstan-ignore argument.type (capture params are conditionally built) */
            $client->paymentIntents->capture($request->providerPaymentId, $params);

            $paymentIntent = $client->paymentIntents->retrieve(
                $request->providerPaymentId,
                ['expand' => ['latest_charge']]
            );

            $capturedCurrency = strtoupper($paymentIntent->currency);
            $amountCaptured = AmountConverter::toMajorUnits($paymentIntent->amount_received, $capturedCurrency);
            /** @phpstan-ignore-next-line nullsafe.neverNull */
            $capturedAtTimestamp = $paymentIntent->latest_charge?->created ?? time();

            /** @var array<string, mixed> $providerData */
            $providerData = $paymentIntent->toArray();

            return CaptureResponse::success(
                providerPaymentId: $paymentIntent->id,
                /** @phpstan-ignore-next-line nullsafe.neverNull */
                captureId: $paymentIntent->latest_charge?->id ?? $paymentIntent->id,
                amountCaptured: $amountCaptured,
                currency: $capturedCurrency,
                status: StripeStatusMapper::STATUS_CAPTURED,
                capturedAt: new DateTimeImmutable('@' . $capturedAtTimestamp),
                providerData: $providerData,
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    private function serializeCaptureResponse(CaptureResponse $response): string
    {
        return (string) json_encode([
            'successful' => $response->successful,
            'providerPaymentId' => $response->providerPaymentId,
            'captureId' => $response->captureId,
            'amountCaptured' => $response->amountCaptured,
            'currency' => $response->currency,
            'status' => $response->status,
            'capturedAt' => $response->capturedAt?->format('Y-m-d H:i:s'),
            'errorMessage' => $response->errorMessage,
            'errorCode' => $response->errorCode,
        ]);
    }

    private function deserializeCaptureResponse(string $json): CaptureResponse
    {
        /** @var array{successful?: bool, providerPaymentId?: string, captureId?: string, amountCaptured?: float, currency?: string, status?: string, capturedAt?: string, errorMessage?: string, errorCode?: string} $data */
        $data = json_decode($json, true);

        if (!($data['successful'] ?? false)) {
            return CaptureResponse::failure(
                $data['errorMessage'] ?? 'Unknown error',
                $data['errorCode'] ?? null
            );
        }

        return CaptureResponse::success(
            providerPaymentId: $data['providerPaymentId'] ?? '',
            captureId: $data['captureId'] ?? '',
            amountCaptured: $data['amountCaptured'] ?? 0.0,
            currency: $data['currency'] ?? '',
            status: $data['status'] ?? '',
            capturedAt: new DateTimeImmutable($data['capturedAt'] ?? 'now'),
        );
    }
}
