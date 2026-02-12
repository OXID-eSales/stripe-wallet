<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

interface SptPaymentServiceInterface
{
    /**
     * Confirm a PaymentIntent using a Shared Payment Token.
     *
     * @param PaymentContractInterface $contract The contract to pay for
     * @param string $sptToken Granted SPT token (spt_granted_*)
     * @param array<string, mixed> $billingAddress Optional billing address
     */
    public function confirmWithSpt(
        PaymentContractInterface $contract,
        string $sptToken,
        array $billingAddress = []
    ): SptPaymentResult;
}
