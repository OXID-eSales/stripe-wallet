<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Controller;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ValidationError;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Controller\StripeAdminWebhookController;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeWebhookPayload;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeWebhookStatus;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminRefusal;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookAdministratorInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookResult;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookStatus;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class StripeAdminWebhookControllerTest extends TestCase
{
    public function testEveryOperationNeedsTheConfigRightAndNoneIsLogged(): void
    {
        $operations = [
            'adminStripeWebhookStatus' => Query::class,
            'adminStripeWebhookRegister' => Mutation::class,
            'adminStripeWebhookClearAll' => Mutation::class,
        ];
        foreach ($operations as $method => $operation) {
            $reflection = new ReflectionMethod(StripeAdminWebhookController::class, $method);

            $rights = $reflection->getAttributes(Right::class);
            self::assertCount(1, $rights, $method);
            self::assertSame('ADMIN_PAYMENT_CONFIG', $rights[0]->getArguments()[0] ?? null, $method);
            self::assertCount(1, $reflection->getAttributes($operation), $method);
            self::assertSame([], $reflection->getAttributes('TheCodingMachine\\GraphQLite\\Annotations\\Logged'), $method);
        }
    }

    public function testStatus_ReportsTheWebhookSetup(): void
    {
        $status = $this->controller(new StripeWebhookStatus('live', true, 'we_1', 'https://shop.example/hook'))->adminStripeWebhookStatus();

        self::assertSame('live', $status->getMode());
        self::assertTrue($status->isConfigured());
        self::assertSame('we_1', $status->getEndpointId());
        self::assertSame('https://shop.example/hook', $status->getUrl());
    }

    public function testStatus_NoEndpointIdIsNullNotAnEmptyString(): void
    {
        $status = $this->controller(new StripeWebhookStatus('test', false, '', 'https://shop.example/hook'))->adminStripeWebhookStatus();

        self::assertNull($status->getEndpointId());
    }

    public function testRegister_AnswersWithTheStatusAndNoSecret(): void
    {
        $payload = $this->controller(new StripeWebhookStatus('test', true, 'we_1', 'u'))->adminStripeWebhookRegister();

        self::assertSame([], $payload->errors());
        self::assertSame('we_1', $payload->status()?->getEndpointId());
        self::assertNull($payload->deleted());
        self::assertEqualsCanonicalizing(['status', 'deleted', 'errors', 'mode', 'configured', 'endpointId', 'url'], array_unique(array_merge(
            $this->fieldNamesOf(AdminStripeWebhookPayload::class),
            $this->fieldNamesOf(AdminStripeWebhookStatus::class),
        )));
    }

    public function testClearAll_AnswersWithTheNumberOfDeletedEndpoints(): void
    {
        $payload = $this->controller(new StripeWebhookStatus('test', false, '', 'u'), 4)->adminStripeWebhookClearAll();

        self::assertSame(4, $payload->deleted());
        self::assertFalse($payload->status()?->isConfigured());
    }

    public function testARefusalIsAnErrorOfThePayloadNotAnException(): void
    {
        $administrator = $this->createStub(StripeWebhookAdministratorInterface::class);
        $administrator->method('register')->willReturn(
            StripeWebhookResult::refused(StripeAdminRefusal::invalid('token', 'token_missing', 'no key'))
        );

        $payload = (new StripeAdminWebhookController($administrator))->adminStripeWebhookRegister();

        self::assertNull($payload->status());
        self::assertInstanceOf(ValidationError::class, $payload->errors()[0]);
        self::assertSame('token_missing', $payload->errors()[0]->code());
    }

    private function controller(StripeWebhookStatus $status, ?int $deleted = null): StripeAdminWebhookController
    {
        $administrator = $this->createStub(StripeWebhookAdministratorInterface::class);
        $administrator->method('status')->willReturn($status);
        $administrator->method('register')->willReturn(StripeWebhookResult::done($status, null));
        $administrator->method('clearAll')->willReturn(StripeWebhookResult::done($status, $deleted));

        return new StripeAdminWebhookController($administrator);
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function fieldNamesOf(string $class): array
    {
        $names = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Field::class) !== []) {
                $names[] = lcfirst((string) preg_replace('/^(get|is|has)(?=[A-Z])/', '', $method->getName()));
            }
        }

        return $names;
    }
}
