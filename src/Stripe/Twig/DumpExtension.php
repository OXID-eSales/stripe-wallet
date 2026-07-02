<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Twig;

use OxidEsales\Payments\Stripe\Environment\DevelopmentEnvironmentCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension providing dump() for template debugging.
 *
 * Security: Only registers functions in development mode (test mode).
 * In live mode, getFunctions() returns [] — the extension is a no-op.
 *
 * Sprint 63d (STRP-99 H1): Environment-aware, HTML-escaped, no dd().
 */
class DumpExtension extends AbstractExtension
{
    public function __construct(
        private readonly DevelopmentEnvironmentCheckerInterface $envChecker
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        if (!$this->envChecker->isDevelopmentMode()) {
            return [];
        }

        return [
            new TwigFunction('dump', [$this, 'dump']),
        ];
    }

    /**
     * Dumps variables with HTML-escaped output wrapped in <pre> tags.
     */
    public function dump(mixed ...$vars): string
    {
        if (empty($vars)) {
            return '';
        }

        ob_start();
        foreach ($vars as $var) {
            var_dump($var);
        }
        $raw = (string) ob_get_clean();

        return '<pre>' . htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }
}
