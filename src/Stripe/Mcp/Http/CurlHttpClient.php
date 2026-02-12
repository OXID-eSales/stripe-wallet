<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Http;

use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientResponse;

class CurlHttpClient implements HttpClientInterface
{
    public function post(
        string $url,
        string $body,
        array $headers = [],
        int $timeoutSeconds = 10
    ): HttpClientResponse {
        return $this->sendRequest('POST', $url, $body, $headers, $timeoutSeconds);
    }

    public function get(
        string $url,
        array $headers = [],
        int $timeoutSeconds = 10
    ): HttpClientResponse {
        return $this->sendRequest('GET', $url, '', $headers, $timeoutSeconds);
    }

    /**
     * @param array<string, string> $headers
     */
    private function sendRequest(
        string $method,
        string $url,
        string $body,
        array $headers,
        int $timeoutSeconds
    ): HttpClientResponse {
        $ch = curl_init($url);
        if ($ch === false) {
            return HttpClientResponse::failed('Failed to initialize cURL');
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method ?: 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeoutSeconds));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        if ($method === 'POST' && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return HttpClientResponse::failed($error);
        }

        return new HttpClientResponse($httpCode, is_string($response) ? $response : '');
    }
}
