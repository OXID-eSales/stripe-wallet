<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support;

use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrarInterface;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrationResult;

/**
 * Stands in for Stripe: no test may reach the real API.
 */
final class ScriptedWebhookRegistrar implements WebhookEndpointRegistrarInterface
{
    /** @var list<array<string, mixed>> */
    public array $registered = [];
    /** @var list<array{string, ?string}> */
    public array $cleared = [];

    public function __construct(
        private readonly ?WebhookEndpointRegistrationResult $result = null,
        private readonly ?WebhookRegistrationException $failure = null,
        private readonly int $deleted = 2,
    ) {
    }

    public function register(
        string $accessToken,
        string $webhookUrl,
        ?string $existingEndpointId,
        bool $isConnect = false,
        string $description = ''
    ): WebhookEndpointRegistrationResult {
        throw new \LogicException('the admin API registers through registerForShop');
    }

    public function clearAll(string $accessToken, ?string $urlFilter = null): int
    {
        $this->cleared[] = [$accessToken, $urlFilter];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->deleted;
    }

    public function registerForShop(
        string $accountKey,
        string $platformKey,
        string $webhookUrl,
        ?string $existingEndpointId,
        string $description = ''
    ): WebhookEndpointRegistrationResult {
        $this->registered[] = [
            'accountKey' => $accountKey,
            'platformKey' => $platformKey,
            'url' => $webhookUrl,
            'existing' => $existingEndpointId,
            'description' => $description,
        ];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result ?? new WebhookEndpointRegistrationResult('we_new', 'whsec_brandnewsecret1');
    }
}
