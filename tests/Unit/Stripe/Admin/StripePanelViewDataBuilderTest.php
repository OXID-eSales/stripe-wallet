<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder;
use OxidEsales\Payments\Stripe\Admin\StripeTransactionHistoryBuilder;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\Service\ChargeAmountResolverInterface;
use OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\OrderContractResolver;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Service\StripeOrderApiService;
use PHPUnit\Framework\TestCase;

/**
 * The panel's view-data projection (VR-22 A5: the admin validation feedback is gone with the Stripe-side money
 * actions; refusals are payment-base's classic feedback).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-120')]
final class StripePanelViewDataBuilderTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Sprint 127 (STRP-15123): capturableRaw is single-sourced to amountCapturable
    // ---------------------------------------------------------------------------
    /**
     * The builder must pass getCaptureableRaw() through unchanged to capturableRaw.
     * No transformation, no re-derivation — one source drives prefill, max, and label.
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testCapturableRawInViewDataIsPassedThroughFromProvider(): void
    {
        $provider = $this->createMock(OrderRefundViewDataProvider::class);
        $provider->method('getCaptureableRaw')->willReturn(40.0);
        $provider->method('getCaptureableAmount')->willReturn('40.00');

        $viewData = $this->builderWithProvider($provider)->build($this->orderStub());

        $this->assertSame(40.0, $viewData['capturableRaw']);
        $this->assertSame('40.00', $viewData['capturableAmount']);
    }

    /**
     * End-to-end SSOT: a PI with amountCapturable=4000 (40.00 EUR) produces
     * capturableRaw=40.0 in assembled view-data. Verified through the full
     * provider → builder chain using the fetchExpandedPaymentIntent seam.
     *
     * This is the single-source regression lock: any accidental re-derivation
     * from ->amount (the old bug) would return 100.0 instead of 40.0.
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testCapturableRawInViewDataEqualsPiAmountCapturable(): void
    {
        $pi = new StripePaymentIntentDto(
            id: 'pi_test',
            status: 'requires_capture',
            amount: 10000,
            currency: 'eur',
            created: 0,
            latestChargeId: null,
            charge: null,
            amountCapturable: 4000,
        );

        $provider = $this->realProviderWithPi($pi);
        $viewData  = $this->builderWithProvider($provider)->build($this->orderStub());

        $this->assertSame(40.0, $viewData['capturableRaw']);
    }

    // ---------------------------------------------------------------------------
    // Sprint 127 Issue 1 (STRP-15123): remainingRefundableRaw prefill regression lock
    //
    // H3 confirms the builder's unit chain correctly computes remainingRefundableRaw
    // for a partial refund fixture. This is GREEN today — the unit chain (provider →
    // resolver) already works for fresh reads. The bug is upstream (cache not busted
    // after action), not in the compute chain itself.
    // ---------------------------------------------------------------------------
    /**
     * SSOT regression lock: a charge with amountCaptured=10000, amountRefunded=3000
     * (full capture, one 30 EUR refund) must produce remainingRefundableRaw=70.0
     * in the assembled view-data.
     *
     * Prevents any regression where the prefill incorrectly re-reads the pre-refund
     * captured amount (100.0) instead of the post-refund residual (70.0).
     * This test is GREEN today — it locks correct behaviour at the builder level.
     *
     * Cross-source note: Order::getStripeRefundedAmount() reads via a separate
     * fetchStripeCharge() seam in the Order model, not via OrderRefundViewDataProvider.
     * Both ultimately delegate to StripeChargeAmountResolver with the same charge data.
     * No SSOT violation requiring a refactor — the two reads are on separate code paths
     * that converge at the resolver.
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testRemainingRefundableRawInViewDataReflectsPartialRefund(): void
    {
        $charge = new StripeChargeDto(
            id: 'ch_test',
            amount: 10000,
            amountCaptured: 10000,
            amountRefunded: 3000,
            currency: 'eur',
            captured: true,
            created: 0,
        );
        $pi = new StripePaymentIntentDto(
            id: 'pi_test',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 0,
            latestChargeId: 'ch_test',
            charge: $charge,
            amountCapturable: 0,
        );

        $provider = $this->realProviderWithPiAndRealResolver($pi);
        $viewData  = $this->builderWithProvider($provider)->build($this->orderStub());

        // remainingRefundableRaw is used as the amount field prefill (max).
        // With 30 EUR already refunded from 100 EUR, only 70 EUR remain.
        $this->assertSame(70.0, $viewData['remainingRefundableRaw']);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /** MOL-10 (shared Help): the panel shows the OXID contract state next to the contract id. */
    public function testExposesTheContractStateForThePanel(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getStateValue')->willReturn('committed');
        $resolver = $this->createMock(OrderContractResolver::class);
        $resolver->method('getContractForOrder')->willReturn($contract);
        $builder = new StripePanelViewDataBuilder(
            viewDataProvider: $this->createMock(OrderRefundViewDataProvider::class),
            contractResolver: $resolver,
            moduleConfig: $this->createMock(ModuleConfigurationServiceInterface::class),
        );

        $this->assertSame('committed', $builder->build($this->orderStub())['contractState']);
    }

    public function testContractStateIsEmptyWithoutAContract(): void
    {
        $this->assertSame('', $this->builder()->build($this->orderStub())['contractState']);
    }

    private function builder(): StripePanelViewDataBuilder
    {
        return new StripePanelViewDataBuilder(
            viewDataProvider: $this->createMock(OrderRefundViewDataProvider::class),
            contractResolver: $this->createMock(OrderContractResolver::class),
            moduleConfig: $this->createMock(ModuleConfigurationServiceInterface::class),
        );
    }

    private function orderStub(): Order
    {
        return $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * Builder variant that accepts an explicit provider (for SSOT tests).
     */
    private function builderWithProvider(OrderRefundViewDataProvider $provider): StripePanelViewDataBuilder
    {
        return new StripePanelViewDataBuilder(
            viewDataProvider: $provider,
            contractResolver: $this->createMock(OrderContractResolver::class),
            moduleConfig: $this->createMock(ModuleConfigurationServiceInterface::class),
        );
    }

    /**
     * Creates a real OrderRefundViewDataProvider whose fetchExpandedPaymentIntent()
     * returns the given PI, bypassing StripeOrderApiService (which is final).
     * Uses a mock ChargeAmountResolverInterface — suitable for passthrough tests.
     */
    private function realProviderWithPi(?StripePaymentIntentDto $pi): OrderRefundViewDataProvider
    {
        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $adapterFactory->method('getStripeAdapter')->willReturn($this->createMock(StripeAdapterInterface::class));
        $apiService = new StripeOrderApiService($adapterFactory);
        $resolver   = $this->createMock(ChargeAmountResolverInterface::class);

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

    /**
     * Like realProviderWithPi() but wires the real StripeChargeAmountResolver
     * so amount computations (remainingRefundableRaw etc.) are exercised end-to-end.
     * Required for H3 regression lock where the fixture charge numbers must drive
     * the computed result.
     */
    private function realProviderWithPiAndRealResolver(?StripePaymentIntentDto $pi): OrderRefundViewDataProvider
    {
        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $adapterFactory->method('getStripeAdapter')->willReturn($this->createMock(StripeAdapterInterface::class));
        $apiService = new StripeOrderApiService($adapterFactory);
        $resolver   = new StripeChargeAmountResolver();

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
