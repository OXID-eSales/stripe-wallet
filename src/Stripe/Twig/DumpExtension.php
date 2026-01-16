<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension to provide dump functionality for debugging templates
 */
class DumpExtension extends AbstractExtension
{
    /**
     * Returns a list of functions to add to the existing list.
     *
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('dump', [$this, 'dump'], ['is_safe' => ['html']]),
            new TwigFunction('dd', [$this, 'dumpAndDie'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Dumps variables using var_dump with HTML formatting
     *
     * @param mixed ...$vars Variables to dump
     * @return string HTML formatted dump output
     */
    public function dump(...$vars): string
    {
        if (empty($vars)) {
            return '';
        }

        ob_start();
        echo '<pre style="background: #f5f5f5; border: 1px solid #ddd; padding: 10px; margin: 10px 0; overflow: auto; font-size: 12px; line-height: 1.4;">';

        foreach ($vars as $var) {
            var_dump($var);
        }

        echo '</pre>';

        $result = ob_get_clean();
        return $result !== false ? $result : '';
    }

    /**
     * Dumps variables and terminates script execution
     * Useful for debugging - "dump and die"
     *
     * @param mixed ...$vars Variables to dump
     * @return string HTML formatted dump output (but script will die after)
     */
    public function dumpAndDie(...$vars): string
    {
        $output = $this->dump(...$vars);
        die($output);
    }

    /**
     * Returns the name of the extension.
     *
     * @return string The extension name
     */
    public function getName(): string
    {
        return 'stripe_dump_extension';
    }
}
