<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Logging;

use OxidEsales\PaymentBase\Logging\ThrowableSummaryExtractorInterface;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * VR-18 L4: what a log line may say about a Stripe API error - its type, its code, the request id Stripe support asks
 * for and the HTTP status. Never the message: it can echo what the request carried (an e-mail, a customer, a token).
 * The SDK exception is found behind the module's own wrapper (`StripeExceptionConverter` keeps it as `previous`).
 * Tagged `payment_base.throwable_summary`.
 */
final class StripeThrowableSummaryExtractor implements ThrowableSummaryExtractorInterface
{
    public function supports(Throwable $throwable): bool
    {
        return $this->apiErrorOf($throwable) !== null;
    }

    public function summarize(Throwable $throwable): array
    {
        $apiError = $this->apiErrorOf($throwable);
        if ($apiError === null) {
            return [];
        }

        return [
            'stripeType' => $apiError->getError()?->type,
            'stripeCode' => $apiError->getStripeCode(),
            'requestId' => $apiError->getRequestId(),
            'httpStatus' => $apiError->getHttpStatus(),
        ];
    }

    private function apiErrorOf(Throwable $throwable): ?ApiErrorException
    {
        for ($cause = $throwable; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ApiErrorException) {
                return $cause;
            }
        }

        return null;
    }
}
