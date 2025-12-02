# Sprint 1: TDD Implementation Plan - Session Restoration via URL Hash

**Approach:** Test-Driven Development (RED → GREEN → REFACTOR)
**Estimated Tests:** 25+ unit tests, 5+ integration tests

---

## Architecture Requirements

### Liskov Substitution Principle (LSP)

All classes MUST follow LSP - any implementation must be substitutable for its interface without breaking behavior.

### Component vs Stripe Separation

Provider-agnostic logic goes to `src/Component/`, Stripe-specific goes to `src/Stripe/`.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     NEW INTERFACES & IMPLEMENTATIONS                         │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  src/Component/ (FRAMEWORK - Provider Agnostic)                             │
│  ├── Contract/                                                              │
│  │   └── SecurityValidationResultInterface.php   ◄── NEW                    │
│  └── Service/                                                               │
│      ├── TokenServiceInterface.php               ◄── NEW                    │
│      └── ReturnSecurityValidatorInterface.php    ◄── NEW                    │
│                                                                             │
│  src/Stripe/ (PROVIDER SPECIFIC - Implements Component interfaces)          │
│  └── Service/                                                               │
│      ├── ContractTokenService.php        implements TokenServiceInterface   │
│      ├── ReturnSessionSecurityService.php implements ReturnSecurityValidator│
│      └── Result/                                                            │
│          └── SecurityValidationResult.php implements SecurityValidationResult│
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## TDD Workflow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           TDD CYCLE                                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. RED:    Write failing test that defines expected behavior               │
│  2. GREEN:  Write minimal code to make test pass                            │
│  3. REFACTOR: Improve code while keeping tests green                        │
│  4. REPEAT: Next test case                                                  │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Phase 1: ReturnSessionSecurityService (New Class)

### Purpose
Validate returning user identity and calculate fraud risk score.

### Test File
`tests/Unit/Stripe/Service/ReturnSessionSecurityServiceTest.php`

### Test Cases (RED first)

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\Service\ReturnSessionSecurityService;
use OxidSolutionCatalysts\Payments\Stripe\Service\Result\SecurityValidationResult;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\ReturnSessionSecurityService
 */
class ReturnSessionSecurityServiceTest extends TestCase
{
    private ReturnSessionSecurityService $service;

    protected function setUp(): void
    {
        $this->service = new ReturnSessionSecurityService();
    }

    // =========================================================================
    // IP Address Validation Tests
    // =========================================================================

    public function testValidateReturnGivesFullScoreWhenIpMatches(): void
    {
        // Arrange
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 300, // 5 minutes ago
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertEquals(100, $result->getScore());
        $this->assertTrue($result->isAllowed());
        $this->assertEmpty($result->getWarnings());
    }

