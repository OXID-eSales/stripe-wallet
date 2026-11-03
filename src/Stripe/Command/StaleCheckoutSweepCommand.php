<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Command;

use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupSettingsInterface;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * VR-23 T3: releases the stale NOT_FINISHED Stripe checkouts - the sweep that ran inline after every webhook, on the
 * request Stripe was waiting for (it cost up to two Stripe round trips per contract, and 66 % of the webhook traffic
 * on pay1 was the shop's own sweep cancelling sessions and receiving their expiry back). Schedule it every few minutes.
 *
 * Usage:
 *   bin/oe-console oe:payments:stripe:sweep
 *   bin/oe-console oe:payments:stripe:sweep --batch=200
 */
class StaleCheckoutSweepCommand extends Command
{
    protected static $defaultName = 'oe:payments:stripe:sweep';
    protected static $defaultDescription = 'Release stale NOT_FINISHED Stripe checkouts';

    /**
     * One run is bounded, so a backlog is worked off over several runs instead of tying up one for as long as it
     * takes; nothing is dropped, only deferred.
     */
    private const DEFAULT_BATCH = 50;

    public function __construct(
        private readonly RetryCleanupService $cleanup,
        private readonly NotFinishedOrderCleanupSettingsInterface $settings,
        private readonly LoggerInterface $logger,
        private readonly ThrowableSummaryInterface $throwableSummary
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription((string) self::$defaultDescription)
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Most contracts released in one run', (string) self::DEFAULT_BATCH);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = filter_var($input->getOption('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($batch === false) {
            $io->error('--batch must be a whole number of at least 1.');

            return Command::INVALID;
        }

        try {
            $released = $this->cleanup->cleanupStaleContracts($this->settings->getStaleCheckoutMinutes(), $batch);
        } catch (Throwable $failure) {
            $this->logger->error('Stripe stale checkout sweep failed', ['throwable' => $this->throwableSummary->fromThrowable($failure)]);
            $io->error('The sweep failed; see the log.');

            return Command::FAILURE;
        }

        $io->writeln('Released ' . $released . ' stale checkout(s).');

        return Command::SUCCESS;
    }
}
