<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Validates user billing and delivery address fields against the Stripe
 * per-plugin character-level rules via payment-base ValidationBase.
 *
 * Sprint 119 Phase B (STRP-129).
 */
interface UserDataValidatorInterface
{
    /**
     * Validates all billing and (if present) delivery address fields read
     * via the supplied field reader.
     *
     * Returns an empty array when all fields pass. Each element describes one
     * field-level violation including the address kind ('billing'|'delivery'),
     * the violation code, the offending character (if available), and the
     * corresponding OXID column name.
     *
     * The caller is responsible for constructing the appropriate
     * UserFieldReaderInterface implementation for the current request context
     * (e.g. OxidUserFieldReader wrapping the live User model).
     *
     * @return FieldValidationFailure[]
     */
    public function validateForUser(UserFieldReaderInterface $reader): array;

    /**
     * Validates a flat map of logical-name → value pairs.
     *
     * Used by the OPC path where the payment-base endpoint receives logical
     * field names directly from the posted form. No OXID column mapping is
     * available in this path; oxidColumn on returned failures will be null.
     *
     * @param array<string, string> $fields   Map of logical field name → raw value
     * @param string                $addressKind  'billing'|'delivery'
     * @return FieldValidationFailure[]
     */
    public function validateFieldMap(array $fields, string $addressKind = 'billing'): array;
}
