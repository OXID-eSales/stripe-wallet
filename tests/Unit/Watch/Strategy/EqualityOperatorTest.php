<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Strategy;

use OxidSolutionCatalysts\Payments\Watch\Strategy\EqualityOperator;
use OxidSolutionCatalysts\Payments\Watch\Strategy\OperatorStrategyInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 * @group watch
 * @group strategy
 */
class EqualityOperatorTest extends TestCase
{
    /**
     * @test
     */
    public function it_implements_operator_strategy_interface(): void
    {
        $operator = new EqualityOperator('==');

        $this->assertInstanceOf(OperatorStrategyInterface::class, $operator);
    }

    /**
     * @test
     */
    public function it_compares_equal_values(): void
    {
        $operator = new EqualityOperator('==');

        $this->assertTrue($operator->compare('committed', 'committed'));
        $this->assertTrue($operator->compare(100, 100));
        $this->assertTrue($operator->compare(true, true));
    }

    /**
     * @test
     */
    public function it_compares_not_equal_values(): void
    {
        $operator = new EqualityOperator('!=');

        $this->assertTrue($operator->compare('pending', 'committed'));
        $this->assertTrue($operator->compare(100, 200));
        $this->assertTrue($operator->compare(true, false));
    }

    /**
     * @test
     */
    public function it_returns_false_for_non_equal_values_with_equality_operator(): void
    {
        $operator = new EqualityOperator('==');

        $this->assertFalse($operator->compare('pending', 'committed'));
        $this->assertFalse($operator->compare(100, 200));
    }

    /**
     * @test
     */
    public function it_returns_false_for_equal_values_with_not_equal_operator(): void
    {
        $operator = new EqualityOperator('!=');

        $this->assertFalse($operator->compare('committed', 'committed'));
        $this->assertFalse($operator->compare(100, 100));
    }

    /**
     * @test
     */
    public function it_handles_type_coercion(): void
    {
        $operator = new EqualityOperator('==');

        // Loose comparison (==) allows type coercion
        $this->assertTrue($operator->compare('100', 100));
        $this->assertTrue($operator->compare(1, true));
        $this->assertTrue($operator->compare(0, false));
    }

    /**
     * @test
     */
    public function it_handles_null_values(): void
    {
        $operator = new EqualityOperator('==');

        $this->assertTrue($operator->compare(null, null));
        $this->assertFalse($operator->compare(null, 'value'));
        $this->assertFalse($operator->compare('value', null));
    }

    /**
     * @test
     */
    public function it_handles_empty_strings(): void
    {
        $operator = new EqualityOperator('==');

        $this->assertTrue($operator->compare('', ''));
        $this->assertFalse($operator->compare('', 'value'));

        // PHP 8.2: Empty string and zero are NOT equal (stricter type juggling)
        $this->assertFalse($operator->compare('', 0));
    }

    /**
     * @test
     */
    public function it_returns_supported_operators(): void
    {
        $operator = new EqualityOperator('==');

        $supported = $operator->getSupportedOperators();

        $this->assertIsArray($supported);
        $this->assertContains('==', $supported);
        $this->assertContains('!=', $supported);
        $this->assertCount(2, $supported);
    }

    /**
     * @test
     */
    public function it_returns_current_operator(): void
    {
        $operator = new EqualityOperator('==');
        $this->assertEquals('==', $operator->getOperator());

        $operator2 = new EqualityOperator('!=');
        $this->assertEquals('!=', $operator2->getOperator());
    }

    /**
     * @test
     */
    public function it_throws_exception_for_invalid_operator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Operator ">" is not supported');

        new EqualityOperator('>');
    }

    /**
     * @test
     */
    public function it_handles_arrays(): void
    {
        $operator = new EqualityOperator('==');

        $this->assertTrue($operator->compare(['a', 'b'], ['a', 'b']));
        $this->assertFalse($operator->compare(['a', 'b'], ['b', 'a']));
    }

    /**
     * @test
     */
    public function it_handles_objects(): void
    {
        $operator = new EqualityOperator('==');

        $obj1 = new \stdClass();
        $obj1->value = 'test';

        $obj2 = new \stdClass();
        $obj2->value = 'test';

        // Objects with same properties are equal with ==
        $this->assertTrue($operator->compare($obj1, $obj2));
    }
}
