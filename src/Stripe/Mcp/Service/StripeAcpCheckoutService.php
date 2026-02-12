<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AbstractAcpCheckoutService;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpResponseFormatterInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;

class StripeAcpCheckoutService extends AbstractAcpCheckoutService
{
    public function __construct(
        ContractServiceInterface $contractService,
        ContractRepositoryInterface $contractRepository,
        EventDispatcherInterface $eventDispatcher,
        AcpResponseFormatterInterface $formatter,
        private readonly SptPaymentServiceInterface $sptPaymentService,
        private readonly ShopAdapterInterface $shopAdapter
    ) {
        parent::__construct($contractService, $contractRepository, $eventDispatcher, $formatter);
    }

    public function createCheckout(array $arguments, AgentContext $agentContext): array
    {
        $context = new EventContext([
            'acp_items' => $arguments['items'] ?? [],
            'acp_buyer' => $arguments['buyer'] ?? [],
            'acp_fulfillment_address' => $arguments['fulfillment_address'] ?? [],
            'acp_currency' => $arguments['currency'] ?? 'EUR',
            'acp_agent_id' => $agentContext->getAgentId(),
            'source' => 'acp',
        ]);

        $event = new StripeCheckoutSessionRequestEvent($context);
        $this->eventDispatcher->dispatch($event);

        $contract = $context->getContract();
        if ($contract === null) {
            return $this->formatter->validationError('Failed to create checkout session');
        }

        return $this->formatter->formatCheckout($contract);
    }

    protected function completePayment(
        PaymentContractInterface $contract,
        array $paymentData,
        AgentContext $agentContext
    ): array {
        /** @var string $sptToken */
        $sptToken = $paymentData['token'] ?? '';
        /** @var array<string, mixed> $billingAddress */
        $billingAddress = $paymentData['billing_address'] ?? [];

        $result = $this->sptPaymentService->confirmWithSpt($contract, $sptToken, $billingAddress);

        if (!$result->isSuccessful()) {
            return $this->formatter->validationError(
                $result->getErrorMessage() ?? 'Payment failed'
            );
        }

        $contractId = $contract->getId();
        if ($contractId === null) {
            return $this->formatter->validationError('Contract has no ID — cannot complete payment');
        }

        $context = new EventContext([
            'paymentIntentId' => $result->getPaymentIntentId(),
            'source' => 'acp_spt',
        ]);
        $context->setContract($contract);

        $paymentIntentId = $result->getPaymentIntentId() ?? '';
        $authorizedEvent = new PaymentAuthorizedEvent(
            $context,
            $paymentIntentId,
            $contractId,
            $contract->getAmount(),
            $contract->getCurrency()
        );
        $this->eventDispatcher->dispatch($authorizedEvent);

        $orderId = $contract->getOrderId();
        $orderPermalink = $this->shopAdapter->getShopUrl() . '?cl=order_confirm&order=' . $orderId;

        return $this->formatter->formatOrder($contract, $orderPermalink);
    }
}
