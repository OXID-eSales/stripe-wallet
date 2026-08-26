<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Return;

use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnInputs;
use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnInputsResolver;
use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnRejection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The customer coming back from Stripe can be turned away for five different
 * reasons, and until now every one of them produced the same sentence —
 * "Payment verification failed" — with nothing in the log. A support request
 * about it was unanswerable.
 *
 * This resolver makes the decision explicit and nameable: the same generic
 * sentence still reaches the customer (the reason is nobody's business), but
 * the shop can now say which check refused.
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
        $outcome = $this->resolver->resolve(
            sessionId: 'cs_test_123',
            contractId: 'contract-1',
            contractToken: 'token-1',
            contractTokenValid: true
        );

        $this->assertInstanceOf(CheckoutReturnInputs::class, $outcome);
        $this->assertSame('cs_test_123', $outcome->sessionId);
        $this->assertSame('contract-1', $outcome->contractId);
        $this->assertSame('token-1', $outcome->contractToken);
    }

    public function testMissingSessionIdIsItsOwnReason(): void
    {
        $outcome = $this->resolver->resolve(
            sessionId: null,
            contractId: 'contract-1',
            contractToken: 'token-1',
            contractTokenValid: true
        );

        $this->assertSame(CheckoutReturnRejection::MissingSessionId, $outcome);
    }

    public function testEmptySessionIdCountsAsMissing(): void
    {
        $outcome = $this->resolver->resolve(
            sessionId: '',
            contractId: 'contract-1',
            contractToken: 'token-1',
            contractTokenValid: true
        );

        $this->assertSame(CheckoutReturnRejection::MissingSessionId, $outcome);
    }

    public function testMissingContractIdIsReported(): void
    {
        $outcome = $this->resolver->resolve(
            sessionId: 'cs_test_123',
            contractId: null,
            contractToken: 'token-1',
            contractTokenValid: true
        );

        $this->assertSame(CheckoutReturnRejection::MissingContractIdentifiers, $outcome);
    }

    public function testMissingContractTokenIsReported(): void
    {
        $outcome = $this->resolver->resolve(
            sessionId: 'cs_test_123',
            contractId: 'contract-1',
            contractToken: null,
            contractTokenValid: true
        );

        $this->assertSame(CheckoutReturnRejection::MissingContractIdentifiers, $outcome);
    }

    public function testInvalidTokenIsReported(): void
    {
        $outcome = $this->resolver->resolve(
            sessionId: 'cs_test_123',
            contractId: 'contract-1',
            contractToken: 'forged',
            contractTokenValid: false
        );

        $this->assertSame(CheckoutReturnRejection::InvalidContractToken, $outcome);
    }

    // ==========================================
    // ownership — replaces the old session-pointer comparison
    // ==========================================

    /**
     * A contract belonging to the shopper who is here may proceed, whichever of
     * the checkout paths created it. This is the case that used to fail: a
     * checkout that produced more than one contract left the session pointing at
     * one of them, and paying any other came back as "Payment verification
     * failed" — after the customer had been charged.
     */
    public function testContractOwnedByTheCurrentUserIsAccepted(): void
    {
        $this->assertNull($this->resolver->checkOwnership('user-1', 'user-1'));
    }

    /**
     * The binding that actually matters: someone else's contract is not yours to
     * complete, however well-formed the token is.
     */
    public function testContractOwnedBySomeoneElseIsRejected(): void
    {
        $this->assertSame(
            CheckoutReturnRejection::ContractMismatch,
            $this->resolver->checkOwnership('user-1', 'user-2')
        );
    }

    /**
     * No shopper in the session — nothing to compare against, and completing a
     * charged payment matters more than a check that cannot be made. The token
     * has already authenticated the contract id at this point.
     */
    public function testUnknownCurrentUserDoesNotBlockTheReturn(): void
    {
        $this->assertNull($this->resolver->checkOwnership('user-1', null));
        $this->assertNull($this->resolver->checkOwnership('user-1', ''));
    }

    public function testContractWithoutAnOwnerDoesNotBlockTheReturn(): void
    {
        $this->assertNull($this->resolver->checkOwnership(null, 'user-1'));
        $this->assertNull($this->resolver->checkOwnership('', 'user-1'));
    }
}
