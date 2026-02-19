<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\DataProtection;

use PHPUnit\Framework\TestCase;

/**
 * Documents Finding F11: Session stores stripe_client_secret (Stripe PI client_secret).
 *
 * The client_secret allows client-side confirmation of a PaymentIntent.
 * Storing it in the server-side session creates an additional attack surface.
 *
 * @group security
 * @group pci-dss
 * @group finding-f11
 * @group sprint-58
 */
final class SessionSensitiveDataTest extends TestCase
{
    /**
     * Known session keys used by the Stripe module that contain sensitive data.
     */
    private const SENSITIVE_SESSION_KEYS = [
        'stripe_client_secret',
        'stripeClientSecret',
    ];

    /**
     * @test
     *
     * Finding F11: Documents all session keys containing sensitive Stripe data.
     */
    public function testSessionKeysContainingSensitiveStripeData(): void
    {
        $sourceDir = dirname(__DIR__, 3) . '/src';
        if (!is_dir($sourceDir)) {
            $this->markTestSkipped('Source directory not found');
        }

        $sensitiveKeysFound = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            foreach (self::SENSITIVE_SESSION_KEYS as $key) {
                if (str_contains($content, $key)) {
                    $sensitiveKeysFound[$key][] = $file->getPathname();
                }
            }
        }

        // Document: these sensitive keys ARE used in the codebase
        $this->assertNotEmpty(
            $sensitiveKeysFound,
            'Expected to find sensitive session keys in the source code'
        );
    }

    /**
     * @test
     *
     * Finding F11: The stripe_client_secret is stored in session variables.
     */
    public function testClientSecretReferencedInSource(): void
    {
        $controllerFile = dirname(__DIR__, 3) . '/src/Stripe/Controller/StripeOrderController.php';
        if (!file_exists($controllerFile)) {
            $this->markTestSkipped('StripeOrderController not found');
        }

        $source = file_get_contents($controllerFile);
        $this->assertIsString($source);

        // Documents that stripe_client_secret is used as a session variable
        $this->assertStringContainsString(
            'stripe_client_secret',
            $source,
            'StripeOrderController references stripe_client_secret session variable'
        );
    }

    /**
     * @test
     *
     * Finding F11: Verify that deleteSessionVariable is called for cleanup.
     */
    public function testClearStripeSessionRemovesSensitiveKeys(): void
    {
        $controllerFile = dirname(__DIR__, 3) . '/src/Stripe/Controller/StripeOrderController.php';
        if (!file_exists($controllerFile)) {
            $this->markTestSkipped('StripeOrderController not found');
        }

        $source = file_get_contents($controllerFile);
        $this->assertIsString($source);

        // Verify cleanup code exists
        $this->assertStringContainsString(
            'deleteSessionVariable',
            $source,
            'Must call deleteSessionVariable to clean up sensitive session data'
        );
    }
}
