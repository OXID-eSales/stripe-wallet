<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\ValueObject;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionResponse;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 * @group watch
 * @group value-object
 */
class AssumptionResponseTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_successful_response(): void
    {
        $response = new AssumptionResponse(
            isMatch: true,
            queryTimeMs: 12.5,
            matchedRows: 1
        );

        $this->assertTrue($response->isMatch());
        $this->assertEquals(12.5, $response->getQueryTimeMs());
        $this->assertEquals(1, $response->getMatchedRows());
    }

    /**
     * @test
     */
    public function it_creates_failed_response_with_actual_value(): void
    {
        $response = new AssumptionResponse(
            isMatch: false,
            queryTimeMs: 10.3,
            matchedRows: 1,
            actualValue: 'pending',
            expectedValue: 'committed'
        );

        $this->assertFalse($response->isMatch());
        $this->assertEquals('pending', $response->getActualValue());
        $this->assertEquals('committed', $response->getExpectedValue());
    }

    /**
     * @test
     */
    public function it_includes_query_time_ms(): void
    {
        $response = new AssumptionResponse(
            isMatch: true,
            queryTimeMs: 15.7,
            matchedRows: 1
        );

        $this->assertEquals(15.7, $response->getQueryTimeMs());
    }

    /**
     * @test
     */
    public function it_includes_matched_rows_count(): void
    {
        $response = new AssumptionResponse(
            isMatch: false,
            queryTimeMs: 8.2,
            matchedRows: 0
        );

        $this->assertEquals(0, $response->getMatchedRows());
    }

    /**
     * @test
     */
    public function it_handles_zero_matched_rows(): void
    {
        $response = new AssumptionResponse(
            isMatch: false,
            queryTimeMs: 5.0,
            matchedRows: 0
        );

        $this->assertEquals(0, $response->getMatchedRows());
        $this->assertFalse($response->isMatch());
    }

    /**
     * @test
     */
    public function it_converts_to_array(): void
    {
        $response = new AssumptionResponse(
            isMatch: true,
            queryTimeMs: 12.5,
            matchedRows: 1,
            actualValue: 'committed',
            expectedValue: 'committed'
        );

        $array = $response->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('assumption', $array);
        $this->assertArrayHasKey('query_time_ms', $array);
        $this->assertArrayHasKey('matched_rows', $array);
        $this->assertArrayHasKey('actual_value', $array);
        $this->assertArrayHasKey('expected_value', $array);

        $this->assertTrue($array['assumption']);
        $this->assertEquals(12.5, $array['query_time_ms']);
        $this->assertEquals(1, $array['matched_rows']);
    }

    /**
     * @test
     */
    public function it_converts_to_json(): void
    {
        $response = new AssumptionResponse(
            isMatch: false,
            queryTimeMs: 10.0,
            matchedRows: 1,
            actualValue: 'pending',
            expectedValue: 'committed'
        );

        $json = $response->toJson();

        $this->assertIsString($json);
        $decoded = json_decode($json, true);

        $this->assertFalse($decoded['assumption']);
        $this->assertEquals('pending', $decoded['actual_value']);
        $this->assertEquals('committed', $decoded['expected_value']);
    }

    /**
     * @test
     */
    public function it_is_immutable(): void
    {
        $response = new AssumptionResponse(
            isMatch: true,
            queryTimeMs: 12.5,
            matchedRows: 1
        );

        $reflection = new \ReflectionClass($response);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property {$property->getName()} should be readonly"
            );
        }
    }

    /**
     * @test
     */
    public function it_rejects_negative_query_time(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Query time cannot be negative');

        new AssumptionResponse(
            isMatch: true,
            queryTimeMs: -5.0,
            matchedRows: 1
        );
    }

    /**
     * @test
     */
    public function it_rejects_negative_matched_rows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Matched rows cannot be negative');

        new AssumptionResponse(
            isMatch: true,
            queryTimeMs: 10.0,
            matchedRows: -1
        );
    }

    /**
     * @test
     */
    public function it_accepts_null_values(): void
    {
        $response = new AssumptionResponse(
            isMatch: true,
            queryTimeMs: 10.0,
            matchedRows: 1,
            actualValue: null,
            expectedValue: null
        );

        $this->assertNull($response->getActualValue());
        $this->assertNull($response->getExpectedValue());
    }

    /**
     * @test
     */
    public function it_excludes_null_values_from_array(): void
    {
        $response = new AssumptionResponse(
            isMatch: true,
            queryTimeMs: 10.0,
            matchedRows: 1
        );

        $array = $response->toArray();

        // When actualValue and expectedValue are null, they should not be in array
        $this->assertArrayNotHasKey('actual_value', $array);
        $this->assertArrayNotHasKey('expected_value', $array);
    }
}
