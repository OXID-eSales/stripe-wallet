<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\LegacyWebhookSecretMigration;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryConfVars;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryModuleSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * VR-26 S1 / D3: the old mode-agnostic webhook secret is moved once into the per-endpoint key of the current mode,
 * then cleared; nothing reads it afterwards.
 */
#[CoversClass(LegacyWebhookSecretMigration::class)]
final class LegacyWebhookSecretMigrationTest extends TestCase
{
    private const LEGACY = 'sStripeWebhookEndpointSecret';

    private InMemoryModuleSettings $settings;
    private InMemoryConfVars $vars;
    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->settings = new InMemoryModuleSettings();
        $this->vars = new InMemoryConfVars();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    #[Test]
    public function copiesTheLegacySecretIntoTheCurrentModesKeyAndClearsIt(): void
    {
        $this->settings->values = ['sStripeMode' => 'test', self::LEGACY => 'whsec_old'];

        $this->migration()->migrate();

        $this->assertSame('whsec_old', $this->vars->read('sStripeWebhookEndpointSecretTest'));
        $this->assertSame('', $this->settings->getString(self::LEGACY, 'x')->toString());
    }

    #[Test]
    public function usesTheLiveKeyInLiveMode(): void
    {
        $this->settings->values = ['sStripeMode' => 'live', self::LEGACY => 'whsec_old'];

        $this->migration()->migrate();

        $this->assertSame('whsec_old', $this->vars->read('sStripeWebhookEndpointSecretLive'));
        $this->assertSame('', $this->vars->read('sStripeWebhookEndpointSecretTest'));
    }

    #[Test]
    public function doesNotOverwriteAnExistingPerEndpointSecretButStillClearsTheLegacyOne(): void
    {
        $this->settings->values = ['sStripeMode' => 'test', self::LEGACY => 'whsec_old'];
        $this->vars->values = ['sStripeWebhookEndpointSecretTest' => 'whsec_registered'];

        $this->migration()->migrate();

        $this->assertSame('whsec_registered', $this->vars->read('sStripeWebhookEndpointSecretTest'));
        $this->assertSame('', $this->settings->getString(self::LEGACY, 'x')->toString());
    }

    #[Test]
    public function doesNotMoveASecretThatBelongsToTheOtherModesEra(): void
    {
        // The other mode registered its own endpoint: the mode-agnostic value is from that earlier era and would
        // verify this mode's webhooks with the wrong secret.
        $this->settings->values = ['sStripeMode' => 'live', self::LEGACY => 'whsec_old'];
        $this->vars->values = ['sStripeWebhookEndpointSecretTest' => 'whsec_test'];

        $this->migration()->migrate();

        $this->assertSame('', $this->vars->read('sStripeWebhookEndpointSecretLive'));
        $this->assertSame('', $this->settings->getString(self::LEGACY, 'x')->toString());
    }

    #[Test]
    public function doesNothingWithoutALegacySecret(): void
    {
        $this->settings->values = ['sStripeMode' => 'test'];

        $this->migration()->migrate();

        $this->assertSame([], $this->settings->saved);
        $this->assertSame('', $this->vars->read('sStripeWebhookEndpointSecretTest'));
    }

    #[Test]
    public function runningItTwiceMovesTheSecretOnce(): void
    {
        $this->settings->values = ['sStripeMode' => 'test', self::LEGACY => 'whsec_old'];
        $migration = $this->migration();

        $migration->migrate();
        $this->vars->values['sStripeWebhookEndpointSecretTest'] = 'whsec_rotated';
        $migration->migrate();

        $this->assertSame('whsec_rotated', $this->vars->read('sStripeWebhookEndpointSecretTest'));
    }

    #[Test]
    public function logsKeysOnlyNeverTheSecret(): void
    {
        $this->settings->values = ['sStripeMode' => 'test', self::LEGACY => 'whsec_old_secret_value'];
        $this->logger->expects($this->once())->method('info')->with(
            $this->anything(),
            $this->callback(static fn (array $context): bool =>
                ($context['target_key'] ?? null) === 'sStripeWebhookEndpointSecretTest'
                && !str_contains((string) json_encode($context), 'whsec_old_secret_value'))
        );

        $this->migration()->migrate();
    }

    private function migration(): LegacyWebhookSecretMigration
    {
        return new LegacyWebhookSecretMigration($this->settings, $this->vars, $this->logger);
    }
}
