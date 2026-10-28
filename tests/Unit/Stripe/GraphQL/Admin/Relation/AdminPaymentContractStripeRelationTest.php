<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Relation;

use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentContract;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetails;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripePaymentDetails;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Relation\AdminPaymentContractStripeRelation;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripePaymentDetails;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripePaymentDetailsReaderInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class AdminPaymentContractStripeRelationTest extends TestCase
{
    public function testItExtendsAdminPaymentContractWithAStripeFieldBehindTheViewRight(): void
    {
        $class = new ReflectionClass(AdminPaymentContractStripeRelation::class);
        $extend = $class->getAttributes(ExtendType::class);
        self::assertCount(1, $extend);
        self::assertSame(AdminPaymentContract::class, $extend[0]->getArguments()['class'] ?? $extend[0]->getArguments()[0]);

        $method = new ReflectionMethod(AdminPaymentContractStripeRelation::class, 'stripe');
        self::assertCount(1, $method->getAttributes(Field::class));
        self::assertSame('ADMIN_PAYMENT_VIEW', $method->getAttributes(Right::class)[0]->getArguments()[0]);
    }

    public function testStripe_ReadsTheDetailsOfTheContractById(): void
    {
        $reader = new class implements StripePaymentDetailsReaderInterface {
            public function find(string $contractId): ?StripePaymentDetails
            {
                return $contractId === 'c-stripe'
                    ? new StripePaymentDetails('pi_1', 'ch_1', 'cs_1', 'succeeded', 'https://dashboard.stripe.com/payments/pi_1', null)
                    : null;
            }
        };
        $relation = new AdminPaymentContractStripeRelation($reader);

        $details = $relation->stripe($this->contract('c-stripe'));

        self::assertInstanceOf(AdminStripePaymentDetails::class, $details);
        self::assertSame('pi_1', $details->paymentIntentId());
        self::assertSame('ch_1', $details->chargeId());
        self::assertSame('cs_1', $details->externalTransactionId());
        self::assertSame('succeeded', $details->intentStatus());
        self::assertSame('https://dashboard.stripe.com/payments/pi_1', $details->dashboardUrl());
        self::assertNull($details->apiError());
        self::assertNull($relation->stripe($this->contract('c-of-paypal')), 'a contract of another provider has no Stripe part');
    }

    public function testTheTypeExposesExactlyTheDocumentedFields(): void
    {
        $fields = [];
        foreach ((new ReflectionClass(AdminStripePaymentDetails::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Field::class) !== []) {
                $fields[] = $method->getName();
            }
        }

        self::assertEqualsCanonicalizing(
            ['paymentIntentId', 'chargeId', 'externalTransactionId', 'intentStatus', 'dashboardUrl', 'apiError'],
            $fields,
        );
    }

    private function contract(string $id): AdminPaymentContract
    {
        return new AdminPaymentContract(new PaymentContractDetails($id, 'stripe', 'committed', 10.0, 0.0, 0.0, 'EUR', []));
    }
}
