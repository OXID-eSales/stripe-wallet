<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;

/**
 * Manages event listeners and provides them to EventDispatcher.
 * Integrates with Symfony DI via tagged services.
 *
 * @since 1.0.0
 */
class EventListenerProvider implements EventListenerProviderInterface
{
    /** @var array<string, array<array{listener: callable, priority: int}>> */
    private array $listeners = [];

    /**
     * @param iterable<HandlerInterface> $handlers Handlers injected via DI (tagged services)
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->registerHandler($handler);
        }
    }

    public function getListenersForEvent(string $eventClass): array
    {
        if (!isset($this->listeners[$eventClass])) {
            return [];
        }

        $listeners = $this->listeners[$eventClass];
        usort($listeners, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return array_map(fn($item) => $item['listener'], $listeners);
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$eventClass])) {
            $this->listeners[$eventClass] = [];
        }

        $this->listeners[$eventClass][] = [
            'listener' => $listener,
            'priority' => $priority,
        ];
    }

    /**
     * Registers a handler by using its getHandledEventClass() method.
     * Priority is determined by getPriority() if implemented, otherwise 0.
     */
    private function registerHandler(HandlerInterface $handler): void
    {
        $eventClass = $handler::getHandledEventClass();
        $priority = method_exists($handler, 'getPriority') ? $handler->getPriority() : 0;
        $this->addListener($eventClass, [$handler, 'handle'], $priority);
    }
}
