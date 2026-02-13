<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Mcp\Notification;

use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientResponse;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentCallbackRegistryInterface;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationPayload;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationResult;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationService;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationService
 * @group sprint-50
 * @group mcp
 * @group notification
 */
final class AgentNotificationServiceTest extends TestCase
{
    private AgentCallbackRegistryInterface&MockObject $callbackRegistry;
    private HttpClientInterface&MockObject $httpClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->callbackRegistry = $this->createMock(AgentCallbackRegistryInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    /**
     * @test
     */
    public function implementsInterface(): void
    {
        $service = new AgentNotificationService($this->callbackRegistry, $this->httpClient);

        $this->assertInstanceOf(AgentNotificationServiceInterface::class, $service);
    }

    /**
     * @test
     */
    public function deliversNotificationSuccessfully(): void
    {
        // Arrange
        $this->callbackRegistry
            ->method('getCallbackUrl')
            ->with('contract_123')
            ->willReturn('https://agent.example.com/callback');

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                'https://agent.example.com/callback',
                $this->isType('string'),
                $this->callback(function (array $headers): bool {
                    return $headers['Content-Type'] === 'application/json'
                        && $headers['User-Agent'] === 'OxidPaymentComponent/1.0';
                }),
                10
            )
            ->willReturn(new HttpClientResponse(200, '{"ok":true}'));

        $service = new AgentNotificationService($this->callbackRegistry, $this->httpClient);
        $payload = new AgentNotificationPayload('order.created', 'contract_123', 'created');

        // Act
        $result = $service->notify('contract_123', $payload);

        // Assert
        $this->assertTrue($result->isDelivered());
        $this->assertSame(200, $result->getHttpStatusCode());
    }

    /**
     * @test
     */
    public function returnsNoCallbackWhenUrlNotRegistered(): void
    {
        // Arrange
        $this->callbackRegistry
            ->method('getCallbackUrl')
            ->with('contract_no_url')
            ->willReturn(null);

        $this->httpClient
            ->expects($this->never())
            ->method('post');

        $service = new AgentNotificationService($this->callbackRegistry, $this->httpClient);
        $payload = new AgentNotificationPayload('order.created', 'contract_no_url', 'created');

        // Act
        $result = $service->notify('contract_no_url', $payload);

        // Assert
        $this->assertFalse($result->isDelivered());
        $this->assertSame('No callback URL registered', $result->getErrorMessage());
    }

    /**
     * @test
     */
    public function includesHmacSignatureWhenSigningSecretProvided(): void
    {
        // Arrange
        $signingSecret = 'test_secret_key_123';

        $this->callbackRegistry
            ->method('getCallbackUrl')
            ->willReturn('https://agent.example.com/callback');

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $headers): bool {
                    if (!isset($headers['X-Webhook-Signature'])) {
                        return false;
                    }
                    $sig = $headers['X-Webhook-Signature'];
                    // Signature format: t=<timestamp>,v1=<hmac>
                    return (bool) preg_match('/^t=\d+,v1=[a-f0-9]{64}$/', $sig);
                }),
                $this->anything()
            )
            ->willReturn(new HttpClientResponse(200, ''));

        $service = new AgentNotificationService(
            $this->callbackRegistry,
            $this->httpClient,
            $signingSecret
        );
        $payload = new AgentNotificationPayload('order.created', 'contract_hmac', 'created');

        // Act
        $result = $service->notify('contract_hmac', $payload);

        // Assert
        $this->assertTrue($result->isDelivered());
    }

    /**
     * @test
     */
    public function omitsSignatureHeaderWhenNoSigningSecret(): void
    {
        // Arrange
        $this->callbackRegistry
            ->method('getCallbackUrl')
            ->willReturn('https://agent.example.com/callback');

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $headers): bool {
                    return !isset($headers['X-Webhook-Signature']);
                }),
                $this->anything()
            )
            ->willReturn(new HttpClientResponse(200, ''));

        $service = new AgentNotificationService($this->callbackRegistry, $this->httpClient);
        $payload = new AgentNotificationPayload('order.created', 'contract_no_sig', 'created');

        // Act
        $result = $service->notify('contract_no_sig', $payload);

        // Assert
        $this->assertTrue($result->isDelivered());
    }

    /**
     * @test
     */
    public function returnsFailedResultOnHttpError(): void
    {
        // Arrange
        $this->callbackRegistry
            ->method('getCallbackUrl')
            ->willReturn('https://agent.example.com/callback');

        $this->httpClient
            ->method('post')
            ->willReturn(HttpClientResponse::failed('Connection refused'));

        $service = new AgentNotificationService($this->callbackRegistry, $this->httpClient);
        $payload = new AgentNotificationPayload('order.created', 'contract_err', 'created');

        // Act
        $result = $service->notify('contract_err', $payload);

        // Assert
        $this->assertFalse($result->isDelivered());
        $this->assertSame(0, $result->getHttpStatusCode());
        $this->assertSame('Connection refused', $result->getErrorMessage());
    }

    /**
     * @test
     */
    public function returnsFailedResultOnNon2xxResponse(): void
    {
        // Arrange
        $this->callbackRegistry
            ->method('getCallbackUrl')
            ->willReturn('https://agent.example.com/callback');

        $this->httpClient
            ->method('post')
            ->willReturn(new HttpClientResponse(503, 'Service Unavailable'));

        $service = new AgentNotificationService($this->callbackRegistry, $this->httpClient);
        $payload = new AgentNotificationPayload('order.created', 'contract_503', 'created');

        // Act
        $result = $service->notify('contract_503', $payload);

        // Assert
        $this->assertFalse($result->isDelivered());
        $this->assertSame(503, $result->getHttpStatusCode());
        $this->assertSame('HTTP 503', $result->getErrorMessage());
    }

    /**
     * @test
     */
    public function sendsPayloadBodyAsJson(): void
    {
        // Arrange
        $this->callbackRegistry
            ->method('getCallbackUrl')
            ->willReturn('https://agent.example.com/callback');

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (string $body): bool {
                    $decoded = json_decode($body, true);
                    return is_array($decoded)
                        && $decoded['event_type'] === 'order.created'
                        && $decoded['checkout_session_id'] === 'contract_json'
                        && $decoded['status'] === 'created';
                }),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(new HttpClientResponse(200, ''));

        $service = new AgentNotificationService($this->callbackRegistry, $this->httpClient);
        $payload = new AgentNotificationPayload('order.created', 'contract_json', 'created');

        // Act
        $service->notify('contract_json', $payload);
    }
}
