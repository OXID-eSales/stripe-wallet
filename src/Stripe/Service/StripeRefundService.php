<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentComponent\Service\AbstractPaymentRefundService;
use Psr\Log\LoggerInterface;

/**
 * Stripe-specific implementation of payment refund service.
 *
 * Sprint 3: Extends AbstractPaymentRefundService with Stripe-specific behavior.
 * Uses contract-based refund approach (per Q&A decision Q6).
 *
 * For now, uses all default behavior from AbstractPaymentRefundService:
 * - Validates FULFILLED state before refund
 * - Logs refund to transaction repository
 *
 * Future enhancements might include:
 * - Setting contract to REFUNDED state on full refund
 * - Stripe-specific webhook integration
 *
 * @since 2.0.0
 */
class StripeRefundService extends AbstractPaymentRefundService
{
    // Uses all default behavior from AbstractPaymentRefundService
    // Stripe-specific customizations can be added here by overriding hook methods
}
