<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Service;

use OxidEsales\GraphQL\Base\Framework\NamespaceMapperInterface;

/**
 * Tells graphql-base where the Stripe checkout mutations live. The result
 * types are payment-base's (`Checkout*Result`), so no type namespace here.
 *
 * Only loaded when graphql-base is installed: the service is neither
 * autowired nor autoconfigured and its tag is consumed by graphql-base.
 *
 * @since 3.4.0
 */
final class NamespaceMapper implements NamespaceMapperInterface
{
    /**
     * @return array<string, string>
     */
    public function getControllerNamespaceMapping(): array
    {
        return [
            'OxidEsales\\Payments\\Stripe\\GraphQL\\Controller' => __DIR__ . '/../Controller/',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getTypeNamespaceMapping(): array
    {
        return [];
    }
}
