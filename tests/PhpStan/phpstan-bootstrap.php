<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);


class_alias(
    \OxidEsales\Eshop\Application\Controller\Admin\PaymentMain::class,
    \OxidEsales\Eshop\Application\Controller\Admin\PaymentMain_parent::class
);

class_alias(
    OxidEsales\EshopCommunity\Application\Controller\Admin\ModuleConfiguration::class,
    OxidEsales\Payments\Stripe\Controller\Admin\ModuleConfiguration_parent::class
);

class_alias(
    OxidEsales\Eshop\Application\Model\Order::class,
    OxidEsales\Payments\Stripe\Model\Order_parent::class
);

class_alias(
    OxidEsales\Eshop\Core\ViewConfig::class,
    OxidEsales\Payments\Stripe\Core\ViewConfig_parent::class
);

class_alias(
    OxidEsales\Eshop\Application\Controller\PaymentController::class,
    OxidEsales\Payments\Stripe\Controller\PaymentController_parent::class
);

class_alias(
    OxidEsales\Eshop\Application\Controller\OrderController::class,
    OxidEsales\Payments\Stripe\Controller\StripeOrderController_parent::class
);
