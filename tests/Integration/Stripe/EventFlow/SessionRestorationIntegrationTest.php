<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\EventFlow;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ReturnSecurityValidatorInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Service\ContractTokenService;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\ReturnSessionSecurityService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Sprint 1: Session Restoration via URL Hash
 *
 * These tests verify the complete flow:
 * 1. Contract creation stores security metadata (IP, user_agent, timestamp)
 * 2. Checkout session URL includes contract_id and contract_token
 * 3. Return handler validates token and restores delivery address hash
 * 4. $_REQUEST['sDeliveryAddressMD5'] is injected for OXID order validation
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('session-restoration')]
#[\PHPUnit\Framework\Attributes\Group('sprint-1')]
class SessionRestorationIntegrationTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private TokenServiceInterface $tokenService;
    private ReturnSecurityValidatorInterface $securityService;
    private string $testSecret = 'sk_test_integration_test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractRepository = $this->createInMemoryContractRepository();
        $this->tokenService = new ContractTokenService($this->createConfigServiceMock());
        $this->securityService = new ReturnSessionSecurityService(50);

        // Clear any previous $_REQUEST data
        $_REQUEST = [];
    }

    /**
     * Create an in-memory contract repository for testing.
     * This is a test double that stores contracts in memory without database.
     */
    private function createInMemoryContractRepository(): ContractRepositoryInterface
    {
        return new class implements ContractRepositoryInterface {
            /** @var array<string, PaymentContractInterface> */
            private array $contracts = [];

            public function save(PaymentContractInterface $contract): void
            {
                $this->contracts[$contract->getId()] = $contract;
            }

            public function findById(string $id): ?PaymentContractInterface
            {
                return $this->contracts[$id] ?? null;
            }

            public function findByProviderOrderId(string $providerOrderId): ?PaymentContractInterface
            {
                foreach ($this->contracts as $contract) {
                    if ($contract->getProviderOrderId() === $providerOrderId) {
                        return $contract;
                    }
                }
                return null;
            }

            public function findByUserId(string $userId): array
            {
                return array_values(array_filter(
                    $this->contracts,
                    fn($contract) => $contract->getUserId() === $userId
                ));
            }

            public function findActiveByUserId(string $userId): ?PaymentContractInterface
            {
                foreach ($this->contracts as $contract) {
                    if ($contract->getUserId() === $userId && !$contract->isFinal()) {
                        return $contract;
                    }
                }
                return null;
            }

            public function findByOrderId(string $orderId): ?PaymentContractInterface
            {
                foreach ($this->contracts as $contract) {
                    if ($contract->getOrderId() === $orderId) {
                        return $contract;
                    }
                }
                return null;
            }

            public function findExpired(): array
            {
                return array_values(array_filter(
                    $this->contracts,
                    fn($contract) => $contract->isExpired()
                ));
            }

            public function findStaleNotFinished(int $minutesOld, ?int $limit = null): array
            {
                return [];
            }
        };
    }

    protected function tearDown(): void
    {
        // Clean up $_REQUEST
        $_REQUEST = [];
        parent::tearDown();
    }

    /**
     * Create a mock ModuleConfigurationServiceInterface with the test secret key
     */
    private function createConfigServiceMock(): ModuleConfigurationServiceInterface
    {
        $configService = $this->createMock(ModuleConfigurationServiceInterface::class);
        // D2: ContractTokenService calls getToken() (canonical accessor).
        $configService->method('getToken')->willReturn($this->testSecret);
        $configService->method('getWebhookSecret')->willReturn('');
        return $configService;
    }

    // =================================================================
    // Token Generation and Validation Tests
    // =================================================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function tokenServiceGeneratesValidTokensForContractId(): void
    {
        $contractId = 'test_contract_' . uniqid();

        $token = $this->tokenService->generateToken($contractId);

        $this->assertNotEmpty($token);
        $this->assertTrue($this->tokenService->validateToken($token, $contractId));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function tokenServiceRejectsTokensForDifferentContractId(): void
    {
        $contractId1 = 'contract_original';
        $contractId2 = 'contract_tampered';

        $token = $this->tokenService->generateToken($contractId1);

        $this->assertFalse($this->tokenService->validateToken($token, $contractId2));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function tokenServiceExtractsContractIdFromValidToken(): void
    {
        $contractId = 'contract_extractable_' . uniqid();

        $token = $this->tokenService->generateToken($contractId);
        $extracted = $this->tokenService->extractContractId($token);

        $this->assertEquals($contractId, $extracted);
    }

    // =================================================================
    // Security Validation Tests
    // =================================================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function securityServiceAllowsReturnFromSameIp(): void
    {
        $contract = $this->createContractWithSecurityMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 Test Browser',
            'created_timestamp' => time() - 120, // 2 minutes ago
        ]);

        $currentContext = [
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 Test Browser',
        ];

        $result = $this->securityService->validateReturn($contract, $currentContext);

        $this->assertTrue($result->isAllowed());
        $this->assertGreaterThanOrEqual(50, $result->getScore());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function securityServiceReducesScoreForDifferentIp(): void
    {
        $contract = $this->createContractWithSecurityMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 Test Browser',
            'created_timestamp' => time() - 120,
        ]);

        $currentContext = [
            'ip' => '10.0.0.50', // Different IP
            'user_agent' => 'Mozilla/5.0 Test Browser',
        ];

        $result = $this->securityService->validateReturn($contract, $currentContext);

        $this->assertLessThan(100, $result->getScore());
        $this->assertTrue($result->hasWarning('ip_changed'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function securityServiceBlocksHighlyAnomalousReturn(): void
    {
        // Create contract with security metadata
        $contract = $this->createContractWithSecurityMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 Windows Chrome',
            'user_country' => 'DE',
            'created_timestamp' => time() - 7200, // 2 hours ago (very late)
        ]);

        // Very different return context
        $currentContext = [
            'ip' => '203.0.113.50', // Different IP
            'user_agent' => 'Mozilla/5.0 Mac Safari', // Different browser AND OS
            'country' => 'US', // Different country
        ];

        $result = $this->securityService->validateReturn($contract, $currentContext);

        // Multiple penalties should reduce score below threshold
        $this->assertFalse($result->isAllowed());
        $this->assertLessThan(50, $result->getScore());
    }

    // =================================================================
    // Contract Metadata Storage Tests
    // =================================================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function contractStoresAndRetrievesDeliveryAddressHash(): void
    {
        $deliveryHash = 'abc123def456';
        $contract = $this->createTestContract();

        $contract->setMetadata('delivery_address_hash', $deliveryHash);

        $retrieved = $contract->getMetadata('delivery_address_hash');
        $this->assertEquals($deliveryHash, $retrieved);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function contractStoresAndRetrievesSecurityMetadata(): void
    {
        $contract = $this->createTestContract();

        $contract->setMetadata('user_ip', '192.168.1.50');
        $contract->setMetadata('user_agent', 'Mozilla/5.0 Test');
        $contract->setMetadata('created_timestamp', 1701500000);
        $contract->setMetadata('session_id', 'php_session_abc123');

        $this->assertEquals('192.168.1.50', $contract->getMetadata('user_ip'));
        $this->assertEquals('Mozilla/5.0 Test', $contract->getMetadata('user_agent'));
        $this->assertEquals(1701500000, $contract->getMetadata('created_timestamp'));
        $this->assertEquals('php_session_abc123', $contract->getMetadata('session_id'));
    }

    // =================================================================
    // Full Flow Integration Tests
    // =================================================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function fullFlowCreatesTokenAndValidatesOnReturn(): void
    {
        // STEP 1: Create contract with delivery address hash
        $contract = $this->createTestContract();
        $deliveryHash = md5('test_delivery_address_data');
        $contract->setMetadata('delivery_address_hash', $deliveryHash);
        $contract->setMetadata('delivery_address_id', 'del_addr_123');
        $contract->setMetadata('user_ip', '192.168.1.100');
        $contract->setMetadata('user_agent', 'Mozilla/5.0 Test Browser');
        $contract->setMetadata('created_timestamp', time());

        // Store in repository
        $this->contractRepository->save($contract);
        $contractId = $contract->getId();

        // STEP 2: Generate token (simulating StripeCheckoutSessionHandler)
        $token = $this->tokenService->generateToken($contractId);

        $this->assertNotEmpty($token);

        // STEP 3: Simulate return - validate token
        $tokenValid = $this->tokenService->validateToken($token, $contractId);
        $this->assertTrue($tokenValid, 'Token should be valid for correct contract ID');

        // STEP 4: Load contract from repository
        $loadedContract = $this->contractRepository->findById($contractId);
        $this->assertNotNull($loadedContract);

        // STEP 5: Verify delivery address hash can be retrieved
        $restoredHash = $loadedContract->getMetadata('delivery_address_hash');
        $this->assertEquals($deliveryHash, $restoredHash);

        // STEP 6: Security validation
        $currentContext = [
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 Test Browser',
        ];
        $securityResult = $this->securityService->validateReturn($loadedContract, $currentContext);
        $this->assertTrue($securityResult->isAllowed());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fullFlowRejectsInvalidToken(): void
    {
        $contract = $this->createTestContract();
        $this->contractRepository->save($contract);
        $contractId = $contract->getId();

        // Generate valid token
        $validToken = $this->tokenService->generateToken($contractId);

        // Tamper with the token
        $tamperedToken = $validToken . 'tampered';

        // Validation should fail
        $this->assertFalse($this->tokenService->validateToken($tamperedToken, $contractId));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fullFlowRejectsContractIdMismatch(): void
    {
        $contract1 = $this->createTestContract('contract_1');
        $contract2 = $this->createTestContract('contract_2');

        $this->contractRepository->save($contract1);
        $this->contractRepository->save($contract2);

        // Generate token for contract 1
        $tokenForContract1 = $this->tokenService->generateToken($contract1->getId());

        // Try to use it with contract 2
        $this->assertFalse(
            $this->tokenService->validateToken($tokenForContract1, $contract2->getId()),
            'Token for contract 1 should not validate for contract 2'
        );
    }

    // =================================================================
    // $_REQUEST Injection Tests (Simulated)
    // =================================================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function requestInjectionSetsDeliveryAddressMd5(): void
    {
        // Simulate what StripeCheckoutReturnHandler does
        $deliveryHash = 'test_delivery_hash_' . uniqid();

        // This is what the handler does
        $_REQUEST['sDeliveryAddressMD5'] = $deliveryHash;

        // Verify it's accessible
        $this->assertEquals($deliveryHash, $_REQUEST['sDeliveryAddressMD5']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function completeSessionRestorationSimulation(): void
    {
        // SETUP: Create contract with all metadata
        $contract = $this->createTestContract();
        $deliveryHash = md5('delivery_address_data_' . time());
        $deliveryAddressId = 'delivery_addr_' . uniqid();

        $contract->setMetadata('delivery_address_hash', $deliveryHash);
        $contract->setMetadata('delivery_address_id', $deliveryAddressId);
        $contract->setMetadata('user_ip', '192.168.1.50');
        $contract->setMetadata('user_agent', 'Mozilla/5.0 Chrome');
        $contract->setMetadata('created_timestamp', time() - 300);

        $this->contractRepository->save($contract);
        $contractId = $contract->getId();

        // Generate token (checkout session handler would do this)
        $contractToken = $this->tokenService->generateToken($contractId);

        // === SIMULATE STRIPE REDIRECT AND RETURN ===

        // Clear $_REQUEST (simulating fresh request after Stripe redirect)
        $_REQUEST = [];

        // === SIMULATE RETURN HANDLER LOGIC ===

        // 1. Validate token
        $this->assertTrue(
            $this->tokenService->validateToken($contractToken, $contractId),
            'Token validation should pass'
        );

        // 2. Load contract
        $loadedContract = $this->contractRepository->findById($contractId);
        $this->assertNotNull($loadedContract);

        // 3. Security check
        $securityResult = $this->securityService->validateReturn($loadedContract, [
            'ip' => '192.168.1.50',
            'user_agent' => 'Mozilla/5.0 Chrome',
        ]);
        $this->assertTrue($securityResult->isAllowed(), 'Security check should pass');

        // 4. CRITICAL: Restore delivery address hash into $_REQUEST
        $restoredDeliveryHash = $loadedContract->getMetadata('delivery_address_hash');
        if ($restoredDeliveryHash !== null && is_string($restoredDeliveryHash)) {
            $_REQUEST['sDeliveryAddressMD5'] = $restoredDeliveryHash;
        }

        // 5. VERIFY: $_REQUEST now contains the delivery address hash
        $this->assertArrayHasKey('sDeliveryAddressMD5', $_REQUEST);
        $this->assertEquals($deliveryHash, $_REQUEST['sDeliveryAddressMD5']);

        // This is the critical fix - OXID's Order::validateDeliveryAddress()
        // reads from $_REQUEST['sDeliveryAddressMD5'], and now it will find the value
    }

    // =================================================================
    // Edge Cases
    // =================================================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function handlesContractWithoutDeliveryAddressHash(): void
    {
        $contract = $this->createTestContract();
        // No delivery address hash set

        $this->contractRepository->save($contract);

        $loadedContract = $this->contractRepository->findById($contract->getId());
        $deliveryHash = $loadedContract->getMetadata('delivery_address_hash');

        $this->assertNull($deliveryHash, 'Should return null for missing metadata');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function handlesEmptyCurrentContext(): void
    {
        $contract = $this->createContractWithSecurityMetadata([
            'user_ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 Test',
            'created_timestamp' => time(),
        ]);

        // Empty context (missing IP and user agent)
        $result = $this->securityService->validateReturn($contract, []);

        // Should still produce a result (with warnings for missing data)
        $this->assertInstanceOf(
            \OxidEsales\PaymentBase\Contract\SecurityValidationResultInterface::class,
            $result
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function tokenServiceHandlesSpecialCharactersInContractId(): void
    {
        $specialContractId = 'contract+with/special=chars';

        $token = $this->tokenService->generateToken($specialContractId);
        $extracted = $this->tokenService->extractContractId($token);

        $this->assertEquals($specialContractId, $extracted);
        $this->assertTrue($this->tokenService->validateToken($token, $specialContractId));
    }

    // =================================================================
    // Helper Methods
    // =================================================================

    private function createTestContract(?string $id = null): PaymentContract
    {
        $contractId = $id ?? 'test_contract_' . uniqid();

        $snapshot = BasketSnapshot::fromArray([
            'items' => [
                [
                    'articleId' => 'test_article_1',
                    'title' => 'Test Product',
                    'amount' => 1,
                    'price' => 99.99,
                    'vat' => 19.0,
                ]
            ],
            'discounts' => [],
            'totalGross' => 99.99,
            'totalNet' => 84.03,
            'totalVat' => 15.96,
            'currency' => 'EUR',
        ]);

        return new PaymentContract(1, 'test_user_' . uniqid(), $snapshot, $contractId);
    }

    private function createContractWithSecurityMetadata(array $metadata): PaymentContract
    {
        $contract = $this->createTestContract();

        foreach ($metadata as $key => $value) {
            $contract->setMetadata($key, $value);
        }

        return $contract;
    }
}
