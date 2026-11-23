<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Command;

use OxidEsales\Payments\Stripe\Service\LegacyOrderMigrationServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\LegacyOrderMigrationReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Adopts orders paid through the legacy oxid-esales/stripe-module into the
 * new module by creating a payment-base contract per order.
 *
 * Dry run by default; --apply writes the contracts.
 *
 * Usage:
 *   bin/oe-console stripe:migrate-legacy-orders
 *   bin/oe-console stripe:migrate-legacy-orders --apply
 *
 * STRP-181.
 */
class MigrateLegacyOrdersCommand extends Command
{
    protected static $defaultName = 'stripe:migrate-legacy-orders';
    protected static $defaultDescription = 'Create payment contracts for orders paid through the legacy Stripe module';

    public function __construct(
        private readonly LegacyOrderMigrationServiceInterface $migrationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription((string) self::$defaultDescription)
            ->setHelp(
                <<<'HELP'
Finds orders paid through the legacy Stripe module (payment id `stripe*`,
PaymentIntent in OXTRANSID) that have no payment contract yet, and creates a
FULFILLED contract for each one. With the contract in place the new module's
admin panel (capture state, refunds, transaction history) manages the order;
the order's payment id and history stay untouched.

Skipped (and listed) are orders that were never paid in the shop (this
includes payments that were authorized and never captured), cancelled orders,
orders whose transaction id is not a PaymentIntent, and orders whose
PaymentIntent the configured Stripe account does not have or does not report
as paid. Captured and refunded amounts come from Stripe.

Without --apply nothing is written: the command asks Stripe the same
questions and lists what --apply would migrate and skip. Re-running is safe —
orders that already have a contract are never candidates again.

On an Enterprise multishop run the command once per subshop (the shop the
console is bound to is the shop that is migrated).

<info>Examples:</info>
  <comment>bin/oe-console stripe:migrate-legacy-orders</comment>
  <comment>bin/oe-console stripe:migrate-legacy-orders --apply</comment>
HELP
            )
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the contracts (default is a dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        $io->title('Legacy Stripe order migration');
        if (!$apply) {
            $io->note('DRY RUN - nothing is written. Use --apply to create the contracts.');
        }

        $report = $this->migrationService->migrate($apply);
        $this->printSummary($io, $report, $apply);

        return $report->failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function printSummary(SymfonyStyle $io, LegacyOrderMigrationReport $report, bool $apply): void
    {
        $io->text([
            'Candidates: ' . count($report->candidateOrderIds),
            ($apply ? 'Migrated: ' : 'Would migrate: ') . $report->migrated,
            'Skipped: ' . count($report->skipped),
            'Failed: ' . count($report->failed),
        ]);

        $this->printReasons($io, 'Skipped', $report->skipped);
        $this->printReasons($io, 'Failed', $report->failed);
    }

    /**
     * @param array<string, string> $reasons order label => reason
     */
    private function printReasons(SymfonyStyle $io, string $heading, array $reasons): void
    {
        if ($reasons === []) {
            return;
        }

        $io->section($heading);
        foreach ($reasons as $label => $reason) {
            $io->text($label . ': ' . $reason);
        }
    }
}
