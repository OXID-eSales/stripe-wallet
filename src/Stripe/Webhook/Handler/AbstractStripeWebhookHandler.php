<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventHandlerInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookOutcome;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use Psr\Log\LoggerInterface;

/**
 * Base class for Stripe webhook handlers.
 *
 * Provides shared helpers used across multiple handlers:
 * - mapHandlerResult(): maps tri-state ?bool → StripeWebhookOutcome
 * - setContractIdFromProviderOrderId(): resolves contractId from PI lookup
 *
 * @since Sprint 114.4
 */
abstract class AbstractStripeWebhookHandler implements StripeWebhookEventHandlerInterface
{
    public function __construct(
        protected readonly StripeWebhookEventParser $parser,
        protected readonly WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        protected readonly ContractRepositoryInterface $contractRepository,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * Map tri-state handler result (true/false/null) to a StripeWebhookOutcome.
     *
     * A non-null result means the handler ran against a real contract — resolve
     * and link its ID for the webhook log row, regardless of whether the action
     * ultimately ran or was state-guard skipped.
     */
    protected function mapHandlerResult(
        ?bool $result,
        string $providerOrderId,
        string $successAction,
        string $skipReason
    ): StripeWebhookOutcome {
        if ($result === null) {
            return StripeWebhookOutcome::of(WebhookResult::skipped('Contract not found'));
        }

        $contractId = $this->resolveContractIdFromProviderOrderId($providerOrderId);

        if ($result === true) {
            return StripeWebhookOutcome::of(WebhookResult::success($successAction), $contractId);
        }

        return StripeWebhookOutcome::of(WebhookResult::skipped($skipReason), $contractId);
    }

    /**
     * Look up the contract ID by providerOrderId (PaymentIntent ID).
     */
    protected function resolveContractIdFromProviderOrderId(string $providerOrderId): ?string
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        return $contract?->getId();
    }
}
