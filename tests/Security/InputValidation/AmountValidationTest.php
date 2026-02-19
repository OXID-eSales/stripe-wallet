<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\InputValidation;

use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\Payments\Stripe\Tests\Security\Helper\SecurityTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * F15: Amount Fields Lack Negative/Overflow Validation
 *
 * HIGH — PCI DSS 3.2 (transaction integrity)
 *
 * setCapturedAmount() and addRefundedAmount() accept any float including
 * negative, INF, and NAN. Refund can exceed captured amount.
 *
 * @group security
 * @group f15
 * @since Sprint 60
 */
class AmountValidationTest extends TestCase
{
    /**
     * F15: Negative captured amount is accepted.
     */
    public function testNegativeCapturedAmountIsAccepted(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');

        // VULNERABILITY: negative amount credits customer
        $contract->setCapturedAmount(-100.00);

        $this->assertSame(-100.00, $contract->getCapturedAmount());
    }

    /**
     * F15: Zero captured amount is accepted.
     */
    public function testZeroCapturedAmountIsAccepted(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');

        $contract->setCapturedAmount(0.0);

        $this->assertSame(0.0, $contract->getCapturedAmount());
    }

    /**
     * F15: INF captured amount is accepted.
     */
    public function testInfiniteCapturedAmountIsAccepted(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');

        $contract->setCapturedAmount(INF);

        $this->assertSame(INF, $contract->getCapturedAmount());
    }

    /**
     * F15: NAN captured amount is accepted.
     */
    public function testNanCapturedAmountIsAccepted(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');

        $contract->setCapturedAmount(NAN);

        $this->assertNan($contract->getCapturedAmount());
    }

    /**
     * F15: Negative refund amount is accepted (creates money).
     */
    public function testNegativeRefundAmountIsAccepted(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');
        $contract->setCapturedAmount(100.00);

        // VULNERABILITY: negative refund reduces total refunded, creating money
        $contract->addRefundedAmount(-50.00);

        $this->assertSame(-50.00, $contract->getRefundedAmount());
    }

    /**
     * F15: Refund exceeding captured amount is accepted.
     */
    public function testRefundExceedingCapturedAmountIsAccepted(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');
        $contract->setCapturedAmount(100.00);

        // VULNERABILITY: refund more than captured — creates money
        $contract->addRefundedAmount(200.00);

        $refunded = $contract->getRefundedAmount();
        $this->assertNotNull($refunded);
        $this->assertGreaterThan(100.00, $refunded);
    }

    /**
     * F15: Multiple refunds can exceed captured amount.
     */
    public function testMultipleRefundsExceedCapturedAmount(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');
        $contract->setCapturedAmount(100.00);

        $contract->addRefundedAmount(60.00);
        $contract->addRefundedAmount(60.00);

        $refunded = $contract->getRefundedAmount();
        $this->assertNotNull($refunded);
        $this->assertSame(120.00, $refunded);
    }

    /**
     * F15: INF refund amount is accepted.
     */
    public function testInfiniteRefundAmountIsAccepted(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');
        $contract->setCapturedAmount(100.00);

        $contract->addRefundedAmount(INF);

        $this->assertSame(INF, $contract->getRefundedAmount());
    }

    /**
     * F15: PHP_FLOAT_MAX captured amount is accepted.
     */
    public function testPhpFloatMaxCapturedAmountIsAccepted(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');

        $contract->setCapturedAmount(PHP_FLOAT_MAX);

        $this->assertSame(PHP_FLOAT_MAX, $contract->getCapturedAmount());
    }

    /**
     * Positive: Normal positive amount works correctly.
     */
    public function testNormalPositiveAmountWorks(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');

        $contract->setCapturedAmount(49.99);
        $contract->addRefundedAmount(10.00);

        $this->assertSame(49.99, $contract->getCapturedAmount());
        $this->assertSame(10.00, $contract->getRefundedAmount());
    }
}
