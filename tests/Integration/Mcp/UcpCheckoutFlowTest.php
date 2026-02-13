<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\AgentContextInterface;
use OxidEsales\Payments\Stripe\Mcp\Event\UcpCheckoutRequestEvent;
use OxidEsales\Payments\Stripe\Mcp\Handler\UcpCheckoutRequestHandler;
use PHPUnit\Framework\TestCase;

/**
 * Tests UCP REST routing through the handler layer.
 *
 * @group sprint-54
 * @group mcp-integration
 * @group ucp
 */
final class UcpCheckoutFlowTest extends TestCase
{
    private UcpCheckoutRequestHandler $handler;
    private AgentContextInterface $agentContext;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $checkoutService = $container->get(AcpCheckoutServiceInterface::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('ACP checkout service not available: ' . $e->getMessage());
        }

        $this->handler = new UcpCheckoutRequestHandler($checkoutService);
        $this->agentContext = new AgentContext('ucp-test-agent', 'test-token');
    }

    public function testPostCheckoutCreatesSession(): void
    {
        $context = $this->dispatchUcpRequest('POST', ['checkout'], [
            'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
            'buyer' => ['email' => 'ucp-test@example.com'],
            'currency' => 'EUR',
        ]);

        $this->assertSame(201, $context->get('httpStatusCode'));
        $responseData = $context->get('responseData');
        $this->assertArrayHasKey('id', $responseData);
    }

    public function testGetCheckoutRetrievesSession(): void
    {
        $createCtx = $this->dispatchUcpRequest('POST', ['checkout'], [
            'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
            'buyer' => ['email' => 'ucp-test@example.com'],
        ]);
        $checkoutId = $createCtx->get('responseData')['id'];

        $getCtx = $this->dispatchUcpRequest('GET', ['checkout', $checkoutId], []);

        $this->assertSame(200, $getCtx->get('httpStatusCode'));
        $this->assertSame($checkoutId, $getCtx->get('responseData')['id']);
    }

    public function testCancelCheckoutViaUcp(): void
    {
        $createCtx = $this->dispatchUcpRequest('POST', ['checkout'], [
            'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
            'buyer' => ['email' => 'ucp-test@example.com'],
        ]);
        $checkoutId = $createCtx->get('responseData')['id'];

        $cancelCtx = $this->dispatchUcpRequest('POST', ['checkout', $checkoutId, 'cancel'], []);

        $this->assertSame(200, $cancelCtx->get('httpStatusCode'));
        $this->assertSame('canceled', $cancelCtx->get('responseData')['status']);
    }

    public function testUnknownRouteReturns404(): void
    {
        $context = $this->dispatchUcpRequest('DELETE', ['checkout', 'some-id'], []);

        $this->assertSame(404, $context->get('httpStatusCode'));
    }

    /**
     * @param list<string> $segments
     * @param array<string, mixed> $body
     */
    private function dispatchUcpRequest(string $method, array $segments, array $body): EventContext
    {
        $context = new EventContext([
            'httpMethod' => $method,
            'pathSegments' => $segments,
            'requestBody' => $body,
            'agentContext' => $this->agentContext,
        ]);

        $event = new UcpCheckoutRequestEvent($context);
        $this->handler->handle($event);

        return $context;
    }

    private function getTestArticleId(): string
    {
        return 'dc5ffdf380e15674b56dd562a7cb6aec';
    }
}
