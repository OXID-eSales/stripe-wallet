<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Translation;

use PHPUnit\Framework\TestCase;

/**
 * Guards that every module setting declared in metadata.php has the admin
 * translation keys OXID's module-config form looks up — both the per-setting
 * label (SHOP_MODULE_<name>) and the group header (SHOP_MODULE_GROUP_<group>) —
 * in every admin language file.
 *
 * Phase 2 (logging-control sprint): also asserts the new STRIPE_LOGGING group
 * and its settings (sStripeLogLevel, blStripeLogWebhooks) are covered, and that
 * the stale StripeTransactions.log reference is absent from help texts.
 *
 * Modelled on payment-base/tests/Unit/Metadata/SettingsTranslationsTest.php.
 */
final class AdminSettingsTranslationsTest extends TestCase
{
    private const ADMIN_LANGS = ['en', 'de'];

    /** @return array<int, array{name: string, group?: string, type?: string, constraints?: string}> */
    private function settings(): array
    {
        $aModule = [];
        require $this->moduleRoot() . '/metadata.php';

        return $aModule['settings'] ?? [];
    }

    /** @return array<string, string> */
    private function langKeys(string $lang): array
    {
        $aLang = [];
        require $this->moduleRoot() . "/views/admin_twig/{$lang}/stripe_lang.php";

        return $aLang;
    }

    private function moduleRoot(): string
    {
        // tests/Unit/Stripe/Translation/ → 4 levels up → module root
        return dirname(__DIR__, 4);
    }

    public function testEverySettingGroupHasAdminTranslationInEveryLanguage(): void
    {
        $groups = array_values(array_unique(array_filter(
            array_map(static fn (array $s): string => $s['group'] ?? '', $this->settings())
        )));

        $this->assertNotEmpty($groups, 'Expected at least one grouped setting in metadata.php');

        foreach (self::ADMIN_LANGS as $lang) {
            $keys = $this->langKeys($lang);
            foreach ($groups as $group) {
                $key = 'SHOP_MODULE_GROUP_' . $group;
                $this->assertArrayHasKey($key, $keys, "Missing [{$lang}] {$key}");
                $this->assertNotSame('', trim($keys[$key]), "Empty [{$lang}] {$key}");
            }
        }
    }

    public function testEverySettingHasAdminLabelInEveryLanguage(): void
    {
        $names = array_map(static fn (array $s): string => $s['name'], $this->settings());

        $this->assertNotEmpty($names, 'Expected at least one setting in metadata.php');

        foreach (self::ADMIN_LANGS as $lang) {
            $keys = $this->langKeys($lang);
            foreach ($names as $name) {
                $key = 'SHOP_MODULE_' . $name;
                $this->assertArrayHasKey($key, $keys, "Missing [{$lang}] {$key}");
                $this->assertNotSame('', trim($keys[$key]), "Empty [{$lang}] {$key}");
            }
        }
    }

    public function testEverySelectSettingHasOptionLabelsInEveryLanguage(): void
    {
        $selectSettings = array_filter(
            $this->settings(),
            static fn (array $s): bool => ($s['type'] ?? '') === 'select' && isset($s['constraints'])
        );

        foreach (self::ADMIN_LANGS as $lang) {
            $keys = $this->langKeys($lang);
            foreach ($selectSettings as $setting) {
                $options = explode('|', (string) $setting['constraints']);
                foreach ($options as $option) {
                    $key = 'SHOP_MODULE_' . $setting['name'] . '_' . $option;
                    $this->assertArrayHasKey(
                        $key,
                        $keys,
                        "Missing [{$lang}] option label {$key} for select setting {$setting['name']}"
                    );
                    $this->assertNotSame('', trim($keys[$key]), "Empty [{$lang}] {$key}");
                }
            }
        }
    }

    public function testStaleTransactionLogPathIsRemovedFromHelpTexts(): void
    {
        foreach (self::ADMIN_LANGS as $lang) {
            $keys = $this->langKeys($lang);
            foreach ($keys as $key => $value) {
                $this->assertStringNotContainsString(
                    'StripeTransactions.log',
                    (string) $value,
                    "Stale StripeTransactions.log path found in [{$lang}] key {$key}"
                );
            }
        }
    }

    public function testLoggingGroupExistsInMetadata(): void
    {
        $groups = array_column($this->settings(), 'group');
        $this->assertContains(
            'STRIPE_LOGGING',
            $groups,
            'STRIPE_LOGGING group must be declared in metadata.php settings'
        );
    }

    public function testLogLevelSettingExistsWithCorrectTypeAndDefault(): void
    {
        $setting = $this->findSetting('sStripeLogLevel');

        $this->assertNotNull($setting, 'sStripeLogLevel must be declared in metadata.php');
        $this->assertSame('select', $setting['type'], 'sStripeLogLevel must be type select');
        $this->assertSame('normal', $setting['value'], 'sStripeLogLevel default must be "normal"');
        $this->assertArrayHasKey('constraints', $setting, 'sStripeLogLevel must have constraints');

        $options = explode('|', (string) $setting['constraints']);
        $this->assertContains('off', $options, 'sStripeLogLevel constraints must include "off"');
        $this->assertContains('errors', $options, 'sStripeLogLevel constraints must include "errors"');
        $this->assertContains('normal', $options, 'sStripeLogLevel constraints must include "normal"');
        $this->assertContains('debug', $options, 'sStripeLogLevel constraints must include "debug"');
    }

    public function testLogWebhooksSettingExistsWithCorrectTypeAndDefault(): void
    {
        $setting = $this->findSetting('blStripeLogWebhooks');

        $this->assertNotNull($setting, 'blStripeLogWebhooks must be declared in metadata.php');
        $this->assertSame('bool', $setting['type'], 'blStripeLogWebhooks must be type bool');
        $this->assertSame('1', $setting['value'], 'blStripeLogWebhooks default must be "1" (on)');
    }

    public function testLegacyTransactionInfoSettingStillExistsForBackCompat(): void
    {
        $setting = $this->findSetting('blStripeLogTransactionInfo');

        $this->assertNotNull(
            $setting,
            'blStripeLogTransactionInfo must remain in metadata.php for Phase 3 back-compat seeding'
        );
    }

    /** @return array{name: string, group: string, type: string, value: string}|null */
    private function findSetting(string $name): ?array
    {
        foreach ($this->settings() as $setting) {
            if ($setting['name'] === $name) {
                return $setting;
            }
        }
        return null;
    }
}
