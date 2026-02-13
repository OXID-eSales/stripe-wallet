<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\Payments\Stripe\Mcp\Event\ProductFeedRequestEvent;

class ProductFeedRequestHandler implements HandlerInterface
{
    public function __construct(
        private readonly AcpProductServiceInterface $productService,
        private readonly ProductFeedGeneratorInterface $feedGenerator
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return ProductFeedRequestEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ProductFeedRequestEvent) {
            return;
        }

        $context = $event->getContext();
        $limitVal = $context->get('limit');
        $limit = is_numeric($limitVal) ? (int) $limitVal : 1000;
        $offsetVal = $context->get('offset');
        $offset = is_numeric($offsetVal) ? (int) $offsetVal : 0;

        $result = $this->productService->listProducts([
            'limit' => $limit,
            'offset' => $offset,
        ]);

        /** @var array<int, array<string, mixed>> $products */
        $products = is_array($result['products'] ?? null) ? $result['products'] : [];
        $feedContent = $this->feedGenerator->generate($products);

        $context->set('feedContent', $feedContent);
        $context->set('feedContentType', $this->feedGenerator->getContentType());
        $context->set('feedFileExtension', $this->feedGenerator->getFileExtension());
    }
}
