<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\PaymentHandler;

use OxidEsales\Payments\Stripe\PaymentHandler\ExistingCheckout;
use OxidEsales\Payments\Stripe\PaymentHandler\PendingCheckoutReuse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One checkout, one Stripe session.
 *
 * The OPC checkout API calls processPayment() several times while the customer
 * works through the accordion, and each call used to mint a fresh contract, a
 * fresh early order and a fresh Stripe Checkout Session. The customer could then
 * pay in a sheet belonging to any of them, which is how a paid return ended up
 * being refused, and it leaves a trail of cancelled contracts and orders with no
 * payment type behind.
 *
 * This decides when the checkout already prepared for this shopper can simply be
 * handed back instead.
 */
#[CoversClass(PendingCheckoutReuse::class)]
final class PendingCheckoutReuseTest extends TestCase
{
    private const AMOUNT = 4897;

    private function existing(
        string $state = 'pending',
        string $provider = 'stripe',
        string $sessionId = 'cs_test_123',
        int $amount = self::AMOUNT,
        string $currency = 'EUR',
        string $paymentStatus = 'unpaid',
    ): ExistingCheckout {
        return new ExistingCheckout($state, $provider, $sessionId, $amount, $currency, $paymentStatus);
    }

    public function testPendingUnpaidSessionForTheSameTotalIsReused(): void
    {
        $this->assertTrue(
            PendingCheckoutReuse::allows($this->existing(), self::AMOUNT, 'EUR')
        );
    }

    /**
     * The basket changed under the customer. The existing session is for the old
     * amount and Stripe would charge that, so it must not be handed out again.
     */
    public function testDifferentTotalIsNotReused(): void
    {
        $this->assertFalse(
            PendingCheckoutReuse::allows($this->existing(), self::AMOUNT + 1, 'EUR')
        );
    }

    public function testDifferentCurrencyIsNotReused(): void
    {
        $this->assertFalse(
            PendingCheckoutReuse::allows($this->existing(), self::AMOUNT, 'CHF')
        );
    }

    public function testCurrencyComparisonIgnoresCase(): void
    {
        $this->assertTrue(
            PendingCheckoutReuse::allows($this->existing(currency: 'eur'), self::AMOUNT, 'EUR')
        );
    }

    /**
     * The gravest case: a session that has already been paid must never be handed
     * to anyone again.
     */
    public function testPaidSessionIsNotReused(): void
    {
        $this->assertFalse(
            PendingCheckoutReuse::allows($this->existing(paymentStatus: 'paid'), self::AMOUNT, 'EUR')
        );
    }

    public function testNoLongerPendingContractIsNotReused(): void
    {
        foreach (['draft', 'not_finished', 'committed', 'fulfilled', 'cancelled', 'expired', 'failed'] as $state) {
            $this->assertFalse(
                PendingCheckoutReuse::allows($this->existing(state: $state), self::AMOUNT, 'EUR'),
                "a contract in state {$state} must not be reused"
            );
        }
    }

    public function testAnotherProvidersContractIsNotReused(): void
    {
        $this->assertFalse(
            PendingCheckoutReuse::allows($this->existing(provider: 'mollie'), self::AMOUNT, 'EUR')
        );
    }

    /**
     * A contract can be pending without a Stripe session yet, and the provider
     * reference can be a payment intent rather than a checkout session — neither
     * is something to reuse here.
     */
    public function testWithoutACheckoutSessionReferenceNothingIsReused(): void
    {
        $this->assertFalse(PendingCheckoutReuse::allows($this->existing(sessionId: ''), self::AMOUNT, 'EUR'));
        $this->assertFalse(
            PendingCheckoutReuse::allows($this->existing(sessionId: 'pi_test_123'), self::AMOUNT, 'EUR')
        );
    }

    public function testZeroAmountIsNotReused(): void
    {
        $this->assertFalse(PendingCheckoutReuse::allows($this->existing(amount: 0), 0, 'EUR'));
    }
}
