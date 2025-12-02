<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Core;

use OxidSolutionCatalysts\Payments\Stripe\Core\Events;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * TDD Tests for Events.php cleanup
 *
 * Sprint 2 Phase 4: Events.php should NOT create redundant tables.
 * Table creation should be handled by migrations only.
 *
 * Tables to remove from Events.php:
 * - osc_payment_webhook_log (use osc_payment_webhooklogs from migration)
 * - osc_stripe_customer_mapping (use osc_payment_customer from migration)
 * - osc_stripe_payment_details (unused)
 *
 * @group sprint-2
 * @group events-cleanup
 */
class EventsCleanupTest extends TestCase
{
    /**
     * @test
     * RED: Events.php should not create osc_payment_webhook_log table
     */
    public function eventsDoesNotCreateWebhookLogTable(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString(
            'osc_payment_webhook_log',
            $source,
            'Events.php should not create osc_payment_webhook_log table (use osc_payment_webhooklogs from migration)'
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
            'Events.php should not create osc_stripe_customer_mapping table (use osc_payment_customer from migration)'
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
     * Events.php should only add columns to OXID core tables
     */
    public function eventsOnlyAddsColumnsToOxidTables(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        // Should have ALTER TABLE for OXID tables
        $this->assertStringContainsString('ALTER TABLE `oxorder`', $source);
        $this->assertStringContainsString('ALTER TABLE `oxorderarticles`', $source);
        $this->assertStringContainsString('ALTER TABLE `oxuser`', $source);
    }

    /**
     * @test
     * Events.php should not create redundant osc_ tables (those handled by migrations)
     *
     * Allowed tables (created by Events.php for activation):
     * - osc_payment_transaction: Core transaction table
     * - osc_payment_order_state: Payment state tracking
     *
     * Forbidden tables (use migrations):
     * - osc_payment_webhook_log -> use osc_payment_webhooklogs
     * - osc_stripe_customer_mapping -> use osc_payment_customer
     * - osc_stripe_payment_details -> unused
     */
    public function eventsDoesNotCreateRedundantOscTables(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        // Find all CREATE TABLE statements for osc_ tables
        preg_match_all('/CREATE TABLE[^`]*`(osc_[^`]+)`/', $source, $matches);

        // Allowed tables that can be created by Events.php
        $allowedTables = [
            'osc_payment_transaction',
            'osc_payment_order_state',
        ];

        // Filter out allowed tables
        $forbiddenTables = array_diff($matches[1], $allowedTables);

        $this->assertEmpty(
            $forbiddenTables,
            'Events.php should not CREATE redundant osc_ tables. Found: ' . implode(', ', $forbiddenTables)
        );
    }
}
