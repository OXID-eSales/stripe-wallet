<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Handler;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\Payments\Stripe\Mcp\Event\ProductFeedRequestEvent;
use OxidEsales\Payments\Stripe\Mcp\Handler\ProductFeedRequestHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for ProductFeedRequestHandler.
 *
 * Tests event handling, product listing delegation, feed generation,
 * and context writing for the product feed pipeline.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Handler\ProductFeedRequestHandler
 */
class ProductFeedRequestHandlerTest extends TestCase
{
    private AcpProductServiceInterface&MockObject $productService;
    private ProductFeedGeneratorInterface&MockObject $feedGenerator;

    protected function setUp(): void
    {
        $this->productService = $this->createMock(AcpProductServiceInterface::class);
        $this->feedGenerator = $this->createMock(ProductFeedGeneratorInterface::class);
    }

    private function createHandler(): ProductFeedRequestHandler
    {
        return new ProductFeedRequestHandler($this->productService, $this->feedGenerator);
    }

    private function createEvent(array $contextData = []): ProductFeedRequestEvent
    {
        $context = new EventContext($contextData);
        return new ProductFeedRequestEvent($context);
    }

    // ==========================================
    // Interface compliance
    // ==========================================

    public function testImplementsHandlerInterface(): void
    {
        $handler = $this->createHandler();

        $this->assertInstanceOf(HandlerInterface::class, $handler);
    }

    public function testGetHandledEventClassReturnsCorrectClass(): void
    {
        $this->assertSame(
            ProductFeedRequestEvent::class,
            ProductFeedRequestHandler::getHandledEventClass()
        );
    }

    // ==========================================
    // Basic handling
    // ==========================================

    public function testHandleListsProductsAndGeneratesFeed(): void
    {
        $products = [
            ['id' => 'p1', 'title' => 'Product One'],
            ['id' => 'p2', 'title' => 'Product Two'],
        ];

        $this->productService
            ->expects($this->once())
            ->method('listProducts')
            ->with(['limit' => 1000, 'offset' => 0])
            ->willReturn([
                'products' => $products,
                'total' => 2,
                'limit' => 1000,
                'offset' => 0,
            ]);

        $this->feedGenerator
            ->expects($this->once())
            ->method('generate')
            ->with($products)
            ->willReturn('csv-content-here');

        $this->feedGenerator
            ->method('getContentType')
            ->willReturn('text/csv; charset=utf-8');

        $this->feedGenerator
            ->method('getFileExtension')
            ->willReturn('csv');

        $event = $this->createEvent();
        $handler = $this->createHandler();
        $handler->handle($event);

        $context = $event->getContext();
        $this->assertSame('csv-content-here', $context->get('feedContent'));
        $this->assertSame('text/csv; charset=utf-8', $context->get('feedContentType'));
        $this->assertSame('csv', $context->get('feedFileExtension'));
    }

    // ==========================================
    // Limit and offset from context
    // ==========================================

