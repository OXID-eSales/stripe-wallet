<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Webhook;

use DateTimeImmutable;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\WebhookLogRepositoryInterface;
use Psr\Log\LoggerInterface;

class WebhookProcessor implements WebhookProcessorInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly WebhookIdempotencyCheckerInterface $idempotencyChecker,
        private readonly WebhookLogRepositoryInterface $logRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $webhookData
     */
    public function process(array $webhookData): void
    {
        $eventId = (string) $webhookData['id'];
        $eventType = (string) $webhookData['type'];
        /** @var array<string, mixed> $eventData */
        $eventData = $webhookData['data'];

        if ($this->idempotencyChecker->isProcessed($eventId)) {
            $this->logger->info('Webhook already processed, skipping', ['eventId' => $eventId]);
            return;
        }

        $paymentIntentId = $this->extractPaymentIntentId($eventData);
        if (!$paymentIntentId) {
            $this->logger->warning('Cannot extract payment intent ID from webhook', ['eventType' => $eventType]);
            return;
        }

        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);
        if (!$contract) {
            $this->logger->warning('Contract not found for payment intent', ['paymentIntentId' => $paymentIntentId]);
            return;
        }

        $context = new EventContext([
            'contractId' => $contract->getId(),
            'webhookEventId' => $eventId,
        ]);

        $event = new WebhookReceivedEvent(
            $context,
            'stripe',
            $eventType,
            $eventData,
            $eventId
        );

        $this->eventDispatcher->dispatch($event);

        $this->idempotencyChecker->markAsProcessed($eventId);

        $this->logWebhookEvent($eventId, $eventType, $contract->getId());
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function extractPaymentIntentId(array $eventData): ?string
    {
        $id = $eventData['object']['id'] ?? null;
        return is_string($id) ? $id : null;
    }

    private function logWebhookEvent(string $eventId, string $eventType, string $contractId): void
    {
        $log = new WebhookLog($eventId, new DateTimeImmutable(), 'processed');
        $log->setEventType($eventType);
        $log->setContractId($contractId);
        $this->logRepository->save($log);
    }
}
