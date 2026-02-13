<?php

/**
 * Bootstrap file for Stripe Module Tests
 *
 * This bootstrap loads the OXID shop context for both unit and integration tests.
 * It provides access to oxNew(), Registry, and other shop functions.
 *
 * Usage:
 *   docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
 *   docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration
 */

declare(strict_types=1);

// Find the shop bootstrap - try multiple possible locations
$possibleBootstraps = [
    // Docker SDK environment - running from /var/www (shop source at /var/www/source/)
    '/var/www/source/bootstrap.php',
    // When running from shop root (source/extensions/stripe/tests/)
    dirname(__DIR__, 4) . '/source/bootstrap.php',
    // Alternative: shop root is parent of extensions
    dirname(__DIR__, 3) . '/bootstrap.php',
];

$shopBootstrap = null;
foreach ($possibleBootstraps as $path) {
    if ($path !== null && file_exists($path)) {
        $shopBootstrap = $path;
        break;
    }
}

if ($shopBootstrap === null) {
    throw new RuntimeException(
        'OXID shop bootstrap not found. Tests must be run from shop context. ' .
        'Searched paths: ' . implode(', ', array_filter($possibleBootstraps))
    );
}

// Load shop bootstrap (includes shop's autoloader and oxfunctions.php which defines oxNew())
require_once $shopBootstrap;

// Load tests/.env file (makes credentials available via getenv() and $_ENV)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

// Register autoloader for test classes (autoload-dev may not be loaded in some contexts)
$testDir = __DIR__;
spl_autoload_register(static function (string $class) use ($testDir): void {
    $prefix = 'OxidEsales\\Payments\\Stripe\\Tests\\';
    $prefixLength = strlen($prefix);

    if (strncmp($class, $prefix, $prefixLength) !== 0) {
        return;
    }

    $relativeClass = substr($class, $prefixLength);
    $file = $testDir . '/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
