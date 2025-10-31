<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Webhook;

interface WebhookProcessorInterface
{
    public function process(array $webhookData): void;
}
