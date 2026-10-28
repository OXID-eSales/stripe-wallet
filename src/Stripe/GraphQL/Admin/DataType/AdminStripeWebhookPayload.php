<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\DataType;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookResult;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * The answer of `adminStripeWebhookRegister` and `adminStripeWebhookClearAll`: the status afterwards (and for a
 * clear-all how many endpoints were deleted), or the error. The signing secret Stripe returns on creation is stored,
 * never returned.
 */
#[Type(name: 'AdminStripeWebhookPayload')]
final class AdminStripeWebhookPayload implements PayloadWithErrors
{
    /**
     * @param list<AdminError> $errors
     */
    private function __construct(
        private readonly ?AdminStripeWebhookStatus $status,
        private readonly ?int $deleted,
        private readonly array $errors,
    ) {
    }

    public static function of(StripeWebhookResult $result): self
    {
        $refusal = $result->refusal();
        if ($refusal !== null) {
            return new self(null, null, [StripeAdminErrors::from($refusal)]);
        }

        return new self(new AdminStripeWebhookStatus($result->status()), $result->deleted(), []);
    }

    #[Field]
    public function status(): ?AdminStripeWebhookStatus
    {
        return $this->status;
    }

    /** Only for a clear-all: the number of endpoints deleted at Stripe. */
    #[Field]
    public function deleted(): ?int
    {
        return $this->deleted;
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
