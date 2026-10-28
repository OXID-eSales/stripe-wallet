<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStepUpInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminSuperadminCheckInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\KeyPairValidatorInterface;

/**
 * `adminStripeConfigModify`: a secret only for a superadmin (checked first), step-up (switching to live, or writing any secret), optimistic lock, validation, the
 * key-mismatch check of the pair a change would leave behind, then the write. The right is the controller's; one
 * audit row per call.
 */
final class StripeConfigModifier implements StripeConfigModifierInterface
{
    private const OPERATION = 'adminStripeConfigModify';

    public function __construct(
        private readonly StripeAdminSettingsInterface $settings,
        private readonly StripeConfigChangeValidator $validator,
        private readonly KeyPairValidatorInterface $keyPairs,
        private readonly AdminSuperadminCheckInterface $superadmin,
        private readonly AdminStepUpInterface $stepUp,
        private readonly StripeAdminAuditInterface $audit,
    ) {
    }

    public function modify(StripeConfigChange $change): StripeConfigResult
    {
        return $this->audit->audited(
            self::OPERATION,
            $change->fieldNames(),
            fn (): StripeConfigResult => $this->run($change)
        );
    }

    private function run(StripeConfigChange $change): StripeConfigResult
    {
        $secretField = $change->firstSecretField();
        if ($secretField !== null && !$this->superadmin->isSuperadmin()) {
            // Before the step-up: nobody is asked for a second factor for something they may not do.
            return StripeConfigResult::refused(StripeAdminRefusal::superadminRequired($secretField));
        }
        $current = $this->settings->snapshot();
        if ($this->needsStepUp($change, $current)) {
            $this->stepUp->require();
        }
        if ($change->expectedVersion !== $current->version()) {
            return StripeConfigResult::refused(
                StripeAdminRefusal::conflict($change->expectedVersion, $current->version())
            );
        }
        $refusal = $this->validator->validate($change) ?? $this->keyMismatch($change, $current);
        if ($refusal !== null) {
            return StripeConfigResult::refused($refusal);
        }
        $this->settings->apply($change);

        return StripeConfigResult::done($this->settings->snapshot());
    }

    private function needsStepUp(StripeConfigChange $change, StripeConfigSnapshot $current): bool
    {
        $switchesToLive = $change->mode === StripeDefinitions::MODE_LIVE
            && $current->mode !== StripeDefinitions::MODE_LIVE;

        return $switchesToLive || $change->writesSecret();
    }

    private function keyMismatch(StripeConfigChange $change, StripeConfigSnapshot $current): ?StripeAdminRefusal
    {
        foreach ([StripeDefinitions::MODE_TEST, StripeDefinitions::MODE_LIVE] as $mode) {
            if ($change->touchesKeys($mode) && $this->pairMismatches($mode, $change, $current)) {
                return StripeAdminRefusal::invalid(
                    $change->tokenFor($mode) !== null ? $mode . 'Token' : $mode . 'PublishableKey',
                    'key_mismatch',
                    StripeKeyPairMessage::DIFFERENT_ACCOUNTS
                );
            }
        }

        return null;
    }

    /**
     * A pair is judged only when both halves exist: a publishable key whose secret key comes later is no mismatch.
     */
    private function pairMismatches(string $mode, StripeConfigChange $change, StripeConfigSnapshot $current): bool
    {
        $publishableKey = $change->publishableKeyFor($mode) ?? $current->publishableKey($mode);
        $token = $change->tokenFor($mode) ?? $this->settings->token($mode);
        if ($publishableKey === '' || $token === '') {
            return false;
        }

        return StripeKeyPairMessage::from($this->keyPairs->getKeyPairError($publishableKey, $token))
            === StripeKeyPairMessage::DIFFERENT_ACCOUNTS;
    }
}
