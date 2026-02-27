<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;

/**
 * Stub helper that returns preset values without Registry calls.
 *
 * Sprint 71: Used by controller tests to avoid OXID bootstrap.
 */
class StubControllerRequestHelper extends ControllerRequestHelper
{
    public ?string $checkoutSessionId = null;
    public ?string $contractIdFromRequest = null;
    public ?string $contractTokenFromRequest = null;
    public ?string $contractIdFromSession = null;
    public ?string $paymentIntentIdFromRequest = null;
    public ?string $sessionPaymentIntentId = null;
    public ?string $redirectStatus = null;
    public bool $tokenValidationResult = true;
    public bool $sessionChallengeResult = true;
    public ?string $lastError = null;
    public string $captureMode = 'automatic';
    public int $shopId = 1;
    public string $shopUrl = 'https://shop.test/';
    public string $sessionId = 'test_session';
    /** @var array<string, mixed> */
    public array $sessionVars = [];
    public ?\OxidEsales\Eshop\Application\Model\Basket $basket = null;

    public function __construct()
    {
        // Skip parent constructor — no dependencies needed for stub
    }

    public function getCheckoutSessionIdFromRequest(): ?string
    {
        return $this->checkoutSessionId;
    }

    public function getContractIdFromRequest(): ?string
    {
        return $this->contractIdFromRequest;
    }

    public function getContractTokenFromRequest(): ?string
    {
        return $this->contractTokenFromRequest;
    }

    public function getContractIdFromSession(): ?string
    {
        return $this->contractIdFromSession;
    }

    public function getPaymentIntentIdFromRequest(): ?string
    {
        return $this->paymentIntentIdFromRequest;
    }

    public function getSessionPaymentIntentId(): ?string
    {
        return $this->sessionPaymentIntentId;
    }

    public function getRedirectStatusFromRequest(): ?string
    {
        return $this->redirectStatus;
    }

    public function getBasketFromSession(): \OxidEsales\Eshop\Application\Model\Basket
    {
        if ($this->basket === null) {
            throw new \RuntimeException('getBasketFromSession() not configured in stub');
        }
        return $this->basket;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function getShopUrl(): string
    {
        return $this->shopUrl;
    }

    public function getCaptureMode(): string
    {
        return $this->captureMode;
    }

    public function validateSessionChallenge(): bool
    {
        return $this->sessionChallengeResult;
    }

    public function validateContractToken(?string $contractId, ?string $contractToken): bool
    {
        if ($contractId === null || $contractToken === null) {
            return false;
        }
        return $this->tokenValidationResult;
    }

    public function addErrorToDisplay(string $message): void
    {
        $this->lastError = $message;
    }

    public function logError(string $message, \Throwable $e): void
    {
        // No-op in tests
    }

    public function setSessionVariable(string $key, mixed $value): void
    {
        $this->sessionVars[$key] = $value;
    }

    public function deleteSessionVariable(string $key): void
    {
        unset($this->sessionVars[$key]);
    }

    public function clearStripeSessionVariables(): void
    {
        $this->sessionVars = [];
    }
}
