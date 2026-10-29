<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigChange;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigChangeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StripeConfigChangeValidatorTest extends TestCase
{
    public function testAChangeWithoutProblems_IsAccepted(): void
    {
        $change = new StripeConfigChange(
            expectedVersion: 'v1',
            mode: 'live',
            captureMode: 'manual',
            logLevel: 'debug',
            testPublishableKey: 'pk_test_fakeAAAAAAAAAAbbbb',
            livePublishableKey: '',
            testToken: 'sk_test_fakeAAAAAAAAAAyyyy',
            livePlatformKey: 'rk_live_fakePLATFORMplatform',
            webhookSecret: 'whsec_AAAAAAAAAAAA',
        );

        self::assertNull((new StripeConfigChangeValidator())->validate($change));
    }

    /**
     * @return iterable<string, array{StripeConfigChange, string, string}>
     */
    public static function invalidChanges(): iterable
    {
        yield 'mode' => [new StripeConfigChange('v', mode: 'staging'), 'mode', 'not_allowed'];
        yield 'capture mode' => [new StripeConfigChange('v', captureMode: 'later'), 'captureMode', 'not_allowed'];
        yield 'log level' => [new StripeConfigChange('v', logLevel: 'verbose'), 'logLevel', 'not_allowed'];
        yield 'publishable key format' => [new StripeConfigChange('v', testPublishableKey: 'sk_test_fakeAAAAAAAAAAbbbb'), 'testPublishableKey', 'invalid_format'];
        yield 'live key in the test field' => [new StripeConfigChange('v', testPublishableKey: 'pk_live_fakeAAAAAAAAAAbbbb'), 'testPublishableKey', 'wrong_mode'];
        yield 'test token in the live field' => [new StripeConfigChange('v', liveToken: 'sk_test_fakeAAAAAAAAAAyyyy'), 'liveToken', 'wrong_mode'];
        yield 'token format' => [new StripeConfigChange('v', testToken: 'not a key'), 'testToken', 'invalid_format'];
        yield 'a publishable key as the token' => [new StripeConfigChange('v', testToken: 'pk_test_fakeAAAAAAAAAAbbbb'), 'testToken', 'invalid_format'];
        yield 'platform key format' => [new StripeConfigChange('v', livePlatformKey: 'whsec_AAAAAAAAAAAA'), 'livePlatformKey', 'invalid_format'];
        yield 'webhook secret format' => [new StripeConfigChange('v', webhookSecret: 'sk_test_fakeAAAAAAAAAAyyyy'), 'webhookSecret', 'invalid_format'];
    }

    #[DataProvider('invalidChanges')]
    public function testAnInvalidChange_IsRefusedAtItsField(StripeConfigChange $change, string $field, string $code): void
    {
        $refusal = (new StripeConfigChangeValidator())->validate($change);

        self::assertSame($field, $refusal?->field);
        self::assertSame($code, $refusal->code);
    }

    public function testAMessageNeverQuotesTheValue(): void
    {
        $refusal = (new StripeConfigChangeValidator())->validate(new StripeConfigChange('v', testToken: 'sk-LEAKED-secret-value'));

        self::assertStringNotContainsString('LEAKED', (string) $refusal?->message);
    }

    public function testAnEmptyPublishableKey_IsAllowedAndClearsIt(): void
    {
        self::assertNull((new StripeConfigChangeValidator())->validate(new StripeConfigChange('v', livePublishableKey: '')));
    }
}
