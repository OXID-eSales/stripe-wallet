<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\CatalogSyncResult;
use OxidEsales\PaymentComponent\Mcp\Acp\HostedCommerceServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

class StripeProductCatalogSyncService implements HostedCommerceServiceInterface
{
    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly AcpProductServiceInterface $productService,
        private readonly ProductFeedGeneratorInterface $feedGenerator,
        private readonly ?FileLoggerInterface $logger = null
    ) {
    }

    public function syncCatalog(string $feedContent, string $feedFormat): CatalogSyncResult
    {
        $this->logger?->log('CatalogSync: starting upload', [
            'format' => $feedFormat,
            'contentLength' => strlen($feedContent),
        ]);

        try {
            $adapter = $this->adapterFactory->getStripeAdapter();
        } catch (\RuntimeException $e) {
            return CatalogSyncResult::failed('Stripe API key not configured');
        }

        $result = $adapter->syncProductCatalog($feedContent, $feedFormat);

        if (!$result['successful']) {
            $error = isset($result['error']) ? (string) $result['error'] : 'Unknown error';
            $this->logger?->log('CatalogSync: upload failed', ['error' => $error]);
            return CatalogSyncResult::failed($error);
        }

        $processed = $result['products_processed'] ?? 0;
        $created = $result['products_created'] ?? 0;
        $updated = $result['products_updated'] ?? 0;

        $this->logger?->log('CatalogSync: upload complete', [
            'processed' => $processed,
        ]);

        return CatalogSyncResult::success($processed, $created, $updated);
    }

    public function syncInventory(array $inventoryUpdates): CatalogSyncResult
    {
        $csvLines = ["ID,Availability\n"];
        foreach ($inventoryUpdates as $update) {
            $csvLines[] = $update['id'] . ',' . $update['availability'] . "\n";
        }

        return $this->syncCatalog(implode('', $csvLines), 'csv');
    }

    public function updateFulfillmentStatus(
        string $orderId,
        string $status,
        array $metadata = []
    ): bool {
        try {
            $adapter = $this->adapterFactory->getStripeAdapter();
        } catch (\RuntimeException $e) {
            return false;
        }

        $success = $adapter->updateFulfillmentStatus($orderId, $status, $metadata);

        if (!$success) {
            $this->logger?->log('FulfillmentUpdate: failed', [
                'orderId' => $orderId,
                'status' => $status,
            ]);
        }

        return $success;
    }

    /**
     * Generate feed from OXID articles and upload in one step.
     */
    public function syncAllProducts(): CatalogSyncResult
    {
        $result = $this->productService->listProducts(['limit' => 10000]);
        /** @var array<int, array<string, mixed>> $products */
        $products = is_array($result['products'] ?? null) ? $result['products'] : [];
        $feedContent = $this->feedGenerator->generate($products);

        return $this->syncCatalog($feedContent, $this->feedGenerator->getFileExtension());
    }
}
