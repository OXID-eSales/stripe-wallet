<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\AbstractWebhookProcessor;
use OxidEsales\PaymentBase\Webhook\Exception\WebhookSignatureException;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe webhook processor.
 *
 * Extends AbstractWebhookProcessor to handle Stripe-specific webhook processing:
 * - Signature verification using Stripe SDK
 * - Event routing to the registered StripeWebhookEventHandlerInterface handlers
 * - Support for payment_intent, charge, and checkout.session events
 *
 * Event routing is open-for-extension (R-2.2 OCP): new event types are handled by
 * registering a new StripeWebhookEventHandlerInterface tagged 'stripe.webhook_handler'
 * in services.yaml, without editing this class.
 *
 * @since Sprint 5
 * @since Sprint 114.4 — match dispatch replaced by tagged handler registry (OCP)
 */
class StripeWebhookProcessor extends AbstractWebhookProcessor
{
    private ?string $lastContractId = null;

    /**
     * @param iterable<StripeWebhookEventHandlerInterface> $handlers Tagged stripe.webhook_handler services
     */
    public function __construct(
        WebhookLogRepositoryInterface $logRepository,
        LoggerInterface $logger,
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly iterable $handlers
    ) {
        parent::__construct($logRepository, $logger);
    }

    protected function getProviderName(): string
    {
        return StripeDefinitions::PROVIDER;
    }

    /**
     * @throws WebhookSignatureException
     */
    protected function parseAndValidateRequest(WebhookRequest $request): WebhookEvent
    {
        try {
            $stripeEvent = Webhook::constructEvent(
                $request->payload,
                $request->signature,
                $this->config->getWebhookSecret()
            );

            /** @var array<string, mixed> $eventData */
            $eventData = $stripeEvent->data->toArray();

            return new WebhookEvent(
                id: $stripeEvent->id,
                type: $stripeEvent->type,
                data: $eventData,
                created: $stripeEvent->created
            );
        } catch (SignatureVerificationException $e) {
            throw new WebhookSignatureException($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function processEvent(WebhookEvent $event): WebhookResult
    {
        $this->lastContractId = null;

        foreach ($this->handlers as $handler) {
            if (!$handler->supports($event->type)) {
                continue;
            }

            $outcome = $handler->handle($event);
            $this->lastContractId = $outcome->contractId;
            return $outcome->result;
        }

        return WebhookResult::skipped("Unhandled event type: {$event->type}");
    }

    protected function getContractIdFromResult(WebhookResult $result): ?string
    {
        return $this->lastContractId;
    }
}
