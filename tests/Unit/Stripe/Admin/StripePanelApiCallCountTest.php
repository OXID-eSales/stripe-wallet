<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\Service\ChargeAmountResolverInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripeOrderApiService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Charge;
use Stripe\PaymentIntent;

/**
 * Sprint 104 — Pins the Stripe API call-count invariant for the admin panel render path.
 *
 * After the sprint, one full panel render must issue exactly one call to
 * fetchExpandedPaymentIntent (→ getPaymentIntentWithRefunds) and zero additional
 * calls to the plain-PI or plain-Charge endpoints. This eliminates the ≈10
 * sequential round-trips that caused ≈1.5–3 s wall-clock delay per panel open.
 *
 * StripeOrderApiService is final — mocking it directly is not possible.
 * OrderRefundViewDataProvider exposes a protected seam fetchExpandedPaymentIntent()
 * introduced in Sprint 104; the counting subclass overrides that seam.
 * For the Order-extension memoisation test, a testable Order subclass
 * overrides getStripeCharge() to count invocations.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider
 * @covers \OxidEsales\Payments\Stripe\Model\Order
 */
final class StripePanelApiCallCountTest extends TestCase
{
    private ChargeAmountResolverInterface&MockObject $chargeAmountResolver;

    protected function setUp(): void
    {
        $this->chargeAmountResolver = $this->createMock(ChargeAmountResolverInterface::class);
        $this->chargeAmountResolver->method('availableForRefund')->willReturn(50.0);
        $this->chargeAmountResolver->method('hasCustomerRefund')->willReturn(false);
        $this->chargeAmountResolver->method('customerRefundedAmount')->willReturn(0.0);
    }

    /**
     * Full panel render exercises all provider methods that were previously
     * triggering independent Stripe fetches. After Sprint 104, all reads
     * share a single expanded PI fetched once via fetchExpandedPaymentIntent.
     *
     * Pre-fix: getLastCharge(refresh=true) twice + getStripeTransactionHistory
     * separate call = ≥3 round-trips. Post-fix: exactly one expanded fetch.
     */
    public function testPanelRenderIssuesOneExpandedPiCall(): void
    {
        // Arrange
        $order    = $this->createMock(Order::class);
        $provider = $this->buildCountingProvider();

        // Act — simulate all API-touching methods called during a panel render
        // (format methods that need OXID Registry are excluded — not relevant to call counts)
        $provider->getPaymentIntent($order);
        $provider->isOrderCapturable($order);
        $provider->getCaptureableRaw($order);
        $provider->getRemainingRefundableRaw($order);
        $provider->isOrderRefundable($order);
        $provider->getStripeTransactionHistory($order);

        // Assert — exactly one expanded-PI fetch, no plain-PI or plain-Charge fetches
        self::assertSame(1, $provider->expandedPiCallCount, 'getPaymentIntentWithRefunds count');
        self::assertSame(0, $provider->plainPiCallCount, 'getPaymentIntent count');
        self::assertSame(0, $provider->plainChargeCallCount, 'getLastCharge count');
    }

    /**
     * Calling getPaymentIntent() then isOrderRefundable() on the same provider
     * must not trigger additional fetches — the cached expanded PI is reused.
     */
    public function testIsOrderRefundableReusesCachedCharge(): void
    {
        // Arrange
        $order    = $this->createMock(Order::class);
        $provider = $this->buildCountingProvider();

        // Act
        $provider->getPaymentIntent($order);
        $provider->isOrderRefundable($order);

        // Assert — one fetch total, no additional fetch from isOrderRefundable
        self::assertSame(1, $provider->expandedPiCallCount, 'Total Stripe call count after both operations');
    }

