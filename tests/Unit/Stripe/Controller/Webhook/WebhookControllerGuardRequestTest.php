<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

use OxidEsales\PaymentBase\Webhook\Guard\TrustedProxyClientIp;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookHttpRequest;
use OxidEsales\Payments\Stripe\Controller\Webhook\WebhookController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * VR-19 W4: what the shared guards are shown. The scheme and the caller are
 * facts about the connection, so the controller resolves them (payment-base's
 * guards only look at WebhookHttpRequest).
 */
#[CoversClass(WebhookController::class)]
final class WebhookControllerGuardRequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['CONTENT_LENGTH']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    #[Test]
    public function aTlsConnectionIsHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';

        self::assertSame('https', $this->guardRequest('{}', '203.0.113.7')->scheme);
    }

    #[Test]
    public function aForwardedHttpsProtoIsHttps(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        self::assertSame('https', $this->guardRequest('{}', '203.0.113.7')->scheme);
    }

    #[Test]
    public function otherwiseTheRequestIsHttp(): void
    {
        self::assertSame('http', $this->guardRequest('{}', '203.0.113.7')->scheme);
        $_SERVER['HTTPS'] = 'off';
        self::assertSame('http', $this->guardRequest('{}', '203.0.113.7')->scheme);
    }

    #[Test]
    public function theRequestCarriesBodyAndDeclaredLength(): void
    {
        $_SERVER['CONTENT_LENGTH'] = '70000';

        $request = $this->guardRequest('{"id":"evt_1"}', '203.0.113.7');

        self::assertSame('{"id":"evt_1"}', $request->rawBody);
        self::assertSame(70000, $request->contentLength);
    }

    #[Test]
    public function aMissingContentLengthIsZero(): void
    {
        self::assertSame(0, $this->guardRequest('{}', '203.0.113.7')->contentLength);
    }

    #[Test]
    public function theCallerIsTheAddressTheTrustedProxyResolverNames(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';
        $resolver = new TrustedProxyClientIp(['10.0.0.0/8'], 'CF-Connecting-IP');

        $request = $this->guardRequest('{}', '10.1.2.3', $resolver);

        self::assertSame('198.51.100.9', $request->clientIp);
    }

    #[Test]
    public function aCallerOutsideTheTrustedProxiesCannotClaimAnotherAddress(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';
        $resolver = new TrustedProxyClientIp(['10.0.0.0/8'], 'CF-Connecting-IP');

        self::assertSame('203.0.113.7', $this->guardRequest('{}', '203.0.113.7', $resolver)->clientIp);
    }

    private function guardRequest(
        string $payload,
        string $remoteAddr,
        ?TrustedProxyClientIp $resolver = null
    ): WebhookHttpRequest {
        $controller = new GuardRequestExposingController();
        $controller->useClientIpResolver($resolver ?? new TrustedProxyClientIp());

        return $controller->exposedGuardRequest($payload, $remoteAddr);
    }
}

class GuardRequestExposingController extends WebhookController
{
    /** @phpstan-ignore-next-line deliberately skips the OXID controller bootstrap */
    public function __construct()
    {
    }

    public function useClientIpResolver(TrustedProxyClientIp $resolver): void
    {
        $this->clientIp = $resolver;
    }

    public function exposedGuardRequest(string $payload, string $remoteAddr): WebhookHttpRequest
    {
        return $this->buildGuardRequest($payload, $remoteAddr);
    }
}
