<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientResponse;
use OxidEsales\Payments\Stripe\Mcp\Service\GraphqlProductService;
use OxidEsales\Payments\Stripe\Mcp\Service\GraphqlQueryBuilder;
use OxidEsales\Payments\Stripe\Mcp\Service\GraphqlResponseMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GraphqlProductService.
 *
 * Tests GraphQL-backed product listing and retrieval via AcpProductServiceInterface,
 * including HTTP communication, error handling, and response parsing.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\GraphqlProductService
 */
class GraphqlProductServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private GraphqlQueryBuilder&MockObject $queryBuilder;
    private GraphqlResponseMapper&MockObject $responseMapper;

    private const ENDPOINT = 'http://localhost/graphql/';

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->queryBuilder = $this->createMock(GraphqlQueryBuilder::class);
        $this->responseMapper = $this->createMock(GraphqlResponseMapper::class);
    }

    private function createService(): GraphqlProductService
    {
        return new GraphqlProductService(
            $this->httpClient,
            $this->queryBuilder,
            $this->responseMapper,
            self::ENDPOINT
        );
    }

    private function createSuccessResponse(array $data): HttpClientResponse
    {
        return new HttpClientResponse(
            200,
            json_encode(['data' => $data], JSON_THROW_ON_ERROR)
        );
    }

    // ==========================================
    // Interface compliance
    // ==========================================

    public function testImplementsAcpProductServiceInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(AcpProductServiceInterface::class, $service);
    }

    // ==========================================
    // listProducts - successful flow
    // ==========================================

    public function testListProductsBuildQueryAndSendsHttp(): void
    {
        $filters = ['search' => 'shoes', 'limit' => 10];
        $graphqlQuery = '{ products(filter: ...) { id title } }';
        $graphqlData = ['products' => [['id' => 'p1', 'title' => 'Shoe']]];
        $mappedResult = ['products' => [['id' => 'p1']], 'total' => 1, 'limit' => 10, 'offset' => 0];

        $this->queryBuilder
            ->expects($this->once())
            ->method('buildProductsQuery')
            ->with($filters)
            ->willReturn($graphqlQuery);

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                self::ENDPOINT,
                json_encode(['query' => $graphqlQuery]),
                ['Content-Type' => 'application/json'],
                10
            )
            ->willReturn($this->createSuccessResponse($graphqlData));

        $this->responseMapper
            ->expects($this->once())
            ->method('mapProductListResponse')
            ->with($graphqlData, $filters)
            ->willReturn($mappedResult);

        $service = $this->createService();
        $result = $service->listProducts($filters);

        $this->assertSame($mappedResult, $result);
    }

    public function testListProductsReturnsMapperResult(): void
    {
        $expected = ['products' => [['id' => 'x']], 'total' => 1, 'limit' => 20, 'offset' => 0];

        $this->queryBuilder->method('buildProductsQuery')->willReturn('query');
        $this->httpClient->method('post')
            ->willReturn($this->createSuccessResponse(['products' => []]));
        $this->responseMapper->method('mapProductListResponse')->willReturn($expected);

        $service = $this->createService();
        $result = $service->listProducts();

        $this->assertSame($expected, $result);
    }

    // ==========================================
    // listProducts - error handling
    // ==========================================

    public function testListProductsThrowsOnHttpFailure(): void
    {
        $this->queryBuilder->method('buildProductsQuery')->willReturn('query');
        $this->httpClient->method('post')
            ->willReturn(HttpClientResponse::failed('Connection refused'));

        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GraphQL endpoint returned invalid response');

        $service->listProducts();
    }

    public function testListProductsThrowsOnHttpErrorStatus(): void
    {
        $this->queryBuilder->method('buildProductsQuery')->willReturn('query');
        $this->httpClient->method('post')
            ->willReturn(new HttpClientResponse(500, 'Internal Server Error'));

        $service = $this->createService();

        $this->expectException(\RuntimeException::class);

        $service->listProducts();
    }

    public function testListProductsThrowsOnGraphqlErrors(): void
    {
        $this->queryBuilder->method('buildProductsQuery')->willReturn('query');

        $errorResponse = new HttpClientResponse(
            200,
            json_encode(['errors' => [['message' => 'Some error']]])
        );
        $this->httpClient->method('post')->willReturn($errorResponse);

        $service = $this->createService();

        $this->expectException(\RuntimeException::class);

        $service->listProducts();
    }

    public function testListProductsThrowsOnInvalidJson(): void
    {
        $this->queryBuilder->method('buildProductsQuery')->willReturn('query');
        $this->httpClient->method('post')
            ->willReturn(new HttpClientResponse(200, 'not json'));

        $service = $this->createService();

        $this->expectException(\RuntimeException::class);

        $service->listProducts();
    }

    public function testListProductsThrowsOnMissingDataKey(): void
    {
        $this->queryBuilder->method('buildProductsQuery')->willReturn('query');
        $this->httpClient->method('post')
            ->willReturn(new HttpClientResponse(200, json_encode(['no_data' => true])));

        $service = $this->createService();

        $this->expectException(\RuntimeException::class);

        $service->listProducts();
    }

    // ==========================================
    // getProduct - successful flow
    // ==========================================

    public function testGetProductBuildQueryAndReturnsProduct(): void
    {
        $graphqlQuery = '{ product(productId: "abc") { id title } }';
        $graphqlData = ['product' => ['id' => 'abc', 'title' => 'Test']];
        $mapped = ['id' => 'abc', 'title' => 'Test', 'price' => '10.00'];

        $this->queryBuilder
            ->expects($this->once())
            ->method('buildProductQuery')
            ->with('abc')
            ->willReturn($graphqlQuery);

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->willReturn($this->createSuccessResponse($graphqlData));

        $this->responseMapper
            ->expects($this->once())
            ->method('mapSingleProductResponse')
            ->with($graphqlData)
            ->willReturn($mapped);

        $service = $this->createService();
        $result = $service->getProduct('abc');

        $this->assertSame($mapped, $result);
    }

    // ==========================================
    // getProduct - error handling
    // ==========================================

    public function testGetProductReturnsNullOnHttpFailure(): void
    {
        $this->queryBuilder->method('buildProductQuery')->willReturn('query');
        $this->httpClient->method('post')
            ->willReturn(HttpClientResponse::failed('Timeout'));

        $service = $this->createService();
        $result = $service->getProduct('abc');

        $this->assertNull($result);
    }

    public function testGetProductReturnsNullOnHttpErrorStatus(): void
    {
        $this->queryBuilder->method('buildProductQuery')->willReturn('query');
        $this->httpClient->method('post')
            ->willReturn(new HttpClientResponse(404, 'Not Found'));

        $service = $this->createService();
        $result = $service->getProduct('abc');

        $this->assertNull($result);
    }

    public function testGetProductReturnsNullOnGraphqlErrors(): void
    {
        $this->queryBuilder->method('buildProductQuery')->willReturn('query');
        $this->httpClient->method('post')
            ->willReturn(new HttpClientResponse(
                200,
                json_encode(['errors' => [['message' => 'Not found']]])
            ));

        $service = $this->createService();
        $result = $service->getProduct('abc');

        $this->assertNull($result);
    }

    public function testGetProductReturnsNullOnInvalidJson(): void
    {
        $this->queryBuilder->method('buildProductQuery')->willReturn('query');
        $this->httpClient->method('post')
            ->willReturn(new HttpClientResponse(200, 'garbage'));

        $service = $this->createService();
        $result = $service->getProduct('abc');

        $this->assertNull($result);
    }

    // ==========================================
    // HTTP request details
    // ==========================================

    public function testSendsPostWithCorrectContentType(): void
    {
        $this->queryBuilder->method('buildProductsQuery')->willReturn('query');

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                self::ENDPOINT,
                $this->anything(),
                $this->callback(function (array $headers): bool {
                    return ($headers['Content-Type'] ?? '') === 'application/json';
                }),
                10
            )
            ->willReturn($this->createSuccessResponse(['products' => []]));

        $this->responseMapper->method('mapProductListResponse')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $service = $this->createService();
        $service->listProducts();
    }

    public function testSendsQueryAsJsonBody(): void
    {
        $graphqlQuery = '{ products { id } }';
        $this->queryBuilder->method('buildProductsQuery')->willReturn($graphqlQuery);

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                self::ENDPOINT,
                json_encode(['query' => $graphqlQuery]),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($this->createSuccessResponse(['products' => []]));

        $this->responseMapper->method('mapProductListResponse')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $service = $this->createService();
        $service->listProducts();
    }
}
