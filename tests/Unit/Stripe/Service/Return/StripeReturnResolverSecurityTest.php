<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Return;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Service\ReturnSecurityValidatorInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutReturnServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutReturnResult;
use OxidEsales\Payments\Stripe\Service\Result\SecurityValidationResult;
use OxidEsales\Payments\Stripe\Service\Return\StripeReturnResolver;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 133 · Story 8 (F5).
 *
 * ReturnSessionSecurityService scored the returning session, was bound in
 * services.yaml and had its own green unit suite — and no production caller
 * anywhere in extensions/ or modules/. A session-hijack defence that never ran,
 * invisible in CI precisely because its own tests passed.
 *
 * It is now wired advisory-first: the score is always recorded and logged, and
 * only rejects the return when a merchant opts in, because rejecting happens
 * after Stripe authorised the payment.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(StripeReturnResolver::class)]
final class StripeReturnResolverSecurityTest extends TestCase
{
    private function context(): EventContext
    {
        $context = new EventContext();
        $context->set('checkoutSessionId', 'cs_1');
        $context->set('contract_token', 'tok_1');
        $context->set('ip', '203.0.113.9');
        $context->set('user_agent', 'Mozilla/5.0');

        return $context;
    }

    private function successfulStripeReturn(): CheckoutReturnServiceInterface
    {
        $service = $this->createMock(CheckoutReturnServiceInterface::class);
        $service->method('validateReturn')->willReturn(
            CheckoutReturnResult::success('contract_1', 'pi_1', 2500, 'eur')
        );

        return $service;
    }

    private function contract(): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_1');

        return $contract;
    }

    private function validator(int $score, bool $allowed): ReturnSecurityValidatorInterface
    {
        $validator = $this->createMock(ReturnSecurityValidatorInterface::class);
        $validator->method('validateReturn')->willReturn(
            new SecurityValidationResult($score, ['ip_changed'], $allowed)
        );

        return $validator;
    }

    public function testTheValidatorIsActuallyInvoked(): void
    {
        $validator = $this->createMock(ReturnSecurityValidatorInterface::class);
        $validator->expects($this->once())
            ->method('validateReturn')
            ->willReturn(new SecurityValidationResult(100, [], true));

        $resolver = new StripeReturnResolver($this->successfulStripeReturn(), $validator);

        $resolver->resolve($this->contract(), $this->context());
    }

    public function testScoreAndWarningsAreRecordedOnTheContract(): void
    {
        $contract = $this->contract();
        $recorded = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function (string $key, mixed $value) use (&$recorded): void {
                $recorded[$key] = $value;
            });

        $resolver = new StripeReturnResolver($this->successfulStripeReturn(), $this->validator(45, false));
        $resolver->resolve($contract, $this->context());

        $this->assertSame(45, $recorded['return_security_score'] ?? null);
        $this->assertSame(['ip_changed'], $recorded['return_security_warnings'] ?? null);
    }

    public function testLowScoreDoesNotBlockTheReturnWhenEnforcementIsOff(): void
    {
        $resolver = new StripeReturnResolver(
            $this->successfulStripeReturn(),
            $this->validator(10, false),
            false
        );

        $resolution = $resolver->resolve($this->contract(), $this->context());

        // The customer already paid; advisory mode must not strand them.
        $this->assertNotSame('security_check_failed', $resolution->errorCode);
    }

    public function testLowScoreBlocksTheReturnWhenEnforcementIsOn(): void
    {
        $resolver = new StripeReturnResolver(
            $this->successfulStripeReturn(),
            $this->validator(10, false),
            true
        );

        $resolution = $resolver->resolve($this->contract(), $this->context());

        $this->assertSame('security_check_failed', $resolution->errorCode);
    }

    public function testAllowedScoreResolvesNormallyUnderEnforcement(): void
    {
        $resolver = new StripeReturnResolver(
            $this->successfulStripeReturn(),
            $this->validator(95, true),
            true
        );

        $resolution = $resolver->resolve($this->contract(), $this->context());

        $this->assertNotSame('security_check_failed', $resolution->errorCode);
    }
}
