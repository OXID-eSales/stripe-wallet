<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\DataType;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigSnapshot;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * The Stripe settings of the shop. Secrets are write-only: the `*Set` flags say whether one is stored, and no field
 * carries its value, ever. `version` is the token `adminStripeConfigModify` wants back (`expectedVersion`), so that
 * two admins do not overwrite each other.
 *
 * The getters carry the GraphQL field names (graphqlite strips get/is).
 */
#[Type(name: 'AdminStripeConfig')]
final class AdminStripeConfig
{
    public function __construct(private readonly StripeConfigSnapshot $config)
    {
    }

    /** `test` or `live`: the credentials the shop uses. */
    #[Field]
    public function getMode(): string
    {
        return $this->config->mode;
    }

    /** `automatic` (capture at checkout) or `manual` (authorise now, capture from the Payment tab). */
    #[Field]
    public function getCaptureMode(): string
    {
        return $this->config->captureMode;
    }

    #[Field]
    public function isRemoveByBillingCountry(): bool
    {
        return $this->config->removeByBillingCountry;
    }

    #[Field]
    public function isRemoveByBasketCurrency(): bool
    {
        return $this->config->removeByBasketCurrency;
    }

    #[Field]
    public function isProvideCustomerEmail(): bool
    {
        return $this->config->provideCustomerEmail;
    }

    /** `off`, `errors`, `normal` or `debug`. */
    #[Field]
    public function getLogLevel(): string
    {
        return $this->config->logLevel;
    }

    #[Field]
    public function isLogWebhooks(): bool
    {
        return $this->config->logWebhooks;
    }

    #[Field]
    public function getTestPublishableKey(): string
    {
        return $this->config->testPublishableKey;
    }

    #[Field]
    public function getLivePublishableKey(): string
    {
        return $this->config->livePublishableKey;
    }

    /** The URL Stripe has to deliver this shop's events to. */
    #[Field]
    public function getWebhookEndpointUrl(): string
    {
        return $this->config->webhookEndpointUrl;
    }

    /**
     * The classic admin's settings page of this module for the current shop: the Connect onboarding of Stripe runs
     * there. No secret and no session token in it.
     */
    #[Field]
    public function getClassicSettingsUrl(): string
    {
        return $this->config->classicSettingsUrl;
    }

    /** The connected account's secret key of the test mode is stored. */
    #[Field]
    public function isTestTokenSet(): bool
    {
        return $this->config->testTokenSet;
    }

    #[Field]
    public function isLiveTokenSet(): bool
    {
        return $this->config->liveTokenSet;
    }

    /** The platform secret key (webhook registration for Connect) of the test mode is stored. */
    #[Field]
    public function isTestPlatformKeySet(): bool
    {
        return $this->config->testPlatformKeySet;
    }

    #[Field]
    public function isLivePlatformKeySet(): bool
    {
        return $this->config->livePlatformKeySet;
    }

    /** The webhook signing secret of the mode in use is stored (deliveries can be verified). */
    #[Field]
    public function isWebhookSecretSet(): bool
    {
        return $this->config->webhookSecretSet;
    }

    /** The test mode has a secret key and a publishable key. */
    #[Field]
    public function isTestConnected(): bool
    {
        return $this->config->testConnected;
    }

    #[Field]
    public function isLiveConnected(): bool
    {
        return $this->config->liveConnected;
    }

    /** Why the keys of the mode in use do not work together; null when they do. Never quotes a key. */
    #[Field]
    public function getKeyValidationError(): ?string
    {
        return $this->config->keyValidationError;
    }

    #[Field]
    public function getVersion(): string
    {
        return $this->config->version();
    }
}
