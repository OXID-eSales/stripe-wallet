<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookResult;

/**
 * Stripe-local value object returned by each StripeWebhookEventHandlerInterface.
 *
 * Bundles the provider-agnostic WebhookResult with the contract ID resolved
 * during handler execution. The contract ID is needed by the processor to
 * link the webhook log row to the correct contract (via getContractIdFromResult()).
 *
 * payment-base WebhookResult carries no contractId; this VO adds that field
 * without modifying the shared package.
 *
 * @since Sprint 114.4
 */
readonly class StripeWebhookOutcome
{
    public function __construct(
        public WebhookResult $result,
        public ?string $contractId = null
    ) {
    }

    public static function of(WebhookResult $result, ?string $contractId = null): self
    {
        return new self($result, $contractId);
    }
}
