<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

// Load the stripe extension's vendor autoloader if it exists
$extensionAutoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($extensionAutoloader)) {
    require_once $extensionAutoloader;
}

// Load the OXID shop bootstrap (which loads shop's vendor autoloader)
$shopBootstrap = __DIR__ . '/../../../source/bootstrap.php';
if (file_exists($shopBootstrap)) {
    require_once $shopBootstrap;
} else {
    // Fallback: try to load from absolute path (when running from shop root)
    $alternativeBootstrap = '/var/www/source/bootstrap.php';
    if (file_exists($alternativeBootstrap)) {
        require_once $alternativeBootstrap;
    } else {
        throw new \RuntimeException('Could not find OXID shop bootstrap.php');
    }
}
