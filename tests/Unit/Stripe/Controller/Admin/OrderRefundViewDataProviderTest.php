<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Admin\StripeTransactionHistoryBuilder;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\Service\ChargeAmountResolverInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver;
use OxidEsales\Payments\Stripe\Service\StripeOrderApiService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 103: Pins getRemainingRefundableRaw() and isOrderRefundable() against
 * the partial-capture fixture that previously produced −197.0.
 *
 * StripeOrderApiService is final — mocking it directly is not possible. The test
 * uses a testable subclass of OrderRefundViewDataProvider that overrides
 * getLastCharge() to return a controlled fixture charge, eliminating any real
 * API or framework dependency.
 *
 * T3 fix (Sprint 114.13): regression tests for the partial-capture formula now
 * inject the real StripeChargeAmountResolver so the fixture charge numbers
 * actually drive the result. Delegation-only tests retain a mock resolver.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider::class)]
final class OrderRefundViewDataProviderTest extends TestCase
{
    private ChargeAmountResolverInterface&MockObject $mockResolver;
    private StripeChargeAmountResolver $realResolver;

    protected function setUp(): void
    {
        $this->mockResolver = $this->createMock(ChargeAmountResolverInterface::class);
        $this->realResolver = new StripeChargeAmountResolver();
    }

    // --- Regression tests: real resolver, fixture numbers drive the result ---

    public function testRemainingRefundableRawForFullCaptureNoRefundReturnsCapturedAmount(): void
    {
        // Full capture, no refund: 10000 cents / 100 = 100.0 EUR
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 10000, amountRefunded: 0);
        $provider = $this->createProviderWithChargeAndRealResolver($charge);

        $result = $provider->getRemainingRefundableRaw($this->createMock(Order::class));

