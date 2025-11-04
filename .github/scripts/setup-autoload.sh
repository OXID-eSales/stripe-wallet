#!/bin/bash
set -e

# Add module test namespace to root composer.json autoload-dev
php -r '
$file = "composer.json";
$json = json_decode(file_get_contents($file), true);

// Initialize autoload-dev structure if needed
if (!isset($json["autoload-dev"])) {
    $json["autoload-dev"] = [];
}
if (!isset($json["autoload-dev"]["psr-4"])) {
    $json["autoload-dev"]["psr-4"] = [];
}

// Add the test namespace (only if not already present)
$namespace = "OxidSolutionCatalysts\\Payments\\Tests\\";
if (!isset($json["autoload-dev"]["psr-4"][$namespace])) {
    $json["autoload-dev"]["psr-4"][$namespace] = "./extensions/stripe/tests";
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    echo "Added test namespace to composer.json\n";
} else {
    echo "Test namespace already configured\n";
}
'

# Regenerate autoloader
composer dump-autoload
