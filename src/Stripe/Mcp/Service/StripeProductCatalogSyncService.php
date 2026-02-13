<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\CatalogSyncResult;
use OxidEsales\PaymentComponent\Mcp\Acp\HostedCommerceServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

class StripeProductCatalogSyncService implements HostedCommerceServiceInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AcpProductServiceInterface $productService,
        private readonly ProductFeedGeneratorInterface $feedGenerator,
        private readonly string $stripeApiKey = '',
        private readonly ?FileLoggerInterface $logger = null
    ) {
    }

    public function syncCatalog(string $feedContent, string $feedFormat): CatalogSyncResult
    {
        $this->logger?->log('CatalogSync: starting upload', [
            'format' => $feedFormat,
            'contentLength' => strlen($feedContent),
        ]);

        if ($this->stripeApiKey === '') {
            return CatalogSyncResult::failed('Stripe API key not configured');
        }

        $response = $this->httpClient->post(
            'https://api.stripe.com/v1/products/import',
            $feedContent,
            [
                'Authorization' => 'Bearer ' . $this->stripeApiKey,
                'Content-Type' => $feedFormat === 'csv' ? 'text/csv' : 'application/x-jsonlines',
            ],
            30
        );

        if (!$response->isSuccessful()) {
            $error = $response->getError() ?? 'HTTP ' . $response->getStatusCode();
            $this->logger?->log('CatalogSync: upload failed', ['error' => $error]);
            return CatalogSyncResult::failed($error);
        }

        $result = json_decode($response->getBody(), true);
        if (!is_array($result)) {
            $result = [];
        }

        $this->logger?->log('CatalogSync: upload complete', [
            'processed' => $result['products_processed'] ?? 0,
        ]);

        $processed = $result['products_processed'] ?? 0;
        $created = $result['products_created'] ?? 0;
        $updated = $result['products_updated'] ?? 0;

        return CatalogSyncResult::success(
            is_numeric($processed) ? (int) $processed : 0,
            is_numeric($created) ? (int) $created : 0,
            is_numeric($updated) ? (int) $updated : 0
        );
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
        if ($this->stripeApiKey === '') {
            return false;
        }

        $body = json_encode([
            'status' => $status,
            'metadata' => $metadata,
        ], JSON_THROW_ON_ERROR);

        $response = $this->httpClient->post(
            'https://api.stripe.com/v1/orders/' . $orderId . '/fulfillment',
            $body,
            [
                'Authorization' => 'Bearer ' . $this->stripeApiKey,
                'Content-Type' => 'application/json',
            ],
            10
        );

        if (!$response->isSuccessful()) {
            $this->logger?->log('FulfillmentUpdate: failed', [
                'orderId' => $orderId,
                'status' => $status,
                'error' => $response->getError() ?? 'HTTP ' . $response->getStatusCode(),
            ]);
            return false;
        }

        return true;
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
