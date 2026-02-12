<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Event\McpRequestReceivedEvent;

class McpController
{
    public function __construct(
        private readonly McpAuthGuardInterface $authGuard,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function handleRequest(): void
    {
        $authResult = $this->authGuard->authenticate();
        if (!$authResult->isAuthenticated()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32000, 'message' => $authResult->getErrorMessage()],
            ]);
            return;
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Empty request body'],
            ]);
            return;
        }

        $context = new EventContext([
            'rawJsonRpc' => $rawBody,
            'agentContext' => $authResult->getAgentContext(),
        ]);

        $event = new McpRequestReceivedEvent($context);
        $this->eventDispatcher->dispatch($event);

        $response = $context->get('mcpResponse') ?? [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32603, 'message' => 'No handler produced a response'],
        ];

        header('Content-Type: application/json');
        echo json_encode($response, JSON_THROW_ON_ERROR);
    }
}
