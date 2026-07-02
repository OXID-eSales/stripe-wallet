<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Immutable value object representing a single field-validation failure.
 *
 * Carries the logical field name, whether the failure came from the billing
 * or delivery address, the violation code from FieldValidationResult, the
 * offending character (if available), and the corresponding OXID column name
 * (null when the failure originates from the OPC path where fields are posted
 * by logical name rather than read from a User/Address model).
 *
 * Sprint 119 Phase B (STRP-129).
 */
class FieldValidationFailure
{
    public function __construct(
        public readonly string $field,
        public readonly string $addressKind,
        public readonly string $code,
        public readonly ?string $offendingChar,
        public readonly ?string $oxidColumn,
    ) {
    }
}
