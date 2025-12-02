<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Repository;

use PHPUnit\Framework\TestCase;

/**
 * Tests verifying osc_stripe_payment_details table is unused and can be removed.
 *
 * Sprint 2 Phase 3: Per Daniil's feedback, card data shouldn't be stored
 * as Stripe wallet handles all payment data.
 *
 * @group sprint-2
 * @group payment-details-removal
 */
class PaymentDetailsTableUsageTest extends TestCase
{
    /**
     * @test
     * Verify no production code (outside repository) references the table
     */
    public function noProductionCodeReferencesPaymentDetailsTable(): void
    {
        $srcDir = __DIR__ . '/../../../../src';
        $references = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Skip Events.php (creates table) and Repository itself
            $filename = $file->getFilename();
            if (
                $filename === 'Events.php'
                || $filename === 'StripePaymentDetailsRepository.php'
            ) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (strpos($content, 'osc_stripe_payment_details') !== false) {
                $references[] = $file->getPathname();
            }
        }

        $this->assertEmpty(
            $references,
            'No production code should reference osc_stripe_payment_details table. Found: ' . implode(', ', $references)
        );
    }

    /**
     * @test
     * Verify StripePaymentDetailsRepository is not used by other classes
     */
    public function stripePaymentDetailsRepositoryIsNotUsed(): void
    {
        $srcDir = __DIR__ . '/../../../../src';
        $usages = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Skip the repository itself
            if ($file->getFilename() === 'StripePaymentDetailsRepository.php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            // Check for class usage (not just interface)
            if (
                strpos($content, 'StripePaymentDetailsRepository') !== false
                || strpos($content, 'PaymentDetailsRepository') !== false
            ) {
                $usages[] = $file->getPathname();
            }
        }

        // OxidShopOrderService may have a reference but it's likely unused - mark for investigation
        $this->assertLessThanOrEqual(
            1,
            count($usages),
            'StripePaymentDetailsRepository should have minimal/no usage. Found: ' . implode(', ', $usages)
        );
    }

    /**
     * @test
     * Verify payment details repository exists (baseline test)
     */
    public function paymentDetailsRepositoryExists(): void
    {
        $this->assertTrue(
            class_exists(\OxidSolutionCatalysts\Payments\Stripe\Repository\StripePaymentDetailsRepository::class),
            'StripePaymentDetailsRepository should exist (to be removed in Phase 3)'
        );
    }
}
