<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use ErrorException;
use OxidEsales\Payments\Stripe\Adapter\Helper\StripeExceptionConverter;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;

/**
 * Sprint 148 (AW): the converter of every Stripe error for capture, refund and session calls had no
 * test, and two reads on a missing error object were in the PHPStan baseline.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(StripeExceptionConverter::class)]
final class StripeExceptionConverterTest extends TestCase
{
    public function testConvert_ForACardError_KeepsTheStripeCodeTheMessageAndTheType(): void
    {
        $error = CardException::factory('Your card was declined.', 402, null, [
            'error' => ['code' => 'card_declined', 'message' => 'Your card was declined.', 'type' => 'card_error'],
        ]);

        $converted = StripeExceptionConverter::convert($error);

        self::assertSame('stripe', $converted->getProviderName());
        self::assertSame('card_declined', $converted->getErrorCode());
        self::assertSame('Your card was declined.', $converted->getMessage());
        self::assertSame('card_error', $converted->getContext()['type']);
        self::assertSame($error, $converted->getPrevious());
    }

    public function testConvert_ForAnInvalidRequest_KeepsTheParameterName(): void
    {
        $error = InvalidRequestException::factory('No such payment_intent', 404, null, [
            'error' => [
                'code' => 'resource_missing',
                'message' => 'No such payment_intent',
                'type' => 'invalid_request_error',
                'param' => 'intent',
            ],
        ]);

        $converted = StripeExceptionConverter::convert($error);

        self::assertSame('resource_missing', $converted->getErrorCode());
        self::assertSame('intent', $converted->getContext()['param']);
    }

    public function testConvert_ForARateLimitError_KeepsTheCodeOfTheError(): void
    {
        $error = RateLimitException::factory('Too many requests', 429, null, [
            'error' => ['code' => 'rate_limit', 'message' => 'Too many requests', 'type' => 'invalid_request_error'],
        ]);

        self::assertSame('rate_limit', StripeExceptionConverter::convert($error)->getErrorCode());
    }

    /**
     * A connection error has no error object. The converter read `type` and `param` on null.
     */
    public function testConvert_ForAnErrorWithNoErrorObject_UsesUnknownErrorAndReadsNothingOnNull(): void
    {
        $error = ApiConnectionException::factory('Could not connect to Stripe');
        self::assertNull($error->getError());

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $converted = StripeExceptionConverter::convert($error);
        } finally {
            restore_error_handler();
        }

        self::assertSame('unknown_error', $converted->getErrorCode());
        self::assertSame('Could not connect to Stripe', $converted->getMessage());
        self::assertNull($converted->getContext()['type']);
        self::assertNull($converted->getContext()['param']);
    }
}
