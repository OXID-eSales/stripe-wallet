<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Command;

use OxidSolutionCatalysts\Payments\Stripe\Command\ReconcileOxpaidCommand;
use OxidSolutionCatalysts\Payments\Stripe\Service\OxpaidReconciliationService;
use OxidSolutionCatalysts\Payments\Stripe\Service\ReconciliationResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for ReconcileOxpaidCommand
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Command\ReconcileOxpaidCommand
 * @group sprint-10
 * @group reconciliation
 * @group command
 */
class ReconcileOxpaidCommandTest extends TestCase
{
    private OxpaidReconciliationService $service;
    private ReconcileOxpaidCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->createMock(OxpaidReconciliationService::class);
        $this->command = new ReconcileOxpaidCommand($this->service);

        $application = new Application();
        $application->add($this->command);

        $this->tester = new CommandTester($this->command);
    }

    /**
     * @test
     */
    public function commandHasCorrectName(): void
    {
        $this->assertEquals('stripe:reconcile-oxpaid', $this->command->getName());
    }

    /**
     * @test
     */
    public function commandHasDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
    }

    /**
     * @test
     */
    public function commandHasDryRunOption(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('dry-run'));
    }

    /**
     * @test
     */
    public function commandHasMaxAgeOption(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('max-age'));
        $this->assertEquals('7', $definition->getOption('max-age')->getDefault());
    }

    /**
     * @test
     */
    public function executeWithNoUnpaidOrders(): void
    {
        $this->service
            ->method('findUnpaidOrders')
            ->willReturn([]);

        $exitCode = $this->tester->execute([]);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('No unpaid orders', $this->tester->getDisplay());
    }

    /**
     * @test
     */
    public function executeWithDryRun(): void
    {
        $this->service
            ->method('findUnpaidOrders')
            ->with(7)
            ->willReturn([
                ['OXID' => 'order1', 'OXTRANSID' => 'pi_123', 'OXORDERNR' => 1, 'OXORDERDATE' => '2025-12-05'],
            ]);

        $this->service
            ->method('reconcileAll')
            ->with(7, true)
            ->willReturn([
                new ReconciliationResult('order1', 'pi_123', true, 'dry_run', 'Would check order')
            ]);

        $exitCode = $this->tester->execute(['--dry-run' => true]);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('DRY RUN MODE', $this->tester->getDisplay());
    }

    /**
     * @test
     */
    public function executeWithCustomMaxAge(): void
    {
        $this->service
            ->method('findUnpaidOrders')
            ->with(14)
            ->willReturn([]);

        $exitCode = $this->tester->execute(['--max-age' => '14']);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('last 14 days', $this->tester->getDisplay());
    }

    /**
     * @test
     */
    public function executeWithSuccessfulReconciliation(): void
    {
        $this->service
            ->method('findUnpaidOrders')
            ->willReturn([
                ['OXID' => 'order1', 'OXTRANSID' => 'pi_123', 'OXORDERNR' => 1, 'OXORDERDATE' => '2025-12-05'],
                ['OXID' => 'order2', 'OXTRANSID' => 'pi_456', 'OXORDERNR' => 2, 'OXORDERDATE' => '2025-12-04'],
            ]);

        $this->service
            ->method('reconcileAll')
            ->willReturn([
                new ReconciliationResult('order1', 'pi_123', true, 'updated', 'OXPAID updated'),
                new ReconciliationResult('order2', 'pi_456', true, 'updated', 'OXPAID updated'),
            ]);

        $exitCode = $this->tester->execute([]);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Successfully reconciled 2 order(s)', $this->tester->getDisplay());
    }

    /**
     * @test
     */
    public function executeWithErrors(): void
    {
        $this->service
            ->method('findUnpaidOrders')
            ->willReturn([
                ['OXID' => 'order1', 'OXTRANSID' => 'pi_123', 'OXORDERNR' => 1, 'OXORDERDATE' => '2025-12-05'],
            ]);

        $this->service
            ->method('reconcileAll')
            ->willReturn([
                new ReconciliationResult('order1', 'pi_123', false, 'error', 'API Error'),
            ]);

        $exitCode = $this->tester->execute([]);

        $this->assertEquals(1, $exitCode); // FAILURE
        $this->assertStringContainsString('error(s)', $this->tester->getDisplay());
    }

    /**
     * @test
     */
    public function executeShowsSkippedOrders(): void
    {
        $this->service
            ->method('findUnpaidOrders')
            ->willReturn([
                ['OXID' => 'order1', 'OXTRANSID' => 'pi_123', 'OXORDERNR' => 1, 'OXORDERDATE' => '2025-12-05'],
            ]);

        $this->service
            ->method('reconcileAll')
            ->willReturn([
                new ReconciliationResult('order1', 'pi_123', false, 'skipped', 'Payment not captured'),
            ]);

        $exitCode = $this->tester->execute([]);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Skipped: 1', $this->tester->getDisplay());
    }

    /**
     * @test
     */
    public function executeShowsContractUpdates(): void
    {
        $this->service
            ->method('findUnpaidOrders')
            ->willReturn([
                ['OXID' => 'order1', 'OXTRANSID' => 'pi_123', 'OXORDERNR' => 1, 'OXORDERDATE' => '2025-12-05'],
            ]);

        $this->service
            ->method('reconcileAll')
            ->willReturn([
                new ReconciliationResult('order1', 'pi_123', true, 'updated', 'OXPAID updated', true),
            ]);

        $exitCode = $this->tester->execute([]);

        $this->assertEquals(0, $exitCode);
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Yes', $output); // Contract column shows Yes
    }
}
