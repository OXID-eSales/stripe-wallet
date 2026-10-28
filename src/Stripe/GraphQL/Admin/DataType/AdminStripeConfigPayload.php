<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\DataType;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigResult;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

#[Type(name: 'AdminStripeConfigPayload')]
final class AdminStripeConfigPayload implements PayloadWithErrors
{
    /**
     * @param list<AdminError> $errors
     */
    private function __construct(private readonly ?AdminStripeConfig $config, private readonly array $errors)
    {
    }

    public static function of(StripeConfigResult $result): self
    {
        $refusal = $result->refusal();
        if ($refusal !== null) {
            return new self(null, [StripeAdminErrors::from($refusal)]);
        }

        return new self(new AdminStripeConfig($result->config()), []);
    }

    #[Field]
    public function config(): ?AdminStripeConfig
    {
        return $this->config;
    }

    /**
     * @return AdminError[]
     */
    #[Field]
    public function errors(): array
    {
        return $this->errors;
    }
}
