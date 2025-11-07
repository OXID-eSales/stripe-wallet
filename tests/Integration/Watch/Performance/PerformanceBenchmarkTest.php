<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Watch\Performance;

use OxidSolutionCatalysts\Payments\Tests\Integration\Watch\PaymentWatchIntegrationTestCase;

/**
 * Performance benchmark tests
 *
 * Tests response times, throughput, and scalability.
 *
 * @group integration
 * @group watch
 * @group performance
 */
class PerformanceBenchmarkTest extends PaymentWatchIntegrationTestCase
{
    /**
     * @test
     */
    public function it_responds_within_50ms_on_average(): void
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

        // Act: Run 100 requests
        $times = [];
        for ($i = 0; $i < 100; $i++) {
            $response = $this->makeAssumptionRequest($payload);
            $times[] = $response['time'];
        }

        // Calculate statistics
        $avgTime = array_sum($times) / count($times);
        $maxTime = max($times);
        $minTime = min($times);
        sort($times);
        $p95Time = $times[(int)(count($times) * 0.95)];
        $p99Time = $times[(int)(count($times) * 0.99)];

        // Assert
        $this->assertLessThan(50, $avgTime, "Average response time should be < 50ms, got: {$avgTime}ms");
        $this->assertLessThan(100, $p95Time, "P95 response time should be < 100ms, got: {$p95Time}ms");
        $this->assertLessThan(200, $p99Time, "P99 response time should be < 200ms, got: {$p99Time}ms");

