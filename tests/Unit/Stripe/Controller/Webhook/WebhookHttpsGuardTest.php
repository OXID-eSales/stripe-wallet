<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookHttpsGuard;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 67b: M6 — HTTPS enforcement on webhook endpoints.
 *
 */
#[CoversClass(\OxidEsales\Payments\Stripe\Controller\Webhook\WebhookHttpsGuard::class)]
    #[Group('sprint-67b')]
    #[Group('security')]
final class WebhookHttpsGuardTest extends TestCase
{
    public function testGuardAllowsHttpsRequest(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: false);
        $guard->setServerVars(['HTTPS' => 'on']);

        $result = $guard->check('payload', 'sig', '54.187.174.169');

        $this->assertNull($result);
    }

    public function testGuardRejectsHttpRequest(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: false);
        $guard->setServerVars([]);

        $result = $guard->check('payload', 'sig', '54.187.174.169');

        $this->assertNotNull($result);
        $this->assertSame(400, $result->httpStatusCode);
        $this->assertSame('insecure_connection', $result->reason);
    }

    public function testGuardAcceptsXForwardedProtoHttps(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: false);
        $guard->setServerVars(['HTTP_X_FORWARDED_PROTO' => 'https']);

        $result = $guard->check('payload', 'sig', '54.187.174.169');

        $this->assertNull($result);
    }

    public function testGuardRejectsXForwardedProtoHttp(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: false);
        $guard->setServerVars(['HTTP_X_FORWARDED_PROTO' => 'http']);

        $result = $guard->check('payload', 'sig', '54.187.174.169');

        $this->assertNotNull($result);
        $this->assertSame(400, $result->httpStatusCode);
    }

    public function testGuardAllowsLocalhostWhenInsecureLoopbackEnabled(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: true);
        $guard->setServerVars([]);

        $result = $guard->check('payload', 'sig', '127.0.0.1');

        $this->assertNull($result);
    }

    public function testGuardRejectsLocalhostWhenInsecureLoopbackDisabled(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: false);
        $guard->setServerVars([]);

        $result = $guard->check('payload', 'sig', '127.0.0.1');

        $this->assertNotNull($result);
        $this->assertSame(400, $result->httpStatusCode);
    }
}

/**
 * Testable subclass that overrides $_SERVER access.
 */
class TestableWebhookHttpsGuard extends WebhookHttpsGuard
{
    /** @var array<string, string> */
    private array $serverVars = [];

    /**
     * @param array<string, string> $vars
     */
    public function setServerVars(array $vars): void
    {
        $this->serverVars = $vars;
    }

    protected function getServerVar(string $key): ?string
    {
        return $this->serverVars[$key] ?? null;
    }
}
