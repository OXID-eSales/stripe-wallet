<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;
use RuntimeException;

/**
 * Thrown by StripeOrderController::buildCheckoutEventContext() when user-data
 * character-level validation fails.
 *
 * Carries the structured failure list so the catching code in
 * createCheckoutSession() can emit the 422 JSON response without repeating
 * the validation call.
 *
 * Sprint 119 Phase C (STRP-129).
 */
class UserDataValidationException extends RuntimeException
{
    /** @var FieldValidationFailure[] */
    private readonly array $failures;

    /**
     * @param FieldValidationFailure[] $failures Non-empty list of field violations.
     */
    public function __construct(array $failures)
    {
        parent::__construct('User data validation failed');
        $this->failures = $failures;
    }

    /**
     * @return FieldValidationFailure[]
     */
    public function getFailures(): array
    {
        return $this->failures;
    }
}
