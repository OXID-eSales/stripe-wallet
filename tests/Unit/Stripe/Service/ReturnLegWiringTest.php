<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Checkout\Return\ContractReturnTokenServiceInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeCheckoutSessionHandler;
use OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler;
use OxidEsales\Payments\Stripe\Service\LegacyReturnTokenValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * VR-20 R5: return URLs carry payment-base's shared token, and Stripe's own token service is only the legacy
 * validator the guard may ask - no longer behind the shared TokenServiceInterface (single-valued in the merged
 * container, so it was another provider's when that module was active).
 */
final class ReturnLegWiringTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $services;

    protected function setUp(): void
    {
        $this->services = Yaml::parseFile(__DIR__ . '/../../../../services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function tokenIssuers(): iterable
    {
        yield 'Checkout Session handler (Twig)' => [StripeCheckoutSessionHandler::class];
        yield 'payment handler (OPC)' => [StripePaymentHandler::class];
    }

    /**
     * @param class-string $issuer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tokenIssuers')]
    public function testReturnUrlsCarryPaymentBasesToken(string $issuer): void
    {
        self::assertSame('@' . ContractReturnTokenServiceInterface::class, $this->services[$issuer]['arguments']['$returnTokens'] ?? null);
    }

    public function testTheOldTokenServiceIsOnlyTheLegacyValidatorAndNoLongerTheSharedInterface(): void
    {
        self::assertArrayHasKey(LegacyReturnTokenValidator::class, $this->services);
        self::assertArrayNotHasKey(TokenServiceInterface::class, $this->services);
    }
}
