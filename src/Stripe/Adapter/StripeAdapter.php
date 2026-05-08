<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\WebhookEvent;
use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\VoidPaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\AuthorizePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\CaptureAuthorizationRequest;
use OxidEsales\PaymentBase\Adapter\Request\VoidAuthorizationRequest;
use OxidEsales\PaymentBase\Adapter\Request\ReauthorizePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentMethodRequest;
use OxidEsales\PaymentBase\Adapter\Request\ThreeDSecureRequest;
use OxidEsales\PaymentBase\Adapter\Response\PaymentResponse;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\PaymentBase\Adapter\Response\CancellationResponse;
use OxidEsales\PaymentBase\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentBase\Adapter\Response\AuthorizationResponse;
use OxidEsales\PaymentBase\Adapter\Response\PaymentMethodResponse;
use OxidEsales\PaymentBase\Adapter\Response\ThreeDSecureResponse;
use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\RefundHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\CheckoutSessionHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentMethodHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\StripeExceptionConverter;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe adapter implementing the payment provider interface.
 *
 * Delegates to focused helper classes for each operation group.
 * Sprint 46: Refactored to reduce ECC from 93 to ~30.
 *
 * Implements StripeAdapterInterface (extends PaymentAdapterInterface from payment-base).
 * Each method is a thin delegation to focused helper classes. Method count is driven by the interface contract.
 *
 * @since 1.0.0
 */
final class StripeAdapter implements StripeAdapterInterface
{
    private readonly PaymentIntentHelper $paymentIntentHelper;
    private readonly RefundHelper $refundHelper;
    private readonly CheckoutSessionHelper $checkoutSessionHelper;
    private readonly PaymentMethodHelper $paymentMethodHelper;

    public function __construct(
        private readonly StripeClient $stripeClient,
        ?PaymentIntentHelper $paymentIntentHelper = null,
        ?RefundHelper $refundHelper = null,
        ?CheckoutSessionHelper $checkoutSessionHelper = null,
        ?PaymentMethodHelper $paymentMethodHelper = null
    ) {
        $this->paymentIntentHelper = $paymentIntentHelper ?? new PaymentIntentHelper();
        $this->refundHelper = $refundHelper ?? new RefundHelper();
        $this->checkoutSessionHelper = $checkoutSessionHelper ?? new CheckoutSessionHelper();
        $this->paymentMethodHelper = $paymentMethodHelper ?? new PaymentMethodHelper();
    }

