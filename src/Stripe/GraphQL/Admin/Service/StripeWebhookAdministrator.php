<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStepUpInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminSuperadminCheckInterface;
use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrarInterface;
use Psr\Log\LoggerInterface;

/**
 * What the two AJAX buttons of the classic module configuration do ("Create webhooks", "Clear all webhooks"), as an
 * admin API service: step-up first (it changes what Stripe sends where), then the registrar, then the stored state.
 * Neither the signing secret nor a key leaves this class; the audit row names the thing, not a value.
 */
final class StripeWebhookAdministrator implements StripeWebhookAdministratorInterface
{
    private const AUDITED_FIELDS = ['webhookEndpoint'];

    public function __construct(
        private readonly StripeAdminSettingsInterface $settings,
        private readonly StripeWebhookStateInterface $state,
        private readonly WebhookEndpointRegistrarInterface $registrar,
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly AdminSuperadminCheckInterface $superadmin,
        private readonly AdminStepUpInterface $stepUp,
        private readonly StripeAdminAuditInterface $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function status(): StripeWebhookStatus
    {
        $snapshot = $this->settings->snapshot();

        return new StripeWebhookStatus(
            $snapshot->mode,
            $this->state->hasSecret($snapshot->mode),
            $this->state->endpointId($snapshot->mode),
            $snapshot->webhookEndpointUrl,
        );
    }

    public function register(): StripeWebhookResult
    {
        return $this->audit->audited(
            'adminStripeWebhookRegister',
            self::AUDITED_FIELDS,
            fn (): StripeWebhookResult => $this->runRegister()
        );
    }

    public function clearAll(): StripeWebhookResult
    {
        return $this->audit->audited(
            'adminStripeWebhookClearAll',
            self::AUDITED_FIELDS,
            fn (): StripeWebhookResult => $this->runClearAll()
        );
    }

    private function runRegister(): StripeWebhookResult
    {
        // Registering stores a signing secret: the superadmin's, asked before the second factor.
        if (!$this->superadmin->isSuperadmin()) {
            return StripeWebhookResult::refused(StripeAdminRefusal::superadminRequired('webhook'));
        }
        $this->stepUp->require();
        $snapshot = $this->settings->snapshot();
        $mode = $snapshot->mode;
        $token = $this->settings->token($mode);
        if ($token === '') {
            return $this->refused('token', 'token_missing', 'The secret key of the mode in use is not set');
        }
        try {
            // The shop's own key first, a Connect webhook on the platform only when the account is a connected one
            // (WebhookEndpointRegistrar::registerForShop()); the existing endpoint is updated in place.
            $result = $this->registrar->registerForShop(
                $token,
                $this->settings->platformKey($mode),
                $snapshot->webhookEndpointUrl,
                $this->nullIfEmpty($this->state->endpointId($mode)),
                $this->config->getModuleDescription(),
            );
        } catch (WebhookRegistrationException $failure) {
            return $this->failed('registration_failed', 'Stripe webhook creation failed', $failure, $mode);
        }
        $this->state->storeEndpoint($mode, $result->endpointId, $result->secret, $snapshot->webhookEndpointUrl);

        return StripeWebhookResult::done($this->status(), null);
    }

    private function runClearAll(): StripeWebhookResult
    {
        $this->stepUp->require();
        $snapshot = $this->settings->snapshot();
        $platformKey = $this->settings->platformKey($snapshot->mode);
        if ($platformKey === '') {
            return $this->refused(
                'platformKey',
                'platform_key_missing',
                'The platform key of the mode in use is not set'
            );
        }
        try {
            // Only the endpoints of THIS shop's URL: another shop sharing the Stripe key keeps its own.
            $deleted = $this->registrar->clearAll($platformKey, $snapshot->webhookEndpointUrl);
        } catch (WebhookRegistrationException $failure) {
            return $this->failed('clear_failed', 'Stripe webhook clear-all failed', $failure, $snapshot->mode);
        }
        $this->state->forgetAll();

        return StripeWebhookResult::done($this->status(), $deleted);
    }

    private function failed(
        string $code,
        string $logMessage,
        WebhookRegistrationException $failure,
        string $mode
    ): StripeWebhookResult {
        $reason = StripeSecretRedactor::redact($failure->getMessage());
        $this->logger->warning($logMessage, ['reason' => $reason, 'mode' => $mode]);

        return $this->refused('webhook', $code, $reason);
    }

    private function refused(string $field, string $code, string $message): StripeWebhookResult
    {
        return StripeWebhookResult::refused(StripeAdminRefusal::invalid($field, $code, $message));
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
