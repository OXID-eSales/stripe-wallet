# Sprint 13.1: Webhook URL Diagnosis Tests (RED Phase)

## Development Principles

| Principle | Application in This Sprint |
|-----------|---------------------------|
| **TDD-FIRST** | Write failing tests first, then fix |
| **LSP** | Use `ShopAdapterInterface` not concrete `OxidShopAdapter` |
| **DI** | Mock all dependencies via constructor injection |
| **No Over-Engineering** | Only test what's broken, minimal test cases |
| **No Duplicate Code** | Reuse existing test helpers from `StripeWebhookTestHelper` |

---

## Objective

Write failing tests that expose the `getWebhookUrl()` bug.

---

## Test File 1: Unit Test for URL Generation

**File**: `tests/Unit/Stripe/Service/ModuleConfigurationServiceWebhookUrlTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidSolutionCatalysts\Payments\Stripe\Module;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;

class ModuleConfigurationServiceWebhookUrlTest extends TestCase
{
    /**
     * @test
     */
    public function getWebhookUrlReturnsNonEmptyString(): void
    {
        // Arrange
        $service = $this->createService();

        // Act
        $url = $service->getWebhookUrl();

        // Assert
        $this->assertNotEmpty($url, 'Webhook URL should not be empty');
    }

    /**
     * @test
     */
    public function getWebhookUrlContainsControllerParameter(): void
    {
        // Arrange
        $service = $this->createService();

        // Act
        $url = $service->getWebhookUrl();

        // Assert
        $this->assertStringContainsString('cl=', $url);
        $this->assertStringContainsString('webhook', $url);
    }

    /**
     * @test
     */
    public function getWebhookUrlContainsValidHttpScheme(): void
    {
        // Arrange
        $service = $this->createService();

        // Act
        $url = $service->getWebhookUrl();

        // Assert - must start with http:// or https://
        $this->assertTrue(
            str_starts_with($url, 'http://') || str_starts_with($url, 'https://'),
            "URL '{$url}' must start with http:// or https://"
        );
    }

    /**
     * @test
     */
    public function getWebhookUrlUsesStripeWebhookController(): void
    {
        // Arrange
        $service = $this->createService();

        // Act
        $url = $service->getWebhookUrl();

        // Assert - should use one of the registered webhook controllers
        $hasValidController =
            str_contains($url, 'cl=stripe_webhook') ||
            str_contains($url, 'cl=osc_stripe_webhook');

        $this->assertTrue(
            $hasValidController,
            "URL must contain 'cl=stripe_webhook' or 'cl=osc_stripe_webhook', got: {$url}"
        );
    }

    /**
     * @test
     */
    public function getWebhookUrlDoesNotHaveDoubleSlashes(): void
    {
        // Arrange
        $service = $this->createService();

        // Act
        $url = $service->getWebhookUrl();

        // Assert - no double slashes except in http://
        $urlWithoutScheme = preg_replace('#^https?://#', '', $url);
        $this->assertStringNotContainsString('//', $urlWithoutScheme);
    }

    private function createService(): ModuleConfigurationService
    {
        $context = $this->createMock(ContextInterface::class);
        $context->method('getCurrentShopId')->willReturn(1);

        $moduleConfig = $this->createMock(ModuleConfiguration::class);
        // Note: ModuleConfiguration does NOT have getShopUrl() - this should fail!

        $moduleConfigDao = $this->createMock(ModuleConfigurationDaoInterface::class);
        $moduleConfigDao->method('get')
            ->with(Module::MODULE_ID, 1)
            ->willReturn($moduleConfig);

        return new ModuleConfigurationService($context, $moduleConfigDao);
    }
}
```

---

## Test File 2: Integration Test for Endpoint Reachability

**File**: `tests/Integration/Stripe/Webhook/WebhookEndpointReachabilityTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Webhook;

use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use OxidSolutionCatalysts\Payments\Tests\Integration\IntegrationTestCase;

/**
 * @group webhook-url
 * @group integration
 */
class WebhookEndpointReachabilityTest extends IntegrationTestCase
{
    private ModuleConfigurationService $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = Registry::get(ModuleConfigurationService::class);
    }

    /**
     * @test
     */
    public function webhookEndpointUrlCanBeGenerated(): void
    {
        // Act
        $url = $this->config->getWebhookUrl();

        // Assert
        $this->assertNotEmpty($url);
        $this->assertStringStartsWith('http', $url);
    }

    /**
     * @test
     */
    public function webhookEndpointDoesNotReturn404(): void
    {
        // Arrange
        $url = $this->config->getWebhookUrl();

        // Act - Make HTTP HEAD request to check if endpoint exists
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Assert - Should NOT be 404
        $this->assertNotEquals(
            404,
            $httpCode,
            "Webhook endpoint returned 404. URL: {$url}"
        );
    }

    /**
     * @test
     */
    public function webhookEndpointReturns200ForValidPost(): void
    {
        // Arrange
        $url = $this->config->getWebhookUrl();
        $payload = json_encode([
            'id' => 'evt_test_' . time(),
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_test_123']],
        ]);

        // Generate test signature (will fail validation, but endpoint should exist)
        $timestamp = time();
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = "t={$timestamp},v1=" . hash_hmac('sha256', $signedPayload, 'test_secret');

        // Act
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Stripe-Signature: ' . $signature,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Assert - Should be 400 (invalid signature) or 200, but NOT 404
        $this->assertNotEquals(
            404,
            $httpCode,
            "Webhook endpoint returned 404. URL: {$url}, Response: {$response}"
        );

        // Acceptable codes: 200 (success), 400 (invalid signature), 401 (auth failed)
        $this->assertContains(
            $httpCode,
            [200, 400, 401, 500],
            "Unexpected HTTP code {$httpCode}. Expected 200, 400, 401, or 500. Got 404 means endpoint doesn't exist."
        );
    }

    /**
     * @test
     */
    public function webhookUrlMatchesExpectedFormat(): void
    {
        // Act
        $url = $this->config->getWebhookUrl();

        // Assert
        $this->assertMatchesRegularExpression(
            '#^https?://[^/]+/index\.php\?cl=(stripe_webhook|osc_stripe_webhook)#',
            $url,
            "URL format should be: https://domain/index.php?cl=stripe_webhook"
        );
    }
}
```

---

## Expected Test Results (Before Fix)

```
ModuleConfigurationServiceWebhookUrlTest
  testGetWebhookUrlReturnsNonEmptyString - FAIL (returns empty or crashes)
  testGetWebhookUrlContainsControllerParameter - FAIL
  testGetWebhookUrlContainsValidHttpScheme - FAIL
  testGetWebhookUrlUsesStripeWebhookController - FAIL
  testGetWebhookUrlDoesNotHaveDoubleSlashes - FAIL

WebhookEndpointReachabilityTest
  testWebhookEndpointUrlCanBeGenerated - FAIL
  testWebhookEndpointDoesNotReturn404 - FAIL (returns 404)
  testWebhookEndpointReturns200ForValidPost - FAIL (returns 404)
  testWebhookUrlMatchesExpectedFormat - FAIL
```

---

## Run Commands

```bash
# Run unit tests
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Stripe/Service/ModuleConfigurationServiceWebhookUrlTest.php"

# Run integration tests
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  /var/www/test-module/tests/Integration/Stripe/Webhook/WebhookEndpointReachabilityTest.php
```

---

## Next Steps

After confirming tests fail, proceed to Sprint 13.2 to fix the bug.
