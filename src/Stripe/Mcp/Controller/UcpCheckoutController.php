<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpRequestValidator;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpResponseFormatterInterface;
use OxidEsales\Payments\Stripe\Mcp\Event\UcpCheckoutRequestEvent;

class UcpCheckoutController
{
    public function __construct(
        private readonly McpAuthGuardInterface $authGuard,
        private readonly UcpRequestValidator $requestValidator,
        private readonly UcpResponseFormatterInterface $responseFormatter,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function handleRequest(): void
    {
        $authResult = $this->authGuard->authenticate();
        if (!$authResult->isAuthenticated()) {
            $this->jsonResponse(401, $this->responseFormatter->formatError(
                'authentication_error',
                $authResult->getErrorMessage() ?? 'Unauthorized'
            ));
            return;
        }

        $headers = $this->extractHeaders();
        $validation = $this->requestValidator->validateHeaders($headers);
        if (!$validation['valid']) {
            $this->jsonResponse(400, $this->responseFormatter->formatError(
                'invalid_request',
                implode(', ', $validation['errors'])
            ));
            return;
        }

        $serverMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $method = is_string($serverMethod) ? $serverMethod : 'GET';
        $serverPathInfo = $_SERVER['PATH_INFO'] ?? null;
        $pathInfo = is_string($serverPathInfo) ? $serverPathInfo : '';
        $segments = array_values(array_filter(explode('/', $pathInfo)));
        $rawBody = file_get_contents('php://input');

        $context = new EventContext([
            'httpMethod' => $method,
            'pathSegments' => $segments,
            'requestBody' => json_decode($rawBody ?: '{}', true) ?? [],
            'agentContext' => $authResult->getAgentContext(),
            'ucpHeaders' => $headers,
        ]);

        $event = new UcpCheckoutRequestEvent($context);
        $this->eventDispatcher->dispatch($event);

        /** @var int $statusCode */
        $statusCode = $context->get('httpStatusCode') ?? 200;
        /** @var array<string, mixed> $responseData */
        $responseData = $context->get('responseData') ?? $this->responseFormatter->formatError(
            'internal_error',
            'No handler produced a response'
        );

        $this->jsonResponse($statusCode, $responseData);
    }

    /**
     * @return array<string, string>
     */
    private function extractHeaders(): array
    {
        $requestId = $_SERVER['HTTP_REQUEST_ID'] ?? null;
        $ucpAgent = $_SERVER['HTTP_UCP_AGENT'] ?? null;
        $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;

        return [
            'request-id' => is_string($requestId) ? $requestId : '',
            'ucp-agent' => is_string($ucpAgent) ? $ucpAgent : '',
            'idempotency-key' => is_string($idempotencyKey) ? $idempotencyKey : '',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }
}
