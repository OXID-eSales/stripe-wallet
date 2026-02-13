<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\WebhookHandler;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

final class SptTokenDeactivatedHandler implements WebhookEventHandlerInterface
{
    private const EVENT_TYPE = 'shared_payment.granted_token.deactivated';

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly FileLoggerInterface $logger
    ) {
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::EVENT_TYPE;
    }

    public function handle(WebhookEvent $event): WebhookResult
    {
        $object = $event->getObject();
        $sellerDetails = is_array($object['seller_details'] ?? null) ? $object['seller_details'] : [];
        $externalId = $sellerDetails['external_id'] ?? null;

        if (!is_string($externalId)) {
            $this->logger->log('SptTokenDeactivated: no external_id', ['event_id' => $event->id]);
            return WebhookResult::skipped('No external_id in SPT deactivation event');
        }

        $contract = $this->contractRepository->findById($externalId);
        if ($contract === null) {
            $this->logger->log('SptTokenDeactivated: contract not found', ['externalId' => $externalId]);
            return WebhookResult::skipped('Contract not found for SPT deactivation');
        }

        $reason = is_string($object['deactivated_reason'] ?? null) ? $object['deactivated_reason'] : 'unknown';

        $contract->setMetadata('spt_deactivated_at', time());
        $contract->setMetadata('spt_deactivated_reason', $reason);

        if (!$contract->getState()->isTerminal()) {
            $contract->cancel('SPT token deactivated: ' . $reason);
            $this->logger->log('SptTokenDeactivated: contract cancelled', [
                'contractId' => $contract->getId(),
                'reason' => $reason,
            ]);
        }

        $this->contractRepository->save($contract);

        return WebhookResult::success('spt_deactivation_handled');
    }
}
