<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Command;

use OxidEsales\Payments\Stripe\Service\CapturedAmountBackfillServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\BackfillReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Records the captured amount (and capture audit row) on contracts that were
 * fulfilled before the payment_intent.succeeded webhook started doing so.
 *
 * Dry run by default; --apply asks Stripe for each PaymentIntent and writes.
 *
 * Usage:
 *   bin/oe-console stripe:backfill-captured-amount
 *   bin/oe-console stripe:backfill-captured-amount --apply
 *
 * @since Sprint 137
 */
class BackfillCapturedAmountCommand extends Command
{
    protected static $defaultName = 'stripe:backfill-captured-amount';
    protected static $defaultDescription = 'Backfill the captured amount on fulfilled Stripe contracts that lack it';

    public function __construct(
        private readonly CapturedAmountBackfillServiceInterface $backfillService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription((string) self::$defaultDescription)
            ->setHelp(
                <<<'HELP'
Finds FULFILLED Stripe contracts whose captured amount is NULL, reads the
amount Stripe received for each PaymentIntent and records it on the contract
together with one capture audit row (never a second one).

Without --apply nothing is read from Stripe and nothing is written: the
command only lists how many contracts are affected.

<info>Examples:</info>
  <comment>bin/oe-console stripe:backfill-captured-amount</comment>
  <comment>bin/oe-console stripe:backfill-captured-amount --apply</comment>
HELP
            )
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the amounts (default is a dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        $io->title('Stripe captured amount backfill');
        if (!$apply) {
            $io->note('DRY RUN - nothing is read from Stripe and nothing is written. Use --apply to record amounts.');
        }

        $report = $this->backfillService->backfill($apply);
        $this->printSummary($io, $report);

        return $report->failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function printSummary(SymfonyStyle $io, BackfillReport $report): void
    {
        $io->text([
            'Candidates: ' . count($report->candidateContractIds),
            'Recorded: ' . $report->recorded,
            'Skipped: ' . count($report->skipped),
            'Failed: ' . count($report->failed),
        ]);

        $this->printReasons($io, 'Skipped', $report->skipped);
        $this->printReasons($io, 'Failed', $report->failed);
    }

    /**
     * @param array<string, string> $reasons contract id => reason
     */
    private function printReasons(SymfonyStyle $io, string $heading, array $reasons): void
    {
        if ($reasons === []) {
            return;
        }

        $io->section($heading);
        foreach ($reasons as $contractId => $reason) {
            $io->text($contractId . ': ' . $reason);
        }
    }
}
