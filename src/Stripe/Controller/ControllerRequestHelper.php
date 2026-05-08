<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\OxidLanguageResolver;

/**
 * Helper for controller request/session/config access.
 *
 * Extracts Registry-wrapping accessor methods from StripeOrderController
 * to reduce controller WMC (PHPMD ExcessiveClassComplexity).
 *
 * Sprint 71: All methods were previously protected on StripeOrderController.
 *
 * @since 2.0.0
 */
class ControllerRequestHelper
{
    private readonly LanguageResolverInterface $languageResolver;

    public function __construct(
        private readonly TokenServiceInterface $tokenService,
        private readonly ModuleConfigurationServiceInterface $moduleConfig,
        ?LanguageResolverInterface $languageResolver = null
    ) {
        $this->languageResolver = $languageResolver ?? new OxidLanguageResolver();
    }

    // ==========================================
    // REQUEST ACCESSORS
    // ==========================================

    public function getPaymentIntentIdFromRequest(): ?string
    {
        $value = Registry::getRequest()->getRequestParameter('payment_intent_id')
            ?? Registry::getRequest()->getRequestParameter('payment_intent');
        return is_string($value) ? $value : null;
    }

    public function getCheckoutSessionIdFromRequest(): ?string
    {
        $value = Registry::getRequest()->getRequestParameter('session_id');
        return is_string($value) ? $value : null;
    }

    public function getContractIdFromRequest(): ?string
    {
        $value = Registry::getRequest()->getRequestParameter('contract_id');
        return is_string($value) ? $value : null;
    }

    public function getContractTokenFromRequest(): ?string
    {
        $value = Registry::getRequest()->getRequestParameter('contract_token');
        return is_string($value) ? $value : null;
    }

    public function getRedirectStatusFromRequest(): ?string
    {
        $value = Registry::getRequest()->getRequestParameter('redirect_status');
        return is_string($value) ? $value : null;
    }

    // ==========================================
    // SESSION ACCESSORS
    // ==========================================

    public function getSessionPaymentIntentId(): ?string
    {
        $value = Registry::getSession()->getVariable('stripe_payment_intent_id');
        return is_string($value) ? $value : null;
    }

    public function getContractIdFromSession(): ?string
    {
        $value = Registry::getSession()->getVariable('stripe_contract_id');
        return is_string($value) ? $value : null;
    }

    public function getBasketFromSession(): \OxidEsales\Eshop\Application\Model\Basket
    {
        return Registry::getSession()->getBasket();
    }

    public function getSessionId(): string
    {
        return Registry::getSession()->getId();
    }

    public function setSessionVariable(string $key, mixed $value): void
    {
        Registry::getSession()->setVariable($key, $value);
    }

    public function deleteSessionVariable(string $key): void
    {
        Registry::getSession()->deleteVariable($key);
    }

    public function clearStripeSessionVariables(): void
    {
        $this->deleteSessionVariable('stripe_payment_intent_id');
        $this->deleteSessionVariable('stripe_client_secret');
        $this->deleteSessionVariable('stripe_checkout_session_id');
        $this->deleteSessionVariable('stripe_contract_id');
    }

    // ==========================================
    // CONFIG ACCESSORS
    // ==========================================

    public function getShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    public function getActiveLanguageId(): int
    {
        return $this->languageResolver->getActiveLanguageId();
    }

    public function getShopUrl(): string
    {
        return Registry::getConfig()->getShopUrl();
    }

    public function getCaptureMode(): string
    {
        return $this->moduleConfig->getCaptureMode();
    }

    // ==========================================
    // AGB CONFIRMATION
    // ==========================================

    public const AGB_REQUEST_KEY = 'ord_agb';
    public const AGB_ACCEPTED_VALUE = '1';

    public function getAgbAcceptedFromRequest(): bool
    {
        $raw = Registry::getRequest()->getRequestEscapedParameter(self::AGB_REQUEST_KEY);
        return is_string($raw) && $raw === self::AGB_ACCEPTED_VALUE;
    }

    public function isAgbConfirmationRequired(): bool
    {
        return (bool) Registry::getConfig()->getConfigParam('blConfirmAGB');
    }

    // ==========================================
    // VALIDATION
    // ==========================================

    public function validateSessionChallenge(): bool
    {
        return Registry::getSession()->checkSessionChallenge();
    }

    public function validateContractToken(?string $contractId, ?string $contractToken): bool
    {
        if ($contractId === null || $contractToken === null) {
            return false;
        }

        return $this->tokenService->validateToken($contractToken, $contractId);
    }

    // ==========================================
    // UTILITIES
    // ==========================================

    public function addErrorToDisplay(string $message): void
    {
        Registry::getUtilsView()->addErrorToDisplay($message);
    }

    public function logError(string $message, \Throwable $e): void
    {
        Registry::getLogger()->error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
