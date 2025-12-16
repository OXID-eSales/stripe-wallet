<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Adapter;

use OxidSolutionCatalysts\Payments\Component\Adapter\WebhookEvent;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreatePaymentRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CapturePaymentRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\RefundPaymentRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\VoidPaymentRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\AuthorizePaymentRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CaptureAuthorizationRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\VoidAuthorizationRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\ReauthorizePaymentRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreatePaymentMethodRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\ThreeDSecureRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\CaptureResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\RefundResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\VoidResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentDetailsResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\AuthorizationResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentMethodResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\ThreeDSecureResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\PaymentAdapterException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;
use DateTimeImmutable;

/**
 * Stripe adapter implementing the payment provider interface.
 *
 * Translates provider-agnostic requests to Stripe SDK calls and
 * Stripe responses back to provider-agnostic response objects.
 *
 * Uses Stripe SDK v18.
 *
 * Sprint 19: Extended with StripeAdapterInterface for Stripe-specific methods.
 *
 * @since 1.0.0
 */
final class StripeAdapter implements StripeAdapterInterface
{
    /**
     * @param StripeClient $stripeClient Configured Stripe SDK client
     */
    public function __construct(
        private readonly StripeClient $stripeClient
    ) {
    }

    // ==========================================
    // BASIC PAYMENT OPERATIONS
    // ==========================================

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        try {
            // Convert amount from major units to cents (Stripe requirement)
            $amountInCents = (int) ($request->amount * 100);

            // Determine capture method based on directCapture flag
            $captureMethod = $request->directCapture ? 'automatic' : 'manual';

            $params = [
                'amount' => $amountInCents,
                'currency' => strtolower($request->currency),
                'capture_method' => $captureMethod,
                'metadata' => array_merge($request->metadata, [
                    'order_id' => $request->orderId,
                    'shop_id' => $request->shopId,
                ]),
            ];

            // Add payment method if provided (for saved cards)
            $willConfirm = $request->paymentMethodId !== null;
            if ($request->paymentMethodId !== null) {
                $params['payment_method'] = $request->paymentMethodId;
                $params['confirm'] = true; // Auto-confirm with saved payment method

                // Stripe requires return_url when confirming a PaymentIntent
                if ($request->returnUrl === null) {
                    throw new PaymentAdapterException(
                        providerName: 'stripe',
                        errorCode: 'missing_return_url',
                        message: 'return_url is required when confirming a PaymentIntent with a saved payment method',
                        context: [
                            'payment_method_id' => $request->paymentMethodId,
                            'order_id' => $request->orderId,
                        ]
                    );
                }
            }

            // Add customer if provided
            if ($request->customerId !== null) {
                $params['customer'] = $request->customerId;
            }

            // Add return URL when confirming
            // Note: return_url is required when confirm=true
            if ($request->returnUrl !== null && $willConfirm) {
                $params['return_url'] = $request->returnUrl;
            }

            // Create PaymentIntent
            $paymentIntent = $this->stripeClient->paymentIntents->create($params);

            return new PaymentResponse(
                providerPaymentId: $paymentIntent->id,
                status: StripeStatusMapper::toNormalized($paymentIntent->status),
                amount: $request->amount,
                currency: $request->currency,
                requiresAction: StripeStatusMapper::requiresAction($paymentIntent->status),
                clientSecret: $paymentIntent->client_secret,
                redirectUrl: $paymentIntent->next_action->redirect_to_url->url ?? null,
                providerData: $paymentIntent->toArray(),
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    public function capturePayment(CapturePaymentRequest $request): CaptureResponse
    {
        try {
            $params = [];

            // If partial capture, specify amount in cents
            if ($request->amount !== null) {
                $params['amount_to_capture'] = (int) ($request->amount * 100);
            }

            // Add metadata if provided
            if (!empty($request->metadata)) {
                $params['metadata'] = $request->metadata;
            }

            $paymentIntent = $this->stripeClient->paymentIntents->capture(
                $request->providerPaymentId,
                $params
            );

            // Retrieve with expanded latest_charge to get charge details
            $paymentIntent = $this->stripeClient->paymentIntents->retrieve(
                $request->providerPaymentId,
                ['expand' => ['latest_charge']]
            );

            $amountCaptured = $paymentIntent->amount_received / 100;

            // Get capture timestamp from latest charge, or use current time if not available
            /** @phpstan-ignore-next-line nullsafe.neverNull */
            $capturedAtTimestamp = $paymentIntent->latest_charge?->created ?? time();

            return new CaptureResponse(
                providerPaymentId: $paymentIntent->id,
                /** @phpstan-ignore-next-line nullsafe.neverNull */
                captureId: $paymentIntent->latest_charge?->id ?? $paymentIntent->id,
                amountCaptured: $amountCaptured,
                currency: strtoupper($paymentIntent->currency),
                status: StripeStatusMapper::STATUS_CAPTURED,
                capturedAt: new DateTimeImmutable('@' . $capturedAtTimestamp),
                providerData: $paymentIntent->toArray(),
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    public function refundPayment(RefundPaymentRequest $request): RefundResponse
    {
        try {
            $params = ['payment_intent' => $request->providerPaymentId];

            // Add amount if partial refund
            if ($request->amount !== null) {
                $params['amount'] = (int) ($request->amount * 100);
            }

            // Add reason if provided
            if ($request->reason !== null) {
                $params['reason'] = $this->mapRefundReason($request->reason);
            }

            // Add metadata
            if (!empty($request->metadata)) {
                $params['metadata'] = $request->metadata;
            }

            $refund = $this->stripeClient->refunds->create($params);

            return new RefundResponse(
                providerPaymentId: $request->providerPaymentId,
                refundId: $refund->id,
                amountRefunded: $refund->amount / 100,
                currency: strtoupper($refund->currency),
                status: $refund->status,
                refundedAt: new DateTimeImmutable('@' . $refund->created),
                reason: $request->reason,
                providerData: $refund->toArray(),
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    public function voidPayment(VoidPaymentRequest $request): VoidResponse
    {
        try {
            $params = [];

            if ($request->reason !== null) {
                $params['cancellation_reason'] = $request->reason;
            }

            $paymentIntent = $this->stripeClient->paymentIntents->cancel(
                $request->providerPaymentId,
                $params
            );

            return new VoidResponse(
                providerPaymentId: $paymentIntent->id,
                status: StripeStatusMapper::STATUS_CANCELLED,
                voidedAt: new DateTimeImmutable(),
                reason: $request->reason,
                providerData: $paymentIntent->toArray(),
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse
    {
        try {
            // Retrieve with expanded latest_charge to get charge details
            $paymentIntent = $this->stripeClient->paymentIntents->retrieve(
                $providerPaymentId,
                ['expand' => ['latest_charge']]
            );

            $amountCaptured = $paymentIntent->amount_received / 100;
            $amount = $paymentIntent->amount / 100;

            // Get refunded amount from latest charge
            $amountRefunded = 0.0;
            if ($paymentIntent->latest_charge) {
                $amountRefunded = ($paymentIntent->latest_charge->amount_refunded ?? 0) / 100;
            }

            $capturedAt = null;
            if ($paymentIntent->latest_charge && isset($paymentIntent->latest_charge->created)) {
                $capturedAt = new DateTimeImmutable('@' . $paymentIntent->latest_charge->created);
            }

            return new PaymentDetailsResponse(
                providerPaymentId: $paymentIntent->id,
                status: StripeStatusMapper::toNormalized($paymentIntent->status),
                amount: $amount,
                currency: strtoupper($paymentIntent->currency),
                amountCaptured: $amountCaptured,
                amountRefunded: $amountRefunded,
                isCaptured: StripeStatusMapper::isCaptured($paymentIntent->status),
                isRefunded: $amountRefunded > 0,
                isCancelled: StripeStatusMapper::isCancelled($paymentIntent->status),
                createdAt: new DateTimeImmutable('@' . $paymentIntent->created),
                capturedAt: $capturedAt,
                providerData: $paymentIntent->toArray()
            );
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    // ==========================================
    // TWO-STEP AUTHORIZATION
    // ==========================================

    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse
    {
        try {
            $amountInCents = (int) ($request->amount * 100);

            $params = [
                'amount' => $amountInCents,
                'currency' => strtolower($request->currency),
                'capture_method' => 'manual', // Two-step: manual capture
                'metadata' => array_merge($request->metadata, [
                    'order_id' => $request->orderId,
                    'shop_id' => $request->shopId,
                ]),
            ];

            $willConfirm = $request->paymentMethodId !== null;
            if ($request->paymentMethodId !== null) {
                $params['payment_method'] = $request->paymentMethodId;
                $params['confirm'] = true;

                // Stripe requires return_url when confirming a PaymentIntent
                if ($request->returnUrl === null) {
                    throw new PaymentAdapterException(
                        providerName: 'stripe',
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

            // Add return URL when confirming
            // Note: return_url is required when confirm=true
            if ($request->returnUrl !== null && $willConfirm) {
                $params['return_url'] = $request->returnUrl;
            }

            $paymentIntent = $this->stripeClient->paymentIntents->create($params);

            // Stripe authorizations expire after 7 days
            $expiresAt = new DateTimeImmutable('+7 days');

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
                providerData: $paymentIntent->toArray(),
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse
    {
        // In Stripe, capturing an authorization is the same as capturing a payment
        $captureRequest = new CapturePaymentRequest(
            providerPaymentId: $request->authorizationId,
            amount: $request->amount,
            metadata: $request->metadata
        );

        return $this->capturePayment($captureRequest);
    }

    public function voidAuthorization(VoidAuthorizationRequest $request): VoidResponse
    {
        // In Stripe, voiding an authorization is the same as cancelling a payment
        $voidRequest = new VoidPaymentRequest(
            providerPaymentId: $request->authorizationId,
            reason: $request->reason,
            metadata: $request->metadata
        );

        return $this->voidPayment($voidRequest);
    }

    public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse
    {
        // Stripe doesn't have native reauthorization
        // We need to create a new PaymentIntent with same details
        throw new PaymentAdapterException(
            providerName: 'stripe',
            errorCode: 'reauthorize_not_supported',
            message: 'Stripe does not support reauthorization. Create a new authorization instead.',
            context: ['authorization_id' => $request->authorizationId]
        );
    }

    // ==========================================
    // VAULTING / TOKENIZATION
    // ==========================================

    public function createPaymentMethod(CreatePaymentMethodRequest $request): PaymentMethodResponse
    {
        try {
            $params = [
                'type' => $this->mapPaymentMethodType($request->paymentMethod),
                'metadata' => $request->metadata,
            ];

            // Add payment method specific data
            $params = array_merge($params, $request->paymentMethodData);

            // Add billing details if provided
            if ($request->billingAddress !== null) {
                $params['billing_details'] = [
                    'address' => $request->billingAddress,
                ];
            }

            $paymentMethod = $this->stripeClient->paymentMethods->create($params);

            // Attach to customer if provided
            if ($request->customerId !== null) {
                $this->stripeClient->paymentMethods->attach(
                    $paymentMethod->id,
                    ['customer' => $request->customerId]
                );
            }

            return new PaymentMethodResponse(
                paymentMethodId: $paymentMethod->id,
                customerId: $request->customerId,
                type: $request->paymentMethod,
                details: $this->extractPaymentMethodDetails($paymentMethod),
                isDefault: false,
                createdAt: new DateTimeImmutable('@' . $paymentMethod->created),
                providerData: $paymentMethod->toArray(),
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    public function listPaymentMethods(string $customerId): array
    {
        try {
            $paymentMethods = $this->stripeClient->paymentMethods->all([
                'customer' => $customerId,
                'type' => 'card', // Can be extended to support other types
            ]);

            $result = [];
            foreach ($paymentMethods->data as $pm) {
                $result[] = new PaymentMethodResponse(
                    paymentMethodId: $pm->id,
                    customerId: $customerId,
                    type: 'card',
                    details: $this->extractPaymentMethodDetails($pm),
                    isDefault: false,
                    createdAt: new DateTimeImmutable('@' . $pm->created),
                    providerData: $pm->toArray()
                );
            }

            return $result;
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    public function deletePaymentMethod(string $paymentMethodId): bool
    {
        try {
            $this->stripeClient->paymentMethods->detach($paymentMethodId);
            return true;
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    // ==========================================
    // 3D SECURE / SCA
    // ==========================================

    public function initiate3DSecure(ThreeDSecureRequest $request): ThreeDSecureResponse
    {
        try {
            // In Stripe, 3DS is automatically handled during payment confirmation
            // We retrieve the payment and check if it requires action
            $paymentIntent = $this->stripeClient->paymentIntents->retrieve($request->paymentId);

            $redirectUrl = $paymentIntent->next_action->redirect_to_url->url ?? null;

            // Payment is authenticated if it's succeeded or requires_capture
            // (requires_capture means authorization was successful)
            $authenticated = in_array($paymentIntent->status, [
                StripeStatusMapper::STRIPE_SUCCEEDED,
                StripeStatusMapper::STRIPE_REQUIRES_CAPTURE
            ], true);

            return new ThreeDSecureResponse(
                paymentId: $paymentIntent->id,
                authenticated: $authenticated,
                status: $this->map3DSecureStatus($paymentIntent->status),
                redirectUrl: $redirectUrl,
                authenticationId: $paymentIntent->id,
                providerData: $paymentIntent->toArray()
            );
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    public function verify3DSecureResult(string $providerPaymentId): bool
    {
        try {
            $paymentIntent = $this->stripeClient->paymentIntents->retrieve($providerPaymentId);

            // Check if payment succeeded after 3DS authentication
            return $paymentIntent->status === StripeStatusMapper::STRIPE_SUCCEEDED
                || $paymentIntent->status === StripeStatusMapper::STRIPE_REQUIRES_CAPTURE;
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    // ==========================================
    // PROVIDER METADATA & CAPABILITIES
    // ==========================================

    public function getSupportedPaymentMethods(): array
    {
        return [
            'card',
            'sepa_debit',
            'ideal',
            'giropay',
            'sofort',
            'bancontact',
            'eps',
            'p24',
        ];
    }

    public function getProviderName(): string
    {
        return 'stripe';
    }

    public function supportsFeature(string $feature): bool
    {
        return match ($feature) {
            'partial_refund' => true,
            'partial_capture' => true,
            'recurring' => true,
            'saved_cards' => true,
            'webhooks' => true,
            '3ds' => true,
            'installments' => false,
            'invoice' => false,
            default => false,
        };
    }

    // ==========================================
    // WEBHOOK PROCESSING
    // ==========================================

    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent
    {
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);

            return new StripeWebhookEvent($event, $payload, verified: true);
        } catch (\UnexpectedValueException $e) {
            throw new PaymentAdapterException(
                providerName: 'stripe',
                errorCode: 'invalid_payload',
                message: 'Invalid webhook payload',
                previous: $e
            );
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            throw new PaymentAdapterException(
                providerName: 'stripe',
                errorCode: 'invalid_signature',
                message: 'Invalid webhook signature',
                previous: $e
            );
        }
    }

    // ==========================================
    // STRIPE-SPECIFIC METHODS (Sprint 19)
    // ==========================================

    /**
     * @inheritDoc
     */
    public function retrieveCheckoutSession(string $sessionId, array $expand = []): Session
    {
        try {
            $options = [];
            if (!empty($expand)) {
                $options['expand'] = $expand;
            }

            return $this->stripeClient->checkout->sessions->retrieve($sessionId, $options);
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function createCheckoutSession(array $params): Session
    {
        try {
            return $this->stripeClient->checkout->sessions->create($params);
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function retrievePaymentIntent(string $paymentIntentId, array $expand = []): PaymentIntent
    {
        try {
            $options = [];
            if (!empty($expand)) {
                $options['expand'] = $expand;
            }

            return $this->stripeClient->paymentIntents->retrieve($paymentIntentId, $options);
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function createRefundByCharge(
        string $chargeId,
        ?int $amount = null,
        ?string $reason = null,
        ?array $metadata = null
    ): Refund {
        try {
            $params = ['charge' => $chargeId];

            if ($amount !== null) {
                $params['amount'] = $amount;
            }

            if ($reason !== null) {
                $params['reason'] = $reason;
            }

            if ($metadata !== null) {
                $params['metadata'] = $metadata;
            }

            return $this->stripeClient->refunds->create($params);
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function cancelPaymentIntent(string $paymentIntentId, ?string $cancellationReason = null): PaymentIntent
    {
        try {
            $params = [];

            if ($cancellationReason !== null) {
                $params['cancellation_reason'] = $this->mapCancellationReason($cancellationReason);
            }

            return $this->stripeClient->paymentIntents->cancel($paymentIntentId, $params);
        } catch (ApiErrorException $e) {
            throw $this->convertStripeException($e);
        }
    }

    // ==========================================
    // PRIVATE HELPER METHODS
    // ==========================================

    private function convertStripeException(ApiErrorException $e): PaymentAdapterException
    {
        $errorCode = $e->getError()->code ?? 'unknown_error';
        $message = $e->getError()->message ?? $e->getMessage();

        return new PaymentAdapterException(
            providerName: 'stripe',
            errorCode: $errorCode,
            message: $message,
            code: $e->getCode(),
            previous: $e,
            context: [
                'type' => $e->getError()->type,
                'param' => $e->getError()->param,
            ]
        );
    }

    private function mapRefundReason(string $reason): string
    {
        return match ($reason) {
            'requested_by_customer' => 'requested_by_customer',
            'fraudulent' => 'fraudulent',
            'duplicate' => 'duplicate',
            default => 'requested_by_customer',
        };
    }

    private function mapCancellationReason(string $reason): string
    {
        return match ($reason) {
            'requested_by_customer' => 'requested_by_customer',
            'fraudulent' => 'fraudulent',
            'duplicate' => 'duplicate',
            'abandoned' => 'abandoned',
            default => 'requested_by_customer',
        };
    }

    private function mapPaymentMethodType(string $genericType): string
    {
        return match ($genericType) {
            'card' => 'card',
            'sepa_debit' => 'sepa_debit',
            'sepa' => 'sepa_debit',
            'paypal' => 'paypal',
            default => $genericType,
        };
    }

    private function extractPaymentMethodDetails(\Stripe\PaymentMethod $pm): array
    {
        if ($pm->type === 'card' && $pm->card) {
            return [
                'last4' => $pm->card->last4,
                'brand' => $pm->card->brand,
                'exp_month' => $pm->card->exp_month,
                'exp_year' => $pm->card->exp_year,
                'funding' => $pm->card->funding,
            ];
        }

        return [];
    }

    private function map3DSecureStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            StripeStatusMapper::STRIPE_SUCCEEDED,
            StripeStatusMapper::STRIPE_REQUIRES_CAPTURE => 'authenticated',
            StripeStatusMapper::STRIPE_REQUIRES_ACTION => 'pending',
            StripeStatusMapper::STRIPE_CANCELED => 'failed',
            default => 'not_required',
        };
    }
}
