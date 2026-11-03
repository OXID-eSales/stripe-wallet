<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Command;

use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupSettingsInterface;
use OxidEsales\Payments\Stripe\Command\StaleCheckoutSweepCommand;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * VR-23 T3: the sweep of stale NOT_FINISHED Stripe checkouts used to run inline after every webhook, on the request
 * Stripe was waiting for - 66 % of the webhook traffic on pay1 was the shop's own sweep cancelling sessions and
 * receiving `checkout.session.expired` back. It is a cron command now.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(StaleCheckoutSweepCommand::class)]
final class StaleCheckoutSweepCommandTest extends TestCase
{
    private RetryCleanupService&MockObject $cleanup;
    private NotFinishedOrderCleanupSettingsInterface&MockObject $settings;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->cleanup = $this->createMock(RetryCleanupService::class);
        $this->settings = $this->createMock(NotFinishedOrderCleanupSettingsInterface::class);
        $command = $this->command();

        (new Application())->add($command);
        $this->tester = new CommandTester($command);
    }

    public function testItIsRegisteredUnderTheCronName(): void
    {
        self::assertSame('oe:payments:stripe:sweep', $this->command()->getName());
    }

    /**
     * STRP-168 item 3: the horizon is the merchant's setting, not a constant - a shop selling by bank transfer or
     * Klarna has customers who legitimately take longer.
     */
    public function testItSweepsOnTheConfiguredStaleCheckoutTimeoutInBoundedBatches(): void
    {
        $this->settings->method('getStaleCheckoutMinutes')->willReturn(120);
        $this->cleanup->expects($this->once())->method('cleanupStaleContracts')->with(120, 50)->willReturn(3);

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('3', $this->tester->getDisplay());
    }

    public function testTheBatchSizeIsAnOption(): void
    {
        $this->settings->method('getStaleCheckoutMinutes')->willReturn(30);
        $this->cleanup->expects($this->once())->method('cleanupStaleContracts')->with(30, 200)->willReturn(0);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['--batch' => '200']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableBatches(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'text' => ['many'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableBatches')]
    public function testAnUnusableBatchSizeIsRefusedBeforeAnythingIsSwept(string $batch): void
    {
        $this->cleanup->expects($this->never())->method('cleanupStaleContracts');

        self::assertSame(Command::INVALID, $this->tester->execute(['--batch' => $batch]));
    }

    public function testAFailedSweepIsAFailedRun(): void
    {
        $this->settings->method('getStaleCheckoutMinutes')->willReturn(30);
        $this->cleanup->method('cleanupStaleContracts')->willThrowException(new RuntimeException('stripe down'));

        self::assertSame(Command::FAILURE, $this->tester->execute([]));
        self::assertStringNotContainsString('stripe down', $this->tester->getDisplay(), 'no exception text on the console');
    }

    private function command(): StaleCheckoutSweepCommand
    {
        return new StaleCheckoutSweepCommand($this->cleanup, $this->settings, new NullLogger(), new ThrowableSummary([]));
    }
}
