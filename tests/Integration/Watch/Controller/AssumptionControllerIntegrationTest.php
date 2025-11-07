<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Watch\Controller;

use OxidSolutionCatalysts\Payments\Tests\Integration\Watch\PaymentWatchIntegrationTestCase;

/**
 * Integration tests for AssumptionController
 *
 * Tests the complete HTTP request/response cycle with real database.
 *
 * @group integration
 * @group watch
 * @group controller
 */
class AssumptionControllerIntegrationTest extends PaymentWatchIntegrationTestCase
{
    /**
     * @test
     */
    public function it_returns_successful_response_for_valid_request(): void
    {
        // Arrange: Create test data
        $contractId = $this->createTestContract([
            'OXSTATE' => 'committed',
        ]);

        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'committed',
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        // Act: Make request
        $response = $this->makeAssumptionRequest($payload);

        // Assert: Verify response
        $this->assertResponseSuccess($response);
        $this->assertTrue($response['body']['assumption']);
        $this->assertEquals(1, $response['body']['matched_rows']);
        $this->assertLessThan(100, $response['time'], 'Response time should be < 100ms');
    }

    /**
     * @test
     */
    public function it_returns_false_when_value_does_not_match(): void
    {
        // Arrange
        $contractId = $this->createTestContract([
            'OXSTATE' => 'pending',
        ]);

        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'committed',
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        // Act
        $response = $this->makeAssumptionRequest($payload);

        // Assert
        $this->assertResponseSuccess($response);
        $this->assertFalse($response['body']['assumption']);
        $this->assertEquals('pending', $response['body']['actual_value']);
        $this->assertEquals('committed', $response['body']['expected_value']);
    }

    /**
     * @test
     */
    public function it_supports_all_comparison_operators(): void
    {
        // Arrange
        $contractId = $this->createTestContract([
            'OXAMOUNT' => 100.00,
        ]);

        $testCases = [
            ['operator' => '==', 'value' => 100.00, 'expected' => true],
            ['operator' => '!=', 'value' => 200.00, 'expected' => true],
            ['operator' => '>', 'value' => 50.00, 'expected' => true],
            ['operator' => '<', 'value' => 150.00, 'expected' => true],
            ['operator' => '>=', 'value' => 100.00, 'expected' => true],
            ['operator' => '<=', 'value' => 100.00, 'expected' => true],
        ];

        foreach ($testCases as $testCase) {
            $payload = [
                'assumption' => [
                    'osc_payment_contract.OXAMOUNT' => $testCase['value'],
                    'operator' => $testCase['operator'],
                    'where' => [
                        'OXID' => $contractId,
                    ],
                ],
            ];

            $response = $this->makeAssumptionRequest($payload);

            $this->assertResponseSuccess($response);
            $this->assertEquals(
                $testCase['expected'],
                $response['body']['assumption'],
                "Operator {$testCase['operator']} failed"
            );
        }
    }

    /**
     * @test
     */
    public function it_supports_like_operators(): void
    {
        // Arrange
        $contractId = $this->createTestContract([
            'OXPROVIDERORDERID' => 'pi_test_123456',
        ]);

        $testCases = [
            ['operator' => '%like%', 'value' => 'test', 'expected' => true],
            ['operator' => 'like%', 'value' => 'pi_test', 'expected' => true],
            ['operator' => '%like', 'value' => '123456', 'expected' => true],
        ];

        foreach ($testCases as $testCase) {
            $payload = [
                'assumption' => [
                    'osc_payment_contract.OXPROVIDERORDERID' => $testCase['value'],
                    'operator' => $testCase['operator'],
                    'where' => [
                        'OXID' => $contractId,
                    ],
                ],
            ];

            $response = $this->makeAssumptionRequest($payload);

            $this->assertResponseSuccess($response);
            $this->assertEquals(
                $testCase['expected'],
                $response['body']['assumption'],
                "LIKE operator {$testCase['operator']} failed"
            );
        }
    }

