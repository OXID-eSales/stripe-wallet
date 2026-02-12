<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\Payments\Stripe\Mcp\Event\UcpCheckoutRequestEvent;

class UcpCheckoutRequestHandler implements HandlerInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return UcpCheckoutRequestEvent::class;
    }

    public function handle(object $event): void
    {
        /** @var UcpCheckoutRequestEvent $event */
        $context = $event->getContext();

        /** @var string $method */
        $method = $context->get('httpMethod');
        /** @var list<string> $segments */
        $segments = $context->get('pathSegments');
        /** @var array<string, mixed> $body */
        $body = $context->get('requestBody');
        /** @var AgentContext $agentContext */
        $agentContext = $context->get('agentContext');

        $checkoutId = $segments[1] ?? '';
        $action = $segments[2] ?? '';
        $rawPaymentData = $body['payment_data'] ?? [];
        /** @var array<string, mixed> $paymentData */
        $paymentData = is_array($rawPaymentData) ? $rawPaymentData : [];

        [$statusCode, $responseData] = match (true) {
            $method === 'POST' && count($segments) === 1
                => [201, $this->checkoutService->createCheckout($body, $agentContext)],
            $method === 'GET' && count($segments) === 2
                => [200, $this->checkoutService->getCheckout($checkoutId)],
            $method === 'PUT' && count($segments) === 2
                => [200, $this->checkoutService->updateCheckout($checkoutId, $body, $agentContext)],
            $method === 'POST' && count($segments) === 3 && $action === 'complete'
                => [200, $this->checkoutService->completeCheckout(
                    $checkoutId,
                    $paymentData,
                    $agentContext
                )],
            $method === 'POST' && count($segments) === 3 && $action === 'cancel'
                => [200, $this->checkoutService->cancelCheckout($checkoutId)],
            default => [404, ['error' => ['type' => 'not_found', 'message' => 'Endpoint not found']]],
        };

        $context->set('httpStatusCode', $statusCode);
        $context->set('responseData', $responseData);
    }
}
