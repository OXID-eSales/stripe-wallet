<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentComponent\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\OrderPaymentStateServiceInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

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
 * Sprint 22: EventDispatcher now injected via constructor (no ContainerFactory).
 * Sprint 25: Added event file logger for debugging.
 *
 * @since 1.0.0
 */
class StripeOrderCreationHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ShopOrderServiceInterface $shopOrderService,
        private readonly OrderPaymentStateServiceInterface $orderPaymentStateService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return ContractReadyToCommitEvent::class;
    }

    public function handle(object $event): void
    {
        $this->logEvent('StripeOrderCreationHandler::handle() START');

        if (!$event instanceof ContractReadyToCommitEvent) {
            $this->logEvent('StripeOrderCreationHandler: Wrong event type, skipping');
            return;
        }

        $contract = $event->getContract();
        $context = $event->getContext();
        $this->logEvent('StripeOrderCreationHandler: Processing', [
            'contractId' => $contract->getId(),
            'state' => $contract->getStateValue(),
        ]);

        if (!$this->validateContractState($contract)) {
            return;
        }

        // STRP-74: Check if order was already created by EarlyOrderCreationHandler
        $existingOrderId = $contract->getOrderId();
        if ($existingOrderId !== null) {
            $this->logEvent('StripeOrderCreationHandler: Order already exists (early creation), skipping new order', [
                'existingOrderId' => $existingOrderId,
            ]);
            $this->handleExistingOrder($contract, $context, $existingOrderId);
            $this->logEvent('StripeOrderCreationHandler::handle() END - SUCCESS (used existing order)');
            return;
        }

        try {
            $basket = $this->validateAndGetBasket($context);
            if ($basket === null) {
                return;
            }

            $orderId = $this->createOrder($contract, $context, $basket);
            $this->handlePostOrderCreation($contract, $context, $orderId);
            $this->logEvent('StripeOrderCreationHandler::handle() END - SUCCESS');
        } catch (\Throwable $e) {
            $this->handleOrderCreationException($contract, $context, $e);
        }
    }

    private function validateContractState(\OxidEsales\PaymentComponent\Contract\PaymentContractInterface $contract): bool
    {
        if (!$contract->getState()->isReadyToCommit()) {
            $this->logEvent('StripeOrderCreationHandler: ERROR - Contract not ready to commit');
            Registry::getLogger()->warning('StripeOrderCreationHandler: Contract not ready to commit', [
                'contract_id' => $contract->getId(),
                'state' => $contract->getStateValue(),
            ]);
            return false;
        }

        if (!$contract->areAllConditionsFulfilled()) {
            $this->logEvent('StripeOrderCreationHandler: ERROR - Not all conditions fulfilled');
            Registry::getLogger()->warning('StripeOrderCreationHandler: Not all conditions fulfilled', [
                'contract_id' => $contract->getId(),
            ]);
            return false;
        }

        return true;
    }

    private function validateAndGetBasket(
        \OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface $context
    ): ?\OxidEsales\Eshop\Application\Model\Basket {
        $basket = Registry::getSession()->getBasket();
        $basketProductsCount = $basket !== null ? $basket->getProductsCount() : 0;
        $this->logEvent('StripeOrderCreationHandler: Checking basket', [
            'basketExists' => $basket !== null,
            'basketProductsCount' => $basketProductsCount,
            'sessionId' => Registry::getSession()->getId(),
        ]);

        /** @phpstan-ignore-next-line booleanNot.alwaysFalse - basket could be empty in edge cases */
        if (!$basket) {
            $this->logEvent('StripeOrderCreationHandler: ERROR - No basket in session');
            Registry::getLogger()->error('StripeOrderCreationHandler: No basket in session');
            $context->set('error', 'No basket found in session');
            return null;
        }

        if ($basket->getProductsCount() === 0) {
            $this->logEvent('StripeOrderCreationHandler: ERROR - Basket is empty (0 products)');
            Registry::getLogger()->error('StripeOrderCreationHandler: Basket is empty');
            $context->set('error', 'Basket is empty');
            return null;
        }

        return $basket;
    }

    private function createOrder(
        \OxidEsales\PaymentComponent\Contract\PaymentContractInterface $contract,
        \OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface $context,
        \OxidEsales\Eshop\Application\Model\Basket $basket
    ): string {
        $paymentIntentId = $context->get('paymentIntentId');
        $authorizationId = $context->get('authorizationId');
        /** @var string|null $paymentTransactionId */
        $paymentTransactionId = is_string($paymentIntentId) ? $paymentIntentId
            : (is_string($authorizationId) ? $authorizationId : null);

        /** @phpstan-ignore-next-line nullCoalesce.expr - getPaymentId can return null in edge cases */
        $paymentId = $basket->getPaymentId() ?? 'oxidstripe';

        $this->logEvent('StripeOrderCreationHandler: Creating order', [
            'userId' => $contract->getUserId(),
            'paymentId' => $paymentId,
        ]);

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

        $orderResponse = $this->shopOrderService->createOrder($request);
        $this->logEvent('StripeOrderCreationHandler: Order created', [
            'orderId' => $orderResponse->orderId,
            'orderNumber' => $orderResponse->orderNumber,
        ]);

        Registry::getLogger()->info('StripeOrderCreationHandler: Order created', [
            'order_id' => $orderResponse->orderId,
            'order_number' => $orderResponse->orderNumber,
            'contract_id' => $contract->getId(),
        ]);

        $context->set('orderId', $orderResponse->orderId);
        $context->set('orderNumber', $orderResponse->orderNumber);

        return $orderResponse->orderId;
    }

    /**
     * Handle existing order from early creation (STRP-74).
     *
     * When EarlyOrderCreationHandler has already created the order, we:
     * 1. Set context variables for downstream handlers
     * 2. Commit the contract to the existing order
     * 3. Update OXPAID on the existing order
     * 4. Dispatch ContractCommittedEvent
     */
    private function handleExistingOrder(
        \OxidEsales\PaymentComponent\Contract\PaymentContractInterface $contract,
        \OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface $context,
        string $orderId
    ): void {
        // Load order to get order number
        /** @var \OxidEsales\Eshop\Application\Model\Order $order */
        $order = \oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
        $orderNumber = null;
        if ($order->load($orderId)) {
            $orderNumber = $order->getFieldData('oxordernr');
        }

        $this->logEvent('StripeOrderCreationHandler: Using existing order', [
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
        ]);

        // Set context for downstream handlers (like thankyou page)
        $context->set('orderId', $orderId);
        $context->set('orderNumber', $orderNumber);

        // Commit contract to existing order
        $contract->commitToOrder($orderId);
        $this->contractRepository->save($contract);

        // Update OXPAID only if payment was captured (not for manual capture)
        $requiresCapture = $context->get('requiresCapture') === true;
        if (!$requiresCapture) {
            $this->logEvent('StripeOrderCreationHandler: Updating OXPAID on existing order (automatic capture)');
            $this->updateOrderPaidTimestamp($orderId, $contract->getProviderOrderId());
        } else {
            $this->logEvent('StripeOrderCreationHandler: Skipping OXPAID (manual capture mode)');
        }

        $committedEvent = new ContractCommittedEvent($contract, $context, $orderId);
        $this->eventDispatcher->dispatch($committedEvent);
    }

    private function handlePostOrderCreation(
        \OxidEsales\PaymentComponent\Contract\PaymentContractInterface $contract,
        \OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface $context,
        string $orderId
    ): void {
        $contract->commitToOrder($orderId);
        $this->contractRepository->save($contract);

        // Sprint 14/25: Update OXPAID only if payment was captured (not for manual capture)
        $requiresCapture = $context->get('requiresCapture') === true;
        if (!$requiresCapture) {
            $this->logEvent('StripeOrderCreationHandler: Updating OXPAID (automatic capture)');
            $this->updateOrderPaidTimestamp($orderId, $contract->getProviderOrderId());
        } else {
            $this->logEvent('StripeOrderCreationHandler: Skipping OXPAID (manual capture mode)');
        }

        $committedEvent = new ContractCommittedEvent($contract, $context, $orderId);
        $this->eventDispatcher->dispatch($committedEvent);
    }

    private function handleOrderCreationException(
        \OxidEsales\PaymentComponent\Contract\PaymentContractInterface $contract,
        \OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface $context,
        \Throwable $e
    ): void {
        $this->logEvent('StripeOrderCreationHandler: EXCEPTION', [
            'error' => $e->getMessage(),
            'class' => get_class($e),
        ]);
        Registry::getLogger()->error('StripeOrderCreationHandler: Order creation failed', [
            'contract_id' => $contract->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        $context->set('error', 'Order creation failed: ' . $e->getMessage());
    }

    /**
     * Update OXPAID timestamp on the order.
     *
     * Sprint 14: Called immediately after order creation since payment was confirmed
     * on Stripe. This is more reliable than waiting for webhook as it might arrive
     * before the contract transitions to COMMITTED state.
     *
     * Sprint 16: Uses OrderPaymentStateService (DRY - single location for OXPAID updates)
     */
    private function updateOrderPaidTimestamp(string $orderId, ?string $providerOrderId): void
    {
        $updated = $this->orderPaymentStateService->markOrderAsPaid(
            $orderId,
            $providerOrderId
        );

        if ($updated) {
            Registry::getLogger()->info('OXPAID updated in order creation flow', [
                'order_id' => $orderId,
                'provider_order_id' => $providerOrderId,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logEvent(string $message, array $context = []): void
    {
        if ($this->eventLogger !== null) {
            $this->eventLogger->log($message, $context);
        }
    }
}
