<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Service\WebhookLogService;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Factory that produces a payment-base WebhookLogService pre-wired with
 * Stripe's isWebhookLoggingEnabled() gate as the $shouldLogPayload closure.
 *
 * Logging-control sprint Phase 4.
 *
 * The claim row (OXEVENTID dedup/idempotency) is ALWAYS written — it lives in
 * DoctrineWebhookLogRepository::claimEvent() which is not touched here.
 * Only OXPAYLOAD and the PSR-3 mirror are gated behind the closure.
 *
 * This mirrors the Phase 3 AbstractFileLoggerFactory pattern: the provider-
 * agnostic class (WebhookLogService) holds the optional seam; the Stripe
 * factory injects the provider-specific gate at composition time.
 *
 * @since logging-control sprint Phase 4
 */
final class WebhookDbLogServiceFactory
{
    public function __construct(
        private readonly WebhookLogRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly ModuleConfigurationServiceInterface $config
    ) {
    }

    public function create(): WebhookLogService
    {
        return new WebhookLogService(
            $this->repository,
            $this->logger,
            fn (): bool => $this->config->isWebhookLoggingEnabled()
        );
    }
}
