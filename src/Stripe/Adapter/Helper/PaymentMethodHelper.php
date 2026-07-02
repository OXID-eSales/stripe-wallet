<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentMethodRequest;
use OxidEsales\PaymentBase\Adapter\Response\PaymentMethodResponse;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;
use Stripe\StripeClient;

/**
 * Helper for payment method and customer operations.
 *
 * Sprint 46: Extracted from StripeAdapter to reduce ECC.
 *
 * @since 2.0.0
 */
class PaymentMethodHelper
{
    public function createPaymentMethod(StripeClient $client, CreatePaymentMethodRequest $request): PaymentMethodResponse
    {
        try {
            $params = [
                'type' => self::mapPaymentMethodType($request->paymentMethod),
                'metadata' => $request->metadata,
            ];

            $params = array_merge($params, $request->paymentMethodData);

            if ($request->billingAddress !== null) {
                $params['billing_details'] = ['address' => $request->billingAddress];
            }

            $paymentMethod = $client->paymentMethods->create($params);

            if ($request->customerId !== null) {
                $client->paymentMethods->attach($paymentMethod->id, ['customer' => $request->customerId]);
            }

            /** @var array<string, mixed> $details */
            $details = self::extractPaymentMethodDetails($paymentMethod);
            /** @var array<string, mixed> $providerData */
            $providerData = $paymentMethod->toArray();

            return new PaymentMethodResponse(
                paymentMethodId: $paymentMethod->id,
                customerId: $request->customerId,
                type: $request->paymentMethod,
                details: $details,
                isDefault: false,
                createdAt: new DateTimeImmutable('@' . $paymentMethod->created),
                providerData: $providerData,
                metadata: $request->metadata
            );
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    /**
     * @return array<PaymentMethodResponse>
     */
    public function listPaymentMethods(StripeClient $client, string $customerId): array
    {
        try {
            $paymentMethods = $client->paymentMethods->all([
                'customer' => $customerId,
                'type' => 'card',
            ]);

            $result = [];
            foreach ($paymentMethods->data as $pm) {
                /** @var array<string, mixed> $details */
                $details = self::extractPaymentMethodDetails($pm);
                /** @var array<string, mixed> $providerData */
                $providerData = $pm->toArray();

                $result[] = new PaymentMethodResponse(
                    paymentMethodId: $pm->id,
                    customerId: $customerId,
                    type: 'card',
                    details: $details,
                    isDefault: false,
                    createdAt: new DateTimeImmutable('@' . $pm->created),
                    providerData: $providerData
                );
            }

            return $result;
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    public function deletePaymentMethod(StripeClient $client, string $paymentMethodId): bool
    {
        try {
            $client->paymentMethods->detach($paymentMethodId);
            return true;
        } catch (ApiErrorException $e) {
            throw StripeExceptionConverter::convert($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractPaymentMethodDetails(PaymentMethod $pm): array
    {
        if ($pm->type === 'card' && $pm->card) {
            return [
                'last4' => $pm->card->last4,
                'brand' => $pm->card->brand,
                'exp_month' => $pm->card->exp_month,
                'exp_year' => $pm->card->exp_year,
                'funding' => $pm->card->funding,
            ];
        }
        return [];
    }

    private static function mapPaymentMethodType(string $genericType): string
    {
        return match ($genericType) {
            'card' => 'card',
            'sepa_debit' => 'sepa_debit',
            'sepa' => 'sepa_debit',
            'paypal' => 'paypal',
            default => $genericType,
        };
    }
}
