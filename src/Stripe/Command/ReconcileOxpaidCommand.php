<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Command;

use OxidEsales\Payments\Stripe\Service\OxpaidReconciliationServiceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command to reconcile OXPAID timestamps with Stripe.
 *
 * Finds orders where OXPAID is not set but Stripe shows payment as succeeded,
 * and updates OXPAID accordingly.
 *
 * Usage:
 *   bin/oe-console stripe:reconcile-oxpaid
 *   bin/oe-console stripe:reconcile-oxpaid --dry-run
 *   bin/oe-console stripe:reconcile-oxpaid --max-age=14
 *
 * @since Sprint 10
 */
class ReconcileOxpaidCommand extends Command
{
    protected static $defaultName = 'stripe:reconcile-oxpaid';
    protected static $defaultDescription = 'Reconcile OXPAID timestamps with Stripe payment status';

    public function __construct(
        private readonly OxpaidReconciliationServiceInterface $reconciliationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription((string) self::$defaultDescription)
            ->setHelp(
                <<<'HELP'
This command checks orders with missing OXPAID timestamps (showing as unpaid)
that have a Stripe PaymentIntent ID, and verifies their actual payment status
via the Stripe API.

If Stripe confirms the payment was captured, the command updates:
- OXPAID timestamp on the order
- Contract state to FULFILLED (if a contract exists)

This handles cases where webhooks were missed or delayed.

<info>Examples:</info>

  Check and fix all unpaid orders from the last 7 days:
    <comment>bin/oe-console stripe:reconcile-oxpaid</comment>

  Dry run (show what would be done without making changes):
    <comment>bin/oe-console stripe:reconcile-oxpaid --dry-run</comment>

  Check orders from the last 14 days:
    <comment>bin/oe-console stripe:reconcile-oxpaid --max-age=14</comment>

<info>Log file:</info>
  source/log/stripe/stripe_reconciliation.log
HELP
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be done without making changes'
            )
            ->addOption(
                'max-age',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum age of orders to check in days',
                '7'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $maxAgeRaw = $input->getOption('max-age');
        $maxAgeDays = is_numeric($maxAgeRaw) ? (int) $maxAgeRaw : 7;

        $io->title('Stripe OXPAID Reconciliation');

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be made');
        }

        $io->text("Checking orders from the last {$maxAgeDays} days...");

        // Find unpaid orders
        $unpaidOrders = $this->reconciliationService->findUnpaidOrders($maxAgeDays);
        $orderCount = count($unpaidOrders);

        if ($orderCount === 0) {
            $io->success('No unpaid orders with Stripe PaymentIntent found.');
            return Command::SUCCESS;
        }

        $io->text("Found {$orderCount} unpaid order(s) to check.");
        $io->newLine();

        // Process orders
        $results = $this->reconciliationService->reconcileAll($maxAgeDays, $dryRun);

        // Display results
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $tableRows = [];
        foreach ($results as $result) {
            $status = match ($result->action) {
                'updated' => '<fg=green>UPDATED</>',
                'skipped' => '<fg=yellow>SKIPPED</>',
                'dry_run' => '<fg=cyan>DRY RUN</>',
                'error' => '<fg=red>ERROR</>',
                default => $result->action,
            };

            $tableRows[] = [
                substr($result->orderId, 0, 12) . '...',
                $result->paymentIntentId,
                $status,
                $result->contractUpdated ? 'Yes' : 'No',
                substr($result->reason, 0, 40),
            ];

            match ($result->action) {
                'updated' => $updated++,
                'skipped', 'dry_run' => $skipped++,
                'error' => $errors++,
                default => null,
            };
        }

        $io->table(
            ['Order ID', 'PaymentIntent', 'Status', 'Contract', 'Reason'],
            $tableRows
        );

        // Summary
        $io->newLine();
        $io->section('Summary');

        if ($dryRun) {
            $io->text([
                "Orders found: {$orderCount}",
                "Would update: {$skipped}",
            ]);
        } else {
            $io->text([
                "Orders checked: {$orderCount}",
                "Updated: {$updated}",
                "Skipped: {$skipped}",
                "Errors: {$errors}",
            ]);
        }

        if ($errors > 0) {
            $io->warning("Completed with {$errors} error(s). Check logs for details.");
            return Command::FAILURE;
        }

        if ($updated > 0) {
            $io->success("Successfully reconciled {$updated} order(s).");
        } else {
            $io->success('No orders needed reconciliation.');
        }

        return Command::SUCCESS;
    }
}
