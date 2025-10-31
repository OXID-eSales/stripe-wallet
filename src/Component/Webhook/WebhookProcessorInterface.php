<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Webhook;

interface WebhookProcessorInterface
{
    /**
     * @param array<string, mixed> $webhookData
     */
    public function process(array $webhookData): void;
}
