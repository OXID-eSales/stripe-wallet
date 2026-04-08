<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Controller\Admin\AdminDetailsController;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Service\OrderPaymentStateServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * Admin controller for Stripe order management (refund, capture, cancel).
 *
 * Delegates to:
 * - OrderActionDispatcher: event dispatching for refund/capture/cancel
 * - OrderRefundViewDataProvider: Stripe API data for template display
 *
 * OXID admin controller pattern requires many public methods for Twig template access.
 * Each method is a thin delegation; actual complexity is in OrderActionDispatcher and OrderRefundViewDataProvider.
 *
 * @since 2.0.0
 */
class OrderRefund extends AdminDetailsController
{
    /** @var string */
    protected $_sThisTemplate = "@oe_payments_stripe_wallet/admin/stripe_order_refund";

    /** @var Order|null */
    protected $_oOrder = null;

    /** @var string|bool */
    protected $_sErrorMessage = false;

    /** @var bool|null */
    protected $_blSuccessfulRefund = null;

    protected ?bool $_blSuccessfulCapture = null;

    protected ?bool $_blSuccessfulCancel = null;

    protected ?EventContext $_oEventContext = null;

    public function render()
    {
        parent::render();

        $oOrder = $this->getOrder();
        if ($oOrder) {
            $this->_aViewData["edit"] = $oOrder;
            $this->reconcilePaymentState($oOrder);
        }

        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $configService = $container->get(
                \OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface::class
            );
            /** @phpstan-ignore-next-line OXID core: ContainerFactory::get() returns mixed */
            $this->_aViewData["isTestMode"] = $configService->isTestMode();
        } catch (\Throwable $e) {
            $this->_aViewData["isTestMode"] = false;
        }

