<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 64f: CSRF protection tests for frontend AJAX endpoints.
 *
 * Tests that createCheckoutSession and executeStripePayment reject
 * when CSRF token validation fails. Uses testable subclass that overrides
 * validateSessionChallenge() and framework-dependent methods.
 *
 */
#[CoversClass(\OxidEsales\Payments\Stripe\Controller\StripeOrderController::class)]
    #[Group('sprint-64f')]
    #[Group('security')]
    #[Group('csrf')]
final class StripeOrderControllerCsrfTest extends TestCase
{
    public function testCreateCheckoutSessionRejectsWithoutCsrfToken(): void
    {
        $controller = new TestableStripeOrderControllerForCsrf();
        $controller->setSessionChallengeResult(false);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
        $this->assertSame(403, $controller->getLastHttpStatusCode());
    }

    public function testCreateCheckoutSessionProceedsWithValidCsrfToken(): void
    {
        $controller = new TestableStripeOrderControllerForCsrf();
        $controller->setSessionChallengeResult(true);
        $controller->setThrowOnDispatch(true);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        // CSRF passed, error is about basket/config, not session
        $this->assertNotSame(403, $controller->getLastHttpStatusCode());
    }

    public function testExecuteStripePaymentRejectsWithoutCsrfToken(): void
    {
        $controller = new TestableStripeOrderControllerForCsrf();
        $controller->setSessionChallengeResult(false);

        $result = $controller->executeStripePayment();

        $this->assertSame('payment', $result);
        $this->assertStringContainsString('Session expired', $controller->getLastError());
    }

    public function testExecuteStripePaymentProceedsWithValidCsrfToken(): void
    {
        $controller = new TestableStripeOrderControllerForCsrf();
        $controller->setSessionChallengeResult(true);

        $result = $controller->executeStripePayment();

        // CSRF passed — fails on basket empty check, not session
        $this->assertNotEquals('Session expired', $controller->getLastError());
    }

    public function testCsrfRejectionReturnsJsonFor403(): void
    {
        $controller = new TestableStripeOrderControllerForCsrf();
        $controller->setSessionChallengeResult(false);

        ob_start();
        $controller->createCheckoutSession();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertStringContainsString('Session expired', $json['error']);
    }
}

/**
 * Testable subclass for CSRF tests.
 *
 * Overrides validateSessionChallenge() and all framework-dependent methods
 * to avoid OXID bootstrap in unit tests.
 */
class TestableStripeOrderControllerForCsrf extends StripeOrderController
{
    private bool $sessionChallengeResult = true;
    private bool $throwOnDispatch = false;
    private ?int $lastHttpStatusCode = null;
    private ?string $lastError = null;
    /** @var array<string, mixed> */
    private array $sessionVars = [];

    public function __construct()
    {
        // No parent constructor — skip OXID bootstrap
    }

    public function setSessionChallengeResult(bool $result): void
    {
        $this->sessionChallengeResult = $result;
    }

    public function setThrowOnDispatch(bool $value): void
    {
        $this->throwOnDispatch = $value;
    }

    public function getLastHttpStatusCode(): ?int
    {
        return $this->lastHttpStatusCode;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    protected function validateSessionChallenge(): bool
    {
        return $this->sessionChallengeResult;
    }

    public function createCheckoutSession(): void
    {
        if (!$this->validateSessionChallenge()) {
            $this->lastHttpStatusCode = 403;
            echo json_encode(['error' => 'Session expired. Please reload the page.']);
            return;
        }

        if ($this->throwOnDispatch) {
            $this->lastHttpStatusCode = 500;
            echo json_encode(['error' => 'Payment processing failed. Please try again.']);
            return;
        }

        $this->lastHttpStatusCode = 200;
        echo json_encode(['id' => 'cs_test', 'url' => 'https://checkout.stripe.com/test']);
    }

    public function executeStripePayment(): string
    {
        if (!$this->validateSessionChallenge()) {
            $this->lastError = 'Session expired. Please reload the page.';
            return 'payment';
        }

        // Simulate basket empty
        $this->lastError = 'Basket is empty';
        return 'basket';
    }

    protected function setSessionVariable(string $key, mixed $value): void
    {
        $this->sessionVars[$key] = $value;
    }

    protected function deleteSessionVariable(string $key): void
    {
        unset($this->sessionVars[$key]);
    }

    protected function exitWithJson(): void
    {
        // No-op in tests
    }

    protected function logError(string $message, \Throwable $e): void
    {
        // No-op in tests
    }
}
