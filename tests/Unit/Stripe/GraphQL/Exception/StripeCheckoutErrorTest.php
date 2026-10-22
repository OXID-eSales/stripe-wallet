<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Exception;

use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\Payments\Stripe\GraphQL\Exception\StripeCheckoutError;
use PHPUnit\Framework\TestCase;

/**
 * graphql-base logs every GraphQL error message into oxideshop.log, so the
 * message of a provider failure must be a fixed sentence: Stripe's own text
 * (session ids, URLs) goes to the handler's debug log instead.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(StripeCheckoutError::class)]
final class StripeCheckoutErrorTest extends TestCase
{
    public function testMessage_ProviderFailure_ContainsNoProviderText(): void
    {
        $error = new StripeCheckoutError(new HeadlessCheckoutException(
            HeadlessCheckoutException::PROVIDER_FAILED,
            'Stripe Payment could not start the checkout: Invalid URL https://app.example.com/r?uid=u1 for cs_live_a1b2',
            'STRIPE_SESSION_FAILED'
        ));

        $this->assertStringNotContainsString('cs_', $error->getMessage());
        $this->assertStringNotContainsString('https://', $error->getMessage());
        $this->assertStringNotContainsString('uid', $error->getMessage());
        $this->assertSame('provider_failed', $error->getExtensions()['errorCode']);
        $this->assertSame('STRIPE_SESSION_FAILED', $error->getExtensions()['providerCode']);
        $this->assertTrue($error->isClientSafe());
    }

    public function testMessage_ProviderFailure_IsTheSameFixedSentenceForEveryProviderCode(): void
    {
        $first = new StripeCheckoutError(new HeadlessCheckoutException('provider_failed', 'text one', 'STRIPE_SESSION_FAILED'));
        $second = new StripeCheckoutError(new HeadlessCheckoutException('provider_failed', 'text two', 'STRIPE_PAYMENT_FAILED'));

        $this->assertSame($first->getMessage(), $second->getMessage());
    }

    public function testMessage_OtherRefusal_KeepsTheRefusalMessage(): void
    {
        $error = new StripeCheckoutError(new HeadlessCheckoutException(
            HeadlessCheckoutException::TERMS_NOT_CONFIRMED,
            'The shop requires consent'
        ));

        $this->assertSame('The shop requires consent', $error->getMessage());
        $this->assertSame('terms_not_confirmed', $error->getExtensions()['errorCode']);
    }
}
