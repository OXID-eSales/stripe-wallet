<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Smoke;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * VR-25 D3 (Stripe): a collaborator is required or it is not there. A constructor parameter that defaults to null / new X(),
 * or a `?? new X()` fallback, silently picks an implementation the container never chose.
 *
 * Only value-object / seam parameters are allowed to stay optional, and each one is listed here with its reason.
 */
final class RequiredCollaboratorsTest extends TestCase
{
    /**
     * Optional parameters of these types are values, not collaborators: a missing value is meaningful.
     *
     * @var list<string>
     */
    private const VALUE_TYPES = [
        '', 'string', 'int', 'float', 'bool', 'array', 'mixed', 'iterable',
        'DateTimeInterface', 'DateTimeImmutable', 'DateTime', 'Throwable', 'Money', 'Address',
        'BillingAddress', 'ShippingAddress', 'GuestDetails', 'GuestAddress',
    ];

    /**
     * "Class::$param" => why this optional is not a collaborator.
     *
     * @var array<string, string>
     */
    private const ALLOWED_OPTIONALS = [
        'Stripe\\Adapter\\Dto\\StripePaymentIntentDto::$charge' => 'DTO value: a payment intent may have no charge yet',
    ];

    public function testNoProductionConstructorDefaultsACollaborator(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $relative => $code) {
            foreach ($this->optionalConstructorParams($code) as $param) {
                $key = $this->classKey($relative) . '::$' . $param;
                if (!isset(self::ALLOWED_OPTIONALS[$key])) {
                    $offenders[] = $key;
                }
            }
        }

        self::assertSame([], $offenders, "Make these constructor parameters required (and wire them):\n"
            . implode("\n", $offenders));
    }

    public function testNoProductionCodeFallsBackToANewCollaborator(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $relative => $code) {
            if (preg_match('/\?\?\s*new\s+(?!DateTime)[A-Z\\\\]/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, "`?? new X()` fallbacks:\n" . implode("\n", $offenders));
    }

    /**
     * Allowed `$this->x?->` call sites: the OXID WebhookController builds its collaborators by container lookup
     * (controllers get no constructor DI) and answers 503 / logs when one is missing - degraded, never silent.
     *
     * @var array<string, string>
     */
    private const ALLOWED_NULLSAFE_FILES = [
        'Stripe/Controller/Webhook/WebhookController.php' => 'container-lookup seam of an OXID controller; a missing guard answers 503',
    ];

    public function testNoProductionCodeCallsThroughAnOptionalCollaborator(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $relative => $code) {
            if (isset(self::ALLOWED_NULLSAFE_FILES[$relative])) {
                continue;
            }
            if (preg_match('/\$this->\w+\?->/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, "`\$this->x?->` on a collaborator:\n" . implode("\n", $offenders));
    }

    /**
     * `@?` optional service references in services.yaml: only the GraphQL packages, which are optional for the module
     * (AdminApiIsOptionalTest proves it loads without them).
     */
    public function testOptionalServiceReferencesAreOnlyTheOptionalGraphQlPackages(): void
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 4) . '/services.yaml');
        $offenders = [];
        foreach (explode("\n", $yaml) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (str_contains($line, "'@?") && !str_contains($line, '@?OxidEsales\\GraphQL\\')
                && !str_contains($line, '@?OxidEsales\\PaymentBase\\GraphQL\\')) {
                $offenders[] = trim($line);
            }
        }

        self::assertSame([], $offenders, "Optional `@?` references outside the GraphQL packages:\n" . implode("\n", $offenders));
    }

    public function testEveryAllowedOptionalStillExists(): void
    {
        $found = [];
        foreach ($this->sourceFiles() as $relative => $code) {
            foreach ($this->optionalConstructorParams($code) as $param) {
                $found[] = $this->classKey($relative) . '::$' . $param;
            }
        }

        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::ALLOWED_OPTIONALS), $found)),
            'Stale allow-list entries: remove them.'
        );
    }

    /**
     * @return iterable<string, string> path relative to src/ => code
     */
    private function sourceFiles(): iterable
    {
        $root = dirname(__DIR__, 4) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            yield substr($file->getPathname(), strlen($root) + 1) => (string) file_get_contents($file->getPathname());
        }
    }

    private function classKey(string $relative): string
    {
        return substr($relative, 0, -4) === '' ? $relative : str_replace('/', '\\', substr($relative, 0, -4));
    }

    /**
     * @return list<string> names of constructor parameters defaulting to null or `new X()`
     */
    private function optionalConstructorParams(string $code): array
    {
        $start = strpos($code, 'function __construct(');
        if ($start === false) {
            return [];
        }
        $params = $this->balancedParameterList($code, $start + strlen('function __construct('));
        $names = [];
        foreach ($this->splitTopLevel($params) as $param) {
            if (preg_match('/(?:(\??[\w\\\\]+)\s+)?\$(\w+)\s*=\s*(null\b|new\s)/', $param, $match) !== 1) {
                continue;
            }
            if (!in_array(ltrim($match[1], '?\\'), self::VALUE_TYPES, true)) {
                $names[] = $match[2];
            }
        }

        return $names;
    }

    private function balancedParameterList(string $code, int $from): string
    {
        $depth = 1;
        for ($i = $from, $length = strlen($code); $i < $length; $i++) {
            $depth += match ($code[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return substr($code, $from, $i - $from);
            }
        }

        return substr($code, $from);
    }

    /**
     * @return list<string>
     */
    private function splitTopLevel(string $list): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($list) as $char) {
            $depth += match ($char) {
                '(', '[' => 1,
                ')', ']' => -1,
                default => 0,
            };
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }
}