        // Output statistics
        echo "\nPerformance Statistics:\n";
        echo "  Average: " . round($avgTime, 2) . "ms\n";
        echo "  Min: " . round($minTime, 2) . "ms\n";
        echo "  Max: " . round($maxTime, 2) . "ms\n";
        echo "  P95: " . round($p95Time, 2) . "ms\n";
        echo "  P99: " . round($p99Time, 2) . "ms\n";
    }

    /**
     * @test
     */
    public function it_handles_concurrent_requests_efficiently(): void
    {
        // Arrange: Create multiple test contracts
        $contracts = [];
        for ($i = 0; $i < 10; $i++) {
            $contracts[] = $this->createTestContract([
                'OXSTATE' => 'pending',
            ]);
        }

        // Act: Simulate concurrent requests (sequential in PHP, but fast)
        $startTime = microtime(true);

        foreach ($contracts as $contractId) {
            $payload = [
                'assumption' => [
                    'osc_payment_contract.OXSTATE' => 'pending',
                    'where' => [
                        'OXID' => $contractId,
                    ],
                ],
            ];

            $response = $this->makeAssumptionRequest($payload);
            $this->assertResponseSuccess($response);
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $avgTimePerRequest = $totalTime / count($contracts);

        // Assert: Should handle all requests efficiently
        $this->assertLessThan(
            1000,
            $totalTime,
            "10 concurrent requests should complete in < 1 second, got: {$totalTime}ms"
        );

        echo "\nConcurrency Test:\n";
        echo "  Total time for 10 requests: " . round($totalTime, 2) . "ms\n";
        echo "  Average per request: " . round($avgTimePerRequest, 2) . "ms\n";
    }

    /**
     * @test
     */
    public function it_performs_well_with_complex_where_clauses(): void
    {
        // Arrange: Create contract with multiple fields
        $userId = 'test_user_' . uniqid();
        $providerId = 'stripe';
        $contractId = $this->createTestContract([
            'OXSTATE' => 'pending',
            'OXUSERID' => $userId,
            'OXPROVIDERID' => $providerId,
        ]);

        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'pending',
                'where' => [
                    'OXID' => $contractId,
                    'OXUSERID' => $userId,
                    'OXPROVIDERID' => $providerId,
                ],
            ],
        ];

        // Act: Run multiple times
        $times = [];
        for ($i = 0; $i < 50; $i++) {
            $response = $this->makeAssumptionRequest($payload);
            $times[] = $response['time'];
            $this->assertResponseSuccess($response);
        }

        $avgTime = array_sum($times) / count($times);

        // Assert: Complex WHERE clauses should still be fast
        $this->assertLessThan(
            75,
            $avgTime,
            "Complex WHERE clause queries should be < 75ms, got: {$avgTime}ms"
        );

        echo "\nComplex WHERE Clause Performance:\n";
        echo "  Average time: " . round($avgTime, 2) . "ms\n";
    }

    /**
     * @test
     */
    public function it_performs_well_with_like_operators(): void
    {
        // Arrange
        $contractId = $this->createTestContract([
            'OXPROVIDERORDERID' => 'pi_test_very_long_identifier_12345678',
        ]);

        $payload = [
            'assumption' => [
                'osc_payment_contract.OXPROVIDERORDERID' => 'test',
                'operator' => '%like%',
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        // Act
        $times = [];
        for ($i = 0; $i < 50; $i++) {
            $response = $this->makeAssumptionRequest($payload);
            $times[] = $response['time'];
        }

        $avgTime = array_sum($times) / count($times);

        // Assert: LIKE queries should be reasonably fast
        $this->assertLessThan(
            100,
            $avgTime,
            "LIKE queries should be < 100ms, got: {$avgTime}ms"
        );

        echo "\nLIKE Operator Performance:\n";
        echo "  Average time: " . round($avgTime, 2) . "ms\n";
    }

    /**
     * @test
     */
    public function it_has_minimal_memory_footprint(): void
    {
        // Measure memory before
        $memoryBefore = memory_get_usage();

        // Execute 100 requests
        $contractId = $this->createTestContract();

        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'pending',
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        for ($i = 0; $i < 100; $i++) {
            $this->makeAssumptionRequest($payload);
        }

        // Measure memory after
        $memoryAfter = memory_get_usage();
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // Convert to MB

        // Assert: Should not have significant memory leaks
        $this->assertLessThan(
            10,
            $memoryUsed,
            "100 requests should use < 10MB memory, got: {$memoryUsed}MB"
        );

        echo "\nMemory Usage:\n";
        echo "  Memory used for 100 requests: " . round($memoryUsed, 2) . "MB\n";
    }

    /**
     * @test
     */
    public function it_scales_linearly_with_data_volume(): void
    {
        // Test query performance doesn't degrade significantly with more test data

        // Baseline: Query with 1 contract
        $contract1 = $this->createTestContract();
        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'pending',
                'where' => [
                    'OXID' => $contract1,
                ],
            ],
        ];

        $response1 = $this->makeAssumptionRequest($payload);
        $time1 = $response1['time'];

        // Create 99 more contracts (total 100)
        for ($i = 0; $i < 99; $i++) {
            $this->createTestContract([
                'OXSTATE' => 'pending',
            ]);
        }

        // Query same contract again
        $response2 = $this->makeAssumptionRequest($payload);
        $time2 = $response2['time'];

        // Time should not increase significantly (indexed queries)
        $increase = (($time2 - $time1) / $time1) * 100;

        $this->assertLessThan(
            50,
            $increase,
            "Query time should not increase > 50% with more data, got: {$increase}% increase"
        );

        echo "\nScalability Test:\n";
        echo "  Time with 1 record: " . round($time1, 2) . "ms\n";
        echo "  Time with 100 records: " . round($time2, 2) . "ms\n";
        echo "  Increase: " . round($increase, 2) . "%\n";
    }

    /**
     * @test
     */
    public function it_measures_database_query_overhead(): void
    {
        $contractId = $this->createTestContract();

        $payload = [
            'assumption' => [
                'osc_payment_contract.OXSTATE' => 'pending',
                'where' => [
                    'OXID' => $contractId,
                ],
            ],
        ];

        // Make request and check query_time_ms from response
        $response = $this->makeAssumptionRequest($payload);

        $this->assertResponseSuccess($response);

        $dbQueryTime = $response['body']['query_time_ms'];
        $totalTime = $response['time'];

        // Calculate overhead (authentication, parsing, etc.)
        $overhead = $totalTime - $dbQueryTime;
        $overheadPercent = ($overhead / $totalTime) * 100;

        // Assert: Database query should be the major component
        $this->assertLessThan(
            50,
            $overheadPercent,
            "Non-database overhead should be < 50% of total time, got: {$overheadPercent}%"
        );

        echo "\nQuery Overhead Analysis:\n";
        echo "  Database query time: " . round($dbQueryTime, 2) . "ms\n";
        echo "  Total request time: " . round($totalTime, 2) . "ms\n";
        echo "  Overhead: " . round($overhead, 2) . "ms (" . round($overheadPercent, 2) . "%)\n";
    }
}
