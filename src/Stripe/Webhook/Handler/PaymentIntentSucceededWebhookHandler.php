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
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookOutcome;
use Psr\Log\LoggerInterface;

/**
 * Handles payment_intent.succeeded webhook events.
 *
 * When the fulfillment handler finds the contract by providerOrderId, it returns
 * true/false/null and we map to a result. When null (contract not indexed by PI yet),
 * we attempt a metadata fallback: look up by contract_id from event metadata.
 *
 * @since Sprint 114.4
 */
class PaymentIntentSucceededWebhookHandler extends AbstractStripeWebhookHandler
{
    private const EVENT_TYPE = 'payment_intent.succeeded';

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

        $this->logger->info('Processing payment_intent.succeeded', [
            'payment_intent_id' => $paymentIntentId,
        ]);

        // GRAPH-QL / PS3: a PENDING contract is committed from the paid intent
        // before the fulfilment; the return leg is no longer the only way.
        $ended = $this->commitOpenContract(
            $this->findContract($event, $paymentIntentId),
            $paymentIntentId,
            $paymentIntentId,
            $this->parser->extractAmountInCurrencyUnits($event, 'amount_received')
                ?? $this->parser->extractAmountInCurrencyUnits($event, 'amount'),
            $this->currencyOf($event),
            ['paymentIntentId' => $paymentIntentId]
        );
        if ($ended !== null) {
            return $ended;
        }

        $result = $this->fulfillmentHandler->handlePaymentSucceeded($paymentIntentId);

        if ($result === null) {
            return $this->tryMetadataLookupOrLegacy($event, $paymentIntentId);
        }

        return $this->mapHandlerResult(
            $result,
            $paymentIntentId,
            'contract_fulfilled',
            'Contract already fulfilled or not in COMMITTED state'
        );
    }

    /**
     * The contract this intent belongs to: indexed by the intent id, or named
     * in the intent's metadata (a Checkout Session contract before the id swap).
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

    /**
     * Try metadata lookup or legacy fallback for orders without contracts indexed by PI.
     */
    private function tryMetadataLookupOrLegacy(WebhookEvent $event, string $paymentIntentId): StripeWebhookOutcome
    {
        $contractId = $this->parser->extractContractIdFromMetadata($event);
        $contract = $contractId !== null ? $this->contractRepository->findById($contractId) : null;

        if ($contract === null) {
            $this->logger->debug('No contract found, webhook processed without contract update', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return StripeWebhookOutcome::of(WebhookResult::skipped('Contract not found'));
        }

        $contract->setProvider(StripeDefinitions::PROVIDER, $paymentIntentId);

        if ($contract->getState()->isFulfilled()) {
            $this->contractRepository->save($contract);
            return StripeWebhookOutcome::of(WebhookResult::skipped('Contract already fulfilled'), $contractId);
        }

        if ($contract->getState()->isCommitted()) {
            $fulfillResult = $this->fulfillmentHandler->handlePaymentSucceeded($paymentIntentId);
            return $fulfillResult === true
                ? StripeWebhookOutcome::of(WebhookResult::success('contract_fulfilled'), $contractId)
                : StripeWebhookOutcome::of(WebhookResult::skipped('Fulfillment skipped'), $contractId);
        }

        $this->contractRepository->save($contract);
        return StripeWebhookOutcome::of(WebhookResult::skipped('Contract not in COMMITTED state'), $contractId);
    }
}
