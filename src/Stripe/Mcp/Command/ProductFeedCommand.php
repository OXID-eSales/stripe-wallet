<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Command;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProductFeedCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'stripe:product-feed:generate';

    public function __construct(
        private readonly AcpProductServiceInterface $productService,
        private readonly ProductFeedGeneratorInterface $defaultFeedGenerator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate product feed for AI agent discovery')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Feed format: csv or jsonl', 'csv')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (without extension)', 'product-feed')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max products per batch', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatOption = $input->getOption('format');
        $format = is_string($formatOption) ? $formatOption : 'csv';
        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : 1000;
        $outputOption = $input->getOption('output');
        $outputBase = is_string($outputOption) ? $outputOption : 'product-feed';
        $outputPath = $outputBase . '.' . ($format === 'jsonl' ? 'jsonl' : 'csv');

        $output->writeln("Generating {$format} feed...");

        $result = $this->productService->listProducts(['limit' => $limit, 'offset' => 0]);
        /** @var array<int, array<string, mixed>> $products */
        $products = is_array($result['products'] ?? null) ? $result['products'] : [];
        $feedContent = $this->defaultFeedGenerator->generate($products);

        file_put_contents($outputPath, $feedContent);

        $total = isset($result['total']) && is_int($result['total']) ? $result['total'] : count($products);
        $output->writeln("Feed written to {$outputPath} ({$total} products)");

        return Command::SUCCESS;
    }
}
