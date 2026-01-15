<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Contract\SecurityValidationResultInterface;
use OxidEsales\PaymentComponent\Service\ReturnSecurityValidatorInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\Result\SecurityValidationResult;

/**
 * Service for validating returning users from Stripe payment redirects.
 *
 * Calculates a fraud risk score based on:
 * - IP address matching/country
 * - Return timing
 * - User agent similarity
 *
 * Implements ReturnSecurityValidatorInterface for LSP compliance -
 * can be substituted anywhere the interface is expected.
 */
final class ReturnSessionSecurityService implements ReturnSecurityValidatorInterface
{
    // Timing thresholds (in seconds)
    private const QUICK_RETURN_MAX = 900;       // 15 minutes
    private const SLOW_RETURN_THRESHOLD = 1800; // 30 minutes
    private const VERY_LATE_THRESHOLD = 3600;   // 1 hour

    // Score penalties
    private const PENALTY_IP_CHANGED = 20;
    private const PENALTY_IP_SAME_COUNTRY = 10;  // Partial restoration
    private const PENALTY_COUNTRY_CHANGED = 40;
    private const PENALTY_SLOW_RETURN = 15;
    private const PENALTY_VERY_LATE_RETURN = 35;
    private const PENALTY_BROWSER_CHANGED = 15;
    private const PENALTY_OS_CHANGED = 25;
    private const PENALTY_MISSING_IP = 20;

    public function __construct(
        private readonly int $threshold = 50
    ) {
    }

    /**
     * Validate a returning user against the original contract context.
     */
    public function validateReturn(
        PaymentContractInterface $contract,
        array $currentContext
    ): SecurityValidationResultInterface {
        $score = 100;
        $warnings = [];

        // Check IP address
        [$score, $warnings] = $this->validateIp($contract, $currentContext, $score, $warnings);

        // Check timing
        [$score, $warnings] = $this->validateTiming($contract, $score, $warnings);

        // Check user agent
        [$score, $warnings] = $this->validateUserAgent($contract, $currentContext, $score, $warnings);

        // Ensure score is within bounds
        $score = max(0, min(100, $score));

        return new SecurityValidationResult(
            $score,
            $warnings,
            $score >= $this->threshold
        );
    }

    /**
     * Validate IP address.
     *
     * @return array{int, array<string>}
     */
    private function validateIp(
        PaymentContractInterface $contract,
        array $currentContext,
        int $score,
        array $warnings
    ): array {
        $originalIp = $contract->getMetadata('user_ip');
        $currentIp = $currentContext['ip'] ?? null;

        // Handle missing original IP
        if ($originalIp === null) {
            $score -= self::PENALTY_MISSING_IP;
            $warnings[] = 'missing_original_ip';
            return [$score, $warnings];
        }

        // IP matches - no penalty
        if ($currentIp !== null && $originalIp === $currentIp) {
            return [$score, $warnings];
        }

        // IP changed - check country
        $originalCountry = $contract->getMetadata('user_country');
        $currentCountry = $currentContext['country'] ?? null;

        if ($originalCountry !== null && $currentCountry !== null) {
            if ($originalCountry !== $currentCountry) {
                // Country changed - heavy penalty
                $score -= self::PENALTY_COUNTRY_CHANGED;
                $warnings[] = 'country_changed';
            } else {
                // Same country - moderate penalty
                $score -= self::PENALTY_IP_CHANGED;
                $score += self::PENALTY_IP_SAME_COUNTRY; // Partial restoration
                $warnings[] = 'ip_changed_same_country';
            }
        } else {
            // Can't determine country - standard IP change penalty
            $score -= self::PENALTY_IP_CHANGED;
            $warnings[] = 'ip_changed';
        }

        return [$score, $warnings];
    }

    /**
     * Validate return timing.
     *
     * @return array{int, array<string>}
     */
    private function validateTiming(
        PaymentContractInterface $contract,
        int $score,
        array $warnings
    ): array {
        $createdTimestamp = $contract->getMetadata('created_timestamp');

        if ($createdTimestamp === null) {
            return [$score, $warnings];
        }

        $elapsed = time() - (int) $createdTimestamp;

        if ($elapsed >= self::VERY_LATE_THRESHOLD) {
            $score -= self::PENALTY_VERY_LATE_RETURN;
            $warnings[] = 'very_late_return';
        } elseif ($elapsed >= self::SLOW_RETURN_THRESHOLD) {
            $score -= self::PENALTY_SLOW_RETURN;
            $warnings[] = 'slow_return';
        }
        // Quick return (< 15 min) - no penalty

        return [$score, $warnings];
    }

    /**
     * Validate user agent.
     *
     * @return array{int, array<string>}
     */
    private function validateUserAgent(
        PaymentContractInterface $contract,
        array $currentContext,
        int $score,
        array $warnings
    ): array {
        $originalUa = $contract->getMetadata('user_agent');
        $currentUa = $currentContext['user_agent'] ?? null;

        // Can't validate if either is missing
        if ($originalUa === null || $currentUa === null) {
            return [$score, $warnings];
        }

        // Exact match - no penalty
        if ($originalUa === $currentUa) {
            return [$score, $warnings];
        }

        // Parse user agents for comparison
        $originalParts = $this->parseUserAgent($originalUa);
        $currentParts = $this->parseUserAgent($currentUa);

        // Check OS
        if ($originalParts['os'] !== $currentParts['os']) {
            $score -= self::PENALTY_OS_CHANGED;
            $warnings[] = 'os_changed';
            return [$score, $warnings]; // OS change is major, skip browser check
        }

        // Check browser
        if ($originalParts['browser'] !== $currentParts['browser']) {
            $score -= self::PENALTY_BROWSER_CHANGED;
            $warnings[] = 'browser_changed';
        }
        // Minor version change - no penalty (common with auto-updates)

        return [$score, $warnings];
    }

    /**
     * Parse user agent string to extract OS and browser.
     *
     * @return array{os: string, browser: string}
     */
    private function parseUserAgent(string $userAgent): array
    {
        $os = 'unknown';
        $browser = 'unknown';

        // Detect OS
        if (str_contains($userAgent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($userAgent, 'Linux')) {
            $os = 'Linux';
        } elseif (str_contains($userAgent, 'Mac')) {
            $os = 'Mac';
        } elseif (str_contains($userAgent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($userAgent, 'iOS') || str_contains($userAgent, 'iPhone')) {
            $os = 'iOS';
        }

        // Detect browser (order matters - Edge contains Chrome)
        if (str_contains($userAgent, 'Edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($userAgent, 'Firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($userAgent, 'Safari/') && !str_contains($userAgent, 'Chrome')) {
            $browser = 'Safari';
        } elseif (str_contains($userAgent, 'Chrome/')) {
            $browser = 'Chrome';
        }

        return ['os' => $os, 'browser' => $browser];
    }
}
