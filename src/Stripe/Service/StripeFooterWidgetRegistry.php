<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\OnePageCheckout\Service\FooterWidgetRegistry;
use Psr\Log\LoggerInterface;

/**
 * Stripe Footer Widget Registry Decorator
 *
 * CRITICAL: This is the DEFINITIVE solution to the "non-persistent registry" problem.
 *
 * Problem:
 * - FooterWidgetRegistry stores widgets in PHP array ($this->widgets)
 * - Array lives only during one HTTP request
 * - Events::onActivate() runs ONCE during module activation
 * - Registration is lost after activation request completes
 * - Method calls in services.yaml don't work reliably (module load order issue)
 *
 * Solution:
 * - Extend FooterWidgetRegistry for type compatibility
 * - Store inner registry and delegate ALL operations to it
 * - Constructor registers Stripe widget in INNER registry (not parent)
 * - Override all methods to delegate to inner (ignore parent's $widgets array)
 *
 * This is the GOLD STANDARD pattern for decorating services in Symfony/OXID 7.
 *
 * IMPORTANT: We extend for type compatibility, but NEVER use parent's state ($widgets).
 *            All operations delegate to $innerRegistry.
 *
 * @since STRP-XXX Footer widget architecture
 */
class StripeFooterWidgetRegistry extends FooterWidgetRegistry
{
    private FooterWidgetRegistry $innerRegistry;

    public function __construct(
        FooterWidgetRegistry $innerRegistry,
        ?LoggerInterface $logger = null
    ) {
        // Call parent constructor to satisfy PHP requirements, but we won't use parent's state
        parent::__construct($logger);

        $this->innerRegistry = $innerRegistry;

        // Register Stripe widget IMMEDIATELY in INNER registry (not parent)
        // Use module ID as payment method ID (oe_payments_stripe_wallet, not oxidstripe)
        $this->innerRegistry->registerWidget('oe_payments_stripe_wallet', 'stripecheckoutfooter');
    }

    // Override ALL methods to delegate to inner registry
    // This ensures parent's $widgets array is never used

    public function registerWidget(string $paymentMethodId, string $widgetClass): void
    {
        $this->innerRegistry->registerWidget($paymentMethodId, $widgetClass);
    }

    public function getWidget(string $paymentMethodId): ?string
    {
        return $this->innerRegistry->getWidget($paymentMethodId);
    }

    public function hasWidget(string $paymentMethodId): bool
    {
        return $this->innerRegistry->hasWidget($paymentMethodId);
    }

    public function unregisterWidget(string $paymentMethodId): void
    {
        $this->innerRegistry->unregisterWidget($paymentMethodId);
    }

    public function getAllWidgets(): array
    {
        return $this->innerRegistry->getAllWidgets();
    }

    public function clearAll(): void
    {
        $this->innerRegistry->clearAll();
    }
}
