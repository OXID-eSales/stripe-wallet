<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\PhpStan;

use OxidEsales\Payments\Stripe\Admin\StripeContractStateHelp;
use OxidEsales\Payments\Stripe\Adapter\StripeClientFactory;
use OxidEsales\Payments\Stripe\Core\Events;
use OxidEsales\Payments\Stripe\Core\ViewConfig;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeContractCreationHandler;
use OxidEsales\Payments\Stripe\GraphQL\Controller\StripeCheckout;
use OxidEsales\Payments\Stripe\Service\CaptureService;
use OxidEsales\Payments\Stripe\Tests\PhpStan\DeadCode\OxidEntryPointUsageProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The dead-code gate (sprint 17 DC-5) cannot see callers that live in a framework: the GraphQL schema builder, the
 * shop's metadata.php, the template engine, the service container and payment-base's duck typing. This provider names
 * them; what metadata.php and services.yaml say is read from those files.
 */
final class OxidEntryPointUsageProviderTest extends TestCase
{
    private OxidEntryPointUsageProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new OxidEntryPointUsageProvider();
    }

    public function testGraphQliteMutationIsAnEntryPoint(): void
    {
        $this->assertTrue($this->provider->isEntryPoint(new ReflectionMethod(StripeCheckout::class, 'stripeCheckoutCancel')));
    }

    public function testMetadataEventHandlerIsAnEntryPoint(): void
    {
        $this->assertTrue($this->provider->isEntryPoint(new ReflectionMethod(Events::class, 'onActivate')));
    }

    public function testPublicMethodOfAShopClassExtensionIsAnEntryPoint(): void
    {
        $method = (new \ReflectionClass(ViewConfig::class))->getMethods(ReflectionMethod::IS_PUBLIC)[0];

        $this->assertTrue($this->provider->isEntryPoint($method));
    }

    public function testServicesYamlFactoryMethodIsAnEntryPoint(): void
    {
        $this->assertTrue($this->provider->isEntryPoint(new ReflectionMethod(StripeClientFactory::class, 'create')));
    }

    public function testTheConstructorOfAServiceIsAnEntryPoint(): void
    {
        $this->assertTrue($this->provider->isEntryPoint(new ReflectionMethod(CaptureService::class, '__construct')));
    }

    public function testAMethodPaymentBaseCallsByDuckTypingIsAnEntryPoint(): void
    {
        $this->assertTrue($this->provider->isEntryPoint(new ReflectionMethod(StripeContractCreationHandler::class, 'getPriority')));
    }

    public function testAMethodATwigTemplateCallsIsAnEntryPoint(): void
    {
        $this->assertTrue($this->provider->isEntryPoint(new ReflectionMethod(StripeContractStateHelp::class, 'introIdent')));
    }

    public function testPlainServiceMethodIsNotAnEntryPoint(): void
    {
        $this->assertFalse($this->provider->isEntryPoint(new ReflectionMethod(CaptureService::class, 'processCapture')));
    }

    public function testPrivateMethodOfAGraphQliteClassIsNotAnEntryPoint(): void
    {
        $this->assertFalse($this->provider->isEntryPoint(new ReflectionMethod(OxidEntryPointUsageProvider::class, 'hasGraphQliteAttribute')));
    }
}
