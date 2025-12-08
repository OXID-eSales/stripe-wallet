<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreateOrderRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\ShopOrderServiceInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;

/**
 * Creates OXID orders when contract is ready to commit.
 *
 * This handler uses OxidShopOrderService to create orders via OXID's
 * standard Order::finalizeOrder() flow, ensuring proper integration
 * with OXID's order processing pipeline.
 *
 * Flow:
 * 1. Contract becomes READY_TO_COMMIT (all conditions fulfilled)
 * 2. This handler creates the order via OxidShopOrderService
 * 3. Contract transitions to COMMITTED state
 * 4. Dispatches ContractCommittedEvent
 *
 * NOTE: EventDispatcher is fetched lazily to avoid circular dependency
 * with EventListenerProvider during container initialization.
 *
 * @since 1.0.0
 */
class StripeOrderCreationHandler implements HandlerInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private ShopOrderServiceInterface $shopOrderService,
        private Connection $connection
    ) {
    }

    private function getEventDispatcher(): EventDispatcherInterface
    {
        return ContainerFactory::getInstance()
            ->getContainer()
            ->get(EventDispatcherInterface::class);
    }

    public static function getHandledEventClass(): string
    {
        return ContractReadyToCommitEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractReadyToCommitEvent) {
            return;
        }

        $contract = $event->getContract();
        $context = $event->getContext();

        // Validate contract state
        if (!$contract->getState()->isReadyToCommit()) {
            Registry::getLogger()->warning('StripeOrderCreationHandler: Contract not ready to commit', [
                'contract_id' => $contract->getId(),
                'state' => $contract->getStateValue(),
            ]);
            return;
        }

        if (!$contract->areAllConditionsFulfilled()) {
            Registry::getLogger()->warning('StripeOrderCreationHandler: Not all conditions fulfilled', [
                'contract_id' => $contract->getId(),
            ]);
            return;
        }

        try {
            // Get session basket
            $basket = Registry::getSession()->getBasket();
            /** @phpstan-ignore-next-line booleanNot.alwaysFalse - basket could be empty in edge cases */
            if (!$basket) {
                Registry::getLogger()->error('StripeOrderCreationHandler: No basket in session');
                $context->set('error', 'No basket found in session');
                return;
            }

            // Create order request
            // Note: CreateOrderRequest gets basket from session via getBasket() method
            $paymentIntentId = $context->get('paymentIntentId');
            $authorizationId = $context->get('authorizationId');
            /** @var string|null $paymentTransactionId */
            $paymentTransactionId = is_string($paymentIntentId) ? $paymentIntentId
                : (is_string($authorizationId) ? $authorizationId : null);

            /** @phpstan-ignore-next-line nullCoalesce.expr - getPaymentId can return null in edge cases */
            $paymentId = $basket->getPaymentId() ?? 'oxidstripe';
            $request = new CreateOrderRequest(
                sessionId: Registry::getSession()->getId(),
                userId: $contract->getUserId(),
                paymentId: $paymentId,
                paymentTransactionId: $paymentTransactionId,
                orderRemark: null,
                metadata: [
                    'contract_id' => $contract->getId(),
                    'provider_order_id' => $contract->getProviderOrderId(),
                ]
            );

            // Create order via OXID's standard flow
            $orderResponse = $this->shopOrderService->createOrder($request);
            $orderId = $orderResponse->orderId;

            Registry::getLogger()->info('StripeOrderCreationHandler: Order created', [
                'order_id' => $orderId,
                'order_number' => $orderResponse->orderNumber,
                'contract_id' => $contract->getId(),
            ]);

            // Update contract
            $contract->commitToOrder($orderId);
            $this->contractRepository->save($contract);

            // Set orderId in context for downstream handlers
            $context->set('orderId', $orderId);
            $context->set('orderNumber', $orderResponse->orderNumber);

            // Sprint 14: Update OXPAID immediately since payment was confirmed
            // This is the reliable path - webhook might arrive before contract is committed
            $this->updateOrderPaidTimestamp($orderId, $contract->getProviderOrderId());

            // Dispatch committed event
            $committedEvent = new ContractCommittedEvent(
                $contract,
                $context,
                $orderId
            );
            $this->getEventDispatcher()->dispatch($committedEvent);
        } catch (\Throwable $e) {
            Registry::getLogger()->error('StripeOrderCreationHandler: Order creation failed', [
                'contract_id' => $contract->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $context->set('error', 'Order creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Update OXPAID timestamp on the order.
     *
     * Sprint 14: Called immediately after order creation since payment was confirmed
     * on Stripe. This is more reliable than waiting for webhook as it might arrive
     * before the contract transitions to COMMITTED state.
     */
    private function updateOrderPaidTimestamp(string $orderId, ?string $providerOrderId): void
    {
        try {
            // Update OXPAID to current time using PHP date to match OXID's timezone handling
            $currentTime = date('Y-m-d H:i:s');
            $sql = "UPDATE oxorder SET OXPAID = :paidTime WHERE OXID = :orderId AND OXPAID = '0000-00-00 00:00:00'";
            $affected = $this->connection->executeStatement($sql, ['orderId' => $orderId, 'paidTime' => $currentTime]);

            // Also update OXTRANSSTATUS to OK
            $sql = "UPDATE oxorder SET OXTRANSSTATUS = 'OK' WHERE OXID = :orderId";
            $this->connection->executeStatement($sql, ['orderId' => $orderId]);

            // Update OXTRANSID if not already set
            if ($providerOrderId !== null && $providerOrderId !== '') {
                $sql = "UPDATE oxorder SET OXTRANSID = :transId WHERE OXID = :orderId AND (OXTRANSID IS NULL OR OXTRANSID = '')";
                $this->connection->executeStatement($sql, [
                    'orderId' => $orderId,
                    'transId' => $providerOrderId,
                ]);
            }

            if ($affected > 0) {
                Registry::getLogger()->info('OXPAID updated in order creation flow', [
                    'order_id' => $orderId,
                    'provider_order_id' => $providerOrderId,
                ]);
            }
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to update OXPAID in order creation', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
