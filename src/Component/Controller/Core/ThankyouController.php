<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Controller\Core;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Application\Controller\ThankYouController as OxidThankyouController;
use OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface;
use OxidSolutionCatalysts\Payments\Component\Traits\ServiceContainer;
use Throwable;

/**
 * Extended ThankyouController for Stripe order completion accounting.
 *
 * Note: Final payment confirmation happens via webhook.
 * This controller only confirms the order was placed and transitions contract state.
 *
 * @since 1.0.0
 */
class ThankyouController extends OxidThankyouController
{
    use ServiceContainer;

    private const SESSION_CONTRACT_ID = 'stripe_contract_id';
    private const SESSION_PAYMENT_INTENT_ID = 'stripe_payment_intent_id';

    /**
     * Renders the thankyou page.
     *
     * For Stripe payments:
     * 1. Confirms order completion via orchestrator
     * 2. Cleans up session variables
     * 3. Logs state for debugging
     *
     * @return string Template name
     */
    public function render(): string
    {
        $contractId = $this->getContractIdFromSession();

        if ($contractId !== null) {
            $this->confirmStripeOrderCompletion($contractId);
        }

        return $this->renderParent();
    }

    /**
     * Confirms Stripe order completion.
     */
    private function confirmStripeOrderCompletion(string $contractId): void
    {
        $order = $this->getOrder();
        if ($order === null) {
            $this->logError('Order not found in thankyou controller', [
                'contractId' => $contractId,
            ]);
            return;
        }

        $orderId = $order->getId();

        try {
            $result = $this->getCheckoutOrchestrator()->confirmOrderCompletion(
                $orderId,
                $contractId
            );

            if ($result->isSuccess()) {
                // Cleanup session - contract is now linked to order
                $this->clearSessionVariables();

                // Log state for debugging
                if ($result->isAwaitingPaymentConfirmation()) {
                    $this->logInfo('Stripe order awaiting payment confirmation via webhook', [
                        'orderId' => $orderId,
                        'contractId' => $contractId,
                        'state' => $result->getContractState(),
                    ]);
                }

                if ($result->isFullyCompleted()) {
                    $this->logInfo('Stripe order fully completed', [
                        'orderId' => $orderId,
                        'contractId' => $contractId,
                    ]);
                }
            } else {
                $this->logError('Failed to confirm order completion', [
                    'orderId' => $orderId,
                    'contractId' => $contractId,
                    'error' => $result->getErrorMessage(),
                ]);
            }
        } catch (Throwable $e) {
            // Log but don't break the thankyou page
            $this->logError('Exception during order confirmation', [
                'orderId' => $orderId,
                'contractId' => $contractId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Gets contract ID from session.
     */
    private function getContractIdFromSession(): ?string
    {
        return $this->getSession()->getVariable(self::SESSION_CONTRACT_ID);
    }

    /**
     * Clears session variables after successful confirmation.
     */
    private function clearSessionVariables(): void
    {
        $session = $this->getSession();
        $session->deleteVariable(self::SESSION_CONTRACT_ID);
        $session->deleteVariable(self::SESSION_PAYMENT_INTENT_ID);
    }

    /**
     * Gets the session.
     */
    protected function getSession(): object
    {
        return Registry::getSession();
    }

    /**
     * Logs an info message.
     */
    protected function logInfo(string $message, array $context = []): void
    {
        Registry::getLogger()->info($message, $context);
    }

    /**
     * Logs an error message.
     */
    protected function logError(string $message, array $context = []): void
    {
        Registry::getLogger()->error($message, $context);
    }

    /**
     * Calls parent render method.
     * Extracted for easier testing.
     */
    protected function renderParent(): string
    {
        return parent::render();
    }

    /**
     * Gets the CheckoutOrchestrator from DI container.
     */
    private function getCheckoutOrchestrator(): CheckoutOrchestratorInterface
    {
        return $this->getServiceFromContainer(CheckoutOrchestratorInterface::class);
    }
}
