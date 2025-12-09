<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\Service\OrderPaymentStateServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Event handler that updates OXPAID on orders when contracts are fulfilled.
 *
 * Sprint 14: Properly implements event-driven architecture.
 * Listens for ContractFulfilledEvent and updates the order's payment timestamp.
 *
 * Sprint 16: Uses OrderPaymentStateService for DRY compliance.
 * All OXPAID/OXTRANSSTATUS/OXTRANSID updates consolidated in single service.
 *
 * @since 1.0.0
 */
class OrderPaymentCompletedHandler implements HandlerInterface
{
    public function __construct(
        private readonly OrderPaymentStateServiceInterface $orderPaymentStateService,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return ContractFulfilledEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractFulfilledEvent) {
            return;
        }

        $contract = $event->getContract();
        $orderId = $contract->getOrderId();
        $providerOrderId = $contract->getProviderOrderId();

        if ($orderId === null || $orderId === '') {
            $this->logger->warning('ContractFulfilledEvent received without order ID', [
                'contract_id' => $contract->getId(),
            ]);
            return;
        }

        // Sprint 16: Use OrderPaymentStateService for all payment state updates (DRY)
        $updated = $this->orderPaymentStateService->markOrderAsPaid(
            $orderId,
            $providerOrderId
        );

        if ($updated) {
            $this->logger->info('Order payment completed via ContractFulfilledEvent', [
                'order_id' => $orderId,
                'contract_id' => $contract->getId(),
                'provider_order_id' => $providerOrderId,
            ]);
        }
    }
}
