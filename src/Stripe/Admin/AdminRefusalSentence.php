<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;

/**
 * The machine code and the one sentence an admin API caller gets when the Payment tab's gate refuses an action. A
 * sentence names the field and the rule, never the offending value, an id or a key.
 */
final class AdminRefusalSentence
{
    private const AMOUNT_SENTENCES = [
        AmountValidationResult::CODE_MALFORMED => 'The amount is not a valid number.',
        AmountValidationResult::CODE_NOT_POSITIVE => 'The amount must be greater than zero.',
        AmountValidationResult::CODE_PRECISION => 'The amount has more decimal places than the currency allows.',
        AmountValidationResult::CODE_BOUND_UNAVAILABLE => 'Stripe could not be reached to check the amount.',
    ];
    private const TEXT_LABELS = [
        'captureReason' => 'capture reason',
        'refundDescription' => 'description',
    ];

    public static function codeOf(FieldValidationFailure $failure): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $failure->code));
    }

    public static function of(FieldValidationFailure $failure): string
    {
        if ($failure->code === AmountValidationResult::CODE_EXCEEDS_BOUND) {
            $verb = $failure->field === 'captureAmount' ? 'captured' : 'refunded';

            return sprintf('The amount is higher than what can still be %s.', $verb);
        }
        $amountSentence = self::AMOUNT_SENTENCES[$failure->code] ?? null;
        if ($amountSentence !== null) {
            return $amountSentence;
        }
        $label = self::TEXT_LABELS[$failure->field] ?? 'text';

        return sprintf('The %s contains characters that are not allowed.', $label);
    }
}
