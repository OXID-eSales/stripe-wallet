<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

/**
 * Security headers for the module's OWN HTTP responses (webhook + JSON endpoints).
 *
 * Scope (Sprint 131 / STRP-XXX): a payment module must NOT set shop-wide response
 * headers — that is the web server's / shop operator's concern (see
 * docs/for_operator/security-headers.md). This helper only hardens the responses
 * the module itself emits, which are JSON/webhook endpoints that are never
 * legitimately framed — hence `X-Frame-Options: DENY` (the storefront's shop-wide
 * policy is `SAMEORIGIN`, because the OXID admin uses framesets).
 *
 * Pure + static (static-for-pure-utilities): the header sink is injected so the
 * behaviour is deterministic and unit-testable without emitting real headers.
 */
final class ResponseHeaders
{
    /**
     * Apply the module's response security headers via the given sink.
     *
     * @param callable(string): void $setHeader header emitter — e.g. `\header(...)`
     *        or `Registry::getUtils()->setHeader(...)`.
     */
    public static function applySecurity(callable $setHeader): void
    {
        $setHeader('X-Content-Type-Options: nosniff');
        $setHeader('X-Frame-Options: DENY');
    }
}
