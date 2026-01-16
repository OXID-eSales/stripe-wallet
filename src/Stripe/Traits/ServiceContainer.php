<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Traits;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;

/**
 * Trait for accessing services from the DI container.
 */
trait ServiceContainer
{
    /**
     * Get a service from the DI container.
     *
     * @template T of object
     * @param class-string<T> $serviceClass
     * @return T
     */
    protected function getServiceFromContainer(string $serviceClass): object
    {
        return ContainerFactory::getInstance()->getContainer()->get($serviceClass);
    }
}
