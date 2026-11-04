<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\PaymentBase\Validation\User\FieldValidationFailure;
use OxidEsales\Payments\Stripe\Service\BuyabilityFailure;
use OxidEsales\Payments\Stripe\Service\LanguageTranslatorInterface;

/**
 * The JSON bodies with which the checkout session endpoint refuses a shopper: one `errors[]` row per problem, the
 * shape the frontend's validation box renders for both kinds. The controller owns the HTTP status and the echo.
 * Static pure functions, the idiom of ShopId.
 */
final class CheckoutRefusalPayloads
{
    private function __construct()
    {
    }

    /**
     * Body of the 422: structured field failures of the shopper's addresses.
     *
     * @param FieldValidationFailure[] $failures
     * @return array{valid: false, errors: list<array<string, string|null>>}
     */
    public static function userData(array $failures, ?MessageFormatterInterface $formatter): array
    {
        $errors = [];
        foreach ($failures as $failure) {
            $errors[] = [
                'field' => $failure->field,
                'code' => $failure->code,
                'char' => $failure->offendingChar,
                'addressKind' => $failure->addressKind,
                'message' => $formatter?->format($failure->field, $failure->code, $failure->offendingChar),
            ];
        }

        return ['valid' => false, 'errors' => $errors];
    }

    /**
     * Body of the 409: cart items that are no longer buyable.
     *
     * @param BuyabilityFailure[] $failures
     * @return array{valid: false, errors: list<array<string, string>>}
     */
    public static function buyability(array $failures, LanguageTranslatorInterface $translator): array
    {
        $errors = [];
        foreach ($failures as $failure) {
            $errors[] = [
                'code' => 'article_not_buyable',
                'articleId' => $failure->articleId,
                'productTitle' => $failure->productTitle,
                'message' => $translator->translateString($failure->reason),
            ];
        }

        return ['valid' => false, 'errors' => $errors];
    }
}