    // ==========================================
    // BASIC PAYMENT OPERATIONS
    // ==========================================

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        return $this->paymentIntentHelper->createPaymentIntent($this->stripeClient, $request);
    }

    public function capturePayment(CapturePaymentRequest $request): CaptureResponse
    {
        return $this->paymentIntentHelper->capturePaymentIntent($this->stripeClient, $request);
    }

    public function refundPayment(RefundPaymentRequest $request): RefundResponse
    {
        return $this->refundHelper->refundPayment($this->stripeClient, $request);
    }

    public function voidPayment(VoidPaymentRequest $request): CancellationResponse
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

            /** @var array<string, mixed> $providerData */
            $providerData = $paymentIntent->toArray();

            return CancellationResponse::success(
                providerPaymentId: $paymentIntent->id,
                authorizationId: $paymentIntent->id,
                status: StripeStatusMapper::STATUS_CANCELLED,
                cancelledAt: new DateTimeImmutable(),
                reason: $request->reason,
                providerData: $providerData,
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse
    {
        return $this->paymentIntentHelper->getPaymentDetails($this->stripeClient, $providerPaymentId);
    }

    // ==========================================
    // TWO-STEP AUTHORIZATION
    // ==========================================

    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse
    {
        return $this->paymentIntentHelper->authorizePayment($this->stripeClient, $request);
    }

    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse
    {
        $captureRequest = new CapturePaymentRequest(
            providerPaymentId: $request->authorizationId,
            amount: $request->amount,
            metadata: $request->metadata
        );
        return $this->capturePayment($captureRequest);
    }

    public function voidAuthorization(VoidAuthorizationRequest $request): CancellationResponse
    {
        $voidRequest = new VoidPaymentRequest(
            providerPaymentId: $request->authorizationId,
            reason: $request->reason,
            metadata: $request->metadata
        );
        return $this->voidPayment($voidRequest);
    }

    public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse
    {
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
        return $this->paymentMethodHelper->createPaymentMethod($this->stripeClient, $request);
    }

    public function listPaymentMethods(string $customerId): array
    {
        return $this->paymentMethodHelper->listPaymentMethods($this->stripeClient, $customerId);
    }

    public function deletePaymentMethod(string $paymentMethodId): bool
    {
        return $this->paymentMethodHelper->deletePaymentMethod($this->stripeClient, $paymentMethodId);
    }

    // ==========================================
    // 3D SECURE / SCA
    // ==========================================

    public function initiate3DSecure(ThreeDSecureRequest $request): ThreeDSecureResponse
    {
        try {
            $paymentIntent = $this->stripeClient->paymentIntents->retrieve($request->paymentId);
            $redirectUrl = $paymentIntent->next_action->redirect_to_url->url ?? null;

            $authenticated = in_array($paymentIntent->status, [
                StripeStatusMapper::STRIPE_SUCCEEDED,
                StripeStatusMapper::STRIPE_REQUIRES_CAPTURE
            ], true);

            /** @var array<string, mixed> $providerData */
            $providerData = $paymentIntent->toArray();

            return new ThreeDSecureResponse(
                paymentId: $paymentIntent->id,
                authenticated: $authenticated,
                status: $this->map3DSecureStatus($paymentIntent->status),
                redirectUrl: $redirectUrl,
                authenticationId: $paymentIntent->id,
                providerData: $providerData
            );
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    public function verify3DSecureResult(string $providerPaymentId): bool
    {
        try {
            $paymentIntent = $this->stripeClient->paymentIntents->retrieve($providerPaymentId);
            return $paymentIntent->status === StripeStatusMapper::STRIPE_SUCCEEDED
                || $paymentIntent->status === StripeStatusMapper::STRIPE_REQUIRES_CAPTURE;
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    // ==========================================
    // PROVIDER METADATA & CAPABILITIES
    // ==========================================

    public function getSupportedPaymentMethods(): array
    {
        return ['card', 'sepa_debit', 'ideal', 'giropay', 'sofort', 'bancontact', 'eps', 'p24'];
    }

    public function getProviderName(): string
    {
        return 'stripe';
    }

    public function supportsFeature(string $feature): bool
    {
        return match ($feature) {
            'partial_refund', 'partial_capture', 'recurring', 'saved_cards', 'webhooks', '3ds' => true,
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

    public function retrieveCheckoutSession(string $sessionId, array $expand = []): Session
    {
        return $this->checkoutSessionHelper->retrieveCheckoutSession($this->stripeClient, $sessionId, $expand);
    }

    public function createCheckoutSession(array $params): Session
    {
        return $this->checkoutSessionHelper->createCheckoutSession($this->stripeClient, $params);
    }

    public function retrievePaymentIntent(string $paymentIntentId, array $expand = []): PaymentIntent
    {
        return $this->paymentIntentHelper->retrievePaymentIntent($this->stripeClient, $paymentIntentId, $expand);
    }

    public function createRefundByCharge(
        string $chargeId,
        ?int $amount = null,
        ?string $reason = null,
        ?array $metadata = null
    ): Refund {
        return $this->refundHelper->createRefundByCharge($this->stripeClient, $chargeId, $amount, $reason, $metadata);
    }

    public function cancelPaymentIntent(string $paymentIntentId, ?string $cancellationReason = null): PaymentIntent
    {
        return $this->paymentIntentHelper->cancelPaymentIntent($this->stripeClient, $paymentIntentId, $cancellationReason);
    }

    public function getPaymentIntentRiskScore(string $paymentIntentId): ?float
    {
        return $this->paymentIntentHelper->getRiskScore($this->stripeClient, $paymentIntentId);
    }

    public function retrieveCharge(string $chargeId): \Stripe\Charge
    {
        return $this->refundHelper->retrieveCharge($this->stripeClient, $chargeId);
    }

    public function createStripeCustomer(array $params): \Stripe\Customer
    {
        try {
            return $this->stripeClient->customers->create($params);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    public function retrieveStripeCustomer(string $customerId): \Stripe\Customer
    {
        try {
            return $this->stripeClient->customers->retrieve($customerId, []);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    public function testConnection(): bool
    {
        try {
            $this->stripeClient->balance->retrieve();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ==========================================
    // PRIVATE HELPER METHODS
    // ==========================================

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
