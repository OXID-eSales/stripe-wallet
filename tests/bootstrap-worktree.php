<?php

// Throw-away (never committed): the shop with its own libraries, Stripe's classes and tests from this worktree,
// payment-base from the wave-5 worktree.
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $map = [
        'OxidEsales\\Payments\\Stripe\\Tests\\' => __DIR__ . '/',
        'OxidEsales\\Payments\\Stripe\\' => __DIR__ . '/../src/Stripe/',
        'OxidEsales\\PaymentBase\\Tests\\' => null,
        'OxidEsales\\PaymentBase\\' => '/var/www/extensions/payment-base/.claude/worktrees/VR-wave5/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            if ($dir === null) {
                return;
            }
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
}, true, true);
