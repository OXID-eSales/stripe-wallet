<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Http\RateLimiterInterface;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpRequestValidator;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpResponseFormatterInterface;
use OxidEsales\Payments\Stripe\Mcp\Event\UcpCheckoutRequestEvent;
use OxidEsales\Payments\Stripe\Mcp\Service\McpLogServiceInterface;

class UcpCheckoutController extends FrontendController
{
    private const MAX_BODY_BYTES = 1_048_576; // 1 MB
    private const CONTROLLER_TYPE = 'UCP_CHECKOUT';

    private ?McpAuthGuardInterface $authGuard = null;
    private ?UcpRequestValidator $requestValidator = null;
    private ?UcpResponseFormatterInterface $responseFormatter = null;
    private ?EventDispatcherInterface $eventDispatcher = null;
    private ?RateLimiterInterface $rateLimiter = null;
    private ?McpLogServiceInterface $mcpLogger = null;

    // OXID constraint: controllers use ContainerFactory, not constructor DI
    public function init(): void
    {
        parent::init();

        $container = ContainerFactory::getInstance()->getContainer();

        $this->authGuard = $container->get(McpAuthGuardInterface::class); // @phpstan-ignore assign.propertyType
        $this->requestValidator = $container->get(UcpRequestValidator::class); // @phpstan-ignore assign.propertyType
        $this->responseFormatter = $container->get(UcpResponseFormatterInterface::class); // @phpstan-ignore assign.propertyType
        $this->eventDispatcher = $container->get(EventDispatcherInterface::class); // @phpstan-ignore assign.propertyType
        $this->rateLimiter = $container->get(RateLimiterInterface::class); // @phpstan-ignore assign.propertyType
        $this->mcpLogger = $container->get(McpLogServiceInterface::class); // @phpstan-ignore assign.propertyType
    }

    public function render(): string
    {
        $clientIp = $this->getClientIp();
        if ($this->rateLimiter !== null && !$this->rateLimiter->isAllowed($clientIp)) {
            $this->mcpLogger?->logError(self::CONTROLLER_TYPE, 429, 'Too many requests', [
                'client_ip' => $clientIp,
            ]);
            $this->jsonResponse(429, $this->formatError('rate_limit_exceeded', 'Too many requests'));
            $this->terminate();

            return ''; // @phpstan-ignore deadCode.unreachable
        }

        if ($this->authGuard === null) {
            $this->mcpLogger?->logError(self::CONTROLLER_TYPE, 500, 'Auth guard not available');
            $this->jsonResponse(500, $this->formatError('internal_error', 'Auth guard not available'));
            $this->terminate();

            return ''; // @phpstan-ignore deadCode.unreachable
        }

        $authResult = $this->authGuard->authenticate();
        if (!$authResult->isAuthenticated()) {
            $errorMsg = $authResult->getErrorMessage() ?? 'Unauthorized';
            $this->mcpLogger?->logError(self::CONTROLLER_TYPE, 401, $errorMsg, [
                'client_ip' => $clientIp,
            ]);
            $this->jsonResponse(401, $this->formatError('authentication_error', $errorMsg));
            $this->terminate();

            return ''; // @phpstan-ignore deadCode.unreachable
        }

        $headers = $this->extractHeaders();
        if ($this->requestValidator !== null) {
            $validation = $this->requestValidator->validateHeaders($headers);
            if (!$validation['valid']) {
                $errorMsg = implode(', ', $validation['errors']);
                $this->mcpLogger?->logError(self::CONTROLLER_TYPE, 400, $errorMsg);
                $this->jsonResponse(400, $this->formatError('invalid_request', $errorMsg));
                $this->terminate();

                return ''; // @phpstan-ignore deadCode.unreachable
            }
        }

        $serverMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $method = is_string($serverMethod) ? $serverMethod : 'GET';
        $serverPathInfo = $_SERVER['PATH_INFO'] ?? null;
        $pathInfo = is_string($serverPathInfo) ? $serverPathInfo : '';
        $segments = array_values(array_filter(explode('/', $pathInfo)));

        $body = [];
        if ($method === 'POST' || $method === 'PUT') {
            $bodyResult = $this->readJsonBody();
            if ($bodyResult === null) {
                $this->terminate();

                return ''; // @phpstan-ignore deadCode.unreachable
            }
            $body = $bodyResult;
        }

        $this->mcpLogger?->logRequest(self::CONTROLLER_TYPE, [
            'client_ip' => $clientIp,
            'http_method' => $method,
            'path_info' => $pathInfo,
            'request_id' => $headers['request-id'],
            'ucp_agent' => $headers['ucp-agent'],
        ]);

        $context = new EventContext([
            'httpMethod' => $method,
            'pathSegments' => $segments,
            'requestBody' => $body,
            'agentContext' => $authResult->getAgentContext(),
            'ucpHeaders' => $headers,
        ]);

        $event = new UcpCheckoutRequestEvent($context);
        $this->eventDispatcher?->dispatch($event);

        /** @var int $statusCode */
        $statusCode = $context->get('httpStatusCode') ?? 200;
        /** @var array<string, mixed> $responseData */
        $responseData = $context->get('responseData') ?? $this->formatError(
            'internal_error',
            'No handler produced a response'
        );

        if ($statusCode >= 400) {
            $this->mcpLogger?->logError(self::CONTROLLER_TYPE, $statusCode, 'Handler error', $responseData);
        } else {
            $this->mcpLogger?->logResponse(self::CONTROLLER_TYPE, $statusCode, $responseData);
        }

        $this->jsonResponse($statusCode, $responseData);
        $this->terminate();

        return ''; // @phpstan-ignore deadCode.unreachable
    }

