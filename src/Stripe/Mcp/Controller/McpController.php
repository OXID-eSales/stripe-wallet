<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Event\McpRequestReceivedEvent;
use OxidEsales\PaymentComponent\Mcp\Http\RateLimiterInterface;

class McpController extends FrontendController
{
    private const MAX_BODY_BYTES = 1_048_576; // 1 MB

    private ?McpAuthGuardInterface $authGuard = null;
    private ?EventDispatcherInterface $eventDispatcher = null;
    private ?RateLimiterInterface $rateLimiter = null;

    public function init(): void
    {
        parent::init();

        $container = ContainerFactory::getInstance()->getContainer();

        $this->authGuard = $container->get(McpAuthGuardInterface::class); // @phpstan-ignore assign.propertyType
        $this->eventDispatcher = $container->get(EventDispatcherInterface::class); // @phpstan-ignore assign.propertyType
        $this->rateLimiter = $container->get(RateLimiterInterface::class); // @phpstan-ignore assign.propertyType
    }

    public function render(): string
    {
        if ($this->rateLimiter !== null && !$this->rateLimiter->isAllowed($this->getClientIp())) {
            $this->jsonRpcError(429, null, -32000, 'Too many requests');
            $this->terminate();

            return ''; // @phpstan-ignore deadCode.unreachable
        }

        if ($this->authGuard === null) {
            $this->jsonRpcError(500, null, -32603, 'Auth guard not available');
            $this->terminate();

            return ''; // @phpstan-ignore deadCode.unreachable
        }

        $authResult = $this->authGuard->authenticate();
        if (!$authResult->isAuthenticated()) {
            $this->jsonRpcError(401, null, -32000, $authResult->getErrorMessage() ?? 'Unauthorized');
            $this->terminate();

            return ''; // @phpstan-ignore deadCode.unreachable
        }

        $rawBody = $this->readJsonBody();
        if ($rawBody === null) {
            $this->terminate();

            return ''; // @phpstan-ignore deadCode.unreachable
        }

        $context = new EventContext([
            'rawJsonRpc' => $rawBody,
            'agentContext' => $authResult->getAgentContext(),
        ]);

        $event = new McpRequestReceivedEvent($context);
        $this->eventDispatcher?->dispatch($event);

        $response = $context->get('mcpResponse') ?? [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32603, 'message' => 'No handler produced a response'],
        ];

        header('Content-Type: application/json');
        echo json_encode($response, JSON_THROW_ON_ERROR);
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

    private function readJsonBody(): ?string
    {
        if (!$this->isJsonContentType()) {
            $this->jsonRpcError(415, null, -32700, 'Content-Type must be application/json');
            return null;
        }

        if ($this->getContentLength() > self::MAX_BODY_BYTES) {
            $this->jsonRpcError(413, null, -32700, 'Request body too large');
            return null;
        }

        $rawBody = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if ($rawBody === false || $rawBody === '') {
            $this->jsonRpcError(400, null, -32700, 'Empty request body');
            return null;
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            $this->jsonRpcError(413, null, -32700, 'Request body too large');
            return null;
        }

        return $rawBody;
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

    private function jsonRpcError(int $httpCode, int|string|null $id, int $code, string $message): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        if ($httpCode === 429) {
            header('Retry-After: 60');
        }
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }

    private function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($remoteAddr) ? $remoteAddr : '0.0.0.0';
    }
}
