<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\GraphQL;

use ArrayIterator;
use Iterator;
use Kcs\ClassFinder\Finder\FinderInterface;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutCancelResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutReturnResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutStartResult;
use OxidEsales\Payments\Stripe\GraphQL\Controller\StripeCheckout;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
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
        $factory->setFinder($this->finderOver([
            StripeCheckout::class,
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
            ['basketId', 'confirmTermsAndConditions', 'returnUrl', 'cancelUrl', 'uiMode'],
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
     * A class finder over a fixed list. GraphQLite's default (composer
     * classmap) finder reflects every class under a namespace prefix, which
     * in this shop re-enters OXID's module-chain autoloader for other
     * modules' controllers; the schema under test needs exactly these classes.
     *
     * @param list<class-string> $classes
     */
    private function finderOver(array $classes): FinderInterface
    {
        return new class ($classes) implements FinderInterface {
            /** @var list<string> */
            private array $namespaces = [];

            /** @param list<class-string> $classes */
            public function __construct(private readonly array $classes)
            {
            }

            public function getIterator(): Iterator
            {
                $found = [];
                foreach ($this->classes as $class) {
                    foreach ($this->namespaces ?: [''] as $namespace) {
                        if (str_starts_with($class, $namespace)) {
                            $found[$class] = new ReflectionClass($class);
                            break;
                        }
                    }
                }

                return new ArrayIterator($found);
            }

            public function inNamespace(string|array $namespaces): self
            {
                $clone = clone $this;
                $clone->namespaces = array_map(
                    static fn(string $ns): string => rtrim($ns, '\\') . '\\',
                    (array) $namespaces
                );

                return $clone;
            }

            public function implementationOf(string|array $interface): self
            {
                return $this;
            }

            public function subclassOf(string|null $superClass): self
            {
                return $this;
            }

            public function annotatedBy(string|null $annotationClass): self
            {
                return $this;
            }

            public function withAttribute(string|null $attributeClass): self
            {
                return $this;
            }

            public function in(string|array $dirs): self
            {
                return $this;
            }

            public function notInNamespace(string|array $namespaces): self
            {
                return $this;
            }

            public function filter(callable|null $callback): self
            {
                return $this;
            }

            public function path(string $pattern): self
            {
                return $this;
            }

            public function notPath(string $pattern): self
            {
                return $this;
            }

            public function pathFilter(callable|null $callback): self
            {
                return $this;
            }
        };
    }
}
