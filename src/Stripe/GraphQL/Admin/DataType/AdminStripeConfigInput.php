<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\DataType;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigChange;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Input;

/**
 * A field that is not given stays as it is. The secrets (`testToken`, `liveToken`, `testPlatformKey`,
 * `livePlatformKey`, `webhookSecret`) are write-only: an empty or missing one is left unchanged. The webhook
 * endpoint itself is registered with `adminStripeWebhookRegister`, not edited here.
 */
#[Input]
final class AdminStripeConfigInput
{
    /** The `version` of the configuration the admin edited. */
    #[Field]
    public string $expectedVersion;

    #[Field]
    public ?string $mode = null;

    #[Field]
    public ?string $captureMode = null;

    #[Field]
    public ?bool $removeByBillingCountry = null;

    #[Field]
    public ?bool $removeByBasketCurrency = null;

    #[Field]
    public ?bool $provideCustomerEmail = null;

    #[Field]
    public ?string $logLevel = null;

    #[Field]
    public ?bool $logWebhooks = null;

    #[Field]
    public ?string $testPublishableKey = null;

    #[Field]
    public ?string $livePublishableKey = null;

    #[Field]
    public ?string $testToken = null;

    #[Field]
    public ?string $liveToken = null;

    #[Field]
    public ?string $testPlatformKey = null;

    #[Field]
    public ?string $livePlatformKey = null;

    #[Field]
    public ?string $webhookSecret = null;

    public function toChange(): StripeConfigChange
    {
        return new StripeConfigChange(
            expectedVersion: $this->expectedVersion,
            mode: $this->mode,
            captureMode: $this->captureMode,
            removeByBillingCountry: $this->removeByBillingCountry,
            removeByBasketCurrency: $this->removeByBasketCurrency,
            provideCustomerEmail: $this->provideCustomerEmail,
            logLevel: $this->logLevel,
            logWebhooks: $this->logWebhooks,
            testPublishableKey: $this->testPublishableKey,
            livePublishableKey: $this->livePublishableKey,
            testToken: $this->testToken,
            liveToken: $this->liveToken,
            testPlatformKey: $this->testPlatformKey,
            livePlatformKey: $this->livePlatformKey,
            webhookSecret: $this->webhookSecret,
        );
    }
}
