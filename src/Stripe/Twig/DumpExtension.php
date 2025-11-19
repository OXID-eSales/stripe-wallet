<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class DumpExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('dump', [$this, 'dump'], ['is_safe' => ['html']]),
            new TwigFunction('dd', [$this, 'dd'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Dumps variable(s) for debugging
     *
     * @param mixed ...$vars
     * @return string
     */
    public function dump(...$vars): string
    {
        ob_start();
        echo '<pre style="background:#1e1e1e;color:#dcdcdc;padding:10px;margin:10px;border-radius:5px;overflow:auto;font-size:12px;">';
        foreach ($vars as $var) {
            var_dump($var);
        }
        echo '</pre>';
        return ob_get_clean();
    }

    /**
     * Dumps variable(s) and dies
     *
     * @param mixed ...$vars
     * @return never
     */
    public function dd(...$vars): never
    {
        echo $this->dump(...$vars);
        exit(1);
    }
}
