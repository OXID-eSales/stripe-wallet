<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Command;

use OxidEsales\Payments\Stripe\Command\MigrateLegacyOrdersCommand;
use OxidEsales\Payments\Stripe\Service\LegacyOrderMigrationServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\LegacyOrderMigrationReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[\PHPUnit\Framework\Attributes\CoversClass(MigrateLegacyOrdersCommand::class)]
final class MigrateLegacyOrdersCommandTest extends TestCase
{
    private LegacyOrderMigrationServiceInterface&MockObject $service;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->service = $this->createMock(LegacyOrderMigrationServiceInterface::class);
        $this->tester = new CommandTester(new MigrateLegacyOrdersCommand($this->service));
    }

    public function testExecute_DefaultsToDryRun(): void
    {
        $this->service->expects($this->once())->method('migrate')->with(false)
            ->willReturn(new LegacyOrderMigrationReport(['ord-1', 'ord-2'], 0, [], []));

        $exitCode = $this->tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('DRY RUN', $this->tester->getDisplay());
    }

    public function testExecute_InDryRun_PrintsWhatWouldBeMigratedAndSkipped(): void
    {
        $this->service->method('migrate')->willReturn(
            new LegacyOrderMigrationReport(['ord-1', 'ord-2'], 1, ['order 1043' => 'order is cancelled (storno)'], [])
        );

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('Would migrate: 1', $display);
        $this->assertStringContainsString('Skipped: 1', $display);
        $this->assertStringContainsString('order 1043: order is cancelled (storno)', $display);
    }

    public function testExecute_WithApplyFlag_Applies(): void
    {
        $this->service->expects($this->once())->method('migrate')->with(true)
            ->willReturn(new LegacyOrderMigrationReport(['ord-1'], 1, [], []));

        $exitCode = $this->tester->execute(['--apply' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('DRY RUN', $this->tester->getDisplay());
    }

    public function testExecute_PrintsSummaryCounts(): void
    {
        $this->service->method('migrate')->willReturn(new LegacyOrderMigrationReport(
            ['ord-1', 'ord-2', 'ord-3', 'ord-4'],
            2,
            ['order 1042' => 'order was never paid — nothing for the new module to manage'],
            ['order 1043' => 'row is locked']
        ));

        $this->tester->execute(['--apply' => true]);

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('Candidates: 4', $display);
        $this->assertStringContainsString('Migrated: 2', $display);
        $this->assertStringContainsString('Skipped: 1', $display);
        $this->assertStringContainsString('Failed: 1', $display);
        $this->assertStringContainsString('order 1043', $display);
    }

    public function testExecute_WhenSomeFailed_ExitsWithFailure(): void
    {
        $this->service->method('migrate')
            ->willReturn(new LegacyOrderMigrationReport(['ord-1'], 0, [], ['order 1042' => 'row is locked']));

        $this->assertSame(Command::FAILURE, $this->tester->execute(['--apply' => true]));
    }
}
