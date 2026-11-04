<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\PaymentBase\Validation\User\FieldValidationFailure;
use OxidEsales\Payments\Stripe\Controller\CheckoutRefusalPayloads;
use OxidEsales\Payments\Stripe\Service\BuyabilityFailure;
use OxidEsales\Payments\Stripe\Service\LanguageTranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckoutRefusalPayloads::class)]
final class CheckoutRefusalPayloadsTest extends TestCase
{
    #[Test]
    public function aUserDataFailureIsAnErrorRowWithTheFormattedMessage(): void
    {
        $formatter = $this->createMock(MessageFormatterInterface::class);
        $formatter->method('format')->with('firstName', 'blocked_character', ':')->willReturn('No colons please');

        $payload = CheckoutRefusalPayloads::userData(
            [new FieldValidationFailure('firstName', 'billing', 'blocked_character', ':', 'oxfname')],
            $formatter
        );

        $this->assertSame(
            ['valid' => false, 'errors' => [[
                'field' => 'firstName',
                'code' => 'blocked_character',
                'char' => ':',
                'addressKind' => 'billing',
                'message' => 'No colons please',
            ]]],
            $payload
        );
    }

    #[Test]
    public function withoutAFormatterTheMessageIsNull(): void
    {
        $payload = CheckoutRefusalPayloads::userData(
            [new FieldValidationFailure('city', 'delivery', 'control_character', null, 'oxcity')],
            null
        );

        $this->assertNull($payload['errors'][0]['message']);
        $this->assertSame('delivery', $payload['errors'][0]['addressKind']);
    }

    #[Test]
    public function anUnbuyableItemIsAnArticleNotBuyableRowWithATranslatedReason(): void
    {
        $translator = $this->createMock(LanguageTranslatorInterface::class);
        $translator->method('translateString')->with('STRIPE_ARTICLE_GONE')->willReturn('Sold out');

        $payload = CheckoutRefusalPayloads::buyability(
            [new BuyabilityFailure('art-1', 'Blue Shirt', 'STRIPE_ARTICLE_GONE')],
            $translator
        );

        $this->assertSame(
            ['valid' => false, 'errors' => [[
                'code' => 'article_not_buyable',
                'articleId' => 'art-1',
                'productTitle' => 'Blue Shirt',
                'message' => 'Sold out',
            ]]],
            $payload
        );
    }
}
