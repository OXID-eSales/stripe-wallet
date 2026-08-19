<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Command;

use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Console command to prune expired idempotency records.
 *
 * Sprint 133 · Story 3 (F8): IdempotencyRepositoryInterface::deleteExpired()
 * existed since Sprint 42 but had no production caller — only a test — so
 * `oe_payments_idempotency` grew without bound. Schedule this from cron.
 *
 * Usage:
 *   bin/oe-console stripe:prune-idempotency
 *
 * @since 2.0.0
 */
class PruneIdempotencyCommand extends Command
{
    protected static $defaultName = 'stripe:prune-idempotency';
    protected static $defaultDescription = 'Delete expired payment idempotency records';

    public function __construct(
        private readonly IdempotencyRepositoryInterface $idempotencyRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription((string) self::$defaultDescription)
            ->setHelp(
                <<<'HELP'
Deletes rows from oe_payments_idempotency whose expiry has passed.

Idempotency records protect against duplicate capture/refund calls for the
length of their TTL. Once expired they are dead weight, and the table has a
UNIQUE index on the key, so pruning keeps inserts cheap.

<info>Example (daily cron):</info>
  bin/oe-console stripe:prune-idempotency
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $deleted = $this->idempotencyRepository->deleteExpired();
        } catch (Throwable $e) {
            // Reporting success for a prune that did not happen is exactly the
            // class of false signal this sprint removes.
            $io->error('Failed to prune idempotency records: ' . $e->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf('Pruned %d expired idempotency record(s).', $deleted));

        return self::SUCCESS;
    }
}
