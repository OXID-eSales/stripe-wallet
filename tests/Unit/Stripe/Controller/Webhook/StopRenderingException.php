<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Webhook;

/**
 * Stands in for the exit() that sendErrorResponse() performs in production.
 */
final class StopRenderingException extends \RuntimeException
{
}
