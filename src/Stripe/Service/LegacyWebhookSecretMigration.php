<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Core\WebhookEndpointKeys;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\ModuleConfVarsInterface;
use OxidEsales\Payments\Stripe\Module;
use Psr\Log\LoggerInterface;

/**
 * VR-26 S1 / D3 - moves the old mode-agnostic webhook secret (`sStripeWebhookEndpointSecret`, pasted by hand or written
 * by an earlier registration) into the per-endpoint key of the current mode, once, and clears it. Nothing reads the old
 * setting afterwards, so the webhook keeps verifying with the same secret.
 *
 * Runs on module activation. The per-endpoint key is never overwritten: a secret the shop registered itself wins. A
 * value is not moved when the OTHER mode already has its own secret: the shop auto-registered before and the
 * mode-agnostic value belongs to that earlier era, so it would verify this mode's webhooks with the wrong secret.
 */
final class LegacyWebhookSecretMigration
{
    /** The setting only this class still knows; it stays in metadata.php until the migration has run everywhere. */
    private const LEGACY_SECRET_SETTING = 'sStripeWebhookEndpointSecret';

    public function __construct(
        private readonly ModuleSettingServiceInterface $settings,
        private readonly ModuleConfVarsInterface $vars,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function migrate(): void
    {
        $legacySecret = $this->settings->getString(self::LEGACY_SECRET_SETTING, Module::MODULE_ID)->toString();
        if ($legacySecret === '') {
            return;
        }

        $mode = $this->currentMode();
        $targetKey = WebhookEndpointKeys::secret($mode);

        $this->settings->saveString(self::LEGACY_SECRET_SETTING, '', Module::MODULE_ID);

        $skipReason = $this->reasonNotToMove($mode);
        if ($skipReason !== null) {
            $this->logger->warning('Legacy webhook secret cleared without moving it', [
                'legacy_key' => self::LEGACY_SECRET_SETTING,
                'target_key' => $targetKey,
                'reason' => $skipReason,
            ]);

            return;
        }

        $this->vars->write($targetKey, $legacySecret);
        $this->logger->info('Legacy webhook secret moved into the per-endpoint key', [
            'legacy_key' => self::LEGACY_SECRET_SETTING,
            'target_key' => $targetKey,
        ]);
    }

    private function reasonNotToMove(string $mode): ?string
    {
        if ($this->vars->read(WebhookEndpointKeys::secret($mode)) !== '') {
            return 'per_endpoint_secret_exists';
        }

        if ($this->vars->read(WebhookEndpointKeys::secret(WebhookEndpointKeys::otherMode($mode))) !== '') {
            return 'other_mode_has_its_own_secret';
        }

        return null;
    }

    private function currentMode(): string
    {
        $mode = $this->settings->getString('sStripeMode', Module::MODULE_ID)->toString();

        return $mode === StripeDefinitions::MODE_LIVE ? StripeDefinitions::MODE_LIVE : StripeDefinitions::MODE_TEST;
    }
}
