<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigChange;
use PHPUnit\Framework\TestCase;

final class StripeConfigChangeTest extends TestCase
{
    public function testFieldNames_ListsOnlyTheFieldsThatWereGiven(): void
    {
        $change = new StripeConfigChange(expectedVersion: 'v1', captureMode: 'manual', logWebhooks: false);

        self::assertSame(['captureMode', 'logWebhooks'], $change->fieldNames());
    }

    public function testASecretThatIsEmptyOrAbsentIsUnchanged(): void
    {
        $change = new StripeConfigChange(expectedVersion: 'v1', testToken: '', liveToken: null, webhookSecret: '');

        self::assertNull($change->tokenFor('test'));
        self::assertNull($change->tokenFor('live'));
        self::assertNull($change->webhookSecret());
        self::assertFalse($change->writesSecret());
        self::assertSame([], $change->fieldNames(), 'an empty secret is not a changed field');
    }

    public function testASecretThatIsGivenIsAChangedFieldByNameNeverByValue(): void
    {
        $change = new StripeConfigChange(
            expectedVersion: 'v1',
            liveToken: 'sk_live_AAAAAAAAAAvalue',
            livePlatformKey: 'sk_live_PLATFORMvalue',
            webhookSecret: 'whsec_value1234',
        );

        self::assertSame('sk_live_AAAAAAAAAAvalue', $change->tokenFor('live'));
        self::assertSame('sk_live_PLATFORMvalue', $change->platformKeyFor('live'));
        self::assertTrue($change->writesSecret());
        self::assertSame(['liveToken', 'livePlatformKey', 'webhookSecret'], $change->fieldNames());
        self::assertStringNotContainsString('value', implode(',', $change->fieldNames()));
    }

    public function testTouchesTheKeysOfAMode(): void
    {
        $change = new StripeConfigChange(expectedVersion: 'v1', livePublishableKey: 'pk_live_AAAAAAAAAAbbbb');

        self::assertTrue($change->touchesKeys('live'));
        self::assertFalse($change->touchesKeys('test'));
        self::assertTrue((new StripeConfigChange(expectedVersion: 'v1', testToken: 'sk_test_AAAAAAAAAAyyyy'))->touchesKeys('test'));
    }

    public function testAPlatformKeyAloneDoesNotTouchThePairOfKeys(): void
    {
        self::assertFalse((new StripeConfigChange(expectedVersion: 'v1', testPlatformKey: 'sk_test_PLATFORMplatform'))->touchesKeys('test'));
    }
}
