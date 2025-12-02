<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

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
        private ShopOrderServiceInterface $shopOrderService
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
            if (!$basket) {
                Registry::getLogger()->error('StripeOrderCreationHandler: No basket in session');
                $context->set('error', 'No basket found in session');
                return;
            }

            // Create order request
            // Note: CreateOrderRequest gets basket from session via getBasket() method
            $request = new CreateOrderRequest(
                sessionId: Registry::getSession()->getId(),
                userId: $contract->getUserId(),
                paymentId: $basket->getPaymentId() ?? 'oxidstripe',
                paymentTransactionId: $context->get('paymentIntentId') ?? $context->get('authorizationId'),
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
}
