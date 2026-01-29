<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\AbstractPaymentCaptureService;
use OxidEsales\PaymentComponent\Service\Exception\CaptureFailedException;
use Psr\Log\LoggerInterface;

/**
 * Stripe-specific implementation of payment capture service.
 *
 * Sprint 3: Extends AbstractPaymentCaptureService with Stripe-specific behavior:
 * - Validates AUTHORIZED state before capture (instead of COMMITTED)
 * - Calls captureAuthorization() after capture (instead of fulfill())
 *
 * Sprint 26: Uses LazyStripeAdapter for lazy adapter creation (module activation fix).
 *
 * This supports Stripe's delayed capture flow where:
 * 1. Payment is authorized (contract in AUTHORIZED state)
 * 2. Admin captures payment (transitions to READY_TO_COMMIT)
 * 3. Order processing commits (contract COMMITTED -> FULFILLED)
 *
 * @since 2.0.0
 */
class StripeCaptureService extends AbstractPaymentCaptureService
{
    /**
     * Validate contract state for Stripe capture.
     *
     * Stripe uses AUTHORIZED state for delayed capture flow,
     * unlike the default which uses COMMITTED state.
     *
     * @throws CaptureFailedException If contract is not in AUTHORIZED state
     */
    protected function validateStateForCapture(PaymentContractInterface $contract): void
    {
        if (!$contract->getState()->isAuthorized()) {
            throw new CaptureFailedException(
                $contract->getId() ?? 'unknown',
                'Contract must be in authorized state for Stripe capture'
            );
        }
    }

    /**
     * Post-capture hook for Stripe.
     *
     * Calls captureAuthorization() to transition contract from AUTHORIZED
     * to READY_TO_COMMIT state (not fulfill() like the default).
     */
    protected function afterCapture(PaymentContractInterface $contract, CaptureResponse $response): void
    {
        $contract->captureAuthorization();
        $this->contractRepository->save($contract);
    }
}
