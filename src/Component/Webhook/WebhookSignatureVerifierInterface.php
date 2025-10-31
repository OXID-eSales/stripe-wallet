<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Webhook;

interface WebhookSignatureVerifierInterface
{
    public function verify(string $payload, string $signature): bool;

    public function parseEvent(string $payload, string $signature): array;
}
