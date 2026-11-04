<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Response\PaymentResponse;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCustomerDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\RefundHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\CheckoutSessionHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\StripeExceptionConverter;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Stripe adapter implementing the payment provider interface.
 *
 * Delegates to focused helper classes for each operation group.
 *
 * Implements StripeAdapterInterface. Each method is a thin delegation to focused helper classes.
 *
 * @since 1.0.0
 */
class StripeAdapter implements StripeAdapterInterface
{
    /**
     * Sprint 133 · Story 3 (F8): the two helpers that carry duplicate-charge
     * protection are required. They used to default to `new XHelper()`, i.e. an
     * instance with no idempotency repository, so an adapter built without them
     * performed unprotected captures and refunds indistinguishably from a
     * protected one.
     */
    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly PaymentIntentHelper $paymentIntentHelper,
        private readonly RefundHelper $refundHelper,
        private readonly CheckoutSessionHelper $checkoutSessionHelper,
    ) {
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

    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse
    {
        return $this->paymentIntentHelper->getPaymentDetails($this->stripeClient, $providerPaymentId);
    }

    // ==========================================
    // STRIPE-SPECIFIC METHODS
    // ==========================================

    public function retrieveCheckoutSession(string $sessionId, array $expand = []): StripeCheckoutSessionDto
    {
        $raw = $this->checkoutSessionHelper->retrieveCheckoutSession($this->stripeClient, $sessionId, $expand);
        return StripeObjectMapper::fromCheckoutSession($raw);
    }

    public function createCheckoutSession(array $params): StripeCheckoutSessionDto
    {
        $raw = $this->checkoutSessionHelper->createCheckoutSession($this->stripeClient, $params);
        return StripeObjectMapper::fromCheckoutSession($raw);
    }

    public function expireCheckoutSession(string $sessionId): StripeCheckoutSessionDto
    {
        $raw = $this->checkoutSessionHelper->expireCheckoutSession($this->stripeClient, $sessionId);
        return StripeObjectMapper::fromCheckoutSession($raw);
    }

    public function retrievePaymentIntent(string $paymentIntentId, array $expand = []): StripePaymentIntentDto
    {
        $raw = $this->paymentIntentHelper->retrievePaymentIntent($this->stripeClient, $paymentIntentId, $expand);
        return StripeObjectMapper::fromPaymentIntent($raw);
    }

    /**
     * @param array<string, string>|null $metadata
     */
    public function createRefundByCharge(
        string $chargeId,
        ?int $amount = null,
        ?string $reason = null,
        ?array $metadata = null,
        ?string $requestReference = null
    ): StripeRefundDto {
        $raw = $this->refundHelper->createRefundByCharge(
            $this->stripeClient,
            $chargeId,
            $amount,
            $reason,
            $metadata,
            $requestReference
        );
        return StripeObjectMapper::fromRefund($raw);
    }

    public function cancelPaymentIntent(string $paymentIntentId, ?string $cancellationReason = null): StripePaymentIntentDto
    {
        $raw = $this->paymentIntentHelper->cancelPaymentIntent($this->stripeClient, $paymentIntentId, $cancellationReason);
        return StripeObjectMapper::fromPaymentIntent($raw);
    }

    public function retrieveCharge(string $chargeId): StripeChargeDto
    {
        $raw = $this->refundHelper->retrieveCharge($this->stripeClient, $chargeId);
        return StripeObjectMapper::fromCharge($raw);
    }

    public function listChargeRefunds(string $chargeId): array
    {
        $refunds = [];
        foreach ($this->refundHelper->listRefundsByCharge($this->stripeClient, $chargeId) as $raw) {
            $refunds[] = StripeObjectMapper::fromRefund($raw);
        }

        return $refunds;
    }

    public function createStripeCustomer(array $params): StripeCustomerDto
    {
        try {
            $raw = $this->stripeClient->customers->create($params);
            return StripeObjectMapper::fromCustomer($raw);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    public function retrieveStripeCustomer(string $customerId): StripeCustomerDto
    {
        try {
            $raw = $this->stripeClient->customers->retrieve($customerId, []);
            return StripeObjectMapper::fromCustomer($raw);
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    // ==========================================
    // PRIVATE HELPER METHODS
    // ==========================================
}
