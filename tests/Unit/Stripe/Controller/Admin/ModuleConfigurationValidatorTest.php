<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Payments\Stripe\Controller\Admin\ModuleConfiguration;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrarInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * S5 (sprint-114.11a): ModuleConfiguration::stripeGetKeyValidationError() must
 * delegate to ConfigurationValidatorInterface through a protected getter seam —
 * not call ContainerFactory::getInstance() ad-hoc on every invocation (R-4.2).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\Admin\ModuleConfiguration::class)]
final class ModuleConfigurationValidatorTest extends TestCase
{
    private ConfigurationValidatorInterface&MockObject $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = $this->createMock(ConfigurationValidatorInterface::class);
    }

    public function testStripeGetKeyValidationErrorDelegatesToValidator(): void
    {
        $this->validator
            ->expects($this->once())
            ->method('getKeyValidationError')
            ->willReturn('Key mismatch error');

        $controller = $this->createController($this->validator);

        $result = $controller->stripeGetKeyValidationError();

        $this->assertSame('Key mismatch error', $result);
    }

    public function testStripeGetKeyValidationErrorReturnsNullWhenNoError(): void
    {
        $this->validator->method('getKeyValidationError')->willReturn(null);

        $controller = $this->createController($this->validator);

        $this->assertNull($controller->stripeGetKeyValidationError());
    }

    /**
     * Build a testable subclass that bypasses OXID bootstrap and injects
     * the ConfigurationValidator via the protected getter seam.
     */
    private function createController(ConfigurationValidatorInterface $validator): ModuleConfiguration
    {
        return new class (
            $this->createMock(WebhookEndpointRegistrarInterface::class),
            $this->createMock(ModuleConfigurationServiceInterface::class),
            new NullLogger(),
            $validator,
        ) extends ModuleConfiguration {
            public function __construct(
                WebhookEndpointRegistrarInterface $registrar,
                ModuleConfigurationServiceInterface $moduleConfig,
                \Psr\Log\LoggerInterface $logger,
                private readonly ConfigurationValidatorInterface $stubValidator
            ) {
                // Skip OXID admin bootstrap.
                $this->initializeWebhookCollaborators($registrar, $moduleConfig, $logger);
            }

            protected function getConfigurationValidator(): ConfigurationValidatorInterface
            {
                return $this->stubValidator;
            }
        };
    }
}
