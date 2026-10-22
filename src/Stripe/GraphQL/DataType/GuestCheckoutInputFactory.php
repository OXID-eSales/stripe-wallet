<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\DataType;

use OxidEsales\PaymentBase\Checkout\Headless\GuestAddress;
use OxidEsales\PaymentBase\Checkout\Headless\GuestDetails;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\Payments\Stripe\GraphQL\Exception\StripeCheckoutError;
use TheCodingMachine\GraphQLite\Annotations\Factory;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * The `guest` argument of `stripeCheckoutStart`: who an anonymous shopper is,
 * as GraphQL input types that build payment-base's validated value objects.
 *
 *   GuestCheckoutInput { email, invoiceAddress: GuestAddressInput!, deliveryAddress: GuestAddressInput }
 *   GuestAddressInput  { firstName, lastName, street, streetNumber, zipCode, city, countryId,
 *                        salutation, company, additionalInfo, stateId, phone }
 *
 * The value objects validate on construction and throw HeadlessCheckoutException
 * (`guest_details_required`, naming the field, never the value). That happens
 * while GraphQLite resolves the arguments, before the controller body runs, so
 * the factories turn it into the client-safe StripeCheckoutError right here:
 * the same `extensions.errorCode` as every other refusal.
 *
 * The type names are schema-wide: another provider module that mirrors these
 * types must not be active in the same shop until the types live in
 * payment-base (see docs/for_developer/05-headless-graphql-checkout.md §3).
 *
 * @since 3.5.0
 */
final class GuestCheckoutInputFactory
{
    /**
     * @param ID $countryId the shop's country id (`countries { id }`)
     */
    #[Factory(name: 'GuestAddressInput')]
    public static function guestAddress(
        string $firstName,
        string $lastName,
        string $street,
        string $streetNumber,
        string $zipCode,
        string $city,
        ID $countryId,
        string $salutation = '',
        string $company = '',
        string $additionalInfo = '',
        ?ID $stateId = null,
        string $phone = '',
    ): GuestAddress {
        try {
            return new GuestAddress(
                $firstName,
                $lastName,
                $street,
                $streetNumber,
                $zipCode,
                $city,
                (string) $countryId,
                $salutation,
                $company,
                $additionalInfo,
                $stateId === null ? '' : (string) $stateId,
                $phone
            );
        } catch (HeadlessCheckoutException $refusal) {
            throw new StripeCheckoutError($refusal);
        }
    }

    #[Factory(name: 'GuestCheckoutInput')]
    public static function guestDetails(
        string $email,
        GuestAddress $invoiceAddress,
        ?GuestAddress $deliveryAddress = null,
    ): GuestDetails {
        try {
            return new GuestDetails($email, $invoiceAddress, $deliveryAddress);
        } catch (HeadlessCheckoutException $refusal) {
            throw new StripeCheckoutError($refusal);
        }
    }
}
