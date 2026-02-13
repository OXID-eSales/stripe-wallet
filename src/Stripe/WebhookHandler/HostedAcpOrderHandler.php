<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\WebhookHandler;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

final class HostedAcpOrderHandler implements WebhookEventHandlerInterface
{
    private const EVENT_TYPE = 'checkout_session.completed';

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
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        if (($metadata['source'] ?? '') !== 'agentic_commerce') {
            return WebhookResult::skipped('Not an agentic commerce session');
        }

        $contractId = $metadata['contract_id'] ?? null;
        if (!is_string($contractId)) {
            $this->logger->log('HostedAcpOrder: no contract_id in metadata');
            return WebhookResult::failure('error', 'Missing contract_id in hosted ACP metadata');
        }

        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            $this->logger->log('HostedAcpOrder: contract not found', ['contractId' => $contractId]);
            return WebhookResult::skipped('Contract not found for hosted ACP order');
        }

        $sessionId = isset($object['id']) && is_scalar($object['id']) ? (string) $object['id'] : '';
        $paymentIntent = isset($object['payment_intent']) && is_scalar($object['payment_intent'])
            ? (string) $object['payment_intent']
            : '';
        $customerDetails = is_array($object['customer_details'] ?? null) ? $object['customer_details'] : [];
        $email = isset($customerDetails['email']) && is_string($customerDetails['email'])
            ? $customerDetails['email']
            : '';

        $contract->setMetadata('hosted_checkout_session_id', $sessionId);
        $contract->setMetadata('hosted_payment_intent', $paymentIntent);
        $contract->setMetadata('hosted_customer_email', $email);

        $this->contractRepository->save($contract);

        $this->logger->log('HostedAcpOrder: processed', [
            'contractId' => $contractId,
            'sessionId' => $object['id'] ?? '',
        ]);

        return WebhookResult::success('hosted_acp_order_processed');
    }
}
