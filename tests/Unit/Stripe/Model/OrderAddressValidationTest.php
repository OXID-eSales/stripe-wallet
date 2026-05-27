<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Model;

use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Order::validateDeliveryAddress() Stripe bypass gate.
 *
 * The bypass is legitimate only during the Stripe Checkout return flow.
 * It must NOT fire unconditionally for every Stripe payment — that would
 * disable OXID's address-tamper detection outside the return flow.
 *
 * Gate logic (R-3, R-8):
 *   if (isStripePaymentId($paymentId) && isStripeSkipAddressCheck()) → return 0
 *   otherwise → parent::validateDeliveryAddress($oUser)
 *
 * The skip flag (SESSION_SKIP_ADDR_CHECK) is set by StripeOrderController
 * immediately before the checkout-session dispatch and cleared by
 * clearStripeSessionVariables() on completion or cancellation.
 *
 * @covers \OxidEsales\Payments\Stripe\Model\Order::validateDeliveryAddress
 */
final class OrderAddressValidationTest extends TestCase
{
    // ==========================================
    // Testable subclass — seams only (R-1.5)
    // ==========================================

    /**
     * Testable subclass overriding ONLY the framework seams:
     *   - getBasketPaymentId()     → controllable payment-id string
     *   - isStripeSkipAddressCheck() → controllable flag
     *   - parentValidateDeliveryAddress() → spy / stub return value
     *
     * The real validateDeliveryAddress() body is NOT re-implemented here;
     * all three overrides are pure seams that remove the Registry/session
     * dependency so the real body can be unit-tested without a live container.
     */
    private function buildOrder(
        string $paymentId,
        bool $skipFlag,
        int $parentReturnValue
    ): Order {
        return new class ($paymentId, $skipFlag, $parentReturnValue) extends Order {
            private string $stubPaymentId;
            private bool $stubSkipFlag;
            private int $stubParentReturn;
            public bool $parentWasCalled = false;

            public function __construct(string $paymentId, bool $skipFlag, int $parentReturn)
            {
                // Skip OXID model bootstrap
                $this->stubPaymentId    = $paymentId;
                $this->stubSkipFlag     = $skipFlag;
                $this->stubParentReturn = $parentReturn;
            }

            protected function getBasketPaymentId(): string
            {
                return $this->stubPaymentId;
            }

            protected function isStripeSkipAddressCheck(): bool
            {
                return $this->stubSkipFlag;
            }

            protected function parentValidateDeliveryAddress(object $oUser): int
            {
                $this->parentWasCalled = true;
                return $this->stubParentReturn;
            }
        };
    }

    // ==========================================
    // Test 1: Stripe + skip-flag set → bypass (return 0, parent NOT called)
    // ==========================================

    public function testStripeWithSkipFlagBypassesValidation(): void
    {
        // Arrange
        $order = $this->buildOrder('oe_payments_stripe_wallet', true, 7);
        $user  = $this->createStub(\OxidEsales\Eshop\Application\Model\User::class);

        // Act
        $result = $order->validateDeliveryAddress($user);

        // Assert
        $this->assertSame(0, $result, 'Bypass must return 0 (address OK) during the return flow');
        $this->assertFalse($order->parentWasCalled, 'Parent must NOT be called during the bypass');
    }

    // ==========================================
    // Test 2: Stripe + NO skip-flag → delegates to parent (security gate)
    // ==========================================

    public function testStripeWithoutSkipFlagDelegatesToParent(): void
    {
        // Arrange — the SECURITY case: Stripe payment but NOT in return flow
        $order = $this->buildOrder('oe_payments_stripe_wallet', false, 7);
        $user  = $this->createStub(\OxidEsales\Eshop\Application\Model\User::class);

        // Act
        $result = $order->validateDeliveryAddress($user);

        // Assert
        $this->assertSame(7, $result, 'Parent result must be returned when flag is absent');
        $this->assertTrue($order->parentWasCalled, 'Parent MUST be called when skip-flag is absent');
    }

    // ==========================================
    // Test 3: Non-Stripe payment → always delegates to parent
    // ==========================================

    public function testNonStripePaymentAlwaysDelegatesToParent(): void
    {
        // Arrange — non-Stripe payment, flag irrelevant
        $order = $this->buildOrder('oxidcashondel', true, 5);
        $user  = $this->createStub(\OxidEsales\Eshop\Application\Model\User::class);

        // Act
        $result = $order->validateDeliveryAddress($user);

        // Assert
        $this->assertSame(5, $result, 'Parent result must be returned for non-Stripe payments');
        $this->assertTrue($order->parentWasCalled, 'Parent MUST be called for non-Stripe payments');
    }
}
