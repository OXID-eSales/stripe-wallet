<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitStoreInterface;
use OxidEsales\PaymentBase\Webhook\Guard\HttpsGuard;
use OxidEsales\PaymentBase\Webhook\Guard\IpAllowlistGuard;
use OxidEsales\PaymentBase\Webhook\Guard\PayloadSizeGuard;
use OxidEsales\PaymentBase\Webhook\Guard\RateLimitGuard;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardChain;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookHttpRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * VR-19 W4: services.yaml wires Stripe's webhook onto payment-base's shared
 * guards. The numbers are Stripe's own (64 KB, 100 per 60 s), so they are
 * pinned here rather than inherited from payment-base's defaults.
 */
final class WebhookGuardChainWiringTest extends TestCase
{
    private const SERVICES_FILE = __DIR__ . '/../../../../../services.yaml';

    /** @return array<string, mixed> */
    private function service(string $id): array
    {
        $parsed = Yaml::parseFile(self::SERVICES_FILE, Yaml::PARSE_CUSTOM_TAGS);
        $service = $parsed['services'][$id] ?? null;
        self::assertIsArray($service, $id . ' must be defined in services.yaml');

        return $service;
    }

    #[Test]
    public function theChainIsPaymentBasesChainInCheapestFirstOrder(): void
    {
        $chain = $this->service('stripe.webhook.guard_chain');

        self::assertSame(WebhookGuardChain::class, $chain['class']);
        self::assertTrue($chain['public'], 'the controller fetches the chain from the container');
        self::assertSame(
            [
                '@stripe.webhook.guard.payload_size',
                '@stripe.webhook.guard.https',
                '@stripe.webhook.guard.ip_allowlist',
                '@stripe.webhook.guard.rate_limit',
            ],
            $chain['arguments']['$guards']
        );
    }

    #[Test]
    public function payloadsAreCappedAtSixtyFourKilobytes(): void
    {
        $guard = $this->service('stripe.webhook.guard.payload_size');

        self::assertSame(PayloadSizeGuard::class, $guard['class']);
        self::assertSame(65536, $guard['arguments']['$maxBytes']);
    }

    #[Test]
    public function httpsIsEnforcedWithoutADevelopmentSwitch(): void
    {
        $guard = $this->service('stripe.webhook.guard.https');

        self::assertSame(HttpsGuard::class, $guard['class']);
        self::assertArrayNotHasKey('$allowHttpForDevelopment', $guard['arguments'] ?? []);
    }

    #[Test]
    public function theIpAllowListIsEmptyByDefault(): void
    {
        $guard = $this->service('stripe.webhook.guard.ip_allowlist');

        self::assertSame(IpAllowlistGuard::class, $guard['class']);
        self::assertSame([], $guard['arguments']['$allowedCidrs']);
    }

    #[Test]
    public function theRateLimitIsHundredPerMinuteForProviderStripeOnTheSharedStore(): void
    {
        $guard = $this->service('stripe.webhook.guard.rate_limit');

        self::assertSame(RateLimitGuard::class, $guard['class']);
        self::assertSame('stripe', $guard['arguments']['$provider']);
        self::assertSame(100, $guard['arguments']['$limitPerWindow']);
        self::assertSame(60, $guard['arguments']['$windowSeconds']);
        self::assertSame(
            '@OxidEsales\PaymentBase\Validation\RateLimit\RateLimitStoreInterface',
            $guard['arguments']['$store']
        );
    }

    #[Test]
    public function theModulesOwnGuardServicesAreGone(): void
    {
        $parsed = Yaml::parseFile(self::SERVICES_FILE, Yaml::PARSE_CUSTOM_TAGS);

        self::assertArrayNotHasKey('stripe.webhook.rate_limiter', $parsed['services']);
        foreach (array_keys($parsed['services']) as $id) {
            self::assertStringNotContainsString('Controller\\Webhook\\WebhookGuard', (string) $id);
            self::assertStringNotContainsString('Controller\\Webhook\\WebhookRequestGuard', (string) $id);
        }
    }

    #[Test]
    public function aHundredAndFirstRequestInTheWindowIsRefused(): void
    {
        $guard = new RateLimitGuard($this->countingStore(), 'stripe', 100, 60);
        $request = new WebhookHttpRequest('https', '203.0.113.7', 10, '{}');

        for ($call = 1; $call <= 100; $call++) {
            self::assertTrue($guard->check($request)->ok);
        }
        $refused = $guard->check($request);

        self::assertFalse($refused->ok);
        self::assertSame(429, $refused->httpStatus);
    }

    /**
     * A store that counts per key and never expires: the window never elapses inside one test.
     */
    private function countingStore(): RateLimitStoreInterface
    {
        return new class implements RateLimitStoreInterface {
            /** @var array<string, int> */
            private array $counts = [];

            public function increment(string $key, int $ttlSeconds): int
            {
                $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

                return $this->counts[$key];
            }
        };
    }
}
