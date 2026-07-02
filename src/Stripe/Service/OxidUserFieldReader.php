<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Address;
use OxidEsales\Eshop\Application\Model\User;

/**
 * OXID-backed implementation of UserFieldReaderInterface.
 *
 * Reads field values from a live OXID User model (billing) and its selected
 * delivery Address (if one is active) via getFieldData(). The mapping from
 * logical names to OXID column names lives in the static MAP constant so there
 * is a single authoritative place to update if a column name ever changes.
 *
 * Registered in services.yaml as a non-shared (prototype) service because it
 * is bound to the specific User object injected at construction time.
 *
 * Sprint 119 Phase B (STRP-129).
 */
class OxidUserFieldReader implements UserFieldReaderInterface
{
    /**
     * Logical name → OXID column suffix (used for both oxuser__ and oxaddress__ prefixes).
     *
     * @var array<string, string>
     */
    private const MAP = [
        'firstName'     => 'oxfname',
        'lastName'      => 'oxlname',
        'additionalInfo' => 'oxaddinfo',
        'street'        => 'oxstreet',
        'houseNumber'   => 'oxstreetnr',
        'postalCode'    => 'oxzip',
        'city'          => 'oxcity',
        'company'       => 'oxcompany',
        'vatId'         => 'oxustid',
        'phone'         => 'oxfon',
        'cellPhone'     => 'oxprivfon',
        'personalPhone' => 'oxmobfon',
        'fax'           => 'oxfax',
    ];

    private ?Address $deliveryAddress = null;

    public function __construct(private readonly User $user)
    {
        /** @var object|null $address */
        $address = $user->getSelectedAddress();
        if ($address instanceof Address) {
            $this->deliveryAddress = $address;
        }
    }

    public function readBillingField(string $logicalName): string
    {
        $column = self::MAP[$logicalName] ?? null;
        if ($column === null) {
            return '';
        }

        /** @phpstan-ignore-next-line OXID core: getFieldData() on virtual User object */
        $value = $this->user->getFieldData($column);

        return is_string($value) ? $value : '';
    }

    public function hasDeliveryAddress(): bool
    {
        return $this->deliveryAddress !== null;
    }

    public function readDeliveryField(string $logicalName): string
    {
        $column = self::MAP[$logicalName] ?? null;
        if ($column === null || $this->deliveryAddress === null) {
            return '';
        }

        /** @phpstan-ignore-next-line OXID core: getFieldData() on virtual Address object */
        $value = $this->deliveryAddress->getFieldData($column);

        return is_string($value) ? $value : '';
    }

    /**
     * Returns the OXID column name for a logical field name, or null when unknown.
     * Used by UserDataValidator to populate oxidColumn on FieldValidationFailure.
     */
    public static function oxidColumn(string $logicalName): ?string
    {
        return self::MAP[$logicalName] ?? null;
    }
}
