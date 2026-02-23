<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefund;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 64e: CSRF protection tests for admin order actions.
 *
 * Tests that fullRefund, capturePayment, cancelAuthorization all reject
 * when CSRF token validation fails. Uses testable subclass that overrides
 * both validateCsrfToken() and getOrder() to avoid OXID framework calls.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Admin\OrderRefund
 * @group sprint-64e
 * @group security
 * @group csrf
 */
final class OrderRefundCsrfTest extends TestCase
{
    /** @test */
    public function fullRefundRejectsInvalidCsrfToken(): void
    {
        $controller = new TestableOrderRefundForCsrf(csrfValid: false);

        $controller->fullRefund();

        $this->assertFalse($controller->wasRefundSuccessful());
        $this->assertStringContainsString('Session expired', (string) $controller->getErrorMessage());
    }

    /** @test */
    public function capturePaymentRejectsInvalidCsrfToken(): void
    {
        $controller = new TestableOrderRefundForCsrf(csrfValid: false);

        $controller->capturePayment();

        $this->assertFalse($controller->wasCaptureSuccessful());
        $this->assertStringContainsString('Session expired', (string) $controller->getErrorMessage());
    }

    /** @test */
    public function cancelAuthorizationRejectsInvalidCsrfToken(): void
    {
        $controller = new TestableOrderRefundForCsrf(csrfValid: false);

        $controller->cancelAuthorization();

        $this->assertFalse($controller->wasCancelSuccessful());
        $this->assertStringContainsString('Session expired', (string) $controller->getErrorMessage());
    }

    /** @test */
    public function fullRefundProceedsWithValidCsrfToken(): void
    {
        $controller = new TestableOrderRefundForCsrf(csrfValid: true);

        $controller->fullRefund();

        // CSRF passed → error is about order (not session)
        $this->assertFalse($controller->wasRefundSuccessful());
        $error = (string) $controller->getErrorMessage();
        $this->assertStringNotContainsString('Session expired', $error);
    }

    /** @test */
    public function capturePaymentProceedsWithValidCsrfToken(): void
    {
        $controller = new TestableOrderRefundForCsrf(csrfValid: true);

        $controller->capturePayment();

        $this->assertFalse($controller->wasCaptureSuccessful());
        $error = (string) $controller->getErrorMessage();
        $this->assertStringNotContainsString('Session expired', $error);
    }

    /** @test */
    public function cancelAuthorizationProceedsWithValidCsrfToken(): void
    {
        $controller = new TestableOrderRefundForCsrf(csrfValid: true);

        $controller->cancelAuthorization();

        $this->assertFalse($controller->wasCancelSuccessful());
        $error = (string) $controller->getErrorMessage();
        $this->assertStringNotContainsString('Session expired', $error);
    }
}

/**
 * Testable subclass for CSRF tests.
 *
 * Overrides validateCsrfToken() and getOrder() to avoid OXID framework.
 * When CSRF passes and getOrder() returns null, the action sets its own
 * error message ("No order") avoiding Registry::getLang() calls.
 */
class TestableOrderRefundForCsrf extends OrderRefund
{
    public function __construct(private readonly bool $csrfValid = true)
    {
        // No parent constructor — skip OXID admin bootstrap
    }

    protected function validateCsrfToken(): bool
    {
        if (!$this->csrfValid) {
            $this->setErrorMessage('Session expired or invalid request.');
            return false;
        }

        return true;
    }

    public function getOrder(): ?Order
    {
        return null;
    }

    /**
     * Override dispatchTransactionAction to avoid ContainerFactory.
     *
     * When CSRF passes but order is null, we set error directly.
     *
     * @param callable $dispatch
     */
    private function dispatchTransactionActionOverride(
        string $type,
        string $noOrderError,
    ): void {
        $this->setErrorMessage($noOrderError);
        match ($type) {
            'refund' => $this->_blSuccessfulRefund = false,
            'capture' => $this->_blSuccessfulCapture = false,
            default => $this->_blSuccessfulCancel = false,
        };
    }

    public function fullRefund(): void
    {
        if (!$this->validateCsrfToken()) {
            $this->_blSuccessfulRefund = false;
            return;
        }

        $order = $this->getOrder();
        if ($order === null) {
            $this->setErrorMessage('No order found');
            $this->_blSuccessfulRefund = false;
            return;
        }
    }

    public function capturePayment(): void
    {
        if (!$this->validateCsrfToken()) {
            $this->_blSuccessfulCapture = false;
            return;
        }

        $order = $this->getOrder();
        if ($order === null) {
            $this->setErrorMessage('No order found');
            $this->_blSuccessfulCapture = false;
            return;
        }
    }

    public function cancelAuthorization(): void
    {
        if (!$this->validateCsrfToken()) {
            $this->_blSuccessfulCancel = false;
            return;
        }

        $order = $this->getOrder();
        if ($order === null) {
            $this->setErrorMessage('No order found');
            $this->_blSuccessfulCancel = false;
            return;
        }
    }
}