    public function testHandleUsesDefaultLimit1000(): void
    {
        $this->productService
            ->expects($this->once())
            ->method('listProducts')
            ->with(['limit' => 1000, 'offset' => 0])
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 1000, 'offset' => 0]);

        $this->feedGenerator->method('generate')->willReturn('');
        $this->feedGenerator->method('getContentType')->willReturn('text/csv');
        $this->feedGenerator->method('getFileExtension')->willReturn('csv');

        $event = $this->createEvent();
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testHandleUsesCustomLimitFromContext(): void
    {
        $this->productService
            ->expects($this->once())
            ->method('listProducts')
            ->with(['limit' => 500, 'offset' => 0])
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 500, 'offset' => 0]);

        $this->feedGenerator->method('generate')->willReturn('');
        $this->feedGenerator->method('getContentType')->willReturn('text/csv');
        $this->feedGenerator->method('getFileExtension')->willReturn('csv');

        $event = $this->createEvent(['limit' => 500]);
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testHandleUsesDefaultOffsetZero(): void
    {
        $this->productService
            ->expects($this->once())
            ->method('listProducts')
            ->with(['limit' => 1000, 'offset' => 0])
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 1000, 'offset' => 0]);

        $this->feedGenerator->method('generate')->willReturn('');
        $this->feedGenerator->method('getContentType')->willReturn('text/csv');
        $this->feedGenerator->method('getFileExtension')->willReturn('csv');

        $event = $this->createEvent();
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testHandleUsesCustomOffsetFromContext(): void
    {
        $this->productService
            ->expects($this->once())
            ->method('listProducts')
            ->with(['limit' => 1000, 'offset' => 200])
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 1000, 'offset' => 200]);

        $this->feedGenerator->method('generate')->willReturn('');
        $this->feedGenerator->method('getContentType')->willReturn('text/csv');
        $this->feedGenerator->method('getFileExtension')->willReturn('csv');

        $event = $this->createEvent(['offset' => 200]);
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testHandleUsesCustomLimitAndOffset(): void
    {
        $this->productService
            ->expects($this->once())
            ->method('listProducts')
            ->with(['limit' => 250, 'offset' => 100])
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 250, 'offset' => 100]);

        $this->feedGenerator->method('generate')->willReturn('');
        $this->feedGenerator->method('getContentType')->willReturn('text/csv');
        $this->feedGenerator->method('getFileExtension')->willReturn('csv');

        $event = $this->createEvent(['limit' => 250, 'offset' => 100]);
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    // ==========================================
    // Context writes
    // ==========================================

    public function testHandleWritesFeedContentToContext(): void
    {
        $this->productService
            ->method('listProducts')
            ->willReturn(['products' => [['id' => 'p1']], 'total' => 1, 'limit' => 1000, 'offset' => 0]);

        $this->feedGenerator
            ->method('generate')
            ->willReturn('generated-feed-content');

        $this->feedGenerator->method('getContentType')->willReturn('text/csv; charset=utf-8');
        $this->feedGenerator->method('getFileExtension')->willReturn('csv');

        $event = $this->createEvent();
        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertSame('generated-feed-content', $event->getContext()->get('feedContent'));
    }

    public function testHandleWritesFeedContentTypeToContext(): void
    {
        $this->productService
            ->method('listProducts')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 1000, 'offset' => 0]);

        $this->feedGenerator->method('generate')->willReturn('');
        $this->feedGenerator->method('getContentType')->willReturn('application/x-jsonlines; charset=utf-8');
        $this->feedGenerator->method('getFileExtension')->willReturn('jsonl');

        $event = $this->createEvent();
        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertSame(
            'application/x-jsonlines; charset=utf-8',
            $event->getContext()->get('feedContentType')
        );
    }

    public function testHandleWritesFeedFileExtensionToContext(): void
    {
        $this->productService
            ->method('listProducts')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 1000, 'offset' => 0]);

        $this->feedGenerator->method('generate')->willReturn('');
        $this->feedGenerator->method('getContentType')->willReturn('text/csv');
        $this->feedGenerator->method('getFileExtension')->willReturn('csv');

        $event = $this->createEvent();
        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertSame('csv', $event->getContext()->get('feedFileExtension'));
    }

    // ==========================================
    // Non-matching event type
    // ==========================================

    public function testHandleIgnoresNonProductFeedRequestEvent(): void
    {
        $this->productService
            ->expects($this->never())
            ->method('listProducts');

        $this->feedGenerator
            ->expects($this->never())
            ->method('generate');

        $nonMatchingEvent = new stdClass();

        $handler = $this->createHandler();
        $handler->handle($nonMatchingEvent);
    }

    // ==========================================
    // Feed generator delegation
    // ==========================================

    public function testHandlePassesOnlyProductsToFeedGenerator(): void
    {
        $products = [
            ['id' => 'p_a', 'title' => 'Alpha'],
            ['id' => 'p_b', 'title' => 'Beta'],
        ];

        $this->productService
            ->method('listProducts')
            ->willReturn([
                'products' => $products,
                'total' => 200,
                'limit' => 1000,
                'offset' => 0,
            ]);

        $this->feedGenerator
            ->expects($this->once())
            ->method('generate')
            ->with($this->identicalTo($products))
            ->willReturn('feed-output');

        $this->feedGenerator->method('getContentType')->willReturn('text/csv');
        $this->feedGenerator->method('getFileExtension')->willReturn('csv');

        $event = $this->createEvent();
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    // ==========================================
    // Empty product list
    // ==========================================

    public function testHandleWithEmptyProductList(): void
    {
        $this->productService
            ->method('listProducts')
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 1000, 'offset' => 0]);

        $this->feedGenerator
            ->expects($this->once())
            ->method('generate')
            ->with([])
            ->willReturn('');

        $this->feedGenerator->method('getContentType')->willReturn('text/csv');
        $this->feedGenerator->method('getFileExtension')->willReturn('csv');

        $event = $this->createEvent();
        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertSame('', $event->getContext()->get('feedContent'));
    }

    // ==========================================
    // Null limit/offset in context fallback
    // ==========================================

    public function testHandleNullLimitDefaultsTo1000(): void
    {
        $this->productService
            ->expects($this->once())
            ->method('listProducts')
            ->with(['limit' => 1000, 'offset' => 0])
            ->willReturn(['products' => [], 'total' => 0, 'limit' => 1000, 'offset' => 0]);

        $this->feedGenerator->method('generate')->willReturn('');
        $this->feedGenerator->method('getContentType')->willReturn('text/csv');
        $this->feedGenerator->method('getFileExtension')->willReturn('csv');

        // Explicitly pass null values
        $event = $this->createEvent(['limit' => null, 'offset' => null]);
        $handler = $this->createHandler();
        $handler->handle($event);
    }
}
