<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;

class SptPaymentService implements SptPaymentServiceInterface
{
    public function __construct(
        private readonly StripeAdapterInterface $stripeAdapter,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly ?FileLoggerInterface $requestLogger = null
    ) {
    }

    public function confirmWithSpt(
        PaymentContractInterface $contract,
        string $sptToken,
        array $billingAddress = []
    ): SptPaymentResult {
        $this->requestLogger?->log('SptPaymentService: Confirming with SPT', [
            'contractId' => $contract->getId(),
            'tokenPrefix' => substr($sptToken, 0, 15) . '...',
        ]);

        try {
            $request = new CreatePaymentRequest(
                amount: $contract->getAmount(),
                currency: strtolower($contract->getCurrency()),
                orderId: $contract->getOrderId() ?? '',
                shopId: $this->shopAdapter->getShopId(),
                paymentMethod: 'stripe_spt',
                paymentMethodId: $sptToken,
                billingAddress: $billingAddress !== []
                    ? array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $billingAddress)
                    : null,
                metadata: [
                    'contract_id' => $contract->getId(),
                    'source' => 'acp',
                ]
            );

            $response = $this->stripeAdapter->createPayment($request);

            $this->requestLogger?->log('SptPaymentService: Payment created', [
                'providerPaymentId' => $response->providerPaymentId,
                'status' => $response->status,
            ]);

            if ($response->status === 'succeeded' || $response->status === 'requires_capture') {
                return SptPaymentResult::success($response->providerPaymentId, $response->status);
            }

            return SptPaymentResult::failed(
                "Unexpected payment status: {$response->status}",
                $response->providerPaymentId
            );
        } catch (\Throwable $e) {
            $this->requestLogger?->log('SptPaymentService: SPT confirmation failed', [
                'error' => $e->getMessage(),
            ]);

            return SptPaymentResult::failed($e->getMessage());
        }
    }
}
