<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Narrow seam for reading user/address field values by logical name.
 *
 * Decouples UserDataValidator from the concrete OXID User / Address model
 * so both can be tested without booting the OXID shop. The production
 * implementation reads from an actual User object via getFieldData();
 * tests inject a stub.
 *
 * Used by UserDataValidator for both the standard-checkout path (reads from
 * a live User model) and by Phase D OPC seam (reads from the posted field map).
 *
 * Logical field names match §4.8 of the sprint plan:
 *   firstName, lastName, additionalInfo, street, houseNumber, postalCode,
 *   city, company, vatId, phone, cellPhone, personalPhone, fax
 *
 * Sprint 119 Phase B (STRP-129).
 */
interface UserFieldReaderInterface
{
    /**
     * Returns the billing-address value for the given logical field name.
     * Returns an empty string when the field has no value.
     */
    public function readBillingField(string $logicalName): string;

    /**
     * Returns true when the user has a delivery address selected.
     */
    public function hasDeliveryAddress(): bool;

    /**
     * Returns the delivery-address value for the given logical field name.
     * Returns an empty string when the field has no value.
     * Must only be called when hasDeliveryAddress() returns true.
     */
    public function readDeliveryField(string $logicalName): string;
}
