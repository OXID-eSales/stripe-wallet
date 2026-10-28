<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * Server-side validation of a settings change (the client's own validation is UX only). Messages name the field and
 * the rule, never the value: an invalid secret must not be echoed anywhere.
 */
final class StripeConfigChangeValidator
{
    private const LOG_LEVELS = ['off', 'errors', 'normal', 'debug'];
    private const PUBLISHABLE_KEY_PATTERN = '/^pk_(test|live)_[A-Za-z0-9]{8,}$/';
    private const TOKEN_PATTERN = '/^sk_(test|live)_[A-Za-z0-9]{8,}$/';
    private const PLATFORM_KEY_PATTERN = '/^[sr]k_(test|live)_[A-Za-z0-9]{8,}$/';
    private const WEBHOOK_SECRET_PATTERN = '/^whsec_[A-Za-z0-9]{8,}$/';

    public function validate(StripeConfigChange $change): ?StripeAdminRefusal
    {
        return $this->choices($change) ?? $this->keys($change) ?? $this->webhookSecret($change);
    }

    private function choices(StripeConfigChange $change): ?StripeAdminRefusal
    {
        $modes = [StripeDefinitions::MODE_TEST, StripeDefinitions::MODE_LIVE];
        $captureModes = [StripeDefinitions::CAPTURE_MODE_AUTOMATIC, StripeDefinitions::CAPTURE_MODE_MANUAL];
        $checks = [
            ['mode', $change->mode, $modes, 'test or live'],
            ['captureMode', $change->captureMode, $captureModes, 'automatic or manual'],
            ['logLevel', $change->logLevel, self::LOG_LEVELS, 'off, errors, normal or debug'],
        ];
        foreach ($checks as [$field, $value, $allowed, $description]) {
            if ($value !== null && !in_array($value, $allowed, true)) {
                return StripeAdminRefusal::invalid(
                    $field,
                    'not_allowed',
                    sprintf('The %s must be %s', $field, $description)
                );
            }
        }

        return null;
    }

    private function keys(StripeConfigChange $change): ?StripeAdminRefusal
    {
        foreach ([StripeDefinitions::MODE_TEST, StripeDefinitions::MODE_LIVE] as $mode) {
            $publishable = $change->publishableKeyFor($mode);
            $keys = [
                [$mode . 'PublishableKey', $publishable === '' ? null : $publishable, self::PUBLISHABLE_KEY_PATTERN],
                [$mode . 'Token', $change->tokenFor($mode), self::TOKEN_PATTERN],
                [$mode . 'PlatformKey', $change->platformKeyFor($mode), self::PLATFORM_KEY_PATTERN],
            ];
            foreach ($keys as [$field, $key, $pattern]) {
                $refusal = $this->keyRefusal($field, $key, $pattern, $mode);
                if ($refusal !== null) {
                    return $refusal;
                }
            }
        }

        return null;
    }

    private function keyRefusal(string $field, ?string $key, string $pattern, string $mode): ?StripeAdminRefusal
    {
        if ($key === null) {
            return null;
        }
        if (preg_match($pattern, $key, $parts) !== 1) {
            return StripeAdminRefusal::invalid(
                $field,
                'invalid_format',
                'The key does not have the format of a Stripe key'
            );
        }

        return $parts[1] === $mode
            ? null
            : StripeAdminRefusal::invalid($field, 'wrong_mode', sprintf('This is not a %s mode key', $mode));
    }

    private function webhookSecret(StripeConfigChange $change): ?StripeAdminRefusal
    {
        $secret = $change->webhookSecret();
        if ($secret === null || preg_match(self::WEBHOOK_SECRET_PATTERN, $secret) === 1) {
            return null;
        }

        return StripeAdminRefusal::invalid(
            'webhookSecret',
            'invalid_format',
            'The webhook signing secret starts with whsec_ and holds letters and digits only'
        );
    }
}
