<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionDispatcherInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\Payments\Stripe\Service\OrderContractResolver;

/**
 * Dispatches admin order actions (refund, capture, cancel) via event system.
 *
 * Sprint 46: Extracted from OrderRefund to reduce ECC.
 *
 * @since 2.0.0
 */
class OrderActionDispatcher implements AdminActionDispatcherInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly OrderContractResolver $contractResolver
    ) {
    }

    /**
     * Dispatch a refund event. Null amount = full refund.
     */
    public function dispatchRefund(
        Order $order,
        ?string $reason,
        ?string $description,
        ?float $amount = null
    ): EventContext {
        $context = new EventContext([
            'orderId' => $order->getId(),
            'contractId' => $this->contractResolver->getContractIdFromOrder($order),
            'amount' => $amount,
            'reason' => $reason,
            'description' => $description,
            'initiator' => 'admin',
        ]);

        $this->eventDispatcher->dispatch(new StripeRefundRequestEvent($context));
        return $context;
    }

    /**
     * Dispatch a capture event. Null amount = full capture.
     */
    public function dispatchCapture(
        Order $order,
        string $paymentIntentId,
        ?string $reason,
        ?float $amount = null
    ): EventContext {
        $context = new EventContext([
            'orderId' => $order->getId(),
            'contractId' => $this->contractResolver->getContractIdFromOrder($order),
            'paymentIntentId' => $paymentIntentId,
            'amount' => $amount,
            'initiator' => 'admin',
            'reason' => $reason,
        ]);

        $this->eventDispatcher->dispatch(new StripeCaptureRequestEvent($context));
        return $context;
    }

    /**
     * Dispatch a cancel authorization event. Returns the EventContext with results.
     */
    public function dispatchCancel(Order $order, string $paymentIntentId, ?string $cancellationReason): EventContext
    {
        $context = new EventContext([
            'orderId' => $order->getId(),
            'contractId' => $this->contractResolver->getContractIdFromOrder($order),
            'paymentIntentId' => $paymentIntentId,
            'cancellationReason' => $cancellationReason,
            'initiator' => 'admin',
        ]);

        $this->eventDispatcher->dispatch(new StripeCancelAuthorizationRequestEvent($context));
        return $context;
    }

    /**
     * Extract payment intent ID from order.
     */
    public function getPaymentIntentId(Order $order): ?string
    {
        /** @phpstan-ignore-next-line OXID core: magic property oxorder__oxtransid->value */
        $transId = $order->oxorder__oxtransid->value ?? null;
        return is_string($transId) && $transId !== '' ? $transId : null;
    }

    /**
     * Get contract ID from order.
     */
    public function getContractIdFromOrder(Order $order): ?string
    {
        return $this->contractResolver->getContractIdFromOrder($order);
    }

    // =========================================================================
    // Sprint I — unified admin-action interface (AdminActionDispatcherInterface).
    // Wraps the PSP-specific dispatchers above. Extras carry Stripe-specific
    // bits: `paymentIntentId`, `description`.
    // =========================================================================

    /** @param array<string, mixed> $extras */
    public function refund(Order $order, ?float $amount, ?string $reason, array $extras = []): void
    {
        $description = isset($extras['description']) && is_string($extras['description'])
            ? $extras['description']
            : null;
        $this->dispatchRefund($order, $reason, $description, $amount);
    }

    /** @param array<string, mixed> $extras */
    public function capture(Order $order, ?float $amount, ?string $reason, array $extras = []): void
    {
        $paymentIntentId = isset($extras['paymentIntentId']) && is_string($extras['paymentIntentId']) && $extras['paymentIntentId'] !== ''
            ? $extras['paymentIntentId']
            : (string) $this->getPaymentIntentId($order);
        if ($paymentIntentId === '') {
            return;
        }
        $this->dispatchCapture($order, $paymentIntentId, $reason, $amount);
    }

    /** @param array<string, mixed> $extras */
    public function cancel(Order $order, ?string $reason, array $extras = []): void
    {
        $paymentIntentId = isset($extras['paymentIntentId']) && is_string($extras['paymentIntentId']) && $extras['paymentIntentId'] !== ''
            ? $extras['paymentIntentId']
            : (string) $this->getPaymentIntentId($order);
        if ($paymentIntentId === '') {
            return;
        }
        $this->dispatchCancel($order, $paymentIntentId, $reason);
    }
}
