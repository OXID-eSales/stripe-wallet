<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use OxidEsales\Payments\Stripe\Core\Events;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Sprint 133 · Story 18 (F18) — structural guards on the activation handler.
 *
 * Events touches Registry and DatabaseProvider statically, so its behaviour is
 * covered by the gated integration suite (`Integration/Module/ModuleLifecycleTest`,
 * group `requires-oxid-container`). What can be pinned here is the shape: no
 * method that promises work it does not do, and no cache-clear hidden behind an
 * admin-only branch.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Events::class)]
final class EventsContractTest extends TestCase
{
    public function testThereIsNoMethodPromisingDeactivationThatDoesNothing(): void
    {
        // deactivatePaymentMethods() had an empty body under a name that
        // promised action, and onDeactivate() called it — so reading the call
        // site suggested payment methods were deactivated. They are not, by
        // design: ensureStripePaymentMethods() deliberately leaves oxactive
        // untouched on re-activation to preserve admin changes, so switching
        // them off here would silently keep them off afterwards.
        $this->assertFalse(
            (new ReflectionClass(Events::class))->hasMethod('deactivatePaymentMethods'),
            'An empty method under an action-promising name must not exist.'
        );
    }

    public function testDeactivationClearsTheCacheRegardlessOfContext(): void
    {
        // The whole body used to sit behind Registry::getConfig()->isAdmin(),
        // so `oe-console oe:module:deactivate` — the documented CLI path — left
        // stale templates and config behind.
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(Events::class))->getFileName()
        );
        $onDeactivate = substr($source, (int) strpos($source, 'function onDeactivate'));
        $onDeactivate = substr($onDeactivate, 0, (int) strpos($onDeactivate, "\n    }"));

        $this->assertStringContainsString('clearTmp', $onDeactivate);
        $this->assertStringNotContainsString('isAdmin', $onDeactivate);
    }
}
