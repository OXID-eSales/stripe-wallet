<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Payments\Stripe\Admin\AdminRefusalSentence;
use OxidEsales\Payments\Stripe\Admin\AmountValidationResult;
use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminRefusalSentenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function failures(): iterable
    {
        yield 'malformed' => ['captureAmount', AmountValidationResult::CODE_MALFORMED, 'amount_malformed', 'The amount is not a valid number.'];
        yield 'not positive' => ['refundAmount', AmountValidationResult::CODE_NOT_POSITIVE, 'amount_not_positive', 'The amount must be greater than zero.'];
        yield 'precision' => ['refundAmount', AmountValidationResult::CODE_PRECISION, 'amount_precision', 'The amount has more decimal places than the currency allows.'];
        yield 'exceeds capture' => ['captureAmount', AmountValidationResult::CODE_EXCEEDS_BOUND, 'amount_exceeds_bound', 'The amount is higher than what can still be captured.'];
        yield 'exceeds refund' => ['refundAmount', AmountValidationResult::CODE_EXCEEDS_BOUND, 'amount_exceeds_bound', 'The amount is higher than what can still be refunded.'];
        yield 'unavailable' => ['captureAmount', AmountValidationResult::CODE_BOUND_UNAVAILABLE, 'amount_bound_unavailable', 'Stripe could not be reached to check the amount.'];
        yield 'capture reason' => ['captureReason', 'blockedCharacter', 'blocked_character', 'The capture reason contains characters that are not allowed.'];
        yield 'description' => ['refundDescription', 'blockedCharacter', 'blocked_character', 'The description contains characters that are not allowed.'];
    }

    #[DataProvider('failures')]
    public function testCodeAndSentenceOfTheFirstFailure(string $field, string $code, string $expectedCode, string $expectedSentence): void
    {
        $failure = new FieldValidationFailure($field, 'admin', $code, '<', null);

        self::assertSame($expectedCode, AdminRefusalSentence::codeOf($failure));
        self::assertSame($expectedSentence, AdminRefusalSentence::of($failure));
        self::assertStringNotContainsString('<', AdminRefusalSentence::of($failure), 'never the offending value');
    }
}
