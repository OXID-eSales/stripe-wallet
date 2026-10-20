<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Command;

use OxidEsales\Payments\Stripe\Command\BackfillCapturedAmountCommand;
use OxidEsales\Payments\Stripe\Service\CapturedAmountBackfillServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\BackfillReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[\PHPUnit\Framework\Attributes\CoversClass(BackfillCapturedAmountCommand::class)]
final class BackfillCapturedAmountCommandTest extends TestCase
{
    private CapturedAmountBackfillServiceInterface&MockObject $service;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->service = $this->createMock(CapturedAmountBackfillServiceInterface::class);
        $this->tester = new CommandTester(new BackfillCapturedAmountCommand($this->service));
    }

    public function testExecute_DefaultsToDryRun(): void
    {
        $this->service->expects($this->once())->method('backfill')->with(false)
            ->willReturn(new BackfillReport(['ctr-1', 'ctr-2'], 0, [], []));

        $exitCode = $this->tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('DRY RUN', $this->tester->getDisplay());
    }

    public function testExecute_WithApplyFlag_Applies(): void
    {
        $this->service->expects($this->once())->method('backfill')->with(true)
            ->willReturn(new BackfillReport(['ctr-1'], 1, [], []));

        $exitCode = $this->tester->execute(['--apply' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('DRY RUN', $this->tester->getDisplay());
    }

    public function testExecute_PrintsSummaryCounts(): void
    {
        $this->service->method('backfill')->willReturn(new BackfillReport(
            ['ctr-1', 'ctr-2', 'ctr-3', 'ctr-4'],
            2,
            ['ctr-3' => 'PaymentIntent status is \'canceled\''],
            ['ctr-4' => 'stripe down']
        ));

        $this->tester->execute(['--apply' => true]);

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('Candidates: 4', $display);
        $this->assertStringContainsString('Recorded: 2', $display);
        $this->assertStringContainsString('Skipped: 1', $display);
        $this->assertStringContainsString('Failed: 1', $display);
        $this->assertStringContainsString('ctr-4', $display);
    }

    public function testExecute_WhenSomeFailed_ExitsWithFailure(): void
    {
        $this->service->method('backfill')
            ->willReturn(new BackfillReport(['ctr-1'], 0, [], ['ctr-1' => 'stripe down']));

        $this->assertSame(Command::FAILURE, $this->tester->execute(['--apply' => true]));
    }
}
