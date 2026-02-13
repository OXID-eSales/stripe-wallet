<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Event;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;

class ProductFeedRequestEvent implements EventInterface
{
    public function __construct(private readonly EventContext $context)
    {
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }
}