        return $this->_sThisTemplate;
    }

    // =========================================================================
    // Action Methods
    // =========================================================================

    public function fullRefund(): void
    {
        if (!$this->validateCsrfToken()) {
            $this->_blSuccessfulRefund = false;
            return;
        }

        $order = $this->getOrder();
        if ($order === null) {
            $this->setErrorMessage(Registry::getLang()->translateString('STRIPE_REFUND_NO_ORDER'));
            $this->_blSuccessfulRefund = false;
            return;
        }

        $context = $this->getActionDispatcher()->dispatchRefund(
            $order,
            $this->getRequestParam('refund_reason'),
            $this->getRequestParam('refund_description'),
            $this->getRefundAmount()
        );
        $this->_oEventContext = $context;
        $this->processResult($context, 'refundSuccess', 'STRIPE_REFUND_FAILED', 'refund');
    }

    public function capturePayment(): void
    {
        if (!$this->validateCsrfToken()) {
            $this->_blSuccessfulCapture = false;
            return;
        }

        $this->dispatchTransactionAction(
            'capture',
            'STRIPE_CAPTURE_NO_ORDER',
            'STRIPE_CAPTURE_NO_TRANSACTION',
            fn($order, $piId) => $this->getActionDispatcher()->dispatchCapture($order, $piId, $this->getRequestParam('capture_reason'), $this->getCaptureAmount()),
            'captureSuccess',
            'STRIPE_CAPTURE_FAILED'
        );
    }

    public function cancelAuthorization(): void
    {
        if (!$this->validateCsrfToken()) {
            $this->_blSuccessfulCancel = false;
            return;
        }

        $this->dispatchTransactionAction(
            'cancel',
            'STRIPE_CANCEL_NO_ORDER',
            'STRIPE_CANCEL_NO_TRANSACTION',
            fn($order, $piId) => $this->getActionDispatcher()->dispatchCancel($order, $piId, $this->getRequestParam('cancellation_reason')),
            'cancelSuccess',
            'STRIPE_CANCEL_FAILED'
        );
    }

    // =========================================================================
    // Template-facing view data (delegates to ViewDataProvider)
    // =========================================================================

    public function isOrderCapturable(): bool
    {
        if ($this->_blSuccessfulCapture === true) {
            return false;
        }
        $order = $this->getOrder();
        return $order !== null && $this->getViewDataProvider()->isOrderCapturable($order);
    }

    public function getCaptureableAmount(): string
    {
        $order = $this->getOrder();
        return $order !== null ? $this->getViewDataProvider()->getCaptureableAmount($order) : '0';
    }

    public function isOrderCancellable(): bool
    {
        return $this->_blSuccessfulCancel !== true && $this->isOrderCapturable();
    }

    public function isOrderRefundable(): bool
    {
        // Sprint 82 (STRP-118): Cannot refund what hasn't been captured.
        // Hide refund section for uncaptured manual-capture orders.
        if ($this->isOrderCapturable()) {
            return false;
        }

        $fnc = Registry::getRequest()->getRequestEscapedParameter('fnc');
        if ($this->_blSuccessfulRefund === true && $fnc == 'fullRefund') {
            return false;
        }
        $order = $this->getOrder();
        return $order !== null && $this->getViewDataProvider()->isOrderRefundable($order);
    }

    public function getRemainingRefundableAmount(): string
    {
        $order = $this->getOrder();
        return $order !== null ? $this->getViewDataProvider()->getRemainingRefundableAmount($order) : '0';
    }

    public function getStripeCapturedAmount(): string
    {
        $order = $this->getOrder();
        return $order !== null ? $this->getViewDataProvider()->getStripeCapturedAmount($order) : '0';
    }

    /**
     * @param float|int|string $dPrice
     */
    public function getFormatedPrice($dPrice): string
    {
        $order = $this->getOrder();
        return $order !== null
            ? $this->getViewDataProvider()->formatPrice((float) $dPrice, $order)
            : (string) $dPrice;
    }

    /**
     * Get transaction history from Stripe API (source of truth).
     *
     * Shows all actions regardless of origin (admin, Stripe Dashboard, webhook).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTransactions(): array
    {
        $order = $this->getOrder();
        if ($order === null) {
            return [];
        }

        return $this->getViewDataProvider()->getStripeTransactionHistory($order);
    }

    public function hasStripeApiError(): bool
    {
        $order = $this->getOrder();
        if ($order !== null) {
            $this->getViewDataProvider()->getLastCharge($order);
        }
        return $this->getViewDataProvider()->hasApiError();
    }

    public function getStripeApiError(): ?string
    {
        return $this->getViewDataProvider()->getApiError();
    }

    public function isFullRefundAvailable(): bool
    {
        return $this->getOrder() !== null;
    }

    // =========================================================================
    // Simple state accessors
    // =========================================================================

    public function isStripeOrder(): bool
    {
        $oOrder = $this->getOrder();
        if ($oOrder === null) {
            return false;
        }
        /** @phpstan-ignore-next-line OXID core: magic property oxorder__oxpaymenttype->value */
        $paymentType = $oOrder->oxorder__oxpaymenttype->value ?? '';
        return StripeDefinitions::isStripePayment($paymentType);
    }

    public function getOrder(): ?Order
    {
        if ($this->_oOrder === null) {
            /** @var Order $oOrder */
            $oOrder = oxNew(Order::class);
            $soxId = $this->getEditObjectId();
            if (isset($soxId) && $soxId != "-1") {
                $oOrder->load($soxId);
                $this->_oOrder = $oOrder;
            }
        }
        return $this->_oOrder;
    }

    public function getContractId(): ?string
    {
        $order = $this->getOrder();
        return $order !== null ? $this->getActionDispatcher()->getContractIdFromOrder($order) : null;
    }

    public function getOrderIdForDisplay(): ?string
    {
        return $this->getOrder()?->getId();
    }

    /** @return bool|string */
    public function getErrorMessage()
    {
        return $this->_sErrorMessage;
    }

    /**
     * @param string $sError
     */
    public function setErrorMessage($sError): void
    {
        $this->_sErrorMessage = $sError;
    }

    public function wasRefundSuccessful(): ?bool
    {
        return $this->_blSuccessfulRefund;
    }

    public function wasCaptureSuccessful(): ?bool
    {
        return $this->_blSuccessfulCapture;
    }

    public function wasCancelSuccessful(): ?bool
    {
        return $this->_blSuccessfulCancel;
    }

    public function getRefundId(): ?string
    {
        $refundId = $this->_oEventContext?->get('refundId');
        return is_string($refundId) ? $refundId : null;
    }

    public function getRefundedAmount(): ?float
    {
        $amount = $this->_oEventContext?->get('refundedAmount');
        return is_numeric($amount) ? (float) $amount : null;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * @param callable(Order, string): EventContext $dispatch
     */
    private function dispatchTransactionAction(
        string $type,
        string $noOrderKey,
        string $noTransKey,
        callable $dispatch,
        string $successKey,
        string $failKey
    ): void {
        $order = $this->getOrder();
        $paymentIntentId = $order !== null ? $this->getActionDispatcher()->getPaymentIntentId($order) : null;

        if ($order === null || $paymentIntentId === null) {
            $errorKey = $order === null ? $noOrderKey : $noTransKey;
            $this->setErrorMessage(Registry::getLang()->translateString($errorKey));
            $this->setFlag($type, false);
            return;
        }

        $context = $dispatch($order, $paymentIntentId);
        $this->_oEventContext = $context;
        $this->processResult($context, $successKey, $failKey, $type);
    }

    protected function processResult(EventContext $context, string $successKey, string $failKey, string $type): void
    {
        if ($context->get($successKey) === true) {
            $this->setFlag($type, true);
            $this->_sErrorMessage = false;
            $this->getViewDataProvider()->resetCache();
            $this->_oOrder = null;
            $this->getOrder();
            return;
        }

        $this->setFlag($type, false);
        $error = $context->get('error');
        $this->setErrorMessage(
            is_string($error) && $error !== '' ? $error : Registry::getLang()->translateString($failKey)
        );
    }

    private function setFlag(string $type, bool $value): void
    {
        match ($type) {
            'refund' => $this->_blSuccessfulRefund = $value,
            'capture' => $this->_blSuccessfulCapture = $value,
            default => $this->_blSuccessfulCancel = $value,
        };
    }

    protected function getRequestParam(string $name): ?string
    {
        $value = Registry::getRequest()->getRequestEscapedParameter($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Parse refund amount from request. Null = full refund.
     */
    public function getRefundAmount(): ?float
    {
        return $this->parseAmountParam('refund_amount');
    }

    /**
     * Parse capture amount from request. Null = full capture.
     */
    public function getCaptureAmount(): ?float
    {
        return $this->parseAmountParam('capture_amount');
    }

    private function parseAmountParam(string $name): ?float
    {
        $raw = $this->getRequestParam($name);
        if ($raw === null) {
            return null;
        }

        $amount = (float) $raw;
        if ($amount <= 0) {
            return null;
        }

        return $amount;
    }

    /**
     * Validate CSRF token (stoken) from session challenge.
     *
     * Sprint 64e: Must be called at the start of every state-changing action.
     * OXID admin forms include stoken automatically via form helpers.
     */
    protected function validateCsrfToken(): bool
    {
        if (!Registry::getSession()->checkSessionChallenge()) {
            $this->setErrorMessage('Session expired or invalid request.');
            return false;
        }

        return true;
    }

    // =========================================================================
    // Payment State Reconciliation
    // =========================================================================

    /**
     * Reconcile OXPAID when Stripe shows payment succeeded but OXPAID is unset.
     *
     * Catches cases where capture was done from Stripe Dashboard or webhooks failed.
     */
    private function reconcilePaymentState(Order $order): void
    {
        /** @phpstan-ignore-next-line OXID core: magic property */
        $oxpaid = $order->oxorder__oxpaid->value ?? '';
        if ($oxpaid !== '0000-00-00 00:00:00' && $oxpaid !== '') {
            return;
        }

        $paymentIntent = $this->getViewDataProvider()->getPaymentIntent($order);
        if ($paymentIntent === null || ($paymentIntent->status ?? '') !== 'succeeded') {
            return;
        }

        try {
            /** @var OrderPaymentStateServiceInterface $stateService */
            $stateService = ContainerFactory::getInstance()->getContainer()
                ->get(OrderPaymentStateServiceInterface::class);
            $stateService->markOrderAsPaid(
                (string) $order->getId(),
                (string) ($paymentIntent->id ?? '')
            );

            // Reload order so template shows updated OXPAID
            $this->_oOrder = null;
            $order = $this->getOrder();
            if ($order) {
                $this->_aViewData["edit"] = $order;
            }
        } catch (\Throwable $e) {
            // Silently fail — reconciliation is best-effort
        }
    }

    // =========================================================================
    // Service Locators (OXID admin controllers don't support constructor DI)
    // =========================================================================

    private ?OrderRefundViewDataProvider $viewDataProvider = null;
    private ?OrderActionDispatcher $actionDispatcher = null;

    protected function getViewDataProvider(): OrderRefundViewDataProvider
    {
        if ($this->viewDataProvider === null) {
            /** @var OrderRefundViewDataProvider $provider */
            $provider = ContainerFactory::getInstance()->getContainer()->get(OrderRefundViewDataProvider::class);
            $this->viewDataProvider = $provider;
        }
        return $this->viewDataProvider;
    }

    protected function getActionDispatcher(): OrderActionDispatcher
    {
        if ($this->actionDispatcher === null) {
            /** @var OrderActionDispatcher $dispatcher */
            $dispatcher = ContainerFactory::getInstance()->getContainer()->get(OrderActionDispatcher::class);
            $this->actionDispatcher = $dispatcher;
        }
        return $this->actionDispatcher;
    }
}
