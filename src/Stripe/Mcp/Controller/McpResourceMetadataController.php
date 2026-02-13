<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\Mcp\Auth\ProtectedResourceMetadata;

class McpResourceMetadataController
{
    public function __construct(
        private readonly ProtectedResourceMetadata $metadata
    ) {
    }

    public function handleRequest(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');
        echo $this->metadata->toJson();
    }
}
