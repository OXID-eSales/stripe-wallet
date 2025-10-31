<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;

interface EventDispatcherInterface
{
    public function addListener(string $eventClass, callable $listener, int $priority = 0): void;

    public function removeListener(string $eventClass, callable $listener): void;

    public function dispatch(EventInterface $event): EventInterface;
}
