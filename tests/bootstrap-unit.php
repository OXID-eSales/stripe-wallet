<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * Bootstrap for the standalone unit suite — no shop, no database.
 *
 * The module's own vendor/ carries oxideshop-ce plus the unified namespace
 * generator, so `OxidEsales\Eshop\*` resolves from there. What is missing are
 * the `*_parent` classes: OXID's ModuleChainsGenerator creates those aliases at
 * module activation, so they cannot exist outside a booted shop. Unit tests
 * never instantiate a real parent — they use testable subclasses — so a minimal
 * stub is enough to let the extending class be autoloaded.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// oxideshop-ce ships oxNew(), getLogger() and friends as plain function
// definitions. Registry and the shop classes call them, so the standalone
// suite needs them declared — the file defines functions only, it boots
// nothing.
// The shop bootstrap normally defines this; Config and friends read it.
if (!defined('OX_BASE_PATH')) {
    define('OX_BASE_PATH', realpath(__DIR__ . '/../vendor/oxid-esales/oxideshop-ce/source') . DIRECTORY_SEPARATOR);
}

foreach (
    [
        '/../vendor/oxid-esales/oxideshop-ce/source/overridablefunctions.php',
        '/../vendor/oxid-esales/oxideshop-ce/source/oxfunctions.php',
    ] as $functionFile
) {
    $path = __DIR__ . $functionFile;
    if (file_exists($path)) {
        require_once $path;
    }
}

if (!class_exists(\OxidEsales\Payments\Stripe\Controller\PaymentController_parent::class, false)) {
    eval(
        'namespace OxidEsales\\Payments\\Stripe\\Controller; '
        . 'class PaymentController_parent { '
        . '  public function __construct() {} '
        . '  public function init(): void {} '
        . '  public function render() { return ""; } '
        . '  public function validatePayment() { return null; } '
        . '  public function getUser() { return null; } '
        . '  public function getBasket() { return false; } '
        . '  public function addTplParam($name, $value): void {} '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Payments\Stripe\Controller\StripeOrderController_parent::class, false)) {
    eval(
        'namespace OxidEsales\\Payments\\Stripe\\Controller; '
        . 'class StripeOrderController_parent { '
        . '  public function __construct() {} '
        . '  public function init(): void {} '
        . '  public function render() { return ""; } '
        . '  public function getUser() { return null; } '
        . '  public function getBasket() { return false; } '
        . '  public function getPayment() { return false; } '
        . '  public function addTplParam($name, $value): void {} '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Payments\Stripe\Controller\Admin\ModuleConfiguration_parent::class, false)) {
    eval(
        'namespace OxidEsales\\Payments\\Stripe\\Controller\\Admin; '
        . 'class ModuleConfiguration_parent { '
        . '  public function __construct() {} '
        . '  public function render() { return ""; } '
        . '  public function getEditObjectId(): string|bool { return false; } '
        . '  public function addTplParam($name, $value): void {} '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Payments\Stripe\Core\ViewConfig_parent::class, false)) {
    eval(
        'namespace OxidEsales\\Payments\\Stripe\\Core; '
        . 'class ViewConfig_parent { '
        . '  public function __construct() {} '
        . '  protected function getServerName(): string { return ""; } '
        . '  public function getModuleUrl($module, $file = ""): string { return ""; } '
        . '}'
    );
}

if (!class_exists(\OxidEsales\Payments\Stripe\Model\Order_parent::class, false)) {
    eval(
        'namespace OxidEsales\\Payments\\Stripe\\Model; '
        . 'class Order_parent { '
        . '  public function __construct() {} '
        . '  public function load($oxid) { return false; } '
        . '  public function getId() { return null; } '
        . '  public function getFieldData($field) { return null; } '
        . '  public function getCounterIdent() { return "oxOrder"; } '
        . '  protected function executePayment(\\OxidEsales\\Eshop\\Application\\Model\\Basket $oBasket, $oUserpayment) { return true; } '
        . '  public function validateDeliveryAddress($user) { return 0; } '
        . '  public function delete($oxid = null) { return true; } '
        . '  public function cancelOrder(): void {} '
        . '}'
    );
}

/**
 * A shop Config that answers from memory instead of from the database.
 *
 * Registry::getConfig() is reachable from a handful of display-only code paths
 * (currency name, productive mode). The real Config resolves those through
 * DatabaseProvider, which needs a configured shop — exactly what this suite
 * does without. Returning `false` for the currency is not a shortcut: it is
 * what Config itself returns when no currency row exists (`reset([])`).
 */
\OxidEsales\Eshop\Core\Registry::set(
    \OxidEsales\Eshop\Core\Config::class,
    new class extends \OxidEsales\Eshop\Core\Config {
        public function getConfigParam($name, $default = null)
        {
            // Without these two the shop code takes the literal placeholders
            // from config.inc.php.dist and creates directories called
            // "<sCompileDir>" / "<sShopDir>" in the working directory.
            return match ($name) {
                'sCompileDir' => sys_get_temp_dir() . '/oxid-unit-tests',
                'sShopDir'    => OX_BASE_PATH,
                default       => $default,
            };
        }

        public function getShopId()
        {
            return 1;
        }

        public function isProductiveMode()
        {
            return true;
        }

        public function getActShopCurrencyObject()
        {
            return false;
        }
    }
);
