<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Module;

/**
 * Registry-backed storage of the module's oxconfig variables, the same calls the classic ModuleConfiguration
 * controller and ModuleConfigurationService make.
 */
final class OxidModuleConfVars implements ModuleConfVarsInterface
{
    private const MODULE_NAMESPACE = 'module:' . Module::MODULE_ID;

    public function read(string $name): string
    {
        // getShopConfVar() is typed object by OXID's PHPDoc but returns what was stored.
        /** @var mixed $value */
        $value = Registry::getConfig()->getShopConfVar($name, null, self::MODULE_NAMESPACE);

        return is_string($value) ? $value : '';
    }
}