    public function testValidateReturnReducesScoreWhenIpChanged(): void
    {
        // Arrange
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = ['ip' => '10.0.0.50'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertLessThan(100, $result->getScore());
        $this->assertContains('ip_changed', $result->getWarnings());
    }

    public function testValidateReturnPartiallyRestoresScoreWhenSameCountry(): void
    {
        // Arrange: Both IPs from Germany
        $contract = $this->createContractWithMetadata([
            'user_ip' => '85.214.132.117',  // German IP
            'user_country' => 'DE',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '91.64.234.56',  // Different German IP
            'country' => 'DE',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Score reduced but not as much as cross-country
        $this->assertGreaterThan(70, $result->getScore());
        $this->assertContains('ip_changed_same_country', $result->getWarnings());
    }

    public function testValidateReturnHeavilyReducesScoreWhenCountryChanged(): void
    {
        // Arrange: IP changed from Germany to Russia
        $contract = $this->createContractWithMetadata([
            'user_ip' => '85.214.132.117',
            'user_country' => 'DE',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '95.165.12.34',
            'country' => 'RU',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Heavy penalty for country change
        $this->assertLessThan(60, $result->getScore());
        $this->assertContains('country_changed', $result->getWarnings());
    }

    // =========================================================================
    // Timing Validation Tests
    // =========================================================================

    public function testValidateReturnAllowsQuickReturn(): void
    {
        // Arrange: Return within 5 minutes
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 300, // 5 minutes ago
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertEquals(100, $result->getScore());
    }

    public function testValidateReturnReducesScoreForSlowReturn(): void
    {
        // Arrange: Return after 30 minutes
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 1800, // 30 minutes ago
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertLessThan(100, $result->getScore());
        $this->assertContains('slow_return', $result->getWarnings());
    }

    public function testValidateReturnBlocksVeryLateReturn(): void
    {
        // Arrange: Return after 2 hours
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 7200, // 2 hours ago
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Very late return is suspicious
        $this->assertLessThan(70, $result->getScore());
        $this->assertContains('very_late_return', $result->getWarnings());
    }

    // =========================================================================
    // User Agent Validation Tests
    // =========================================================================

    public function testValidateReturnAllowsSameUserAgent(): void
    {
        // Arrange
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0';
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => $userAgent,
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '192.168.1.100',
            'user_agent' => $userAgent,
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertEquals(100, $result->getScore());
    }

    public function testValidateReturnAllowsSimilarUserAgent(): void
    {
        // Arrange: Same browser, different version (common after auto-update)
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/119.0',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Minor version change is OK
        $this->assertGreaterThan(90, $result->getScore());
    }

    public function testValidateReturnReducesScoreForDifferentBrowser(): void
    {
        // Arrange: Changed from Chrome to Firefox
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/121.0',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertLessThan(90, $result->getScore());
        $this->assertContains('browser_changed', $result->getWarnings());
    }

    public function testValidateReturnReducesScoreForDifferentOS(): void
    {
        // Arrange: Changed from Windows to Linux
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertLessThan(80, $result->getScore());
        $this->assertContains('os_changed', $result->getWarnings());
    }

    // =========================================================================
    // Multiple Factor Tests
    // =========================================================================

    public function testValidateReturnCumulatesMultipleRiskFactors(): void
    {
        // Arrange: IP changed + country changed + late return
        $contract = $this->createContractWithMetadata([
            'user_ip' => '85.214.132.117',
            'user_country' => 'DE',
            'user_agent' => 'Chrome/120.0',
            'created_timestamp' => time() - 3600, // 1 hour ago
        ]);
        $currentContext = [
            'ip' => '95.165.12.34',
            'country' => 'RU',
            'user_agent' => 'Firefox/121.0',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Multiple factors = very low score
        $this->assertLessThan(40, $result->getScore());
        $this->assertFalse($result->isAllowed());
        $this->assertGreaterThan(2, count($result->getWarnings()));
    }

    // =========================================================================
    // Threshold Tests
    // =========================================================================

    public function testIsAllowedReturnsTrueAboveThreshold(): void
    {
        // Arrange: Score 75 (above default 50 threshold)
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 1800, // 30 min - slight penalty
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertTrue($result->isAllowed());
    }

    public function testIsAllowedReturnsFalseBelowThreshold(): void
    {
        // Arrange: Many risk factors
        $contract = $this->createContractWithMetadata([
            'user_ip' => '85.214.132.117',
            'user_country' => 'DE',
            'created_timestamp' => time() - 7200,
        ]);
        $currentContext = [
            'ip' => '95.165.12.34',
            'country' => 'CN',
        ];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert
        $this->assertFalse($result->isAllowed());
    }

    public function testCustomThresholdIsRespected(): void
    {
        // Arrange
        $service = new ReturnSessionSecurityService(threshold: 80);
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 1800, // Score ~85
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $service->validateReturn($contract, $currentContext);

        // Assert: Score ~85, threshold 80 = allowed
        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testHandlesMissingMetadata(): void
    {
        // Arrange: Contract with minimal metadata
        $contract = $this->createContractWithMetadata([
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = ['ip' => '192.168.1.100'];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Should not crash, just reduce score for missing data
        $this->assertInstanceOf(SecurityValidationResult::class, $result);
        $this->assertContains('missing_original_ip', $result->getWarnings());
    }

    public function testHandlesEmptyContext(): void
    {
        // Arrange
        $contract = $this->createContractWithMetadata([
            'user_ip' => '192.168.1.100',
            'created_timestamp' => time() - 300,
        ]);
        $currentContext = [];

        // Act
        $result = $this->service->validateReturn($contract, $currentContext);

        // Assert: Should not crash
        $this->assertInstanceOf(SecurityValidationResult::class, $result);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContractWithMetadata(array $metadata): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'totalGross' => 100.0,
            'currency' => 'EUR',
            'items' => [],
        ]);

        $contract = new PaymentContract(1, 'user123', $basketSnapshot);

        foreach ($metadata as $key => $value) {
            $contract->setMetadata($key, $value);
        }

        return $contract;
    }
}
```

### Implementation File
`src/Stripe/Service/ReturnSessionSecurityService.php`

---

## Phase 2: SecurityValidationResult (Value Object)

### Test File
`tests/Unit/Stripe/Service/Result/SecurityValidationResultTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service\Result;

use OxidSolutionCatalysts\Payments\Stripe\Service\Result\SecurityValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\Result\SecurityValidationResult
 */
class SecurityValidationResultTest extends TestCase
{
    public function testCreateWithScore(): void
    {
        $result = new SecurityValidationResult(85, [], true);

        $this->assertEquals(85, $result->getScore());
        $this->assertTrue($result->isAllowed());
        $this->assertEmpty($result->getWarnings());
    }

    public function testCreateWithWarnings(): void
    {
        $warnings = ['ip_changed', 'slow_return'];
        $result = new SecurityValidationResult(60, $warnings, true);

        $this->assertEquals(['ip_changed', 'slow_return'], $result->getWarnings());
    }

    public function testIsAllowedReflectsConstructorValue(): void
    {
        $allowed = new SecurityValidationResult(80, [], true);
        $blocked = new SecurityValidationResult(30, [], false);

        $this->assertTrue($allowed->isAllowed());
        $this->assertFalse($blocked->isAllowed());
    }

    public function testToArrayReturnsAllData(): void
    {
        $result = new SecurityValidationResult(75, ['ip_changed'], true);

        $array = $result->toArray();

        $this->assertEquals(75, $array['score']);
        $this->assertEquals(['ip_changed'], $array['warnings']);
        $this->assertTrue($array['allowed']);
    }

    public function testScoreIsBoundedBetween0And100(): void
    {
        $low = new SecurityValidationResult(-10, [], false);
        $high = new SecurityValidationResult(150, [], true);

        $this->assertEquals(0, $low->getScore());
        $this->assertEquals(100, $high->getScore());
    }
}
```

---

## Phase 3: ContractTokenService (New Class)

### Purpose
Generate and validate secure tokens for contract identification in URLs.

### Test File
`tests/Unit/Stripe/Service/ContractTokenServiceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\Service\ContractTokenService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\ContractTokenService
 */
class ContractTokenServiceTest extends TestCase
{
    private ContractTokenService $service;
    private string $secret = 'test_secret_key_for_hmac_generation';

    protected function setUp(): void
    {
        $this->service = new ContractTokenService($this->secret);
    }

    // =========================================================================
    // Token Generation Tests
    // =========================================================================

    public function testGenerateTokenReturnsNonEmptyString(): void
    {
        $token = $this->service->generateToken('contract_123');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testGenerateTokenIsDeterministic(): void
    {
        $token1 = $this->service->generateToken('contract_123');
        $token2 = $this->service->generateToken('contract_123');

        $this->assertEquals($token1, $token2);
    }

    public function testGenerateTokenDiffersForDifferentContracts(): void
    {
        $token1 = $this->service->generateToken('contract_123');
        $token2 = $this->service->generateToken('contract_456');

        $this->assertNotEquals($token1, $token2);
    }

    public function testGenerateTokenIncludesContractId(): void
    {
        $token = $this->service->generateToken('contract_abc123');

        // Token format: base64(contractId:hmac)
        $decoded = base64_decode($token);
        $this->assertStringContainsString('contract_abc123', $decoded);
    }

    public function testGenerateTokenIsUrlSafe(): void
    {
        $token = $this->service->generateToken('contract_123');

        // Should be URL-safe base64
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    // =========================================================================
    // Token Validation Tests
    // =========================================================================

    public function testValidateTokenReturnsTrueForValidToken(): void
    {
        $contractId = 'contract_xyz';
        $token = $this->service->generateToken($contractId);

        $this->assertTrue($this->service->validateToken($token, $contractId));
    }

    public function testValidateTokenReturnsFalseForTamperedToken(): void
    {
        $contractId = 'contract_xyz';
        $token = $this->service->generateToken($contractId);
        $tamperedToken = $token . 'x';

        $this->assertFalse($this->service->validateToken($tamperedToken, $contractId));
    }

    public function testValidateTokenReturnsFalseForWrongContractId(): void
    {
        $token = $this->service->generateToken('contract_123');

        $this->assertFalse($this->service->validateToken($token, 'contract_456'));
    }

    public function testValidateTokenReturnsFalseForDifferentSecret(): void
    {
        $service1 = new ContractTokenService('secret_1');
        $service2 = new ContractTokenService('secret_2');

        $token = $service1->generateToken('contract_123');

        $this->assertFalse($service2->validateToken($token, 'contract_123'));
    }

    public function testValidateTokenReturnsFalseForEmptyToken(): void
    {
        $this->assertFalse($this->service->validateToken('', 'contract_123'));
    }

    public function testValidateTokenReturnsFalseForMalformedToken(): void
    {
        $this->assertFalse($this->service->validateToken('not_valid_base64!!!', 'contract_123'));
    }

    // =========================================================================
    // Contract ID Extraction Tests
    // =========================================================================

    public function testExtractContractIdFromValidToken(): void
    {
        $contractId = 'contract_abc123';
        $token = $this->service->generateToken($contractId);

        $extracted = $this->service->extractContractId($token);

        $this->assertEquals($contractId, $extracted);
    }

    public function testExtractContractIdReturnsNullForInvalidToken(): void
    {
        $this->assertNull($this->service->extractContractId('invalid_token'));
    }

    public function testExtractContractIdReturnsNullForEmptyToken(): void
    {
        $this->assertNull($this->service->extractContractId(''));
    }
}
```

---

## Phase 4: Update StripeCheckoutSessionHandler

### Test File (Update Existing)
`tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutSessionHandlerTest.php`

### New Test Cases to Add

```php
// Add to existing test class

public function testSuccessUrlContainsContractToken(): void
{
    // Arrange
    $context = $this->createCheckoutContext();
    $event = new StripeCheckoutSessionRequestEvent($context);

    // Act
    $handler->handle($event);

    // Assert
    $checkoutUrl = $context->get('checkoutUrl');
    $this->assertStringContainsString('contract_token=', $checkoutUrl);
}

public function testSuccessUrlContainsContractId(): void
{
    // Arrange
    $context = $this->createCheckoutContext();
    $contract = $context->getContract();
    $event = new StripeCheckoutSessionRequestEvent($context);

    // Act
    $handler->handle($event);

    // Assert
    $successUrl = $this->extractSuccessUrlFromStripeCall();
    $this->assertStringContainsString($contract->getId(), $successUrl);
}

public function testCancelUrlDoesNotContainToken(): void
{
    // Arrange & Act
    $context = $this->createCheckoutContext();
    $handler->handle(new StripeCheckoutSessionRequestEvent($context));

    // Assert: Cancel URL should just go back to payment page
    $cancelUrl = $this->extractCancelUrlFromStripeCall();
    $this->assertStringNotContainsString('contract_token', $cancelUrl);
}
```

---

## Phase 5: Update StripeContractCreationHandler

### Test File (Update Existing)
`tests/Unit/Stripe/EventSystem/Handler/StripeContractCreationHandlerTest.php`

### New Test Cases to Add

```php
// Add to existing test class

public function testContractStoresUserIpInMetadata(): void
{
    // Arrange
    $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
    $context = $this->createCheckoutContext();
    $event = new StripeCheckoutSessionRequestEvent($context);

    // Act
    $handler->handle($event);

    // Assert
    $contract = $context->getContract();
    $this->assertEquals('192.168.1.100', $contract->getMetadata('user_ip'));
}

public function testContractStoresUserAgentInMetadata(): void
{
    // Arrange
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0';
    $context = $this->createCheckoutContext();
    $event = new StripeCheckoutSessionRequestEvent($context);

    // Act
    $handler->handle($event);

    // Assert
    $contract = $context->getContract();
    $this->assertEquals('Mozilla/5.0 Chrome/120.0', $contract->getMetadata('user_agent'));
}

public function testContractStoresCreatedTimestamp(): void
{
    // Arrange
    $beforeTime = time();
    $context = $this->createCheckoutContext();
    $event = new StripeCheckoutSessionRequestEvent($context);

    // Act
    $handler->handle($event);

    // Assert
    $contract = $context->getContract();
    $timestamp = $contract->getMetadata('created_timestamp');
    $this->assertGreaterThanOrEqual($beforeTime, $timestamp);
    $this->assertLessThanOrEqual(time(), $timestamp);
}

public function testContractStoresDeliveryAddressHash(): void
{
    // Arrange
    $context = $this->createCheckoutContextWithDeliveryAddress();
    $event = new StripeCheckoutSessionRequestEvent($context);

    // Act
    $handler->handle($event);

    // Assert
    $contract = $context->getContract();
    $this->assertNotEmpty($contract->getMetadata('delivery_address_hash'));
}

public function testContractStoresDeliveryAddressId(): void
{
    // Arrange
    $context = $this->createCheckoutContextWithDeliveryAddress('addr_123');
    $event = new StripeCheckoutSessionRequestEvent($context);

    // Act
    $handler->handle($event);

    // Assert
    $contract = $context->getContract();
    $this->assertEquals('addr_123', $contract->getMetadata('delivery_address_id'));
}

public function testContractStoresSessionId(): void
{
    // Arrange
    $context = $this->createCheckoutContext();
    $context->set('sessionId', 'sess_abc123');
    $event = new StripeCheckoutSessionRequestEvent($context);

    // Act
    $handler->handle($event);

    // Assert
    $contract = $context->getContract();
    $this->assertEquals('sess_abc123', $contract->getMetadata('session_id'));
}

public function testContractStoresUserCountry(): void
{
    // Arrange
    $context = $this->createCheckoutContext();
    $context->set('userCountry', 'DE');
    $event = new StripeCheckoutSessionRequestEvent($context);

    // Act
    $handler->handle($event);

    // Assert
    $contract = $context->getContract();
    $this->assertEquals('DE', $contract->getMetadata('user_country'));
}
```

---

## Phase 6: Update StripeCheckoutReturnHandler

### Test File (Update/Create)
`tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidSolutionCatalysts\Payments\Stripe\Service\ReturnSessionSecurityService;
use OxidSolutionCatalysts\Payments\Stripe\Service\ContractTokenService;
use OxidSolutionCatalysts\Payments\Stripe\Service\Result\SecurityValidationResult;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler
 */
class StripeCheckoutReturnHandlerTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private ContractTokenService $tokenService;
    private ReturnSessionSecurityService $securityService;
    private LoggerInterface $logger;
    private StripeCheckoutReturnHandler $handler;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->tokenService = $this->createMock(ContractTokenService::class);
        $this->securityService = $this->createMock(ReturnSessionSecurityService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new StripeCheckoutReturnHandler(
            $this->contractRepository,
            $this->tokenService,
            $this->securityService,
            $this->logger
        );
    }

    // =========================================================================
    // Token Validation Tests
    // =========================================================================

    public function testLoadsContractFromToken(): void
    {
        // Arrange
        $contractId = 'contract_123';
        $token = 'valid_token';
        $contract = $this->createContract($contractId);

        $this->tokenService
            ->method('extractContractId')
            ->with($token)
            ->willReturn($contractId);

        $this->tokenService
            ->method('validateToken')
            ->willReturn(true);

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->securityService
            ->method('validateReturn')
            ->willReturn(new SecurityValidationResult(100, [], true));

        $context = new EventContext(['contract_token' => $token]);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertEquals($contract, $context->getContract());
    }

    public function testRejectsInvalidToken(): void
    {
        // Arrange
        $this->tokenService
            ->method('validateToken')
            ->willReturn(false);

        $this->contractRepository
            ->expects($this->never())
            ->method('findById');

        $context = new EventContext(['contract_token' => 'invalid_token']);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertNotNull($context->get('error'));
        $this->assertEquals('payment', $context->get('redirectTarget'));
    }

    public function testRejectsMissingToken(): void
    {
        // Arrange
        $context = new EventContext([]);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertNotNull($context->get('error'));
    }

    // =========================================================================
    // Security Validation Tests
    // =========================================================================

    public function testPerformsSecurityValidation(): void
    {
        // Arrange
        $contract = $this->createContractWithMetadata(['user_ip' => '192.168.1.1']);

        $this->setupValidTokenAndContract($contract);

        $this->securityService
            ->expects($this->once())
            ->method('validateReturn')
            ->with($contract, $this->isType('array'))
            ->willReturn(new SecurityValidationResult(100, [], true));

        $context = new EventContext([
            'contract_token' => 'valid_token',
            'current_ip' => '192.168.1.1',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert: No error set
        $this->assertNull($context->get('error'));
    }

    public function testLogsWarningForLowSecurityScore(): void
    {
        // Arrange
        $contract = $this->createContract('contract_123');
        $this->setupValidTokenAndContract($contract);

        $this->securityService
            ->method('validateReturn')
            ->willReturn(new SecurityValidationResult(60, ['ip_changed'], true));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Suspicious'),
                $this->callback(fn($ctx) => $ctx['score'] === 60)
            );

        $context = new EventContext(['contract_token' => 'valid_token']);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $this->handler->handle($event);
    }

    public function testBlocksReturnWhenSecurityCheckFails(): void
    {
        // Arrange
        $contract = $this->createContract('contract_123');
        $this->setupValidTokenAndContract($contract);

        $this->securityService
            ->method('validateReturn')
            ->willReturn(new SecurityValidationResult(30, ['country_changed', 'very_late'], false));

        $context = new EventContext(['contract_token' => 'valid_token']);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertNotNull($context->get('error'));
        $this->assertEquals('security_check_failed', $context->get('errorCode'));
    }

    // =========================================================================
    // Session Restoration Tests
    // =========================================================================

    public function testRestoresDeliveryAddressHashToRequest(): void
    {
        // Arrange
        $expectedHash = 'abc123hash';
        $contract = $this->createContractWithMetadata([
            'delivery_address_hash' => $expectedHash,
        ]);
        $this->setupValidTokenAndContract($contract);
        $this->setupPassingSecurityCheck();

        $context = new EventContext(['contract_token' => 'valid_token']);
        $event = new StripeCheckoutReturnEvent($context);

        // Clear REQUEST to simulate return from Stripe
        unset($_REQUEST['sDeliveryAddressMD5']);

        // Act
        $this->handler->handle($event);

        // Assert: Hash should be injected into REQUEST
        $this->assertEquals($expectedHash, $_REQUEST['sDeliveryAddressMD5']);
    }

    public function testRestoresDeliveryAddressIdToSession(): void
    {
        // Arrange
        $contract = $this->createContractWithMetadata([
            'delivery_address_id' => 'addr_456',
        ]);
        $this->setupValidTokenAndContract($contract);
        $this->setupPassingSecurityCheck();

        $context = new EventContext(['contract_token' => 'valid_token']);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert: Address ID should be in context for session restoration
        $this->assertEquals('addr_456', $context->get('restoredDeliveryAddressId'));
    }

    public function testRestoresSessionIdForVerification(): void
    {
        // Arrange
        $contract = $this->createContractWithMetadata([
            'session_id' => 'original_session_123',
        ]);
        $this->setupValidTokenAndContract($contract);
        $this->setupPassingSecurityCheck();

        $context = new EventContext(['contract_token' => 'valid_token']);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertEquals('original_session_123', $context->get('originalSessionId'));
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContract(string $id): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'totalGross' => 100.0,
            'currency' => 'EUR',
            'items' => [],
        ]);
        return new PaymentContract(1, 'user123', $basketSnapshot, $id);
    }

    private function createContractWithMetadata(array $metadata): PaymentContract
    {
        $contract = $this->createContract('contract_' . uniqid());
        foreach ($metadata as $key => $value) {
            $contract->setMetadata($key, $value);
        }
        return $contract;
    }

    private function setupValidTokenAndContract(PaymentContract $contract): void
    {
        $this->tokenService
            ->method('extractContractId')
            ->willReturn($contract->getId());

        $this->tokenService
            ->method('validateToken')
            ->willReturn(true);

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);
    }

    private function setupPassingSecurityCheck(): void
    {
        $this->securityService
            ->method('validateReturn')
            ->willReturn(new SecurityValidationResult(100, [], true));
    }
}
```

---

## Phase 7: Integration Tests

### Test File
`tests/Integration/Stripe/SessionRestorationIntegrationTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe;

use OxidSolutionCatalysts\Payments\Tests\Integration\IntegrationTestCase;
use OxidSolutionCatalysts\Payments\Stripe\Service\ContractTokenService;
use OxidSolutionCatalysts\Payments\Stripe\Service\ReturnSessionSecurityService;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Repository\DoctrineContractRepository;

/**
 * Integration tests for session restoration flow
 */
class SessionRestorationIntegrationTest extends IntegrationTestCase
{
    public function testFullSessionRestorationFlow(): void
    {
        // 1. Create contract with session data (simulates checkout start)
        $contract = $this->createContractWithFullSessionData();
        $this->contractRepository->save($contract);

        // 2. Generate token for URL
        $tokenService = new ContractTokenService($this->getTestSecret());
        $token = $tokenService->generateToken($contract->getId());

        // 3. Simulate return from Stripe (new request context)
        $_REQUEST = []; // Clear all request data
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100'; // Same IP

        // 4. Load contract and validate
        $extractedId = $tokenService->extractContractId($token);
        $this->assertEquals($contract->getId(), $extractedId);

        $loadedContract = $this->contractRepository->findById($extractedId);
        $this->assertNotNull($loadedContract);

        // 5. Security validation
        $securityService = new ReturnSessionSecurityService();
        $result = $securityService->validateReturn($loadedContract, [
            'ip' => $_SERVER['REMOTE_ADDR'],
        ]);
        $this->assertTrue($result->isAllowed());

        // 6. Restore session data
        $_REQUEST['sDeliveryAddressMD5'] = $loadedContract->getMetadata('delivery_address_hash');

        // 7. Verify restoration
        $this->assertEquals(
            'expected_hash_123',
            $_REQUEST['sDeliveryAddressMD5']
        );
    }

    public function testSessionRestorationBlocksSuspiciousReturn(): void
    {
        // 1. Create contract
        $contract = $this->createContractWithFullSessionData();
        $contract->setMetadata('user_ip', '192.168.1.100');
        $contract->setMetadata('user_country', 'DE');
        $this->contractRepository->save($contract);

        // 2. Simulate return from different country
        $_SERVER['REMOTE_ADDR'] = '95.165.12.34'; // Russian IP

        // 3. Security validation should fail
        $securityService = new ReturnSessionSecurityService();
        $result = $securityService->validateReturn($contract, [
            'ip' => '95.165.12.34',
            'country' => 'RU',
        ]);

        $this->assertFalse($result->isAllowed());
        $this->assertContains('country_changed', $result->getWarnings());
    }

    public function testContractPersistsAllRequiredMetadata(): void
    {
        // Arrange
        $contract = new PaymentContract(
            1,
            'user_123',
            BasketSnapshot::fromArray(['totalGross' => 100.0, 'currency' => 'EUR', 'items' => []])
        );
        $contract->setMetadata('delivery_address_hash', 'hash_abc');
        $contract->setMetadata('delivery_address_id', 'addr_xyz');
        $contract->setMetadata('user_ip', '192.168.1.1');
        $contract->setMetadata('user_agent', 'Chrome/120');
        $contract->setMetadata('session_id', 'sess_123');
        $contract->setMetadata('created_timestamp', time());

        // Act
        $this->contractRepository->save($contract);
        $loaded = $this->contractRepository->findById($contract->getId());

        // Assert: All metadata persisted
        $this->assertEquals('hash_abc', $loaded->getMetadata('delivery_address_hash'));
        $this->assertEquals('addr_xyz', $loaded->getMetadata('delivery_address_id'));
        $this->assertEquals('192.168.1.1', $loaded->getMetadata('user_ip'));
        $this->assertEquals('Chrome/120', $loaded->getMetadata('user_agent'));
        $this->assertEquals('sess_123', $loaded->getMetadata('session_id'));
        $this->assertNotNull($loaded->getMetadata('created_timestamp'));
    }

    private function createContractWithFullSessionData(): PaymentContract
    {
        $contract = new PaymentContract(
            1,
            'test_user',
            BasketSnapshot::fromArray(['totalGross' => 50.0, 'currency' => 'EUR', 'items' => []])
        );

        $contract->setMetadata('delivery_address_hash', 'expected_hash_123');
        $contract->setMetadata('delivery_address_id', 'addr_test');
        $contract->setMetadata('user_ip', '192.168.1.100');
        $contract->setMetadata('user_agent', 'TestBrowser/1.0');
        $contract->setMetadata('user_country', 'DE');
        $contract->setMetadata('session_id', 'sess_original');
        $contract->setMetadata('created_timestamp', time());

        return $contract;
    }

    private function getTestSecret(): string
    {
        return 'test_secret_for_integration_tests';
    }
}
```

---

## Implementation Order (TDD)

### Round 1: Value Objects & Services (No Dependencies)

| Order | Class | Tests | LOC Est. |
|-------|-------|-------|----------|
| 1.1 | `SecurityValidationResult` | 5 tests | 30 |
| 1.2 | `ContractTokenService` | 12 tests | 50 |
| 1.3 | `ReturnSessionSecurityService` | 18 tests | 100 |

### Round 2: Handler Updates

| Order | Class | Tests | LOC Est. |
|-------|-------|-------|----------|
| 2.1 | `StripeContractCreationHandler` | +7 tests | 40 |
| 2.2 | `StripeCheckoutSessionHandler` | +3 tests | 20 |
| 2.3 | `StripeCheckoutReturnHandler` | +12 tests | 80 |

### Round 3: Integration

| Order | Test File | Tests |
|-------|-----------|-------|
| 3.1 | `SessionRestorationIntegrationTest` | 3 tests |

---

## Run Commands

```bash
# ═══════════════════════════════════════════════════════════════════════════════
# UNIT TESTS (no database required)
# ═══════════════════════════════════════════════════════════════════════════════

# Run single test class during TDD cycle
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "SecurityValidationResultTest"

# Run single test method
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "testValidateReturnGivesFullScoreWhenIpMatches"

# Run all Sprint 1 unit tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "SecurityValidationResult|ContractTokenService|ReturnSessionSecurity|StripeCheckoutReturnHandler"

# Run all new service tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "Stripe/Service"

# ═══════════════════════════════════════════════════════════════════════════════
# INTEGRATION TESTS (requires database + OXID bootstrap)
# ═══════════════════════════════════════════════════════════════════════════════

# Run session restoration integration tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --filter "SessionRestorationIntegrationTest"

# Run all Stripe integration tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --filter "Integration/Stripe"

# ═══════════════════════════════════════════════════════════════════════════════
# FULL TEST SUITE (CI/CD)
# ═══════════════════════════════════════════════════════════════════════════════

# Run pre-commit checks (includes all tests)
./source/extensions/stripe/bin/pre-commit-check.sh

# Or run unit and integration separately
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit

docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php

# ═══════════════════════════════════════════════════════════════════════════════
# DEBUGGING
# ═══════════════════════════════════════════════════════════════════════════════

# Run with verbose output
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "SecurityValidationResultTest" \
    -v

# Run with stop on failure (useful during TDD)
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "ReturnSessionSecurityServiceTest" \
    --stop-on-failure
```

---

**Created:** 2025-12-02
**Last Updated:** 2025-12-02
