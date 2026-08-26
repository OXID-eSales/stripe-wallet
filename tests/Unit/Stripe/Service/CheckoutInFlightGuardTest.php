<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Service\CheckoutInFlightGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A contract as the repository hands it over — only the three facts the guard
 * reads from it.
 */
final class GuardProbeContract
{
    public function __construct(
        private readonly string $state = 'pending',
        private readonly string $provider = 'stripe',
        private readonly ?string $providerOrderId = 'cs_test_123',
    ) {
    }

    public function getStateValue(): string
    {
        return $this->state;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
    }

    public function getId(): ?string
    {
        return 'contract-1';
    }
}

/**
 * Guard with the two shop-facing reads replaced.
 */
final class TestableCheckoutInFlightGuard extends CheckoutInFlightGuard
{
    /** @param array{0: int, 1: string}|string $basketTotal */
    public function __construct(
        private readonly ?StripeCheckoutSessionDto $session,
        private readonly array|string $basketTotal = [4897, 'EUR'],
    ) {
        parent::__construct();
    }

    protected function retrieveSession(string $sessionId): ?StripeCheckoutSessionDto
    {
        // Mirrors production: there is nothing to retrieve without a reference.
        if ($sessionId === '') {
            return null;
        }

        if ($sessionId === 'cs_throws') {
            throw new RuntimeException('Stripe unreachable');
        }

        return $this->session;
    }

    /** @return array{0: int, 1: string} */
    protected function readCurrentBasketTotal(): array
    {
        if (is_string($this->basketTotal)) {
            throw new RuntimeException('no basket');
        }

        return $this->basketTotal;
    }
}

/**
 * The single question "is the checkout this shopper already has still the one to
 * use?", asked by two callers that used to disagree: the OPC payment handler,
 * which prepared a brand-new checkout on every call, and the stale-checkout
 * cleanup, which cancelled whatever the handler had just prepared as soon as a
 * page rendered. Between them one checkout produced five Stripe sessions, five
 * contracts and five early orders.
 */
#[CoversClass(CheckoutInFlightGuard::class)]
final class CheckoutInFlightGuardTest extends TestCase
{
    private function session(
        string $id = 'cs_test_123',
        int $amount = 4897,
        string $currency = 'eur',
        string $paymentStatus = 'unpaid',
    ): StripeCheckoutSessionDto {
        return new StripeCheckoutSessionDto(
            id: $id,
            paymentStatus: $paymentStatus,
            paymentIntentId: 'pi_1',
            paymentIntentStatus: 'requires_payment_method',
            metadata: [],
            amountTotal: $amount,
            currency: $currency,
            url: 'https://checkout.stripe.com/c/pay/cs_test_123',
            clientSecret: 'cs_test_123_secret',
        );
    }

    public function testUsableCheckoutIsHandedBack(): void
    {
        $guard = new TestableCheckoutInFlightGuard($this->session());

        $found = $guard->inspect(new GuardProbeContract());

        $this->assertNotNull($found);
        $this->assertSame('cs_test_123', $found->id);
    }

    public function testNoContractMeansNothingToKeep(): void
    {
        $guard = new TestableCheckoutInFlightGuard($this->session());

        $this->assertNull($guard->inspect(null));
    }

    public function testChangedBasketMakesTheCheckoutUnusable(): void
    {
        $guard = new TestableCheckoutInFlightGuard($this->session(amount: 4897), [5000, 'EUR']);

        $this->assertNull($guard->inspect(new GuardProbeContract()));
    }

    public function testPaidSessionIsNeverHandedBack(): void
    {
        $guard = new TestableCheckoutInFlightGuard($this->session(paymentStatus: 'paid'));

        $this->assertNull($guard->inspect(new GuardProbeContract()));
    }

    public function testCancelledContractIsNotUsable(): void
    {
        $guard = new TestableCheckoutInFlightGuard($this->session());

        $this->assertNull($guard->inspect(new GuardProbeContract(state: 'cancelled')));
    }

    public function testContractWithoutASessionReferenceIsNotUsable(): void
    {
        $guard = new TestableCheckoutInFlightGuard($this->session());

        $this->assertNull($guard->inspect(new GuardProbeContract(providerOrderId: null)));
    }

    /**
     * Both callers treat "cannot tell" as "carry on as before" — the handler
     * creates a fresh checkout, the cleanup cancels the old one. Neither may
     * break because Stripe or the basket was unavailable for a moment.
     */
    public function testStripeFailureMeansNoAnswer(): void
    {
        $guard = new TestableCheckoutInFlightGuard($this->session(), [4897, 'EUR']);

        $this->assertNull($guard->inspect(new GuardProbeContract(providerOrderId: 'cs_throws')));
    }

    public function testBasketFailureMeansNoAnswer(): void
    {
        $guard = new TestableCheckoutInFlightGuard($this->session(), 'throw');

        $this->assertNull($guard->inspect(new GuardProbeContract()));
    }

    public function testUnknownSessionMeansNoAnswer(): void
    {
        $guard = new TestableCheckoutInFlightGuard(null);

        $this->assertNull($guard->inspect(new GuardProbeContract()));
    }
}
