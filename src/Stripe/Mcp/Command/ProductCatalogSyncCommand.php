<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Command;

use OxidEsales\Payments\Stripe\Mcp\Service\StripeProductCatalogSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProductCatalogSyncCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'stripe:catalog:sync';

    public function __construct(
        private readonly StripeProductCatalogSyncService $syncService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Sync OXID product catalog to Stripe Agentic Commerce Suite');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Syncing product catalog to Stripe...');

        $result = $this->syncService->syncAllProducts();

        if ($result->isSuccessful()) {
            $output->writeln(sprintf(
                'Sync complete: %d processed, %d created, %d updated',
                $result->getProductsProcessed(),
                $result->getProductsCreated(),
                $result->getProductsUpdated()
            ));
            return Command::SUCCESS;
        }

        $output->writeln('<error>Sync failed:</error>');
        foreach ($result->getErrorMessages() as $error) {
            $output->writeln("  - {$error}");
        }

        return Command::FAILURE;
    }
}
