<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Payment as EshopModelPayment;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\StaticContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see StaticContent}.
 *
 * Guards the re-activation behaviour: when a Stripe payment method already
 * exists, its multilingual descriptions must be refreshed from
 * StripeDefinitions (the single source of truth) instead of being skipped,
 * so title/wording changes propagate on the next module activation.
 */
#[CoversClass(StaticContent::class)]
#[Group('static-content')]
#[Group('payment-method')]
final class StaticContentTest extends TestCase
{
    #[Test]
    public function ensureStripePaymentMethodsRefreshesDescriptionsOfExistingMethod(): void
    {
        /** @var array<int, array<string, mixed>> $assigned */
        $assigned = [];

        $model = $this->createMock(EshopModelPayment::class);
        $model->method('load')->willReturn(true);        // method already installed
        $model->method('loadInLang')->willReturn(true);
        $model->method('save')->willReturn(true);
        $model->method('assign')->willReturnCallback(
            function (array $data) use (&$assigned): void {
                $assigned[] = $data;
            }
        );

        $service = new TestableStaticContent($model, [0 => 'de', 1 => 'en']);
        $service->ensureStripePaymentMethods();

        $walletDefinition =
            StripeDefinitions::getStripeDefinitions()[StripeDefinitions::STRIPE_WALLET_PAYMENT_ID];
        /** @var array<string, array{desc?: string, longdesc?: string}> $descriptions */
        $descriptions = $walletDefinition['descriptions'];

        $this->assertNotEmpty(
            $assigned,
            'An existing payment method must have its descriptions refreshed, not skipped.'
        );

        $expectedPairs = [];
        foreach ($descriptions as $data) {
            $expectedPairs[] = [
                'oxdesc' => $data['desc'] ?? '',
                'oxlongdesc' => $data['longdesc'] ?? '',
            ];
        }

        $actualPairs = [];
        foreach ($assigned as $data) {
            $this->assertArrayNotHasKey(
                'oxactive',
                $data,
                'Re-activation must not reset the active flag of an existing method.'
            );
            $actualPairs[] = [
                'oxdesc' => $data['oxdesc'] ?? null,
                'oxlongdesc' => $data['oxlongdesc'] ?? null,
            ];
        }

        $this->assertEqualsCanonicalizing(
            $expectedPairs,
            $actualPairs,
            'Every language title and description must be refreshed from StripeDefinitions.'
        );
    }

    #[Test]
    public function ensureStripePaymentMethodsCreatesAndAssignsMissingMethod(): void
    {
        /** @var array<int, array<string, mixed>> $assigned */
        $assigned = [];

        $model = $this->createMock(EshopModelPayment::class);
        $model->method('load')->willReturn(false);       // method not installed yet
        $model->method('loadInLang')->willReturn(true);
        $model->method('save')->willReturn(true);
        $model->method('assign')->willReturnCallback(
            function (array $data) use (&$assigned): void {
                $assigned[] = $data;
            }
        );

        $service = new TestableStaticContent($model, [0 => 'de', 1 => 'en']);
        $service->ensureStripePaymentMethods();

        $baseAssignments = array_filter($assigned, static fn (array $d): bool => isset($d['oxactive']));
        $this->assertCount(1, $baseAssignments, 'A new method must be created with its base data once.');

        $titles = array_column(
            array_filter($assigned, static fn (array $d): bool => isset($d['oxdesc'])),
            'oxdesc'
        );
        $this->assertContains(
            'Stripe Wallet',
            $titles,
            'A new method must receive its descriptions from StripeDefinitions.'
        );

        $this->assertSame(
            [StripeDefinitions::STRIPE_WALLET_PAYMENT_ID],
            $service->deliveryAssignments,
            'A newly created method must be assigned to the active delivery sets.'
        );
    }
}

/**
 * Testable subclass: substitutes the OXID payment model with a spy and pins the
 * language map, so the existing-method path can be exercised without a database.
 */
final class TestableStaticContent extends StaticContent
{
    /** @var array<int, string> Payment IDs passed to delivery-set assignment. */
    public array $deliveryAssignments = [];

    /**
     * @param EshopModelPayment $paymentModel
     * @param array<int, string> $languageIds
     */
    public function __construct(
        private EshopModelPayment $paymentModel,
        private array $languageIds
    ) {
        // Intentionally skip the parent constructor: the paths under test never
        // touch the QueryBuilderFactory (delivery assignment is stubbed below).
    }

    protected function makePaymentModel(): EshopModelPayment
    {
        return $this->paymentModel;
    }

    protected function assignPaymentToActiveDeliverySets(string $paymentId): void
    {
        $this->deliveryAssignments[] = $paymentId;
    }

    /**
     * @return array<int, string>
     */
    protected function getLanguageIds(): array
    {
        return $this->languageIds;
    }
}
