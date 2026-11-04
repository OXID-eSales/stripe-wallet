<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Logging;

use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\Payments\Stripe\Logging\StripeThrowableSummaryExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stripe\Exception\InvalidRequestException;

#[\PHPUnit\Framework\Attributes\CoversClass(StripeThrowableSummaryExtractor::class)]
final class StripeThrowableSummaryExtractorTest extends TestCase
{
    public function testItSupportsAStripeApiError(): void
    {
        $this->assertTrue((new StripeThrowableSummaryExtractor())->supports($this->apiError()));
    }

    public function testItSupportsAThrowableWrappingAStripeApiError(): void
    {
        $wrapped = new RuntimeException('converted', 0, $this->apiError());

        $this->assertTrue((new StripeThrowableSummaryExtractor())->supports($wrapped));
    }

    public function testItDoesNotSupportAnythingElse(): void
    {
        $this->assertFalse((new StripeThrowableSummaryExtractor())->supports(new RuntimeException('x')));
    }

    public function testItSummarisesTypeCodeAndRequestIdAndNeverTheMessage(): void
    {
        $summary = (new StripeThrowableSummaryExtractor())->summarize($this->apiError());

        $this->assertSame('invalid_request_error', $summary['stripeType']);
        $this->assertSame('resource_missing', $summary['stripeCode']);
        $this->assertSame('req_123', $summary['requestId']);
        $this->assertSame(404, $summary['httpStatus']);
        $this->assertStringNotContainsString('buyer@example.com', (string) json_encode($summary));
    }

    public function testTheSharedSummaryCarriesTheStripeFields(): void
    {
        $summary = (new ThrowableSummary([new StripeThrowableSummaryExtractor()]))->fromThrowable($this->apiError());

        $this->assertSame('req_123', $summary['requestId']);
        $this->assertSame(InvalidRequestException::class, $summary['class']);
    }

    private function apiError(): InvalidRequestException
    {
        $error = InvalidRequestException::factory(
            'No such customer: buyer@example.com',
            404,
            '{}',
            ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing']],
            ['Request-Id' => 'req_123'],
            'resource_missing'
        );

        return $error;
    }
}
