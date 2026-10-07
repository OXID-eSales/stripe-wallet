<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp;

use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Checkout\Headless\ContractOpeningServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserResolverInterface;
use OxidEsales\PaymentBase\Checkout\Headless\UserBasketFactoryInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AbstractAcpCheckoutService;
use OxidEsales\PaymentBase\Mcp\Acp\AcpResponseFormatterInterface;
use OxidEsales\PaymentBase\Mcp\AgentContextInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use Throwable;

/**
 * Stripe's ACP checkout service (GRAPH-QL / PS5) on payment-base's headless
 * path. `create_checkout` is the base class's default: the agent's buyer
 * becomes a shop user, the items a user basket paying with Stripe Wallet, and
 * the contract is opened through the same chain as every checkout (early
 * order, PENDING) - no Checkout Session, the agent pays with a token.
 * `complete_checkout` charges that delegated payment token as a confirmed
 * PaymentIntent (the module's capture mode decides automatic vs manual) and
 * commits through the service the webhooks and the headless return use.
 *
 * Reachability: the MCP / UCP transport (server, tools, auth guard,
 * controllers) is a separate feature; this is the provider half it needs.
 *
 * @since 3.4.0
 */
final class StripeAcpCheckoutService extends AbstractAcpCheckoutService
{
    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly ModuleConfigurationServiceInterface $config,
        ContractServiceInterface $contractService,
        ContractRepositoryInterface $contractRepository,
        EventDispatcherInterface $eventDispatcher,
        AcpResponseFormatterInterface $formatter,
        ?ContractOpeningServiceInterface $contractOpening = null,
        ?UserBasketFactoryInterface $userBaskets = null,
        ?GuestUserResolverInterface $buyers = null,
        ?ContractCommitServiceInterface $contractCommit = null
    ) {
        parent::__construct(
            $contractService,
            $contractRepository,
            $eventDispatcher,
            $formatter,
            $contractOpening,
            $userBaskets,
            $buyers,
            $contractCommit
        );
    }

    protected function paymentId(): string
    {
        return StripeDefinitions::STRIPE_WALLET_PAYMENT_ID;
    }

    protected function providerName(): string
    {
        return StripeDefinitions::PROVIDER;
    }

    protected function completePayment(
        PaymentContractInterface $contract,
        array $paymentData,
        AgentContextInterface $agentContext
    ): array {
        $rawToken = $paymentData['token'] ?? '';
        $token = is_string($rawToken) ? $rawToken : '';
        $rawOrderNumber = $contract->getMetadata('order_number');
        $orderNumber = is_scalar($rawOrderNumber) ? (string) $rawOrderNumber : '';
        $directCapture = $this->config->getCaptureMode() === StripeDefinitions::CAPTURE_MODE_AUTOMATIC;

        try {
            $payment = $this->adapterFactory->getStripeAdapter()->createPayment(new CreatePaymentRequest(
                amount: $contract->getAmount(),
                currency: $contract->getCurrency(),
                orderId: (string) $contract->getOrderId(),
                shopId: $this->shopAdapter->getShopId(),
                paymentMethod: 'card',
                directCapture: $directCapture,
                paymentMethodId: $token,
                metadata: [
                    'contract_id' => (string) $contract->getId(),
                    'order_id' => (string) $contract->getOrderId(),
                    'order_number' => $orderNumber,
                    'channel' => 'acp',
                    'agent_id' => $agentContext->getAgentId(),
                ],
            ));
        } catch (Throwable $e) {
            return $this->formatter->validationError(
                'Stripe could not charge the delegated payment token: ' . $e->getMessage(),
                'payment_data.token'
            );
        }

        $confirmed = in_array(
            $payment->status,
            [StripeStatusMapper::STATUS_CAPTURED, StripeStatusMapper::STATUS_AUTHORIZED],
            true
        );
        if (!$confirmed) {
            return $this->formatter->validationError(
                sprintf('Stripe did not confirm the payment (status: %s)', $payment->status),
                'payment_data.token'
            );
        }

        $outcome = $this->commitPaid(
            $contract,
            $payment->providerPaymentId,
            $payment->providerPaymentId,
            $payment->amount,
            $payment->currency,
            $payment->status === StripeStatusMapper::STATUS_AUTHORIZED
        );
        if (!$outcome->isSettled()) {
            return $this->formatter->validationError(sprintf(
                'Stripe charged PaymentIntent %s but the order could not be committed: %s',
                $payment->providerPaymentId,
                (string) $outcome->reason
            ));
        }

        return $this->formatter->formatOrder($contract, $this->shopAdapter->getShopUrl() . 'index.php?cl=account_order');
    }
}
