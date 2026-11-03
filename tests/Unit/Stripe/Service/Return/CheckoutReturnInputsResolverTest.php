<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Return;

use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnInputs;
use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnInputsResolver;
use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnRejection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The customer coming back from Stripe can be turned away for several reasons, and until the log named them every one
 * produced the same sentence - "Payment verification failed" - with nothing in the log.
 *
 * VR-20 R5: this resolver only checks that the return names a Stripe session and a contract. Who may come back for
 * that contract - a valid return token, or the shopper in the session that started the payment - is payment-base's
 * ReturnAccessGuard's decision, made once the contract is loaded. A return without a token is therefore not refused
 * here: the shopper's own session may open it.
 */
#[CoversClass(CheckoutReturnInputsResolver::class)]
final class CheckoutReturnInputsResolverTest extends TestCase
{
    private CheckoutReturnInputsResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CheckoutReturnInputsResolver();
    }

    public function testCompleteReturnIsAccepted(): void
    {
        $outcome = $this->resolver->resolve(sessionId: 'cs_test_123', contractId: 'contract-1', contractToken: 'token-1');

        $this->assertInstanceOf(CheckoutReturnInputs::class, $outcome);
        $this->assertSame('cs_test_123', $outcome->sessionId);
        $this->assertSame('contract-1', $outcome->contractId);
        $this->assertSame('token-1', $outcome->contractToken);
    }

    public function testAReturnWithoutATokenIsAcceptedForTheGuardToJudge(): void
    {
        $outcome = $this->resolver->resolve(sessionId: 'cs_test_123', contractId: 'contract-1', contractToken: null);

        $this->assertInstanceOf(CheckoutReturnInputs::class, $outcome);
        $this->assertSame('', $outcome->contractToken);
    }

    public function testMissingSessionIdIsItsOwnReason(): void
    {
        $this->assertSame(
            CheckoutReturnRejection::MissingSessionId,
            $this->resolver->resolve(sessionId: null, contractId: 'contract-1', contractToken: 'token-1')
        );
        $this->assertSame(
            CheckoutReturnRejection::MissingSessionId,
            $this->resolver->resolve(sessionId: '', contractId: 'contract-1', contractToken: 'token-1')
        );
    }

    public function testMissingContractIdIsReported(): void
    {
        $this->assertSame(
            CheckoutReturnRejection::MissingContractIdentifiers,
            $this->resolver->resolve(sessionId: 'cs_1', contractId: null, contractToken: 'token-1')
        );
        $this->assertSame(
            CheckoutReturnRejection::MissingContractIdentifiers,
            $this->resolver->resolve(sessionId: 'cs_1', contractId: '', contractToken: 'token-1')
        );
    }

    public function testTheSessionIsCheckedBeforeTheContract(): void
    {
        $this->assertSame(
            CheckoutReturnRejection::MissingSessionId,
            $this->resolver->resolve(sessionId: null, contractId: null, contractToken: null)
        );
    }
}