    /**
     * Terminates request execution.
     * Extracted as a protected method to allow testable subclasses to override
     * and throw an exception instead of calling exit.
     *
     * @codeCoverageIgnore
     */
    protected function terminate(): never
    {
        exit;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonBody(): ?array
    {
        if (!$this->isJsonContentType()) {
            $this->mcpLogger?->logError(self::CONTROLLER_TYPE, 415, 'Content-Type must be application/json');
            $this->jsonResponse(415, $this->formatError('invalid_request', 'Content-Type must be application/json'));
            return null;
        }

        if ($this->getContentLength() > self::MAX_BODY_BYTES) {
            $this->mcpLogger?->logError(self::CONTROLLER_TYPE, 413, 'Request body too large');
            $this->jsonResponse(413, $this->formatError('invalid_request', 'Request body too large'));
            return null;
        }

        $rawBody = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($rawBody !== false && strlen($rawBody) > self::MAX_BODY_BYTES) {
            $this->mcpLogger?->logError(self::CONTROLLER_TYPE, 413, 'Request body too large');
            $this->jsonResponse(413, $this->formatError('invalid_request', 'Request body too large'));
            return null;
        }

        $decoded = json_decode($rawBody ?: '{}', true);

        /** @var array<string, mixed> $result */
        $result = is_array($decoded) ? $decoded : [];

        return $result;
    }

    private function isJsonContentType(): bool
    {
        $rawContentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $contentType = is_string($rawContentType) ? $rawContentType : '';

        return stripos($contentType, 'application/json') !== false;
    }

    private function getContentLength(): int
    {
        $rawLength = $_SERVER['CONTENT_LENGTH'] ?? null;

        return is_string($rawLength) || is_int($rawLength) ? (int) $rawLength : 0;
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
        if ($statusCode === 429) {
            header('Retry-After: 60');
        }
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatError(string $type, string $message): array
    {
        if ($this->responseFormatter !== null) {
            return $this->responseFormatter->formatError($type, $message);
        }

        return ['error' => ['type' => $type, 'message' => $message]];
    }

    private function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($remoteAddr) ? $remoteAddr : '0.0.0.0';
    }
}
