<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Support;

use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScope;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Controller\SessionWriterInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use Psr\Log\NullLogger;

/**
 * Builds payment-base's CheckoutReturnResponder for controller tests that run inside an anonymous
 * controller subclass (where $this->createMock() is not available). The responder's collaborators
 * beyond dispatcher and session writer matter only on the stale-contract reload path, which these
 * tests do not take: the contract repository is an inert double and the headless scope is inactive.
 */
final class CheckoutReturnResponderFactory
{
    public static function create(
        EventDispatcherInterface $dispatcher,
        SessionWriterInterface $sessionWriter
    ): CheckoutReturnResponder {
        return new CheckoutReturnResponder(
            $dispatcher,
            $sessionWriter,
            new NullLogger(),
            self::inertContractRepository(),
            new HeadlessCheckoutScope(),
            new ThrowableSummary([])
        );
    }

    private static function inertContractRepository(): ContractRepositoryInterface
    {
        $doubleFactory = new class ('inert') extends \PHPUnit\Framework\TestCase {
            public function repository(): ContractRepositoryInterface
            {
                return $this->createStub(ContractRepositoryInterface::class);
            }
        };

        return $doubleFactory->repository();
    }
}
