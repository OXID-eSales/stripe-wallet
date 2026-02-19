<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\ContractStateMachine;

use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * F25: `oxidstripe` Hardcoded in EarlyOrderCreationHandler (Sprint 56b regression)
 *
 * MEDIUM — Code correctness
 *
 * EarlyOrderCreationHandler uses hardcoded 'oxidstripe' instead of
 * StripeDefinitions::STRIPE_WALLET_PAYMENT_ID ('oe_payments_stripe_wallet').
 * This was the exact same bug fixed in Sprint 56b for AcpContextResolverHandler
 * but missed in EarlyOrderCreationHandler.
 *
 * @group security
 * @group f25
 * @since Sprint 61
 */
class PaymentIdRegressionTest extends TestCase
{
    /**
     * F25: EarlyOrderCreationHandler uses hardcoded 'oxidstripe'.
     */
    public function testEarlyOrderCreationHandlerUsesHardcodedPaymentId(): void
    {
        $sourceFile = dirname(__DIR__, 4)
            . '/source/extensions/payment-component/src/EventSystem/Handler/EarlyOrderCreationHandler.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // VULNERABILITY: hardcoded 'oxidstripe' instead of constant
        $this->assertStringContainsString(
            "'oxidstripe'",
            $source,
            'F25: Hardcoded oxidstripe payment ID found'
        );
    }

    /**
     * F25: The correct constant value is 'oe_payments_stripe_wallet'.
     */
    public function testCorrectPaymentIdConstant(): void
    {
        $this->assertSame(
            'oe_payments_stripe_wallet',
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            'The correct payment ID is oe_payments_stripe_wallet'
        );
    }

    /**
     * F25: The hardcoded value does not match the constant.
     */
    public function testHardcodedValueDoesNotMatchConstant(): void
    {
        $this->assertNotSame(
            'oxidstripe',
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            'F25: oxidstripe != oe_payments_stripe_wallet — wrong payment ID causes ORDER_STATE_INVALIDPAYMENT'
        );
    }

    /**
     * F25: AcpContextResolverHandler correctly uses the constant (Sprint 56b fix).
     */
    public function testAcpContextResolverHandlerUsesConstant(): void
    {
        $sourceFile = dirname(__DIR__, 3)
            . '/src/Stripe/Mcp/Handler/AcpContextResolverHandler.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // Sprint 56b fix applied correctly here
        $this->assertStringContainsString(
            'StripeDefinitions::STRIPE_WALLET_PAYMENT_ID',
            $source,
            'AcpContextResolverHandler uses the constant (Sprint 56b fix)'
        );

        // And does NOT use the hardcoded string
        $this->assertStringNotContainsString(
            "'oxidstripe'",
            $source,
            'AcpContextResolverHandler does not use hardcoded oxidstripe'
        );
    }

    /**
     * F25: EarlyOrderCreationHandler does NOT reference StripeDefinitions.
     */
    public function testEarlyOrderHandlerDoesNotReferenceStripeDefinitions(): void
    {
        $sourceFile = dirname(__DIR__, 4)
            . '/source/extensions/payment-component/src/EventSystem/Handler/EarlyOrderCreationHandler.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // Handler is in payment-component, so it can't reference StripeDefinitions
        // This is the root cause — the constant is in the stripe module
        $this->assertStringNotContainsString(
            'StripeDefinitions',
            $source,
            'F25: Handler in payment-component cannot reference stripe-specific constants'
        );
    }

    /**
     * F25: Document the pattern — fix applied to one handler but not all.
     */
    public function testFixAppliedToOneHandlerButNotAll(): void
    {
        $acpHandler = dirname(__DIR__, 3)
            . '/src/Stripe/Mcp/Handler/AcpContextResolverHandler.php';
        $earlyHandler = dirname(__DIR__, 4)
            . '/source/extensions/payment-component/src/EventSystem/Handler/EarlyOrderCreationHandler.php';

        if (!file_exists($acpHandler) || !file_exists($earlyHandler)) {
            $this->markTestSkipped('Source files not found');
        }

        $acpSource = file_get_contents($acpHandler);
        $earlySource = file_get_contents($earlyHandler);
        $this->assertIsString($acpSource);
        $this->assertIsString($earlySource);

        // AcpContextResolver: fixed (uses constant)
        $acpFixed = !str_contains($acpSource, "'oxidstripe'");

        // EarlyOrderCreation: NOT fixed (still hardcoded)
        $earlyFixed = !str_contains($earlySource, "'oxidstripe'");

        $this->assertTrue($acpFixed, 'AcpContextResolverHandler IS fixed');
        $this->assertFalse($earlyFixed, 'F25: EarlyOrderCreationHandler is NOT fixed');
    }
}
