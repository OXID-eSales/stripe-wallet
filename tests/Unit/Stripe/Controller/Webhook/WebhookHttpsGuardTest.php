<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookHttpsGuard;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 67b: M6 — HTTPS enforcement on webhook endpoints.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Webhook\WebhookHttpsGuard
 * @group sprint-67b
 * @group security
 */
final class WebhookHttpsGuardTest extends TestCase
{
    /** @test */
    public function guardAllowsHttpsRequest(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: false);
        $guard->setServerVars(['HTTPS' => 'on']);

        $result = $guard->check('payload', 'sig', '54.187.174.169');

        $this->assertNull($result);
    }

    /** @test */
    public function guardRejectsHttpRequest(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: false);
        $guard->setServerVars([]);

        $result = $guard->check('payload', 'sig', '54.187.174.169');

        $this->assertNotNull($result);
        $this->assertSame(400, $result->httpStatusCode);
        $this->assertSame('insecure_connection', $result->reason);
    }

    /** @test */
    public function guardAcceptsXForwardedProtoHttps(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: false);
        $guard->setServerVars(['HTTP_X_FORWARDED_PROTO' => 'https']);

        $result = $guard->check('payload', 'sig', '54.187.174.169');

        $this->assertNull($result);
    }

    /** @test */
    public function guardRejectsXForwardedProtoHttp(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: false);
        $guard->setServerVars(['HTTP_X_FORWARDED_PROTO' => 'http']);

        $result = $guard->check('payload', 'sig', '54.187.174.169');

        $this->assertNotNull($result);
        $this->assertSame(400, $result->httpStatusCode);
    }

    /** @test */
    public function guardAllowsLocalhostWhenInsecureLoopbackEnabled(): void
    {
        $guard = new TestableWebhookHttpsGuard(allowInsecureLoopback: true);
        $guard->setServerVars([]);

        $result = $guard->check('payload', 'sig', '127.0.0.1');

        $this->assertNull($result);
    }

    /** @test */
    public function guardRejectsLocalhostWhenInsecureLoopbackDisabled(): void
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
