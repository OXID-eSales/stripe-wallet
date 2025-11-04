#!/bin/bash
set -e

# Determine the correct test path (CI uses test-module, local uses extensions/stripe)
if [ -d "./test-module" ]; then
    TEST_PATH="./test-module/tests"
elif [ -d "./extensions/stripe" ]; then
    TEST_PATH="./extensions/stripe/tests"
else
    echo "Error: Cannot find module directory"
    exit 1
fi

echo "Using test path: $TEST_PATH"

# Add module test namespace to root composer.json autoload-dev
php -r "
\$file = 'composer.json';
\$json = json_decode(file_get_contents(\$file), true);

// Initialize autoload-dev structure if needed
if (!isset(\$json['autoload-dev'])) {
    \$json['autoload-dev'] = [];
}
if (!isset(\$json['autoload-dev']['psr-4'])) {
    \$json['autoload-dev']['psr-4'] = [];
}

// Add the test namespace (only if not already present)
\$namespace = 'OxidSolutionCatalysts\\\\Payments\\\\Tests\\\\';
\$testPath = '$TEST_PATH';

if (!isset(\$json['autoload-dev']['psr-4'][\$namespace])) {
    \$json['autoload-dev']['psr-4'][\$namespace] = \$testPath;
    file_put_contents(\$file, json_encode(\$json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    echo \"Added test namespace to composer.json with path: \$testPath\n\";
} else {
    echo \"Test namespace already configured\n\";
}
"

# Regenerate autoloader
composer dump-autoload
