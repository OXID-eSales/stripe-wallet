<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\Mcp\Http\RateLimiterInterface;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpProfileInterface;
use OxidEsales\Payments\Stripe\Mcp\Controller\UcpProfileController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Exception thrown by TestableUcpProfileController::terminate() to replace exit.
 * Allows unit tests to capture the point where the controller would terminate.
 */
class UcpProfileTerminateException extends RuntimeException
{
}

/**
 * Testable subclass of UcpProfileController.
 *
 * Overrides init() to skip ContainerFactory and parent::init() (FrontendController),
 * allowing mock injection in unit tests without the full OXID framework.
 * Overrides terminate() to throw an exception instead of calling exit.
 */
class TestableUcpProfileController extends UcpProfileController
{
    private UcpProfileInterface $testProfile;
    private RateLimiterInterface $testRateLimiter;

    public function setTestDependencies(
        UcpProfileInterface $profile,
        RateLimiterInterface $rateLimiter
    ): void {
        $this->testProfile = $profile;
        $this->testRateLimiter = $rateLimiter;
    }

    public function init(): void
    {
        // Skip parent::init() and ContainerFactory — inject mocks via reflection
        $reflection = new ReflectionClass(UcpProfileController::class);

        $profileProp = $reflection->getProperty('profile');
        $profileProp->setValue($this, $this->testProfile);

        $rateLimiterProp = $reflection->getProperty('rateLimiter');
        $rateLimiterProp->setValue($this, $this->testRateLimiter);
    }

    protected function terminate(): never
    {
        throw new UcpProfileTerminateException('Controller terminated');
    }
}

/**
 * Unit tests for UcpProfileController.
 *
 * The controller uses global functions (http_response_code, header, echo,
 * $_SERVER), limiting what we can verify in unit tests. We focus on
 * verifying dependency interaction patterns and output.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Controller\UcpProfileController
 */
class UcpProfileControllerTest extends TestCase
{
    private UcpProfileInterface&MockObject $profile;
    private RateLimiterInterface&MockObject $rateLimiter;

    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        $this->profile = $this->createMock(UcpProfileInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);

        // Default: rate limiter allows requests
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
    }

    private function createController(): TestableUcpProfileController
    {
        $controller = new TestableUcpProfileController();
        $controller->setTestDependencies(
            $this->profile,
            $this->rateLimiter
        );
        $controller->init();

        return $controller;
    }

    /**
     * Calls render() and captures echo output, catching the terminate exception.
     *
     * @return string The captured output
     */
    private function callRenderAndCapture(TestableUcpProfileController $controller): string
    {
        ob_start();
        try {
            @$controller->render();
        } catch (UcpProfileTerminateException) {
            // Expected — controller called terminate() instead of exit
        }

        return (string) ob_get_clean();
    }

    public function testControllerCanBeConstructed(): void
    {
        $controller = $this->createController();

        $this->assertInstanceOf(UcpProfileController::class, $controller);
    }

    /**
     * When rate limit is exceeded, the controller returns 429
     * without returning profile data.
     */
    public function testRateLimitExceededReturns429(): void
    {
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->rateLimiter->method('isAllowed')->willReturn(false);

        $this->profile
            ->expects($this->never())
            ->method('toArray');

        $controller = $this->createController();
        $output = $this->callRenderAndCapture($controller);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame('rate_limit_exceeded', $decoded['error']['type']);
        $this->assertSame('Too many requests', $decoded['error']['message']);
    }

    /**
     * When profile service is available and rate limit passes,
     * the controller returns the profile as JSON.
     */
    public function testSuccessfulProfileResponse(): void
    {
        $profileData = [
            'name' => 'Stripe Checkout',
            'version' => '1.0.0',
            'capabilities' => ['checkout.create', 'checkout.get'],
        ];

        $this->profile
            ->expects($this->once())
            ->method('toArray')
            ->willReturn($profileData);

        $controller = $this->createController();
        $output = $this->callRenderAndCapture($controller);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Stripe Checkout', $decoded['name']);
        $this->assertSame('1.0.0', $decoded['version']);
        $this->assertSame(['checkout.create', 'checkout.get'], $decoded['capabilities']);
    }

    /**
     * Profile data with slashes should not be escaped in JSON output.
     */
    public function testProfileWithUrlsNotEscaped(): void
    {
        $profileData = [
            'name' => 'Stripe Checkout',
            'profile_url' => 'https://example.com/ucp/profile',
        ];

        $this->profile
            ->method('toArray')
            ->willReturn($profileData);

        $controller = $this->createController();
        $output = $this->callRenderAndCapture($controller);

        // JSON_UNESCAPED_SLASHES should keep URLs readable
        $this->assertStringContainsString('https://example.com/ucp/profile', $output);
        $this->assertStringNotContainsString('\\/', $output);
    }
}
