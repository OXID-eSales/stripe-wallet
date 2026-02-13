<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\WebhookHandler;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

final class SptTokenUsedHandler implements WebhookEventHandlerInterface
{
    private const EVENT_TYPE = 'shared_payment.granted_token.used';

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
            $this->logger->log('SptTokenUsed: no external_id in event', ['event_id' => $event->id]);
            return WebhookResult::skipped('No external_id in SPT event');
        }

        $contract = $this->contractRepository->findById($externalId);
        if ($contract === null) {
            $this->logger->log('SptTokenUsed: contract not found', ['externalId' => $externalId]);
            return WebhookResult::skipped('Contract not found for SPT token');
        }

        $tokenId = isset($object['id']) && is_scalar($object['id']) ? (string) $object['id'] : '';
        $paymentMethod = is_array($object['payment_method'] ?? null) ? $object['payment_method'] : [];
        $card = is_array($paymentMethod['card'] ?? null) ? $paymentMethod['card'] : [];
        $cardBrand = isset($card['brand']) && is_string($card['brand']) ? $card['brand'] : '';
        $cardLast4 = isset($card['last4']) && is_string($card['last4']) ? $card['last4'] : '';

        $contract->setMetadata('spt_token_id', $tokenId);
        $contract->setMetadata('spt_used_at', time());
        $contract->setMetadata('spt_card_brand', $cardBrand);
        $contract->setMetadata('spt_card_last4', $cardLast4);

        $this->contractRepository->save($contract);

        $this->logger->log('SptTokenUsed: metadata updated', [
            'contractId' => $contract->getId(),
            'sptId' => $object['id'] ?? '',
        ]);

        return WebhookResult::success('spt_metadata_updated');
    }
}
