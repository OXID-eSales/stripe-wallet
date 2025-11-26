<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Controller\Core;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Core\Exception\ArticleInputException;
use OxidEsales\Eshop\Core\Exception\NoArticleException;
use OxidEsales\Eshop\Core\Exception\OutOfStockException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Session;
use OxidEsales\Eshop\Application\Controller\OrderController as OxidOrderController;
use OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface;
use OxidSolutionCatalysts\Payments\Component\Traits\ServiceContainer;

/**
 * Extended OrderController for Stripe payment accounting.
 *
 * Note: Actual payment processing happens on the frontend via Stripe.js.
 * This controller only handles backend accounting (contract creation, order linking).
 *
 * @since 1.0.0
 */
class OrderController extends OxidOrderController
{
    use ServiceContainer;

    private const SESSION_CONTRACT_ID = 'stripe_contract_id';
    private const STRIPE_PAYMENT_PREFIX = 'stripe_';

    /**
     * Executes order placement.
     *
     * For Stripe payments:
     * 1. Calls orchestrator to create contract
     * 2. Stores contract ID in session
     * 3. Calls parent to create OXID order
     *
     * For non-Stripe payments:
     * - Falls back to parent behavior
     *
     * @return mixed View name or redirect
     */
    public function execute(): mixed
    {
        if (!$this->isStripePaymentMethod()) {
            return $this->executeParentWithExceptionHandling();
        }

        return $this->executeWithStripeAccounting();
    }

    /**
     * Processes checkout with Stripe contract accounting.
     */
    protected function executeWithStripeAccounting(): mixed
    {
        /** @var Basket $basket */
        $basket = $this->getBasketFromSession();
        $user = $this->getUser();
        $paymentId = (string) $basket->getPaymentId();

        // Get payment_intent_id from request (set by frontend Stripe.js)
        $paymentIntentId = $this->getPaymentIntentIdFromRequest();

        $result = $this->getCheckoutOrchestrator()->processCheckout(
            $basket,
            $user,
            $paymentId,
            $paymentIntentId
        );

        if (!$result->isSuccess()) {
            $this->addErrorToDisplay($result->getErrorMessage() ?? 'Unknown error');
            return $this->getViewName();
        }

        // Store contract ID for ThankyouController
        $contractId = $result->getContractId();
        if ($contractId !== null) {
            /** @var Session $session */
            $session = $this->getSessionForVariables();
            $session->setVariable(self::SESSION_CONTRACT_ID, $contractId);
        }

        // Continue with standard OXID order creation
        return $this->executeParent();
    }

    /**
     * Gets the basket from session.
     * Extracted for easier testing.
     *
     * @return Basket|object
     */
    protected function getBasketFromSession(): object
    {
        return $this->getOxidSession()->getBasket();
    }

    /**
     * Gets session for storing variables.
     * Extracted for easier testing.
     *
     * @return Session|object
     */
    protected function getSessionForVariables(): object
    {
        return $this->getOxidSession();
    }

    /**
     * Checks if the selected payment method is a Stripe method.
     */
    protected function isStripePaymentMethod(): bool
    {
        $basket = $this->getOxidSession()->getBasket();
        $paymentId = $basket->getPaymentId();

        if ($paymentId === null || $paymentId === '') {
            return false;
        }

        return str_starts_with((string) $paymentId, self::STRIPE_PAYMENT_PREFIX);
    }

    /**
     * Gets payment intent ID from request.
     */
    protected function getPaymentIntentIdFromRequest(): ?string
    {
        /** @var string|null $value */
        $value = Registry::getRequest()->getRequestParameter('stripe_payment_intent_id');
        return $value;
    }

    /**
     * Gets the OXID session.
     *
     * @return Session
     */
    protected function getOxidSession(): Session
    {
        /** @var Session $session */
        $session = Registry::getSession();
        return $session;
    }

    /**
     * Adds an error message to display.
     */
    protected function addErrorToDisplay(string $message): void
    {
        Registry::getUtilsView()->addErrorToDisplay($message);
    }

    /**
     * Gets the view name for error display.
     */
    protected function getViewName(): string
    {
        return 'order';
    }

    /**
     * Executes parent with exception handling (preserves original behavior).
     */
    private function executeParentWithExceptionHandling(): mixed
    {
        try {
            return $this->executeParent();
        } catch (NoArticleException | OutOfStockException | ArticleInputException $e) {
            Registry::getSession()->setVariable('OrderException', $e);
            /** @phpstan-ignore-next-line method.notFound - setViewConfigParam exists in parent */
            $this->setViewConfigParam('bOrderStepError', true);

            return $this->getViewName();
        }
    }

    /**
     * Calls parent execute method.
     * Extracted for easier testing.
     */
    protected function executeParent(): mixed
    {
        return parent::execute();
    }

    /**
     * Gets the CheckoutOrchestrator from DI container.
     */
    private function getCheckoutOrchestrator(): CheckoutOrchestratorInterface
    {
        return $this->getServiceFromContainer(CheckoutOrchestratorInterface::class);
    }
}