        self::assertSame(100.0, $result);
    }

    public function testRemainingRefundableRawForFullCaptureWithCustomerRefundReturnsResidual(): void
    {
        // Full capture (10000 cents), 3000 cents customer refund → 70.0 EUR available
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 10000, amountRefunded: 3000);
        $provider = $this->createProviderWithChargeAndRealResolver($charge);

        $result = $provider->getRemainingRefundableRaw($this->createMock(Order::class));

        self::assertSame(70.0, $result);
    }

    /**
     * Regression for the partial-capture bug: Stripe encodes the released
     * (uncaptured) portion as amount_refunded. The pre-fix formula produced
     * (10000 − 29700) / 100 = −197.0. With the corrected formula the fixture
     * should yield 100.0 — exactly the captured amount, with no customer refund.
     */
    public function testRemainingRefundableRawForPartialCaptureNoCustomerRefundReturnsCapturedAmount(): void
    {
        // 397 EUR originally authorized, 100 EUR captured, 297 EUR auto-released
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 29700);
        $provider = $this->createProviderWithChargeAndRealResolver($charge);

        $result = $provider->getRemainingRefundableRaw($this->createMock(Order::class));

        self::assertSame(100.0, $result, 'Pre-fix would have returned -197.0');
    }

    public function testRemainingRefundableRawForPartialCaptureWithCustomerRefundReturnsResidual(): void
    {
        // 397 EUR authorized, 100 EUR captured, 297 EUR auto-released + 50 EUR customer refund
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 34700);
        $provider = $this->createProviderWithChargeAndRealResolver($charge);

        $result = $provider->getRemainingRefundableRaw($this->createMock(Order::class));

        self::assertSame(50.0, $result);
    }

    public function testIsOrderRefundableTrueForPartialCaptureNoCustomerRefund(): void
    {
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 29700);
        $provider = $this->createProviderWithChargeAndRealResolver($charge);

        self::assertTrue($provider->isOrderRefundable($this->createMock(Order::class)));
    }

    public function testIsOrderRefundableFalseWhenCaptureFullyRefundedToCustomer(): void
    {
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 10000, amountRefunded: 10000);
        $provider = $this->createProviderWithChargeAndRealResolver($charge);

        self::assertFalse($provider->isOrderRefundable($this->createMock(Order::class)));
    }

    // --- Delegation test: verifies getRemainingRefundableRaw passes charge to the resolver ---

    public function testRemainingRefundableRawDelegatesToInjectedResolver(): void
    {
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 5000, amountRefunded: 0);
        $this->mockResolver->expects($this->once())
            ->method('availableForRefund')
            ->with($this->equalTo($charge))
            ->willReturn(50.0);
        $provider = $this->createProviderWithCharge($charge, $this->mockResolver);

        $result = $provider->getRemainingRefundableRaw($this->createMock(Order::class));

        self::assertSame(50.0, $result);
    }

    public function testIsOrderRefundableFalseWhenChargeIsNull(): void
    {
        // No charge available (network error or no transaction ID)
        $provider = $this->createProviderWithCharge(null, $this->mockResolver);

        self::assertFalse($provider->isOrderRefundable($this->createMock(Order::class)));
    }

    // -------------------------------------------------------------------------
    // Issue 2 (STRP-15123): getCaptureableRaw() must return remaining capturable,
    // not the full authorized amount.
    // -------------------------------------------------------------------------
    /**
     * Repro: PI authorized 100.00, but only 40.00 is still capturable.
     * getCaptureableRaw() must return 40.0, not 100.0.
     * RED today — StripePaymentIntentDto has no amountCapturable field.
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testGetCaptureableRawReturnsRemainingCapturableNotFullAuthorized(): void
    {
        $pi = $this->buildPiDtoWithAmountCapturable(
            amount: 10000,
            amountCapturable: 4000,
            currency: 'eur',
        );
        $provider = $this->createProviderWithPaymentIntent($pi);

        self::assertSame(40.0, $provider->getCaptureableRaw($this->createMock(Order::class)));
    }

    /**
     * Repro: PI authorized 100.00, amountCapturable == amount (no prior partial capture).
     * getCaptureableRaw() must return 100.0 (no regression for the fresh-authorized case).
     * RED today — StripePaymentIntentDto has no amountCapturable field.
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testGetCaptureableRawReturnFullAmountWhenNoPriorCapture(): void
    {
        $pi = $this->buildPiDtoWithAmountCapturable(
            amount: 10000,
            amountCapturable: 10000,
            currency: 'eur',
        );
        $provider = $this->createProviderWithPaymentIntent($pi);

        self::assertSame(100.0, $provider->getCaptureableRaw($this->createMock(Order::class)));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a provider backed by the real StripeChargeAmountResolver.
     * The fixture charge's minor-unit values drive the computed result.
     */
    private function createProviderWithChargeAndRealResolver(?StripeChargeDto $charge): OrderRefundViewDataProvider
    {
        return $this->createProviderWithCharge($charge, $this->realResolver);
    }

    /**
     * Creates a testable subclass of OrderRefundViewDataProvider that returns
     * a fixed StripeChargeDto from getLastCharge(), bypassing StripeOrderApiService.
     *
     * StripeOrderApiService is final and cannot be mocked with PHPUnit. We build
     * a real instance with a mocked factory (the factory interface IS mockable) and
     * then override getLastCharge() so the service is never actually called. This
     * pattern follows CLAUDE.md §"Final class mocking".
     *
     * Sprint 114.10b: migrated from \Stripe\Charge to StripeChargeDto.
     */
    private function createProviderWithCharge(
        ?StripeChargeDto $charge,
        ChargeAmountResolverInterface $resolver
    ): OrderRefundViewDataProvider {
        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $adapterFactory->method('getStripeAdapter')->willReturn($this->createMock(StripeAdapterInterface::class));
        $apiService = new StripeOrderApiService($adapterFactory);

        return new class ($apiService, $resolver, $charge) extends OrderRefundViewDataProvider {
            public function __construct(
                StripeOrderApiService $apiService,
                ChargeAmountResolverInterface $chargeAmountResolver,
                private readonly ?StripeChargeDto $stubCharge,
            ) {
                parent::__construct($apiService, $chargeAmountResolver, new StripeTransactionHistoryBuilder());
            }

            public function getLastCharge(Order $order, bool $refresh = false): ?StripeChargeDto
            {
                return $this->stubCharge;
            }
        };
    }

    private function buildCharge(int $amount, int $amountCaptured, int $amountRefunded): StripeChargeDto
    {
        return new StripeChargeDto(
            id: 'ch_test',
            amount: $amount,
            amountCaptured: $amountCaptured,
            amountRefunded: $amountRefunded,
            currency: 'eur',
            captured: true,
            created: 0,
        );
    }

    /**
     * Constructs a StripePaymentIntentDto with explicit amountCapturable.
     * Phase A2: will fail with "Unknown named argument" until Phase B2 adds the field.
     */
    private function buildPiDtoWithAmountCapturable(
        int $amount,
        int $amountCapturable,
        string $currency = 'eur',
    ): StripePaymentIntentDto {
        return new StripePaymentIntentDto(
            id: 'pi_test',
            status: 'requires_capture',
            amount: $amount,
            currency: $currency,
            created: 0,
            latestChargeId: null,
            charge: null,
            amountCapturable: $amountCapturable,
        );
    }

    /**
     * Creates a provider whose getPaymentIntent() returns the given PI DTO,
     * bypassing StripeOrderApiService entirely (it is final and cannot be mocked).
     * Uses the same fetchExpandedPaymentIntent() seam as OrderRefundViewDataProviderDtoCharacterizationTest.
     */
    private function createProviderWithPaymentIntent(?StripePaymentIntentDto $pi): OrderRefundViewDataProvider
    {
        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $adapterFactory->method('getStripeAdapter')->willReturn($this->createMock(StripeAdapterInterface::class));
        $apiService = new StripeOrderApiService($adapterFactory);
        $resolver   = $this->mockResolver;

        return new class ($apiService, $resolver, $pi) extends OrderRefundViewDataProvider {
            public function __construct(
                StripeOrderApiService $apiService,
                ChargeAmountResolverInterface $chargeAmountResolver,
                private readonly ?StripePaymentIntentDto $stubPi,
            ) {
                parent::__construct($apiService, $chargeAmountResolver, new StripeTransactionHistoryBuilder());
            }

            protected function fetchExpandedPaymentIntent(Order $order): ?StripePaymentIntentDto
            {
                return $this->stubPi;
            }
        };
    }
}
