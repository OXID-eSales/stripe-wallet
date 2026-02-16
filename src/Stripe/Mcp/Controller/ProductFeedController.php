<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\Payments\Stripe\Mcp\Event\ProductFeedRequestEvent;
use OxidEsales\Payments\Stripe\Mcp\Service\McpLogServiceInterface;

class ProductFeedController
{
    private const CONTROLLER_TYPE = 'PRODUCT_FEED';

    public function __construct(
        private readonly McpAuthGuardInterface $authGuard,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly McpLogServiceInterface $mcpLogger
    ) {
    }

    public function handleRequest(): void
    {
        $authResult = $this->authGuard->authenticate();
        if (!$authResult->isAuthenticated()) {
            $this->mcpLogger->logError(self::CONTROLLER_TYPE, 401, 'Unauthorized');
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        $this->mcpLogger->logRequest(self::CONTROLLER_TYPE, [
            'action' => 'product_feed',
        ]);

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

        $this->mcpLogger->logResponse(self::CONTROLLER_TYPE, 200, [
            'content_type' => $contentType,
            'content_length' => strlen($feedContent),
        ]);

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="product-feed.' . $fileExtension . '"');
        echo $feedContent;
    }
}
