<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use OxidEsales\Payments\Stripe\Core\Events;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * TDD Tests for Events.php cleanup
 *
 * Sprint 2 Phase 4: Events.php should NOT create redundant tables.
 * Sprint 5: Events.php should NOT add columns to core OXID tables.
 *
 * Architecture Rules:
 * - NO ALTER TABLE on oxorder, oxorderarticles, oxuser
 * - NO CREATE TABLE (use Doctrine migrations)
 * - All STRIPE* column additions disabled
 *
 * @group sprint-2
 * @group sprint-5
 * @group events-cleanup
 * @group unit
 */
class EventsCleanupTest extends TestCase
{
    /**
     * @test
     * RED: Events.php should not create oe_payments_webhook_log table
     */
    public function eventsDoesNotCreateWebhookLogTable(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString(
            'oe_payments_webhook_log',
            $source,
            'Events.php should not create oe_payments_webhook_log table (use oe_payments_webhooklogs from migration)'
        );
    }

    /**
     * @test
     * RED: Events.php should not create osc_stripe_customer_mapping table
     */
    public function eventsDoesNotCreateCustomerMappingTable(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString(
            'osc_stripe_customer_mapping',
            $source,
            'Events.php should not create osc_stripe_customer_mapping table (use oe_payments_stripe_customer from migration)'
        );
    }

    /**
     * @test
     * RED: Events.php should not create osc_stripe_payment_details table
     */
    public function eventsDoesNotCreatePaymentDetailsTable(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString(
            'osc_stripe_payment_details',
            $source,
            'Events.php should not create osc_stripe_payment_details table (unused)'
        );
    }

    /**
     * @test
     * Sprint 5: Events.php should NOT add STRIPE* columns to oxorder table
     */
    public function eventsDoesNotAddStripeColumnsToOxorder(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        // Find active (non-commented) addColumnIfNotExists calls for oxorder
        $lines = explode("\n", $source);
        $activeOxorderColumns = [];

        foreach ($lines as $lineNum => $line) {
            $trimmedLine = trim($line);
            // Skip comments
            if (str_starts_with($trimmedLine, '//') || str_starts_with($trimmedLine, '*')) {
                continue;
            }
            // Check for active STRIPE column additions to oxorder
            if (preg_match("/addColumnIfNotExists\s*\(\s*'oxorder'\s*,\s*'STRIPE/", $trimmedLine)) {
                $activeOxorderColumns[] = "Line " . ($lineNum + 1);
            }
        }

        $this->assertEmpty(
            $activeOxorderColumns,
            "Sprint 5: Events.php should NOT have active STRIPE* column additions to oxorder.\n" .
            "Found at: " . implode(", ", $activeOxorderColumns)
        );
    }

    /**
     * @test
     * Sprint 5: Events.php should NOT add STRIPE* columns to oxorderarticles table
     */
    public function eventsDoesNotAddStripeColumnsToOxorderarticles(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        $lines = explode("\n", $source);
        $activeColumns = [];

        foreach ($lines as $lineNum => $line) {
            $trimmedLine = trim($line);
            if (str_starts_with($trimmedLine, '//') || str_starts_with($trimmedLine, '*')) {
                continue;
            }
            if (preg_match("/addColumnIfNotExists\s*\(\s*'oxorderarticles'\s*,\s*'STRIPE/", $trimmedLine)) {
                $activeColumns[] = "Line " . ($lineNum + 1);
            }
        }

        $this->assertEmpty(
            $activeColumns,
            "Sprint 5: Events.php should NOT have active STRIPE* column additions to oxorderarticles.\n" .
            "Found at: " . implode(", ", $activeColumns)
        );
    }

    /**
     * @test
     * Sprint 5: Events.php should NOT add STRIPE* columns to oxuser table
     */
    public function eventsDoesNotAddStripeColumnsToOxuser(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        $lines = explode("\n", $source);
        $activeColumns = [];

        foreach ($lines as $lineNum => $line) {
            $trimmedLine = trim($line);
            if (str_starts_with($trimmedLine, '//') || str_starts_with($trimmedLine, '*')) {
                continue;
            }
            if (preg_match("/addColumnIfNotExists\s*\(\s*'oxuser'\s*,\s*'STRIPE/", $trimmedLine)) {
                $activeColumns[] = "Line " . ($lineNum + 1);
            }
        }

        $this->assertEmpty(
            $activeColumns,
            "Sprint 5: Events.php should NOT have active STRIPE* column additions to oxuser.\n" .
            "Found at: " . implode(", ", $activeColumns)
        );
    }

    /**
     * @test
     * Sprint 5: Events.php should NOT create oe_payments_* tables (use migrations)
     */
    public function eventsDoesNotCreateOscPaymentTables(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        // Find active (non-commented) CREATE TABLE statements
        $lines = explode("\n", $source);
        $activeCreateTables = [];

        foreach ($lines as $lineNum => $line) {
            $trimmedLine = trim($line);
            // Skip comments
            if (str_starts_with($trimmedLine, '//') || str_starts_with($trimmedLine, '*')) {
                continue;
            }
            // Check for active CREATE TABLE with osc_payment
            if (preg_match('/CREATE\s+TABLE.*osc_payment/i', $trimmedLine)) {
                $activeCreateTables[] = "Line " . ($lineNum + 1) . ": " . substr($trimmedLine, 0, 60);
            }
        }

        $this->assertEmpty(
            $activeCreateTables,
            "Sprint 5: Events.php should NOT have active CREATE TABLE for oe_payments_* tables.\n" .
            "These should be in migrations. Found:\n" . implode("\n", $activeCreateTables)
        );
    }

    /**
     * @test
     * Sprint 5: Events.php should NOT have addDatabaseStructure method
     */
    public function eventsDoesNotHaveAddDatabaseStructureMethod(): void
    {
        $reflection = new ReflectionClass(Events::class);

        $this->assertFalse(
            $reflection->hasMethod('addDatabaseStructure'),
            'Sprint 5: Events.php should NOT have addDatabaseStructure() method - removed completely'
        );
    }

    /**
     * @test
     * Sprint 5: Events.php should NOT have addStandardCheckoutTables method
     */
    public function eventsDoesNotHaveAddStandardCheckoutTablesMethod(): void
    {
        $reflection = new ReflectionClass(Events::class);

        $this->assertFalse(
            $reflection->hasMethod('addStandardCheckoutTables'),
            'Sprint 5: Events.php should NOT have addStandardCheckoutTables() method - removed completely'
        );
    }

    /**
     * @test
     * Sprint 5: Events.php should state that migrations handle database schema
     */
    public function eventsDocumentationMentionsMigrations(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            'Doctrine migrations',
            $source,
            'Sprint 5: Events.php should mention that Doctrine migrations handle database schema'
        );
    }
}
