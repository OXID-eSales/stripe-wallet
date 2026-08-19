<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Command;

use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Command\PruneIdempotencyCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Sprint 133 · Story 3 (F8).
 *
 * IdempotencyRepositoryInterface::deleteExpired() existed but was only ever
 * called from a test, so expired records accumulated forever and nothing
 * reclaimed them. This command is the production caller.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(PruneIdempotencyCommand::class)]
#[\PHPUnit\Framework\Attributes\Group('idempotency')]
#[\PHPUnit\Framework\Attributes\Group('command')]
final class PruneIdempotencyCommandTest extends TestCase
{
    private IdempotencyRepositoryInterface $repository;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $command = new PruneIdempotencyCommand($this->repository);

        (new Application())->add($command);
        $this->tester = new CommandTester($command);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function deletesExpiredRecordsAndReportsTheCount(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('deleteExpired')
            ->willReturn(7);

        $exitCode = $this->tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('7', $this->tester->getDisplay());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reportsCleanlyWhenNothingIsExpired(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('deleteExpired')
            ->willReturn(0);

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reportsFailureWhenTheRepositoryThrows(): void
    {
        $this->repository
            ->method('deleteExpired')
            ->willThrowException(new \RuntimeException('table missing'));

        $exitCode = $this->tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode, 'A failed prune must not report success.');
        $this->assertStringContainsString('table missing', $this->tester->getDisplay());
    }
}
