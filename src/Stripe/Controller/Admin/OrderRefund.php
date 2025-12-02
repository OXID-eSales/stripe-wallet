<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Controller\Admin\AdminDetailsController;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidSolutionCatalysts\Payments\Stripe\Core\StripeDefinitions;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Stripe\Charge;
use Stripe\PaymentIntent;

/**
 * Admin controller for Stripe order refunds.
 *
 * This controller is THIN - it only:
 * 1. Validates input
 * 2. Creates EventContext with data
 * 3. Dispatches StripeRefundRequestEvent
 * 4. Returns result from context
 *
 * ALL business logic is in StripeRefundRequestHandler.
 *
 * @since 2.0.0
 */
class OrderRefund extends AdminDetailsController
{
    /**
     * Template to be used
     *
     * @var string
     */
    protected $_sThisTemplate = "@osc_stripe_wallet/admin/stripe_order_refund";

    /**
     * Order object
     *
     * @var Order|null
     */
    protected $_oOrder = null;

    /**
     * Error message property
     *
     * @var string|bool
     */
    protected $_sErrorMessage = false;

    /**
     * Stripe ApiOrder
     *
     * @var PaymentIntent|null
     */
    protected $_oStripeApiOrder = null;

    /**
     * Stripe ApiCharge
     *
     * @var Charge|null
     */
    protected $_oStripeApiCharge = null;

    /**
     * Flag if a successful refund was executed
     *
     * @var bool|null
     */
    protected $_blSuccessfulRefund = null;

    /**
     * Array of refund items
     *
     * @var array|null
     */
    protected $_aRefundItems = null;

    /**
     * Event context from last operation
     *
     * @var EventContext|null
     */
    protected ?EventContext $_oEventContext = null;

    /**
     * Stripe API error message (if API call failed)
     *
     * @var string|null
     */
    protected ?string $stripeApiError = null;

    /**
     * Main render method
     *
     * @return string
     */
    public function render()
    {
        parent::render();

        $oOrder = $this->getOrder();
        if ($oOrder) {
            $this->_aViewData["edit"] = $oOrder;
        }

        return $this->_sThisTemplate;
    }

    /**
     * Execute full refund action via event system.
     *
     * This method is THIN - it creates an event and dispatches it.
     * All business logic is in StripeRefundRequestHandler.
     *
     * @return void
     */
    public function fullRefund(): void
    {
        $oOrder = $this->getOrder();
        if ($oOrder === null) {
            $this->setErrorMessage(Registry::getLang()->translateString('STRIPE_REFUND_NO_ORDER'));
            $this->_blSuccessfulRefund = false;
            return;
        }

        // Create event context with refund data
        $context = new EventContext([
            'orderId' => $oOrder->getId(),
            'contractId' => $this->getContractIdFromOrder($oOrder),
            'amount' => null, // Full refund
            'reason' => $this->getRefundReasonFromRequest(),
            'description' => $this->getRefundDescriptionFromRequest(),
            'initiator' => 'admin',
        ]);

        // Dispatch event - handler does all the work
        $event = new StripeRefundRequestEvent($context);
        $this->getEventDispatcher()->dispatch($event);

        // Store context for result processing
        $this->_oEventContext = $context;

        // Process results from handler
        $this->processContextResults($context);
    }

    /**
     * Execute partial refund action via event system.
     *
     * @return void
     */
    public function partialRefund(): void
    {
        $oOrder = $this->getOrder();
        if ($oOrder === null) {
            $this->setErrorMessage(Registry::getLang()->translateString('STRIPE_REFUND_NO_ORDER'));
            $this->_blSuccessfulRefund = false;
            return;
        }

        $amount = $this->getRefundAmountFromRequest();
        if ($amount === null || $amount <= 0) {
            $this->setErrorMessage(Registry::getLang()->translateString('STRIPE_REFUND_INVALID_AMOUNT'));
            $this->_blSuccessfulRefund = false;
            return;
        }

        // Create event context with refund data
        $context = new EventContext([
            'orderId' => $oOrder->getId(),
            'contractId' => $this->getContractIdFromOrder($oOrder),
            'amount' => $amount,
            'reason' => $this->getRefundReasonFromRequest(),
            'description' => $this->getRefundDescriptionFromRequest(),
            'initiator' => 'admin',
        ]);

        // Dispatch event - handler does all the work
        $event = new StripeRefundRequestEvent($context);
        $this->getEventDispatcher()->dispatch($event);

        // Store context for result processing
        $this->_oEventContext = $context;

        // Process results from handler
        $this->processContextResults($context);
    }

