<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Core\WebhookEndpointKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookEndpointKeys::class)]
final class WebhookEndpointKeysTest extends TestCase
{
    #[Test]
    public function theEndpointIdIsStoredPerMode(): void
    {
        $this->assertSame('sStripeWebhookEndpointIdTest', WebhookEndpointKeys::endpointId(StripeDefinitions::MODE_TEST));
        $this->assertSame('sStripeWebhookEndpointIdLive', WebhookEndpointKeys::endpointId(StripeDefinitions::MODE_LIVE));
    }

    #[Test]
    public function theSigningSecretIsStoredPerMode(): void
    {
        $this->assertSame('sStripeWebhookEndpointSecretTest', WebhookEndpointKeys::secret(StripeDefinitions::MODE_TEST));
        $this->assertSame('sStripeWebhookEndpointSecretLive', WebhookEndpointKeys::secret(StripeDefinitions::MODE_LIVE));
    }

    #[Test]
    public function anyModeButLiveIsTest(): void
    {
        $this->assertSame('sStripeWebhookEndpointSecretTest', WebhookEndpointKeys::secret('anything'));
    }

    #[Test]
    public function theOtherModeIsTheOneNotGiven(): void
    {
        $this->assertSame(StripeDefinitions::MODE_LIVE, WebhookEndpointKeys::otherMode(StripeDefinitions::MODE_TEST));
        $this->assertSame(StripeDefinitions::MODE_TEST, WebhookEndpointKeys::otherMode(StripeDefinitions::MODE_LIVE));
    }

    #[Test]
    public function theLegacyModeAgnosticSecretHasItsOwnSetting(): void
    {
        $this->assertSame('sStripeWebhookEndpointSecret', WebhookEndpointKeys::LEGACY_SECRET);
        $this->assertSame('sStripeWebhookEndpoint', WebhookEndpointKeys::LEGACY_URL);
    }
}
