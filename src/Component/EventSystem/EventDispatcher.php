<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;

class EventDispatcher
{
    private array $listeners = [];

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

    public function removeListener(string $eventClass, callable $listener): void
    {
        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        $this->listeners[$eventClass] = array_filter(
            $this->listeners[$eventClass],
            fn($item) => $item['listener'] !== $listener
        );
    }

    public function dispatch(EventInterface $event): EventInterface
    {
        $eventClass = get_class($event);

        if (!isset($this->listeners[$eventClass])) {
            return $event;
        }

        $listeners = $this->getSortedListeners($eventClass);

        foreach ($listeners as $listener) {
            if ($this->isStoppableEvent($event) && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    private function getSortedListeners(string $eventClass): array
    {
        $listeners = $this->listeners[$eventClass];

        usort($listeners, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return array_map(fn($item) => $item['listener'], $listeners);
    }

    private function isStoppableEvent(EventInterface $event): bool
    {
        return method_exists($event, 'isPropagationStopped');
    }
}
