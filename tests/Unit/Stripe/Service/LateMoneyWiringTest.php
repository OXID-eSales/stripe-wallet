<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\LateMoney\LateMoneyResolverInterface;
use OxidEsales\Payments\Stripe\Command\StaleCheckoutSweepCommand;
use OxidEsales\Payments\Stripe\Service\StripeProviderPaymentGateway;
use OxidEsales\Payments\Stripe\Webhook\Handler\CheckoutSessionCompletedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentAmountCapturableUpdatedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentSucceededWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * VR-23: without these registrations payment-base's cleanup throws a LogicException for every order Stripe holds money
 * for, the webhooks take their money back from a resolver nobody wired, and the sweep never runs.
 */
final class LateMoneyWiringTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $services;

    protected function setUp(): void
    {
        $this->services = Yaml::parseFile(__DIR__ . '/../../../../services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];
    }

    public function testStripeIsRegisteredAsProviderPaymentGateway(): void
    {
        self::assertContains(['name' => 'oe.payment.provider_payment_gateway'], $this->services[StripeProviderPaymentGateway::class]['tags'] ?? []);
    }

    public function testTheSweepIsTheTaggedCronCommand(): void
    {
        $tags = $this->services[StaleCheckoutSweepCommand::class]['tags'] ?? [];

        self::assertContains(['name' => 'console.command', 'command' => 'oe:payments:stripe:sweep'], $tags);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function handlersGivingLateMoneyBack(): iterable
    {
        yield 'checkout.session.completed' => [CheckoutSessionCompletedWebhookHandler::class];
        yield 'payment_intent.succeeded' => [PaymentIntentSucceededWebhookHandler::class];
        yield 'payment_intent.amount_capturable_updated' => [PaymentIntentAmountCapturableUpdatedWebhookHandler::class];
    }

    /**
     * @param class-string $handler
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('handlersGivingLateMoneyBack')]
    public function testTheHandlersUsePaymentBasesResolver(string $handler): void
    {
        self::assertSame('@' . LateMoneyResolverInterface::class, $this->services[$handler]['arguments']['$lateMoney'] ?? null);
    }

    public function testTheProcessorJudgesEventOriginBeforeAnyHandler(): void
    {
        self::assertArrayHasKey('$origin', $this->services[StripeWebhookProcessor::class]['arguments']);
    }
}
