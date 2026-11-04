<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\PhpStan\DeadCode;

use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Entry points of the dead-code gate (sprint 17 DC-5) that are called by a framework, not by PHP code.
 *
 * Six callers are invisible to a static call graph:
 * - GraphQLite builds the schema from attributes (`#[Field]`, `#[Query]`, `#[Mutation]`, ...);
 * - the shop calls the `events` of metadata.php by name;
 * - the classes metadata.php registers as shop class extensions or controllers are called by the shop, by templates
 *   and by `fnc=` request parameters;
 * - payment-base (scanned, not analysed) calls the methods that Stripe's classes inherit from its interfaces and base
 *   classes;
 * - a few classes hand their public methods to Twig templates ({@see TWIG_CALLED});
 * - the container calls the `factory: ['@Service', 'method']` entries of services.yaml and the constructor of every
 *   service it builds (a class named in services.yaml, or under a namespace its `resource:` sweeps).
 * What metadata.php and services.yaml name is read from those files, so the lists cannot drift from them.
 */
final class OxidEntryPointUsageProvider extends ReflectionBasedMemberUsageProvider
{
    private const GRAPHQLITE_ATTRIBUTE_NAMESPACE = 'TheCodingMachine\\GraphQLite\\Annotations\\';
    private const MODULE_ROOT = __DIR__ . '/../../..';
    private const PAYMENT_BASE_NAMESPACE = 'OxidEsales\\PaymentBase\\';

    /** Methods payment-base calls by duck typing (`method_exists`) on every handler it dispatches to. */
    private const DUCK_TYPED_BY_PAYMENT_BASE = ['getPriority'];

    /** Classes whose public methods a Twig template calls (`help.introIdent` in the admin panel and module settings). */
    private const TWIG_CALLED = [
        'OxidEsales\\Payments\\Stripe\\Admin\\StripeContractStateHelp',
    ];

    /** @var array<string, true> classes whose public methods the shop, a template or a request calls */
    private array $shopCalledClasses;

    /** @var array<string, array<string, true>> class => methods called by metadata.php events or services.yaml factories */
    private array $namedMethods;

    /** @var array<string, true> classes named as a service id in services.yaml */
    private array $serviceClasses = [];

    /** @var list<string> namespaces (with trailing backslash) that a `resource:` entry of services.yaml registers */
    private array $serviceNamespaces = [];

    public function __construct()
    {
        $this->shopCalledClasses = [];
        $this->namedMethods = [];
        $this->readMetadata();
        $this->readServices();
    }

    public function isEntryPoint(ReflectionMethod $method): bool
    {
        if (!$method->isPublic()) {
            return false;
        }
        $className = $method->getDeclaringClass()->getName();

        return $this->hasGraphQliteAttribute($method)
            || isset($this->shopCalledClasses[$className])
            || in_array($className, self::TWIG_CALLED, true)
            || $this->overridesPaymentBase($method)
            || isset($this->namedMethods[$className][$method->getName()])
            || ($method->isConstructor() && $this->isBuiltByTheContainer($className));
    }

    private function overridesPaymentBase(ReflectionMethod $method): bool
    {
        $class = $method->getDeclaringClass();
        if (in_array($method->getName(), self::DUCK_TYPED_BY_PAYMENT_BASE, true)) {
            return $this->extendsOrImplementsPaymentBase($class);
        }
        $ancestors = array_merge($class->getInterfaces(), $this->parentClasses($class));
        foreach ($ancestors as $ancestor) {
            if (str_starts_with($ancestor->getName(), self::PAYMENT_BASE_NAMESPACE) && $ancestor->hasMethod($method->getName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function extendsOrImplementsPaymentBase(\ReflectionClass $class): bool
    {
        foreach (array_merge($class->getInterfaces(), $this->parentClasses($class)) as $ancestor) {
            if (str_starts_with($ancestor->getName(), self::PAYMENT_BASE_NAMESPACE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<\ReflectionClass<object>>
     */
    private function parentClasses(\ReflectionClass $class): array
    {
        $parents = [];
        while (($class = $class->getParentClass()) !== false) {
            $parents[] = $class;
        }

        return $parents;
    }

    private function isBuiltByTheContainer(string $className): bool
    {
        if (isset($this->serviceClasses[$className])) {
            return true;
        }
        foreach ($this->serviceNamespaces as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return true;
            }
        }

        return false;
    }

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        if (!$this->isEntryPoint($method)) {
            return null;
        }

        return VirtualUsageData::withNote('Called by GraphQLite, metadata.php, a template, fnc= or the service container');
    }

    private function hasGraphQliteAttribute(ReflectionMethod $method): bool
    {
        foreach ($method->getAttributes() as $attribute) {
            if (str_starts_with($attribute->getName(), self::GRAPHQLITE_ATTRIBUTE_NAMESPACE)) {
                return true;
            }
        }

        return false;
    }

    private function readMetadata(): void
    {
        $aModule = [];
        $sMetadataVersion = '';
        require self::MODULE_ROOT . '/metadata.php';

        foreach (array_merge(array_values($aModule['extend'] ?? []), array_values($aModule['controllers'] ?? [])) as $class) {
            $this->shopCalledClasses[ltrim((string) $class, '\\')] = true;
        }
        foreach ($aModule['events'] ?? [] as $callable) {
            [$class, $method] = explode('::', (string) $callable);
            $this->namedMethods[ltrim($class, '\\')][$method] = true;
        }
    }

    private function readServices(): void
    {
        $yaml = (string) file_get_contents(self::MODULE_ROOT . '/services.yaml');
        preg_match_all("/factory:\s*\[\s*'@?([\w\\\\]+)'\s*,\s*'(\w+)'\s*\]/", $yaml, $factories, PREG_SET_ORDER);
        foreach ($factories as $match) {
            $this->namedMethods[ltrim($match[1], '\\')][$match[2]] = true;
        }
        preg_match_all('/^  ([A-Za-z][\w\\\\]*):\s*$/m', $yaml, $ids);
        foreach ($ids[1] as $id) {
            $this->serviceClasses[$id] = true;
        }
        preg_match_all('/^\s+(?:class|alias):\s*[\'"]?@?([A-Za-z][\w\\\\]*)/m', $yaml, $named);
        foreach ($named[1] as $class) {
            $this->serviceClasses[$class] = true;
        }
        preg_match_all('/^  ([A-Za-z][\w\\\\]*\\\\):\s*\n\s+resource:/m', $yaml, $resources);
        $this->serviceNamespaces = $resources[1];
    }
}
