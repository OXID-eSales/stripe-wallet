<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;

/**
 * Builds the `stripe` details of a payment contract: the intent id the order keeps (`oxtransid`), the reference
 * checkout stored on the contract, the dashboard link of the mode in use, and what Stripe says about the intent.
 */
final class StripePaymentDetailsReader implements StripePaymentDetailsReaderInterface
{
    private const DASHBOARD = 'https://dashboard.stripe.com';

    public function __construct(
        private readonly ContractRepositoryInterface $contracts,
        private readonly OrderPaymentIntentIdReaderInterface $intentIds,
        private readonly StripePaymentIntentInsightInterface $insights,
        private readonly ModuleConfigurationServiceInterface $config,
    ) {
    }

    public function find(string $contractId): ?StripePaymentDetails
    {
        $contract = $this->contracts->findById($contractId);
        if ($contract === null || $contract->getProvider() !== StripeDefinitions::PROVIDER) {
            return null;
        }
        $orderId = $contract->getOrderId();
        $intentId = $orderId === null ? '' : $this->intentIds->intentIdOf($orderId);
        $reference = $contract->getProviderOrderId();
        if ($intentId === '') {
            return new StripePaymentDetails(null, null, $this->nullIfEmpty($reference), null, null, null);
        }
        $facts = $this->insights->factsOf($intentId);

        return new StripePaymentDetails(
            paymentIntentId: $intentId,
            chargeId: $facts->chargeId,
            externalTransactionId: $this->nullIfEmpty($reference),
            intentStatus: $facts->status,
            dashboardUrl: $this->dashboardUrl($intentId),
            apiError: $facts->apiError,
        );
    }

    private function dashboardUrl(string $intentId): string
    {
        $segment = $this->config->isTestMode() ? '/test' : '';

        return self::DASHBOARD . $segment . '/payments/' . rawurlencode($intentId);
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
