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
        }

        return $this->_sThisTemplate;
    }

    // =========================================================================
    // Action Methods
    // =========================================================================

    public function fullRefund(): void
    {
        $order = $this->getOrder();
        if ($order === null) {
            $this->setErrorMessage(Registry::getLang()->translateString('STRIPE_REFUND_NO_ORDER'));
            $this->_blSuccessfulRefund = false;
            return;
        }

        $context = $this->getActionDispatcher()->dispatchRefund(
            $order,
            $this->getRequestParam('refund_reason'),
            $this->getRequestParam('refund_description')
        );
        $this->_oEventContext = $context;
        $this->processResult($context, 'refundSuccess', 'STRIPE_REFUND_FAILED', 'refund');
    }

    public function capturePayment(): void
    {
        $this->dispatchTransactionAction(
            'capture',
            'STRIPE_CAPTURE_NO_ORDER',
            'STRIPE_CAPTURE_NO_TRANSACTION',
            fn($order, $piId) => $this->getActionDispatcher()->dispatchCapture($order, $piId, $this->getRequestParam('capture_reason')),
            'captureSuccess',
            'STRIPE_CAPTURE_FAILED'
        );
    }

    public function cancelAuthorization(): void
    {
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

    private function getRequestParam(string $name): ?string
    {
        $value = Registry::getRequest()->getRequestEscapedParameter($name);
        return is_string($value) && $value !== '' ? $value : null;
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
