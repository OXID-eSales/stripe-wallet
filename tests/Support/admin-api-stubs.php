<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * Stand-ins for the classes of oxid-admin-graphql (and the GraphQLite attributes of its operations) that the
 * Stripe admin API relies on. The admin API is optional for this module, so none of it is in the module's vendor:
 * the standalone unit suite and PHPStan load this file instead. Minimal, with the shape the module uses;
 * tests/Integration/GraphQL/Admin/AdminModuleParityTest proves it against the real classes (payment-base's
 * approach).
 */

declare(strict_types=1);

if (!interface_exists(\OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Admin\\Shared\\DataType\\Error; '
        . 'interface AdminError { public function message(): string; public function errorCode(): string; } '
        . 'abstract class AbstractAdminError implements AdminError { '
        . '  public function __construct(private string $code, private ?string $text = null) {} '
        . '  public function message(): string { return $this->text ?? $this->code; } '
        . '  public function errorCode(): string { return $this->code; } '
        . '} '
        . 'final class ValidationError extends AbstractAdminError { '
        . '  public function __construct(private string $field, private string $reason, ?string $message = null) { parent::__construct("validation_failed", $message); } '
        . '  public function field(): string { return $this->field; } '
        . '  public function code(): string { return $this->reason; } '
        . '} '
        . 'final class ConflictError extends AbstractAdminError { '
        . '  public function __construct(private string $expectedTimestamp, private string $actualTimestamp, ?string $message = null) { parent::__construct("conflict", $message); } '
        . '  public function expectedTimestamp(): string { return $this->expectedTimestamp; } '
        . '  public function actualTimestamp(): string { return $this->actualTimestamp; } '
        . '}'
    );
}
if (!interface_exists(\OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Admin\\Shared\\DataType\\Payload; '
        . 'interface PayloadWithErrors { public function errors(): array; }'
    );
}
if (!class_exists(\OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder::class, false)) {
    eval(
        'namespace OxidEsales\\GraphQL\\Admin\\Audit\\DataType; '
        . 'final class AuditTrail { '
        . '  public string $entityType = ""; public string $entityId = ""; public array $fields = []; public array $values = []; '
        . '  public function __construct(public readonly string $operation) {} '
        . '  public function on(string $entityType, string $entityId): self { $this->entityType = $entityType; $this->entityId = $entityId; return $this; } '
        . '  public function field(string ...$names): self { $this->fields = [...$this->fields, ...$names]; return $this; } '
        . '  public function value(string $name, bool|int|float|string|array $value): self { $this->values[$name] = $value; return $this; } '
        . '} '
        . 'namespace OxidEsales\\GraphQL\\Admin\\Audit\\Service; '
        . 'class AuditRecorder { '
        . '  public function auditedAdmin(string $operation, \\Closure $action): mixed { return $action(new \\OxidEsales\\GraphQL\\Admin\\Audit\\DataType\\AuditTrail($operation)); } '
        . '}'
    );
}

// The GraphQLite attributes of the admin operations; each declared only when no other bootstrap did.
$attributeStubs = [
    'Query' => '\\Attribute::TARGET_METHOD',
    'Mutation' => '\\Attribute::TARGET_METHOD',
    'Right' => '\\Attribute::TARGET_METHOD | \\Attribute::TARGET_PROPERTY',
    'ExtendType' => '\\Attribute::TARGET_CLASS',
    'Type' => '\\Attribute::TARGET_CLASS',
    'Input' => '\\Attribute::TARGET_CLASS',
    'Field' => '\\Attribute::TARGET_METHOD | \\Attribute::TARGET_PROPERTY',
];
foreach ($attributeStubs as $attribute => $targets) {
    if (!class_exists('TheCodingMachine\\GraphQLite\\Annotations\\' . $attribute, false)) {
        eval(
            'namespace TheCodingMachine\\GraphQLite\\Annotations; '
            . '#[\\Attribute(' . $targets . ')] class ' . $attribute . ' { public function __construct(mixed ...$args) {} }'
        );
    }
}
