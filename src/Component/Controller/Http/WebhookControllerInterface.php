<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Controller\Http;

interface WebhookControllerInterface
{
    public function handleWebhook(string $payload, string $signature): array;
}
