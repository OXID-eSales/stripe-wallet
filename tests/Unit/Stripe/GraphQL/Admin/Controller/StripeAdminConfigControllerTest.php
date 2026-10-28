<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Controller;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ConflictError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ValidationError;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Controller\StripeAdminConfigController;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeConfigInput;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminRefusal;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminSettingsInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigChange;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigModifierInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigResult;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigSnapshot;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\ConfigFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class StripeAdminConfigControllerTest extends TestCase
{
    public function testEveryOperationNeedsTheConfigRightAndNoneIsLogged(): void
    {
        foreach (['adminStripeConfig' => Query::class, 'adminStripeConfigModify' => Mutation::class] as $method => $operation) {
            $reflection = new ReflectionMethod(StripeAdminConfigController::class, $method);

            $rights = $reflection->getAttributes(Right::class);
            self::assertCount(1, $rights, $method);
            self::assertSame('ADMIN_PAYMENT_CONFIG', $rights[0]->getArguments()[0] ?? $rights[0]->getArguments()['name'] ?? null, $method);
            self::assertCount(1, $reflection->getAttributes($operation), $method);
            self::assertSame([], $reflection->getAttributes('TheCodingMachine\\GraphQLite\\Annotations\\Logged'), 'admin operations carry #[Right] only');
        }
    }

    public function testAdminStripeConfig_ReturnsTheSettingsWithoutAnySecretValue(): void
    {
        $config = $this->controller()->adminStripeConfig();

        self::assertSame('test', $config->getMode());
        self::assertTrue($config->isTestTokenSet());
        self::assertFalse($config->isLiveTokenSet());
    }

    public function testAdminStripeConfigModify_MapsTheInputAndReturnsTheNewConfig(): void
    {
        $changes = [];
        $modifier = new class ($changes) implements StripeConfigModifierInterface {
            /** @param list<StripeConfigChange> $changes */
            public function __construct(private array &$changes)
            {
            }

            public function modify(StripeConfigChange $change): StripeConfigResult
            {
                $this->changes[] = $change;

                return StripeConfigResult::done(ConfigFixtures::snapshot(['captureMode' => $change->captureMode ?? 'automatic']));
            }
        };
        $input = new AdminStripeConfigInput();
        $input->expectedVersion = 'v1';
        $input->captureMode = 'manual';
        $input->testToken = '';

        $payload = $this->controller($modifier)->adminStripeConfigModify($input);

        self::assertSame([], $payload->errors());
        self::assertSame('manual', $payload->config()?->getCaptureMode());
        self::assertSame('v1', $changes[0]->expectedVersion);
        self::assertSame(['captureMode'], $changes[0]->fieldNames());
    }

    public function testAdminStripeConfigModify_AConflictIsAnErrorOfThePayloadNotAnException(): void
    {
        $payload = $this->controller($this->refusing(StripeAdminRefusal::conflict('a', 'b')))->adminStripeConfigModify($this->input());

        self::assertNull($payload->config());
        self::assertCount(1, $payload->errors());
        self::assertInstanceOf(ConflictError::class, $payload->errors()[0]);
    }

    public function testAdminStripeConfigModify_AMismatchIsAValidationErrorOfTheField(): void
    {
        $refusal = StripeAdminRefusal::invalid('testToken', 'key_mismatch', 'different accounts');

        $errors = $this->controller($this->refusing($refusal))->adminStripeConfigModify($this->input())->errors();

        self::assertInstanceOf(ValidationError::class, $errors[0]);
        self::assertSame('testToken', $errors[0]->field());
        self::assertSame('key_mismatch', $errors[0]->code());
        self::assertSame('validation_failed', $errors[0]->errorCode());
    }

    private function input(): AdminStripeConfigInput
    {
        $input = new AdminStripeConfigInput();
        $input->expectedVersion = 'a';

        return $input;
    }

    private function refusing(StripeAdminRefusal $refusal): StripeConfigModifierInterface
    {
        return new class ($refusal) implements StripeConfigModifierInterface {
            public function __construct(private readonly StripeAdminRefusal $refusal)
            {
            }

            public function modify(StripeConfigChange $change): StripeConfigResult
            {
                return StripeConfigResult::refused($this->refusal);
            }
        };
    }

    private function controller(?StripeConfigModifierInterface $modifier = null): StripeAdminConfigController
    {
        $settings = new class implements StripeAdminSettingsInterface {
            public function snapshot(): StripeConfigSnapshot
            {
                return ConfigFixtures::snapshot();
            }

            public function token(string $mode): string
            {
                return '';
            }

            public function apply(StripeConfigChange $change): void
            {
            }
        };

        return new StripeAdminConfigController($settings, $modifier ?? $this->createStub(StripeConfigModifierInterface::class));
    }
}
