<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Frontend;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class BaseJsProbeViewConf
{
    public function isStripeCheckoutActive(): bool
    {
        return true;
    }

    public function getStripeJsPath(): string
    {
        return 'js/stripe-frontend.min.js';
    }

    public function getStripeModuleVersion(): string
    {
        return '0.0.0-test';
    }

    /** @param array<int, mixed> $args */
    public function getModuleUrl(string $moduleId, string $path = '', array ...$args): string
    {
        return 'https://shop.test/out/modules/' . $moduleId . '/' . $path;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * Sprint 137 S6 — base_js is rendered inside <body>, so a Content-Security-Policy
 * <meta> placed here is ignored by every browser and logs an error on every
 * Stripe-active page (14 per checkout, measured 2026-09-18). CSP belongs in the
 * shop's HTTP headers; this template must not pretend to set one.
 */
#[Group('integration')]
#[Group('requires-oxid-container')]
final class BaseJsTemplateTest extends TestCase
{
    private const TEMPLATE = '@oe_payments_stripe_wallet/frontend/base_js.html.twig';

    public function testLoadsStripeJsWithoutEmittingAContentSecurityPolicyMeta(): void
    {
        $renderer = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class)
            ->getTemplateRenderer();

        $output = $renderer->renderTemplate(self::TEMPLATE, [
            'oViewConf' => new BaseJsProbeViewConf(),
        ]);

        self::assertStringContainsString('https://js.stripe.com/v3/', $output, 'Stripe.js must still be loaded');
        self::assertStringNotContainsString('Content-Security-Policy', $output);
    }
}
