<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Fixture\Mcp;

class AgentTestHelper
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $bearerToken
    ) {
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    public function mcpRequest(string $method, array $params = [], int $id = 1): array
    {
        return $this->httpPost(
            $this->baseUrl . '/?cl=stripemcp',
            [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => $method,
                'params' => $params,
            ]
        );
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    public function initialize(): array
    {
        return $this->mcpRequest('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-agent', 'version' => '1.0.0'],
        ]);
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    public function listTools(): array
    {
        return $this->mcpRequest('tools/list');
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    public function callTool(string $toolName, array $arguments = []): array
    {
        return $this->mcpRequest('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    public function ucpRequest(string $method, string $path, array $body = []): array
    {
        $url = $this->baseUrl . '/?cl=stripeucp' . $path;
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->bearerToken,
            'Request-Id: ' . uniqid('test-', true),
        ];

        return match ($method) {
            'POST' => $this->doRequest('POST', $url, $body, $headers),
            'GET' => $this->doRequest('GET', $url, [], $headers),
            'PUT' => $this->doRequest('PUT', $url, $body, $headers),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    private function httpPost(string $url, array $body): array
    {
        return $this->doRequest('POST', $url, $body, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->bearerToken,
        ]);
    }

    /**
     * @param list<string> $headers
     * @return array{httpCode: int, body: array<string, mixed>}
     */
    private function doRequest(string $method, string $url, array $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['httpCode' => 0, 'body' => ['error' => 'curl_init failed']];
        }

        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif ($method === 'PUT') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'httpCode' => $httpCode,
            'body' => is_string($response) ? (json_decode($response, true) ?? []) : [],
        ];
    }
}