    /**
     * @test
     */
    public function it_supports_null_check_operators(): void
    {
        // Arrange: Contract with null OXORDERID
        $contractId = $this->createTestContract([
            'OXORDERID' => null,
        ]);

        // Test IS NULL
        $payload = [
            'assumption' => [
                'osc_payment_contract.OXORDERID' => null,
                'operator' => 'IS NULL',
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        $response = $this->makeAssumptionRequest($payload);

        $this->assertResponseSuccess($response);
        $this->assertTrue($response['body']['assumption']);

        // Test IS NOT NULL with a field that has a value
        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => null,
                'operator' => 'IS NOT NULL',
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        $response = $this->makeAssumptionRequest($payload);

        $this->assertResponseSuccess($response);
        $this->assertTrue($response['body']['assumption']);
    }

    /**
     * @test
     */
    public function it_returns_401_for_missing_api_key(): void
    {
        // Arrange
        $contractId = $this->createTestContract();

        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'pending',
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        // Act: Request without API key
        $ch = curl_init($this->baseUrl . '/paymentwatch/assume');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                // No X-API-Key header
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Assert
        $this->assertEquals(401, $httpCode);
    }

    /**
     * @test
     */
    public function it_returns_400_for_invalid_json(): void
    {
        // Act: Send invalid JSON
        $ch = curl_init($this->baseUrl . '/paymentwatch/assume');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => 'invalid json {{{',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Assert
        $this->assertEquals(400, $httpCode);
        $body = json_decode($response, true);
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * @test
     */
    public function it_returns_400_for_sql_injection_attempt(): void
    {
        // Arrange: Payload with SQL injection attempt
        $payload = [
            'assumption' => [
                "osc_payment_contract'; DROP TABLE users; --" => 'value',
            ],
        ];

        // Act
        $response = $this->makeAssumptionRequest($payload);

        // Assert: Should be rejected
        $this->assertResponseValidationError($response);
    }

    /**
     * @test
     */
    public function it_handles_multiple_where_conditions(): void
    {
        // Arrange
        $userId = 'test_user_' . uniqid();
        $contractId = $this->createTestContract([
            'OXSTATE' => 'committed',
            'OXUSERID' => $userId,
        ]);

        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'committed',
                'where' => [
                    'OXID' => $contractId,
                    'OXUSERID' => $userId,
                ],
            ],
        ];

        // Act
        $response = $this->makeAssumptionRequest($payload);

        // Assert
        $this->assertResponseSuccess($response);
        $this->assertTrue($response['body']['assumption']);
    }

    /**
     * @test
     */
    public function it_returns_false_when_row_not_found(): void
    {
        // Arrange: Non-existent contract ID
        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'committed',
                'where' => [
                    'OXID' => 'nonexistent_contract',
                ],
            ],
        ];

        // Act
        $response = $this->makeAssumptionRequest($payload);

        // Assert
        $this->assertResponseSuccess($response);
        $this->assertFalse($response['body']['assumption']);
        $this->assertEquals(0, $response['body']['matched_rows']);
    }

    /**
     * @test
     */
    public function it_includes_query_time_in_response(): void
    {
        // Arrange
        $contractId = $this->createTestContract();

        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'pending',
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        // Act
        $response = $this->makeAssumptionRequest($payload);

        // Assert
        $this->assertResponseSuccess($response);
        $this->assertArrayHasKey('query_time_ms', $response['body']);
        $this->assertIsFloat($response['body']['query_time_ms']);
        $this->assertGreaterThan(0, $response['body']['query_time_ms']);
        $this->assertLessThan(1000, $response['body']['query_time_ms'], 'Query should be < 1s');
    }

    /**
     * @test
     */
    public function it_includes_request_id_in_response_header(): void
    {
        // Arrange
        $contractId = $this->createTestContract();
        $requestId = 'custom_request_' . uniqid();

        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'pending',
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        // Act: Make request with custom request ID
        $ch = curl_init($this->baseUrl . '/paymentwatch/assume');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
                'X-Request-ID: ' . $requestId,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        // Assert: Response should include request ID in header
        $this->assertStringContainsString('X-Request-ID', $response);
    }
}
