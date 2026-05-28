<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\Payments\Stripe\Module;
use Throwable;

/**
 * Provides the human-readable description of the Stripe module from metadata.php.
 *
 * Sprint 114.11b (S2): extracted from ModuleConfigurationService to honor SRP.
 * Previously the service mixed metadata.php extraction with typed setting access;
 * this class owns only the description-extraction concern.
 *
 * Used as the `description` field when registering a webhook endpoint on Stripe;
 * the merchant sees it as the endpoint's label in the Stripe Dashboard.
 *
 * @since 2.0.0
 */
class ModuleDescriptionProvider
{
    public function __construct(
        private readonly ContextInterface $context,
        private readonly ModuleConfigurationDaoInterface $moduleConfigurationDao,
    ) {
    }

    /**
     * Returns metadata.php's `description.en` for this module.
     *
     * Falls back to the first available translation, then to an empty string when
     * the module is not yet activated (so the registrar can pass an empty string
     * to Stripe rather than crashing).
     */
    public function getModuleDescription(): string
    {
        try {
            $moduleConfig = $this->moduleConfigurationDao->get(Module::MODULE_ID, $this->context->getCurrentShopId());
        } catch (Throwable) {
            return '';
        }

        $descriptions = $moduleConfig->getDescription();

        if (isset($descriptions['en']) && is_string($descriptions['en'])) {
            return $descriptions['en'];
        }

        $first = reset($descriptions);
        return is_string($first) ? $first : '';
    }
}