    /**
     * Process results from event context.
     */
    protected function processContextResults(EventContext $context): void
    {
        $success = $context->get('refundSuccess');

        if ($success === true) {
            $this->_blSuccessfulRefund = true;
            $this->_sErrorMessage = false;

            // Reload order to reflect updated state
            $orderId = $context->get('orderId');
            if (is_string($orderId)) {
                $this->_oOrder = null; // Force reload
                $this->getOrder();
            }
        } else {
            $this->_blSuccessfulRefund = false;
            $error = $context->get('error');
            if (is_string($error) && $error !== '') {
                $this->setErrorMessage($error);
            } else {
                $this->setErrorMessage(Registry::getLang()->translateString('STRIPE_REFUND_FAILED'));
            }
        }
    }

    /**
     * Get event dispatcher from DI container.
     */
    protected function getEventDispatcher(): EventDispatcherInterface
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = ContainerFactory::getInstance()
            ->getContainer()
            ->get(EventDispatcherInterface::class);

        return $dispatcher;
    }

    /**
     * Get contract ID from order metadata (if available).
     */
    protected function getContractIdFromOrder(Order $order): ?string
    {
        // Contract ID might be stored in order's transaction reference
        // or in a custom field - implementation depends on how contracts are linked
        return null;
    }

    /**
     * Get refund reason from request.
     */
    protected function getRefundReasonFromRequest(): ?string
    {
        $reason = Registry::getRequest()->getRequestEscapedParameter('refund_reason');
        return !empty($reason) ? $reason : null;
    }

    /**
     * Get refund description from request.
     */
    protected function getRefundDescriptionFromRequest(): ?string
    {
        $description = Registry::getRequest()->getRequestEscapedParameter('refund_description');
        return !empty($description) ? $description : null;
    }

    /**
     * Get refund amount from request.
     */
    protected function getRefundAmountFromRequest(): ?float
    {
        $amount = Registry::getRequest()->getRequestEscapedParameter('refund_amount');
        if ($amount === null || $amount === '') {
            return null;
        }
        return (float) $amount;
    }

    /**
     * Returns remaining refundable amount from Stripe Api
     *
     * @return string
     */
    public function getRemainingRefundableAmount(): string
    {
        $oApiCharge = $this->getStripeApiOrderLastCharge(true);

        $dPrice = 0;
        if ($oApiCharge && !empty($oApiCharge->amount_captured)) {
            $dPrice = ($oApiCharge->amount_captured - ($oApiCharge->amount_refunded ?? 0)) / 100;
        }
        return $this->getFormatedPrice($dPrice);
    }

    /**
     * Returns total captured amount from Stripe Api (what was actually charged)
     *
     * @return string
     */
    public function getStripeCapturedAmount(): string
    {
        $oApiCharge = $this->getStripeApiOrderLastCharge(false);

        $dPrice = 0;
        if ($oApiCharge && !empty($oApiCharge->amount_captured)) {
            $dPrice = $oApiCharge->amount_captured / 100;
        }
        return $this->getFormatedPrice($dPrice);
    }

    /**
     * Get refunded amount formatted
     *
     * @param float $dPrice
     * @return string
     */
    public function getFormatedPrice($dPrice): string
    {
        $oOrder = $this->getOrder();
        if ($oOrder === null) {
            return (string) $dPrice;
        }

        $oCurrency = Registry::getConfig()->getCurrencyObject($oOrder->oxorder__oxcurrency->value);

        return Registry::getLang()->formatCurrency($dPrice, $oCurrency);
    }

    /**
     * Loads current order
     *
     * @return null|Order
     */
    public function getOrder(): ?Order
    {
        if ($this->_oOrder === null) {
            $oOrder = oxNew(Order::class);

            $soxId = $this->getEditObjectId();
            if (isset($soxId) && $soxId != "-1") {
                $oOrder->load($soxId);

                $this->_oOrder = $oOrder;
            }
        }
        return $this->_oOrder;
    }

    /**
     * Checks if there were previous partial refunds and therefore full refund is not available anymore
     *
     * @return bool
     */
    public function isFullRefundAvailable(): bool
    {
        $oOrder = $this->getOrder();
        if ($oOrder === null) {
            return false;
        }

        foreach ($oOrder->getOrderArticles() as $orderArticle) {
            $amountRefunded = (float)($orderArticle->oxorderarticles__stripeamountrefunded->value ?? 0);
            $quantityRefunded = $orderArticle->oxorderarticles__stripequantityrefunded->value ?? 0;
            if ($amountRefunded > 0 || $quantityRefunded > 0) {
                return false;
            }
        }

        if (
            ($oOrder->oxorder__stripedelcostrefunded->value ?? 0) > 0
            || ($oOrder->oxorder__stripepaycostrefunded->value ?? 0) > 0
            || ($oOrder->oxorder__stripewrapcostrefunded->value ?? 0) > 0
            || ($oOrder->oxorder__stripegiftcardrefunded->value ?? 0) > 0
            || ($oOrder->oxorder__stripevoucherdiscountrefunded->value ?? 0) > 0
            || ($oOrder->oxorder__stripediscountrefunded->value ?? 0) > 0
        ) {
            return false;
        }
        return true;
    }

    /**
     * Check Stripe API if order is refundable
     *
     * @return bool
     */
    public function isOrderRefundable(): bool
    {
        $fnc = Registry::getRequest()->getRequestEscapedParameter('fnc');
        if ($this->wasRefundSuccessful() === true && $fnc == 'fullRefund') {
            // Order was just fully refunded, hide refund option even before Stripe sync
            return false;
        }

        $oApiOrderCharge = $this->getStripeApiOrderLastCharge(true);
        if ($oApiOrderCharge === null) {
            // API error - can't determine refundability but NOT "already refunded"
            return false;
        }

        $amountRefunded = $oApiOrderCharge->amount_refunded ?? 0;
        $amount = $oApiOrderCharge->amount ?? 0;

        if (empty($amountRefunded) || $amountRefunded != $amount) {
            return true;
        }
        return false;
    }

    /**
     * Check if there was a Stripe API error
     *
     * @return bool
     */
    public function hasStripeApiError(): bool
    {
        // Trigger API call if not already done
        $this->getStripeApiOrderLastCharge(false);
        return $this->stripeApiError !== null;
    }

    /**
     * Get Stripe API error message
     *
     * @return string|null
     */
    public function getStripeApiError(): ?string
    {
        return $this->stripeApiError;
    }

    /**
     * Checks if order was payed with Stripe
     *
     * @return bool
     */
    public function isStripeOrder(): bool
    {
        $oOrder = $this->getOrder();
        if ($oOrder === null) {
            return false;
        }
        $paymentType = $oOrder->oxorder__oxpaymenttype->value ?? '';
        return StripeDefinitions::isStripePayment($paymentType);
    }

    /**
     * Triggers sending Stripe second chance email
     *
     * @return void
     */
    public function sendSecondChanceEmail(): void
    {
        $oOrder = $this->getOrder();
        if ($oOrder === null) {
            return;
        }
        // Check if order has the second chance email method (added by Stripe model extension)
        if (method_exists($oOrder, 'stripeSendSecondChanceEmail')) {
            $oOrder->stripeSendSecondChanceEmail();
        }
    }

    /**
     * Returns errormessage
     *
     * @return bool|string
     */
    public function getErrorMessage()
    {
        return $this->_sErrorMessage;
    }

    /**
     * Sets error message
     *
     * @param string $sError
     */
    public function setErrorMessage($sError): void
    {
        $this->_sErrorMessage = $sError;
    }

    /**
     * Returns if refund was successful
     *
     * @return bool|null
     */
    public function wasRefundSuccessful(): ?bool
    {
        return $this->_blSuccessfulRefund;
    }

    /**
     * Get refund ID from last operation (if available).
     */
    public function getRefundId(): ?string
    {
        if ($this->_oEventContext === null) {
            return null;
        }
        $refundId = $this->_oEventContext->get('refundId');
        return is_string($refundId) ? $refundId : null;
    }

    /**
     * Get refunded amount from last operation (if available).
     */
    public function getRefundedAmount(): ?float
    {
        if ($this->_oEventContext === null) {
            return null;
        }
        $amount = $this->_oEventContext->get('refundedAmount');
        return is_numeric($amount) ? (float) $amount : null;
    }

    /**
     * Return Stripe api order
     *
     * @param bool $blRefresh
     * @return PaymentIntent|null
     */
    protected function getStripeApiOrder(bool $blRefresh = false): ?PaymentIntent
    {
        try {
            if ($this->_oStripeApiOrder === null || $blRefresh === true) {
                $oOrder = $this->getOrder();
                if ($oOrder === null) {
                    $this->stripeApiError = 'Order not found';
                    Registry::getLogger()->error('OrderRefund: getStripeApiOrder - order is null');
                    return null;
                }
                $transId = $oOrder->oxorder__oxtransid->value;
                if (empty($transId)) {
                    $this->stripeApiError = 'Order has no Stripe transaction ID';
                    Registry::getLogger()->error('OrderRefund: Order has no transaction ID', [
                        'orderId' => $oOrder->getId(),
                    ]);
                    return null;
                }
                Registry::getLogger()->debug('OrderRefund: Retrieving PaymentIntent', ['transId' => $transId]);
                $this->_oStripeApiOrder = $this->getStripeApiRequestModel()->paymentIntents->retrieve($transId);
                Registry::getLogger()->debug('OrderRefund: PaymentIntent retrieved', [
                    'id' => $this->_oStripeApiOrder->id ?? 'N/A',
                    'status' => $this->_oStripeApiOrder->status ?? 'N/A',
                    'latest_charge' => $this->_oStripeApiOrder->latest_charge ?? 'N/A',
                ]);
            }
            return $this->_oStripeApiOrder;
        } catch (\Exception $oEx) {
            $this->stripeApiError = $oEx->getMessage();
            Registry::getLogger()->error('OrderRefund: getStripeApiOrder exception', [
                'error' => $oEx->getMessage(),
                'code' => $oEx->getCode(),
            ]);
            return null;
        }
    }

    /**
     * @param boolean $blRefresh
     * @return Charge|null
     */
    protected function getStripeApiOrderLastCharge(bool $blRefresh = false): ?Charge
    {
        try {
            if ($this->_oStripeApiCharge === null || $blRefresh === true) {
                $oApiOrder = $this->getStripeApiOrder($blRefresh);

                // Check if we got an API error while fetching PaymentIntent
                if ($oApiOrder === null && $this->stripeApiError !== null) {
                    // Error already set by getStripeApiOrder()
                    return null;
                }

                $sLastChargeId = $oApiOrder ? ($oApiOrder->latest_charge ?? null) : null;

                if (!$sLastChargeId) {
                    $oOrder = $this->getOrder();
                    $transId = $oOrder ? ($oOrder->oxorder__oxtransid->value ?? 'N/A') : 'N/A';
                    $this->stripeApiError = "PaymentIntent has no charge (transId: $transId)";
                    Registry::getLogger()->warning('OrderRefund: PaymentIntent has no latest_charge', [
                        'transId' => $transId,
                        'paymentIntentStatus' => $oApiOrder ? ($oApiOrder->status ?? 'N/A') : 'N/A',
                    ]);
                    return null;
                }

                Registry::getLogger()->debug('OrderRefund: Retrieving Charge', ['chargeId' => $sLastChargeId]);
                $this->_oStripeApiCharge = $this->getStripeApiRequestModel()->charges->retrieve($sLastChargeId);
                Registry::getLogger()->debug('OrderRefund: Charge retrieved', [
                    'chargeId' => $this->_oStripeApiCharge->id ?? 'N/A',
                    'amount' => $this->_oStripeApiCharge->amount ?? 0,
                    'amount_refunded' => $this->_oStripeApiCharge->amount_refunded ?? 0,
                ]);
            }
            return $this->_oStripeApiCharge;
        } catch (\Exception $oEx) {
            $this->stripeApiError = $oEx->getMessage();
            Registry::getLogger()->error('OrderRefund: getStripeApiOrderLastCharge exception', [
                'error' => $oEx->getMessage(),
                'code' => $oEx->getCode(),
            ]);
            return null;
        }
    }

    /**
     * Returns Stripe API client
     *
     * @return \Stripe\StripeClient
     */
    protected function getStripeApiRequestModel()
    {
        /** @var StripeAdapterFactoryInterface $factory */
        $factory = ContainerFactory::getInstance()
            ->getContainer()
            ->get(StripeAdapterFactoryInterface::class);

        return $factory->getStripeClient();
    }
}
