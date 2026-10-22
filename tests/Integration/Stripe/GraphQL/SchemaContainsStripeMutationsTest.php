<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\GraphQL;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutCancelResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutReturnResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutStartResult;
use OxidEsales\PaymentBase\GraphQL\DataType\GuestCheckoutInputFactory;
use OxidEsales\Payments\Stripe\GraphQL\Controller\StripeCheckout;
use OxidEsales\Payments\Stripe\Tests\Support\FixedClassFinder;
use GraphQL\Type\Definition\InputObjectType;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use TheCodingMachine\GraphQLite\SchemaFactory;

/**
 * GRAPH-QL / PS4 against the real container: the Stripe controller resolves,
 * and GraphQLite builds a valid schema from it plus payment-base's result
 * types - the three mutations with the documented arguments and types. This
 * is what graphql-base's SchemaFactory does for every tagged namespace
 * mapper; it is built here for our two namespaces only, so the proof does
 * not depend on every other module's controllers loading in this shop.
 * Skipped where GraphQLite (graphql-base) is not installed - which is why
 * the class-finder stand-in below is an anonymous class built after the
 * guard: a named class implementing kcs/class-finder's interface at file
 * level is a fatal "Interface not found" on such a shop (CI), before any
 * test could skip.
 */
#[Group('integration')]
final class SchemaContainsStripeMutationsTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(SchemaFactory::class)) {
            self::markTestSkipped('graphql-base / GraphQLite is not installed');
        }
        // Required by path, not by autoload: the shop bootstrap CI uses does not register the Tests\ namespace, and
        // the class implements a kcs/class-finder interface that exists only where GraphQLite does (guard above).
        require_once __DIR__ . '/../../../Support/FixedClassFinder.php';
    }

    public function testTheControllerResolvesFromTheContainer(): void
    {
        self::assertInstanceOf(
            StripeCheckout::class,
            ContainerFactory::getInstance()->getContainer()->get(StripeCheckout::class)
        );
    }

    public function testGraphQliteBuildsTheThreeStripeCheckoutMutationsOverPaymentBasesTypes(): void
    {
        $factory = new SchemaFactory(new Psr16Cache(new ArrayAdapter()), ContainerFactory::getInstance()->getContainer());
        $factory->setFinder(new FixedClassFinder([
            StripeCheckout::class,
            GuestCheckoutInputFactory::class,
            CheckoutStartResult::class,
            CheckoutReturnResult::class,
            CheckoutCancelResult::class,
        ]));
        $factory->addControllerNamespace('OxidEsales\\Payments\\Stripe\\GraphQL\\Controller');
        $factory->addTypeNamespace('OxidEsales\\PaymentBase\\GraphQL\\DataType');

        $schema = $factory->createSchema();
        $schema->assertValid();
        $mutations = $schema->getMutationType();
        self::assertNotNull($mutations);

        foreach (['stripeCheckoutStart', 'stripeCheckoutReturn', 'stripeCheckoutCancel'] as $name) {
            self::assertTrue($mutations->hasField($name), "mutation $name missing from the schema");
        }

        $start = $mutations->getField('stripeCheckoutStart');
        self::assertSame('CheckoutStartResult!', (string) $start->getType());
        self::assertSame(
            ['basketId', 'confirmTermsAndConditions', 'returnUrl', 'cancelUrl', 'uiMode', 'guest'],
            array_map(static fn($arg) => $arg->name, $start->args)
        );
        self::assertSame('CheckoutReturnResult!', (string) $mutations->getField('stripeCheckoutReturn')->getType());
        self::assertSame('CheckoutCancelResult!', (string) $mutations->getField('stripeCheckoutCancel')->getType());

        $startType = $schema->getType('CheckoutStartResult');
        self::assertNotNull($startType);
        self::assertSame(
            ['contractId', 'contractToken', 'providerName', 'orderNumber', 'redirectUrl', 'clientSecret', 'renderMode'],
            array_keys($startType->getFields())
        );
    }

    /**
     * The input type is payment-base's (its fields are pinned there, once); here only that the start mutation
     * takes it under the agreed name, and that this module does not define a second one.
     */
    public function testStartTakesTheSharedGuestCheckoutInput(): void
    {
        $factory = new SchemaFactory(new Psr16Cache(new ArrayAdapter()), ContainerFactory::getInstance()->getContainer());
        $factory->setFinder(new FixedClassFinder([
            StripeCheckout::class,
            GuestCheckoutInputFactory::class,
            CheckoutStartResult::class,
            CheckoutReturnResult::class,
            CheckoutCancelResult::class,
        ]));
        $factory->addControllerNamespace('OxidEsales\\Payments\\Stripe\\GraphQL\\Controller');
        $factory->addTypeNamespace('OxidEsales\\PaymentBase\\GraphQL\\DataType');
        $schema = $factory->createSchema();
        $schema->assertValid();

        $guest = $schema->getMutationType()?->getField('stripeCheckoutStart')->getArg('guest');
        self::assertNotNull($guest);
        self::assertSame('GuestCheckoutInput', (string) $guest->getType());
        self::assertInstanceOf(InputObjectType::class, $schema->getType('GuestCheckoutInput'));
    }
}
