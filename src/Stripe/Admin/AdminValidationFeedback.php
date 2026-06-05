<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;

/**
 * Session-backed implementation of the admin validation feedback channel.
 *
 * The session payload is plain arrays (field/code/char/action) — never
 * serialized value objects. The key is namespaced per order so two admins
 * editing different orders in one session do not cross-talk.
 *
 * Sprint 120 Phase B (STRP-129).
 */
class AdminValidationFeedback implements AdminValidationFeedbackInterface
{
    private const SESSION_KEY_PREFIX = 'stripe_admin_validation_';

    public function __construct(private readonly SessionAdapterInterface $session)
    {
    }

    /**
     * @param FieldValidationFailure[] $failures
     */
    public function store(string $orderId, string $action, array $failures): void
    {
        $entries = array_map(
            static fn (FieldValidationFailure $failure): array => [
                'field'  => $failure->field,
                'code'   => $failure->code,
                'char'   => $failure->offendingChar,
                'action' => $action,
            ],
            $failures,
        );

        $this->session->setVariable(self::SESSION_KEY_PREFIX . $orderId, $entries);
    }

    /**
     * @return list<array{field: string, code: string, char: ?string, action: string}>
     */
    public function consume(string $orderId): array
    {
        $key = self::SESSION_KEY_PREFIX . $orderId;
        $stored = $this->session->getVariable($key);
        $this->session->setVariable($key, null);

        if (!is_array($stored)) {
            return [];
        }

        /** @var array<array{field: string, code: string, char: ?string, action: string}> $stored */
        return array_values($stored);
    }
}
