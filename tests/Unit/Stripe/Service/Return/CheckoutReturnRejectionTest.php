<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Return;

use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnRejection;
use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessRejection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Two audiences, two texts: the customer gets a sentence that gives nothing
 * away, the shop log gets the reason. Neither may leak into the other.
 */
#[CoversClass(CheckoutReturnRejection::class)]
final class CheckoutReturnRejectionTest extends TestCase
{
    public function testMissingSessionIdKeepsItsOwnCustomerMessage(): void
    {
        $this->assertSame(
            'Payment information missing',
            CheckoutReturnRejection::MissingSessionId->customerMessage()
        );
    }

    public function testEveryOtherReasonSharesTheGenericCustomerMessage(): void
    {
        foreach (
            [
                CheckoutReturnRejection::MissingContractIdentifiers,
                CheckoutReturnRejection::AccessDenied,
                CheckoutReturnRejection::TokenExpired,
                CheckoutReturnRejection::ProviderMismatch,
                CheckoutReturnRejection::ContractNotFound,
                CheckoutReturnRejection::NoOrderCreated,
            ] as $rejection
        ) {
            $this->assertSame(
                'Payment verification failed',
                $rejection->customerMessage(),
                $rejection->name . ' must not tell the customer which check refused'
            );
        }
    }

    /**
     * VR-20 R5: every way payment-base's guard refuses a return has a Stripe reason of its own.
     */
    public function testEveryGuardRefusalMapsToItsOwnReason(): void
    {
        $this->assertSame(CheckoutReturnRejection::ContractNotFound, CheckoutReturnRejection::fromGuard(ReturnAccessRejection::ContractNotFound));
        $this->assertSame(CheckoutReturnRejection::ProviderMismatch, CheckoutReturnRejection::fromGuard(ReturnAccessRejection::ProviderMismatch));
        $this->assertSame(CheckoutReturnRejection::TokenExpired, CheckoutReturnRejection::fromGuard(ReturnAccessRejection::TokenExpired));
        $this->assertSame(CheckoutReturnRejection::AccessDenied, CheckoutReturnRejection::fromGuard(ReturnAccessRejection::AccessDenied));
    }

    /**
     * The log line is the whole point of this type — every reason needs one,
     * and it has to differ from every other, or the log cannot tell them apart.
     */
    public function testEveryReasonHasItsOwnDistinctLogReason(): void
    {
        $reasons = array_map(
            static fn (CheckoutReturnRejection $r): string => $r->logReason(),
            CheckoutReturnRejection::cases()
        );

        $this->assertNotContains('', $reasons);
        $this->assertSameSize($reasons, array_unique($reasons));
    }
}
