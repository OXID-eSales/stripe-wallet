<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;

class GraphqlProductService implements AcpProductServiceInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GraphqlQueryBuilder $queryBuilder,
        private readonly GraphqlResponseMapper $responseMapper,
        private readonly string $graphqlEndpoint
    ) {
    }

    public function listProducts(array $filters = []): array
    {
        $query = $this->queryBuilder->buildProductsQuery($filters);
        $response = $this->sendQuery($query);

        if ($response === null) {
            throw new \RuntimeException('GraphQL endpoint returned invalid response');
        }

        return $this->responseMapper->mapProductListResponse($response, $filters);
    }

    public function getProduct(string $productId): ?array
    {
        $query = $this->queryBuilder->buildProductQuery($productId);
        $response = $this->sendQuery($query);

        if ($response === null) {
            return null;
        }

        return $this->responseMapper->mapSingleProductResponse($response);
    }

    /**
     * @return array<string, mixed>|null Decoded JSON response data
     */
    private function sendQuery(string $query): ?array
    {
        $body = json_encode(['query' => $query], JSON_THROW_ON_ERROR);
        $response = $this->httpClient->post(
            $this->graphqlEndpoint,
            $body,
            ['Content-Type' => 'application/json'],
            10
        );

        if (!$response->isSuccessful()) {
            return null;
        }

        $decoded = json_decode($response->getBody(), true);
        if (!is_array($decoded) || isset($decoded['errors'])) {
            return null;
        }

        $data = $decoded['data'] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }
}
