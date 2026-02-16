<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientResponse;
use OxidEsales\Payments\Stripe\Mcp\Service\FallbackProductService;
use OxidEsales\Payments\Stripe\Mcp\Service\GraphqlProductService;
use OxidEsales\Payments\Stripe\Mcp\Service\OxidProductService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FallbackProductService.
 *
 * Tests the decorator pattern: GraphQL first, falls back to direct model access.
 * Covers availability probing, caching, fallback on errors, and logging.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\FallbackProductService
 */
class FallbackProductServiceTest extends TestCase
{
    private GraphqlProductService&MockObject $graphqlService;
    private OxidProductService&MockObject $directService;
    private HttpClientInterface&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;

    private const ENDPOINT = 'http://localhost/graphql/';

    protected function setUp(): void
    {
        $this->graphqlService = $this->createMock(GraphqlProductService::class);
        $this->directService = $this->createMock(OxidProductService::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createService(): FallbackProductService
    {
        return new FallbackProductService(
            $this->graphqlService,
            $this->directService,
            $this->httpClient,
            $this->logger,
            self::ENDPOINT
        );
    }

    private function mockGraphqlAvailable(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn(new HttpClientResponse(200, '{"data":{"__typename":"Query"}}'));
    }

    private function mockGraphqlUnavailable(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn(HttpClientResponse::failed('Connection refused'));
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
    // listProducts - GraphQL available
    // ==========================================

    public function testListProductsUsesGraphqlWhenAvailable(): void
    {
        $this->mockGraphqlAvailable();
        $expected = ['products' => [['id' => 'p1']], 'total' => 1, 'limit' => 20, 'offset' => 0];

        $this->graphqlService
            ->expects($this->once())
            ->method('listProducts')
            ->with(['search' => 'test'])
            ->willReturn($expected);

        $this->directService
            ->expects($this->never())
            ->method('listProducts');

        $service = $this->createService();
        $result = $service->listProducts(['search' => 'test']);

        $this->assertSame($expected, $result);
    }

    // ==========================================
    // listProducts - GraphQL unavailable
    // ==========================================

    public function testListProductsUsesDirectWhenGraphqlUnavailable(): void
    {
        $this->mockGraphqlUnavailable();
        $expected = ['products' => [['id' => 'p2']], 'total' => 1, 'limit' => 20, 'offset' => 0];

        $this->graphqlService
            ->expects($this->never())
            ->method('listProducts');

        $this->directService
            ->expects($this->once())
            ->method('listProducts')
            ->willReturn($expected);

        $service = $this->createService();
        $result = $service->listProducts();

        $this->assertSame($expected, $result);
    }

    public function testListProductsLogsInfoWhenGraphqlUnavailable(): void
    {
        $this->mockGraphqlUnavailable();
        $this->directService->method('listProducts')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with($this->stringContains('GraphQL endpoint not available'));

        $service = $this->createService();
        $service->listProducts();
    }

    // ==========================================
    // listProducts - GraphQL throws exception
    // ==========================================

    public function testListProductsFallsBackOnGraphqlException(): void
    {
        $this->mockGraphqlAvailable();
        $expected = ['products' => [['id' => 'fallback']], 'total' => 1, 'limit' => 20, 'offset' => 0];

        $this->graphqlService
            ->method('listProducts')
            ->willThrowException(new \RuntimeException('GraphQL error'));

        $this->directService
            ->expects($this->once())
            ->method('listProducts')
            ->willReturn($expected);

        $service = $this->createService();
        $result = $service->listProducts();

        $this->assertSame($expected, $result);
    }

    public function testListProductsLogsWarningOnGraphqlException(): void
    {
        $this->mockGraphqlAvailable();
        $this->directService->method('listProducts')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $this->graphqlService
            ->method('listProducts')
            ->willThrowException(new \RuntimeException('Parse error'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('GraphQL product query failed'),
                $this->callback(function (array $context): bool {
                    return ($context['error'] ?? '') === 'Parse error';
                })
            );

        $service = $this->createService();
        $service->listProducts();
    }

    // ==========================================
    // getProduct - GraphQL available
    // ==========================================

    public function testGetProductUsesGraphqlWhenAvailable(): void
    {
        $this->mockGraphqlAvailable();
        $expected = ['id' => 'p1', 'title' => 'Test'];

        $this->graphqlService
            ->expects($this->once())
            ->method('getProduct')
            ->with('p1')
            ->willReturn($expected);

        $this->directService
            ->expects($this->never())
            ->method('getProduct');

        $service = $this->createService();
        $result = $service->getProduct('p1');

        $this->assertSame($expected, $result);
    }

    // ==========================================
    // getProduct - GraphQL unavailable
    // ==========================================

    public function testGetProductUsesDirectWhenGraphqlUnavailable(): void
    {
        $this->mockGraphqlUnavailable();
        $expected = ['id' => 'p2', 'title' => 'Direct'];

        $this->directService
            ->expects($this->once())
            ->method('getProduct')
            ->with('p2')
            ->willReturn($expected);

        $service = $this->createService();
        $result = $service->getProduct('p2');

        $this->assertSame($expected, $result);
    }

    // ==========================================
    // getProduct - GraphQL throws exception
    // ==========================================

    public function testGetProductFallsBackOnGraphqlException(): void
    {
        $this->mockGraphqlAvailable();
        $expected = ['id' => 'fb', 'title' => 'Fallback'];

        $this->graphqlService
            ->method('getProduct')
            ->willThrowException(new \RuntimeException('Timeout'));

        $this->directService
            ->expects($this->once())
            ->method('getProduct')
            ->with('fb')
            ->willReturn($expected);

        $service = $this->createService();
        $result = $service->getProduct('fb');

        $this->assertSame($expected, $result);
    }

    public function testGetProductLogsWarningOnGraphqlException(): void
    {
        $this->mockGraphqlAvailable();
        $this->directService->method('getProduct')->willReturn(null);

        $this->graphqlService
            ->method('getProduct')
            ->willThrowException(new \RuntimeException('Network error'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('single product query failed'),
                $this->callback(function (array $context): bool {
                    return ($context['error'] ?? '') === 'Network error';
                })
            );

        $service = $this->createService();
        $service->getProduct('abc');
    }

    // ==========================================
    // Availability caching
    // ==========================================

    public function testGraphqlAvailabilityIsCachedAcrossCalls(): void
    {
        // Probe should be called exactly once, even with 3 listProducts calls
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->willReturn(new HttpClientResponse(200, '{"data":{"__typename":"Query"}}'));

        $this->graphqlService->method('listProducts')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $service = $this->createService();
        $service->listProducts();
        $service->listProducts();
        $service->listProducts();
    }

    public function testGraphqlUnavailabilityCachedAcrossCalls(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->willReturn(HttpClientResponse::failed('refused'));

        $this->directService->method('listProducts')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $service = $this->createService();
        $service->listProducts();
        $service->listProducts();
    }

    // ==========================================
    // Probe request details
    // ==========================================

    public function testProbeUsesIntrospectionQuery(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                self::ENDPOINT,
                $this->callback(function (string $body): bool {
                    $decoded = json_decode($body, true);
                    return is_array($decoded) && ($decoded['query'] ?? '') === '{ __typename }';
                }),
                ['Content-Type' => 'application/json'],
                3
            )
            ->willReturn(new HttpClientResponse(200, '{"data":{"__typename":"Query"}}'));

        $this->graphqlService->method('listProducts')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $service = $this->createService();
        $service->listProducts();
    }

    public function testProbeWith3SecondTimeout(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                3
            )
            ->willReturn(new HttpClientResponse(200, '{"data":{"__typename":"Query"}}'));

        $this->graphqlService->method('listProducts')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $service = $this->createService();
        $service->listProducts();
    }

    // ==========================================
    // Edge: probe throws exception
    // ==========================================

    public function testProbeExceptionTreatedAsUnavailable(): void
    {
        $this->httpClient
            ->method('post')
            ->willThrowException(new \RuntimeException('DNS failure'));

        $this->directService
            ->expects($this->once())
            ->method('listProducts')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $this->graphqlService
            ->expects($this->never())
            ->method('listProducts');

        $service = $this->createService();
        $service->listProducts();
    }
}
