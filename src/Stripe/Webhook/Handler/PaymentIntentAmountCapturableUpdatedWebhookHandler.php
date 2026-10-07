<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookOutcome;
use Psr\Log\LoggerInterface;

/**
 * Handles payment_intent.amount_capturable_updated: a manual-capture
 * authorization. The Checkout Session completes "unpaid" and the intent is
 * `requires_capture`, so neither `checkout.session.completed` nor
 * `payment_intent.succeeded` commits anything - until the merchant captures.
 * A headless client that never comes back would leave the contract PENDING
 * and the order NOT_FINISHED although the money is reserved.
 *
 * So the authorized intent commits the open contract with `requiresCapture`
 * (payment-base ContractCommitService): the order exists, is not marked paid,
 * and the capture later fulfils it as in the Twig flow. Nothing else to do
 * here: an already committed contract (the return leg was first) is left alone.
 *
 * GRAPH-QL / PS6 follow-up.
 */
class PaymentIntentAmountCapturableUpdatedWebhookHandler extends AbstractStripeWebhookHandler
{
    private const EVENT_TYPE = 'payment_intent.amount_capturable_updated';
    private const REQUIRES_CAPTURE = 'requires_capture';

    public function __construct(
        StripeWebhookEventParser $parser,
        WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        ContractRepositoryInterface $contractRepository,
        LoggerInterface $logger,
        ?ContractCommitServiceInterface $contractCommit = null
    ) {
        parent::__construct($parser, $fulfillmentHandler, $contractRepository, $logger, $contractCommit);
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::EVENT_TYPE;
    }

    public function handle(WebhookEvent $event): StripeWebhookOutcome
    {
        $paymentIntentId = $this->parser->extractPaymentIntentId($event);
        if ($paymentIntentId === null) {
            return StripeWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing payment intent ID'));
        }

        if (($event->getObject()['status'] ?? null) !== self::REQUIRES_CAPTURE) {
            return StripeWebhookOutcome::of(WebhookResult::skipped('Payment intent is not awaiting capture'));
        }

        $contract = $this->findContract($event, $paymentIntentId);
        if ($contract === null) {
            return StripeWebhookOutcome::of(WebhookResult::skipped('Contract not found for payment intent'));
        }

        $this->logger->info('Processing payment_intent.amount_capturable_updated', [
            'payment_intent_id' => $paymentIntentId,
            'contract_id' => $contract->getId(),
        ]);

        $wasOpen = !$contract->getState()->isCommitted() && !$contract->getState()->isFulfilled();
        $ended = $this->commitOpenContract(
            $contract,
            $paymentIntentId,
            $paymentIntentId,
            $this->parser->extractAmountInCurrencyUnits($event, 'amount_capturable')
                ?? $this->parser->extractAmountInCurrencyUnits($event, 'amount'),
            $this->currencyOf($event),
            ['paymentIntentId' => $paymentIntentId],
            true
        );
        if ($ended !== null) {
            return $ended;
        }

        return $wasOpen
            ? StripeWebhookOutcome::of(WebhookResult::success('contract_authorized'), (string) $contract->getId())
            : StripeWebhookOutcome::of(WebhookResult::skipped('Contract already committed'), (string) $contract->getId());
    }

    /**
     * Indexed by the intent id, or named in the intent's metadata (a Checkout
     * Session contract still carries the session id as its provider order id).
     */
    private function findContract(WebhookEvent $event, string $paymentIntentId): ?PaymentContractInterface
    {
        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);
        if ($contract !== null) {
            return $contract;
        }

        $contractId = $this->parser->extractContractIdFromMetadata($event);

        return $contractId !== null ? $this->contractRepository->findById($contractId) : null;
    }

    private function currencyOf(WebhookEvent $event): string
    {
        $currency = $event->getObject()['currency'] ?? '';

        return is_string($currency) ? strtoupper($currency) : '';
    }
}
