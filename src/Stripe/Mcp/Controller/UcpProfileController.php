<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Mcp\Http\RateLimiterInterface;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpProfileInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\McpLogServiceInterface;

class UcpProfileController extends FrontendController
{
    private const CONTROLLER_TYPE = 'UCP_PROFILE';

    private ?UcpProfileInterface $profile = null;
    private ?RateLimiterInterface $rateLimiter = null;
    private ?McpLogServiceInterface $mcpLogger = null;

    // OXID constraint: controllers use ContainerFactory, not constructor DI
    public function init(): void
    {
        parent::init();

        $container = ContainerFactory::getInstance()->getContainer();

        $this->profile = $container->get(UcpProfileInterface::class); // @phpstan-ignore assign.propertyType
        $this->rateLimiter = $container->get(RateLimiterInterface::class); // @phpstan-ignore assign.propertyType
        $this->mcpLogger = $container->get(McpLogServiceInterface::class); // @phpstan-ignore assign.propertyType
    }

    public function render(): string
    {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $ip = is_string($clientIp) ? $clientIp : '0.0.0.0';

        if ($this->rateLimiter !== null && !$this->rateLimiter->isAllowed($ip)) {
            $this->mcpLogger?->logError(self::CONTROLLER_TYPE, 429, 'Too many requests', [
                'client_ip' => $ip,
            ]);
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: 60');
            echo json_encode(['error' => ['type' => 'rate_limit_exceeded', 'message' => 'Too many requests']]);
            $this->terminate();

            return ''; // @phpstan-ignore deadCode.unreachable
        }

        if ($this->profile === null) {
            $this->mcpLogger?->logError(self::CONTROLLER_TYPE, 500, 'Profile service not available');
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => ['type' => 'internal_error', 'message' => 'Profile service not available']]);
            $this->terminate();

            return ''; // @phpstan-ignore deadCode.unreachable
        }

        $this->mcpLogger?->logRequest(self::CONTROLLER_TYPE, [
            'client_ip' => $ip,
        ]);

        $profileData = $this->profile->toArray();

        $this->mcpLogger?->logResponse(self::CONTROLLER_TYPE, 200, $profileData);

        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');
        echo json_encode($profileData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
}
