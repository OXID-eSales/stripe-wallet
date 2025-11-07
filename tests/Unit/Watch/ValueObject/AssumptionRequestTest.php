<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\ValueObject;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 * @group watch
 * @group value-object
 */
class AssumptionRequestTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_valid_assumption_request(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE',
            expectedValue: 'committed'
        );

        $this->assertInstanceOf(AssumptionRequest::class, $request);
        $this->assertEquals('osc_payment_contract', $request->getTableName());
        $this->assertEquals('OXSTATE', $request->getFieldName());
        $this->assertEquals('committed', $request->getExpectedValue());
    }

    /**
     * @test
     */
    public function it_builds_field_path(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE',
            expectedValue: 'committed'
        );

        $this->assertEquals('osc_payment_contract.OXSTATE', $request->getFieldPath());
    }

    /**
     * @test
     */
    public function it_uses_default_operator(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE',
            expectedValue: 'committed'
        );

        $this->assertEquals('==', $request->getOperator());
    }

    /**
     * @test
     */
    public function it_accepts_custom_operator(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXAMOUNT',
            expectedValue: 100,
            operator: '>='
        );

        $this->assertEquals('>=', $request->getOperator());
    }

    /**
     * @test
     */
    public function it_accepts_where_clause(): void
    {
        $whereClause = [
            'OXID' => 'contract-123',
            'OXUSERID' => 'user-456'
        ];

        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE',
            expectedValue: 'committed',
            whereClause: $whereClause
        );

        $this->assertEquals($whereClause, $request->getWhereClause());
    }

    /**
     * @test
     */
    public function it_is_immutable(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE',
            expectedValue: 'committed'
        );

        // Properties should be readonly - this is enforced by PHP's readonly keyword
        $reflection = new \ReflectionClass($request);
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
    public function it_rejects_empty_table_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name cannot be empty');

        new AssumptionRequest(
            tableName: '',
            fieldName: 'OXSTATE',
            expectedValue: 'committed'
        );
    }

    /**
     * @test
     */
    public function it_rejects_empty_field_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field name cannot be empty');

        new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: '',
            expectedValue: 'committed'
        );
    }

    /**
     * @test
     */
    public function it_rejects_invalid_table_name_with_special_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid table name format');

        new AssumptionRequest(
            tableName: 'osc_payment; DROP TABLE',
            fieldName: 'OXSTATE',
            expectedValue: 'committed'
        );
    }

    /**
     * @test
     */
    public function it_rejects_invalid_field_name_with_special_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid field name format');

        new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE; DROP',
            expectedValue: 'committed'
        );
    }

    /**
     * @test
     */
    public function it_accepts_null_as_expected_value(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE',
            expectedValue: null
        );

        $this->assertNull($request->getExpectedValue());
    }

    /**
     * @test
     */
    public function it_validates_operator_whitelist(): void
    {
        $validOperators = ['==', '!=', '>', '<', '>=', '<=', '%like%', 'like%', '%like', 'IS NULL', 'IS NOT NULL'];

        foreach ($validOperators as $operator) {
            $request = new AssumptionRequest(
                tableName: 'osc_payment_contract',
                fieldName: 'OXSTATE',
                expectedValue: 'committed',
                operator: $operator
            );

            $this->assertEquals($operator, $request->getOperator());
        }
    }

    /**
     * @test
     */
    public function it_rejects_invalid_operator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid operator');

        new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE',
            expectedValue: 'committed',
            operator: 'INVALID'
        );
    }
}