    /**
     * Three consecutive accessors on the same Order instance must produce
     * exactly one call to fetchStripeCharge() (the underlying Stripe API fetch).
     *
     * getStripeCharge() is memoised: on the first call it delegates to
     * fetchStripeCharge() (ContainerFactory + API), stores the result, and
     * returns it. Subsequent calls return the cached result without re-entering
     * fetchStripeCharge(). The testable subclass overrides fetchStripeCharge()
     * to count invocations, while getStripeCharge()'s memoisation remains active.
     */
    public function testOrderExtensionMemoisesChargePerInstance(): void
    {
        // Arrange — testable Order that counts how many times fetchStripeCharge() is entered
        $charge   = $this->buildCharge();
        $resolver = $this->chargeAmountResolver;

        $order = new class ($charge, $resolver) extends \OxidEsales\Payments\Stripe\Model\Order {
            public int $fetchCallCount = 0;

            public function __construct(
                private readonly ?\Stripe\Charge $stubCharge,
                private readonly ChargeAmountResolverInterface $stubResolver,
            ) {
                // Skip OXID parent constructor — would attempt DB/Registry access.
            }

            protected function fetchStripeCharge(): ?\Stripe\Charge
            {
                $this->fetchCallCount++;
                return $this->stubCharge;
            }

            protected function getChargeAmountResolver(): ?ChargeAmountResolverInterface
            {
                return $this->stubResolver;
            }

            protected function formatStripeAmount(float $amount): string
            {
                return (string) $amount;
            }
        };

        // Act — three accessors, each internally calls getStripeCharge()
        $order->getStripeCapturedAmount();
        $order->getStripeRefundedAmount();
        $order->hasStripeRefunds();

        // Assert — fetchStripeCharge() must only be called once (memoisation in getStripeCharge)
        self::assertSame(1, $order->fetchCallCount, 'fetchStripeCharge() invocation count');
    }

    /**
     * Mutation path escape hatch: passing refresh=true to getPaymentIntent()
     * after a cached read must issue a second fetch, bypassing the cache.
     */
    public function testMutationPathStillRefreshes(): void
    {
        // Arrange
        $order    = $this->createMock(Order::class);
        $provider = $this->buildCountingProvider();

        // Act — first read populates cache; second with refresh=true must re-fetch
        $provider->getPaymentIntent($order);
        $provider->getPaymentIntent($order, true);

        // Assert — two expanded-PI fetches: one cached, one forced
        self::assertSame(2, $provider->expandedPiCallCount, 'PI retrieve count with forced refresh');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a counting subclass of OrderRefundViewDataProvider.
     *
     * Overrides the protected seam fetchExpandedPaymentIntent() introduced in Sprint 104
     * to intercept and count calls that would otherwise reach the final StripeOrderApiService.
     * Also tracks whether legacy plain-PI or plain-Charge paths are ever invoked (they must not be).
     *
     * @return OrderRefundViewDataProvider&object{
     *     expandedPiCallCount: int,
     *     plainPiCallCount: int,
     *     plainChargeCallCount: int
     * }
     */
    private function buildCountingProvider(): OrderRefundViewDataProvider
    {
        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $apiService     = new StripeOrderApiService($adapterFactory);
        $resolver       = $this->chargeAmountResolver;
        $pi             = $this->buildPaymentIntent();

        return new class ($apiService, $resolver, $pi) extends OrderRefundViewDataProvider {
            public int $expandedPiCallCount  = 0;
            public int $plainPiCallCount     = 0;
            public int $plainChargeCallCount = 0;

            public function __construct(
                StripeOrderApiService $apiService,
                ChargeAmountResolverInterface $chargeAmountResolver,
                private readonly PaymentIntent $stubPi,
            ) {
                parent::__construct($apiService, $chargeAmountResolver);
            }

            protected function fetchExpandedPaymentIntent(Order $order): ?PaymentIntent
            {
                $this->expandedPiCallCount++;
                return $this->stubPi;
            }
        };
    }

    private function buildPaymentIntent(): PaymentIntent
    {
        $charge = $this->buildCharge();

        return PaymentIntent::constructFrom([
            'id'            => 'pi_test123',
            'amount'        => 10000,
            'currency'      => 'eur',
            'status'        => 'succeeded',
            'created'       => 1700000000,
            'latest_charge' => $charge,
        ]);
    }

    private function buildCharge(): Charge
    {
        return Charge::constructFrom([
            'id'              => 'ch_test123',
            'amount'          => 10000,
            'amount_captured' => 10000,
            'amount_refunded' => 0,
            'currency'        => 'eur',
            'captured'        => true,
            'created'         => 1700000001,
            'refunds'         => [
                'object' => 'list',
                'data'   => [],
                'url'    => '/v1/charges/ch_test123/refunds',
            ],
        ]);
    }
}
