<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\Mcp\Auth\ProtectedResourceMetadata;
use OxidEsales\Payments\Stripe\Mcp\Service\McpLogServiceInterface;

class McpResourceMetadataController
{
    private const CONTROLLER_TYPE = 'RESOURCE_METADATA';

    public function __construct(
        private readonly ProtectedResourceMetadata $metadata,
        private readonly McpLogServiceInterface $mcpLogger
    ) {
    }

    public function handleRequest(): void
    {
        $this->mcpLogger->logRequest(self::CONTROLLER_TYPE, [
            'action' => 'resource_metadata',
        ]);

        $this->mcpLogger->logResponse(self::CONTROLLER_TYPE, 200, [
            'served' => true,
        ]);

        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');
        echo $this->metadata->toJson();
    }
}
