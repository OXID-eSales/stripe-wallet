<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use Doctrine\DBAL\Connection;

/**
 * Reads `oxorder.oxtransid` without loading the order model (the admin API has no shop view or session).
 */
final class DbalOrderPaymentIntentIdReader implements OrderPaymentIntentIdReaderInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function intentIdOf(string $orderId): string
    {
        $intentId = $this->connection->fetchOne('SELECT OXTRANSID FROM oxorder WHERE OXID = ?', [$orderId]);

        return is_string($intentId) ? $intentId : '';
    }
}
