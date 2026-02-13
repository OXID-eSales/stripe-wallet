<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\Payments\Stripe\Mcp\Event\ProductFeedRequestEvent;

class ProductFeedController
{
    public function __construct(
        private readonly McpAuthGuardInterface $authGuard,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function handleRequest(): void
    {
        $authResult = $this->authGuard->authenticate();
        if (!$authResult->isAuthenticated()) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        $context = new EventContext([
            'agentContext' => $authResult->getAgentContext(),
            'limit' => 1000,
            'offset' => 0,
        ]);

        $event = new ProductFeedRequestEvent($context);
        $this->eventDispatcher->dispatch($event);

        $feedContentVal = $context->get('feedContent');
        $feedContent = is_string($feedContentVal) ? $feedContentVal : '';
        $contentTypeVal = $context->get('feedContentType');
        $contentType = is_string($contentTypeVal) ? $contentTypeVal : 'text/csv; charset=utf-8';
        $fileExtensionVal = $context->get('feedFileExtension');
        $fileExtension = is_string($fileExtensionVal) ? $fileExtensionVal : 'csv';

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="product-feed.' . $fileExtension . '"');
        echo $feedContent;
    }
}
