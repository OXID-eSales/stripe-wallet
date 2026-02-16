<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use Psr\Log\LoggerInterface;

class FallbackProductService implements AcpProductServiceInterface
{
    private ?bool $graphqlAvailable = null;

    public function __construct(
        private readonly GraphqlProductService $graphqlService,
        private readonly OxidProductService $directService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $graphqlEndpoint
    ) {
    }

    public function listProducts(array $filters = []): array
    {
        if (!$this->isGraphqlAvailable()) {
            return $this->directService->listProducts($filters);
        }

        try {
            return $this->graphqlService->listProducts($filters);
        } catch (\Throwable $e) {
            $this->logger->warning('GraphQL product query failed, falling back to direct model', [
                'error' => $e->getMessage(),
            ]);

            return $this->directService->listProducts($filters);
        }
    }

    public function getProduct(string $productId): ?array
    {
        if (!$this->isGraphqlAvailable()) {
            return $this->directService->getProduct($productId);
        }

        try {
            return $this->graphqlService->getProduct($productId);
        } catch (\Throwable $e) {
            $this->logger->warning('GraphQL single product query failed, falling back to direct model', [
                'error' => $e->getMessage(),
            ]);

            return $this->directService->getProduct($productId);
        }
    }

    private function isGraphqlAvailable(): bool
    {
        if ($this->graphqlAvailable !== null) {
            return $this->graphqlAvailable;
        }

        try {
            $response = $this->httpClient->post(
                $this->graphqlEndpoint,
                json_encode(['query' => '{ __typename }'], JSON_THROW_ON_ERROR),
                ['Content-Type' => 'application/json'],
                3
            );

            $this->graphqlAvailable = $response->isSuccessful();
        } catch (\Throwable) {
            $this->graphqlAvailable = false;
        }

        if (!$this->graphqlAvailable) {
            $this->logger->info('GraphQL endpoint not available, using direct model access for products');
        }

        return $this->graphqlAvailable;
    }
}
