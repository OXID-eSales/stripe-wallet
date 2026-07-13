<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Validation\ValidationBaseFactory;
use OxidEsales\PaymentBase\Validation\ValidationBaseInterface;

/**
 * Stripe-side facade that maps OXID user/address fields to logical names and
 * delegates character-level validation to payment-base ValidationBase.
 *
 * Two public entry-points:
 *   - validateForUser(UserFieldReaderInterface)  — standard checkout; reads via the reader
 *   - validateFieldMap(array, string)            — OPC path; flat logical-name → value map
 *
 * The UserFieldReaderInterface is passed as a method argument (not constructor)
 * so UserDataValidator can be a stateless singleton in the DI container while
 * the reader is request-scoped (bound to the live User object in Phase C).
 *
 * Sprint 119 Phase B (STRP-129).
 */
class UserDataValidator implements UserDataValidatorInterface
{
    /** Logical field names validated for both billing and delivery passes. */
    private const LOGICAL_FIELDS = [
        'firstName', 'lastName', 'additionalInfo', 'street', 'houseNumber',
        'postalCode', 'city', 'company', 'vatId', 'phone', 'cellPhone',
        'personalPhone', 'fax',
    ];

    public function __construct(
        private readonly ValidationBaseFactory $factory,
    ) {
    }

    /**
     * @return FieldValidationFailure[]
     */
    public function validateForUser(UserFieldReaderInterface $reader): array
    {
        $failures = $this->validateAddressPass($reader, 'billing');

        if ($reader->hasDeliveryAddress()) {
            $failures = array_merge($failures, $this->validateAddressPass($reader, 'delivery'));
        }

        return $failures;
    }

    /**
     * @param array<string, string> $fields
     * @return FieldValidationFailure[]
     */
    public function validateFieldMap(array $fields, string $addressKind = 'billing'): array
    {
        $failures = [];

        foreach ($fields as $logicalName => $value) {
            $failure = $this->validateSingleField($logicalName, $value, $addressKind, null);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /** @return FieldValidationFailure[] */
    private function validateAddressPass(UserFieldReaderInterface $reader, string $addressKind): array
    {
        $failures = [];

        foreach (self::LOGICAL_FIELDS as $logicalName) {
            $value = $addressKind === 'billing'
                ? $reader->readBillingField($logicalName)
                : $reader->readDeliveryField($logicalName);

            $oxidColumn = OxidUserFieldReader::oxidColumn($logicalName);
            $failure = $this->validateSingleField($logicalName, $value, $addressKind, $oxidColumn);

            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    private function validateSingleField(
        string $logicalName,
        string $value,
        string $addressKind,
        ?string $oxidColumn,
    ): ?FieldValidationFailure {
        if ($value === '') {
            return null;
        }

        $validationBase = $this->factory->create('oe_payments_stripe_wallet');
        $result = $validationBase->validateField($logicalName, $value);

        if ($result->valid) {
            return null;
        }

        return new FieldValidationFailure(
            field: $logicalName,
            addressKind: $addressKind,
            code: (string) $result->code,
            offendingChar: $result->offendingChar,
            oxidColumn: $oxidColumn,
        );
    }
}
