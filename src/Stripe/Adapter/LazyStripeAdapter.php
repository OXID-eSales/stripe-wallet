<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\WebhookEvent;
use OxidEsales\PaymentComponent\Adapter\Request\AuthorizePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CaptureAuthorizationRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CreatePaymentMethodRequest;
use OxidEsales\PaymentComponent\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\ReauthorizePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\ThreeDSecureRequest;
use OxidEsales\PaymentComponent\Adapter\Request\VoidAuthorizationRequest;
use OxidEsales\PaymentComponent\Adapter\Request\VoidPaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\AuthorizationResponse;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Adapter\Response\CreatePaymentResponse;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentMethodResponse;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentResponse;
use OxidEsales\PaymentComponent\Adapter\Response\RefundResponse;
use OxidEsales\PaymentComponent\Adapter\Response\ThreeDSecureResponse;
use OxidEsales\PaymentComponent\Adapter\Response\CancellationResponse;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

/**
 * Lazy adapter that defers adapter creation until first use.
 *
 * Sprint 26: Created to allow module activation without API keys configured.
 * The factory throws when keys are missing, but this adapter delays that check
 * until actual payment operations are performed.
 *
 * @since 2.0.0
 */
final class LazyStripeAdapter implements PaymentAdapterInterface
{
    private ?PaymentAdapterInterface $adapter = null;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory
    ) {
    }

    private function getAdapter(): PaymentAdapterInterface
    {
        if ($this->adapter === null) {
            $this->adapter = $this->adapterFactory->getStripeAdapter();
        }
        return $this->adapter;
    }

    // ==========================================
    // BASIC PAYMENT OPERATIONS
    // ==========================================

    public function createPayment(CreatePaymentRequest $request): PaymentResponse
    {
        return $this->getAdapter()->createPayment($request);
    }

    public function capturePayment(CapturePaymentRequest $request): CaptureResponse
    {
        return $this->getAdapter()->capturePayment($request);
    }

    public function refundPayment(RefundPaymentRequest $request): RefundResponse
    {
        return $this->getAdapter()->refundPayment($request);
    }

    public function voidPayment(VoidPaymentRequest $request): CancellationResponse
    {
        return $this->getAdapter()->voidPayment($request);
    }

    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse
    {
        return $this->getAdapter()->getPaymentDetails($providerPaymentId);
    }

    // ==========================================
    // TWO-STEP AUTHORIZATION
    // ==========================================

    public function authorizePayment(AuthorizePaymentRequest $request): AuthorizationResponse
    {
        return $this->getAdapter()->authorizePayment($request);
    }

    public function captureAuthorization(CaptureAuthorizationRequest $request): CaptureResponse
    {
        return $this->getAdapter()->captureAuthorization($request);
    }

    public function voidAuthorization(VoidAuthorizationRequest $request): CancellationResponse
    {
        return $this->getAdapter()->voidAuthorization($request);
    }

    public function reauthorizePayment(ReauthorizePaymentRequest $request): AuthorizationResponse
    {
        return $this->getAdapter()->reauthorizePayment($request);
    }

    // ==========================================
    // VAULTING / TOKENIZATION
    // ==========================================

    public function createPaymentMethod(CreatePaymentMethodRequest $request): PaymentMethodResponse
    {
        return $this->getAdapter()->createPaymentMethod($request);
    }

    /**
     * @return array<PaymentMethodResponse>
     */
    public function listPaymentMethods(string $customerId): array
    {
        return $this->getAdapter()->listPaymentMethods($customerId);
    }

    public function deletePaymentMethod(string $paymentMethodId): bool
    {
        return $this->getAdapter()->deletePaymentMethod($paymentMethodId);
    }

    // ==========================================
    // 3D SECURE / SCA
    // ==========================================

    public function initiate3DSecure(ThreeDSecureRequest $request): ThreeDSecureResponse
    {
        return $this->getAdapter()->initiate3DSecure($request);
    }

    public function verify3DSecureResult(string $providerPaymentId): bool
    {
        return $this->getAdapter()->verify3DSecureResult($providerPaymentId);
    }

    // ==========================================
    // PROVIDER METADATA & CAPABILITIES
    // ==========================================

    /**
     * @return array<string>
     */
    public function getSupportedPaymentMethods(): array
    {
        return $this->getAdapter()->getSupportedPaymentMethods();
    }

    public function getProviderName(): string
    {
        return $this->getAdapter()->getProviderName();
    }

    public function supportsFeature(string $feature): bool
    {
        return $this->getAdapter()->supportsFeature($feature);
    }

    // ==========================================
    // WEBHOOK PROCESSING
    // ==========================================

    public function parseWebhook(string $payload, string $signature, string $secret): WebhookEvent
    {
        return $this->getAdapter()->parseWebhook($payload, $signature, $secret);
    }
}
