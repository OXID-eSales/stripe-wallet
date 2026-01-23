<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for updating order fields after refund.
 *
 * Sprint 10: Extracted from StripeRefundRequestHandler::updateOrderAfterRefund().
 *
 * @since 2.0.0
 */
final class OrderRefundUpdateService implements OrderRefundUpdateServiceInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function updateOrderAfterFullRefund(Order $order): void
    {
        $this->updateOrderCostFields($order);
        $this->updateOrderArticles($order);

        $order->save();

        $this->logger->info('Order updated after full refund', [
            'order_id' => $order->getId(),
        ]);
    }

    private function updateOrderCostFields(Order $order): void
    {
        /** @phpstan-ignore-next-line */
        $order->oxorder__stripedelcostrefunded = new Field($order->oxorder__oxdelcost->value);
        /** @phpstan-ignore-next-line */
        $order->oxorder__stripepaycostrefunded = new Field($order->oxorder__oxpaycost->value);
        /** @phpstan-ignore-next-line */
        $order->oxorder__stripewrapcostrefunded = new Field($order->oxorder__oxwrapcost->value);
        /** @phpstan-ignore-next-line */
        $order->oxorder__stripegiftcardrefunded = new Field($order->oxorder__oxgiftcardcost->value);
        /** @phpstan-ignore-next-line */
        $order->oxorder__stripevoucherdiscountrefunded = new Field($order->oxorder__oxvoucherdiscount->value);
        /** @phpstan-ignore-next-line */
        $order->oxorder__stripediscountrefunded = new Field($order->oxorder__oxdiscount->value);
    }

    private function updateOrderArticles(Order $order): void
    {
        foreach ($order->getOrderArticles() as $orderArticle) {
            /** @phpstan-ignore-next-line */
            $orderArticle->oxorderarticles__stripeamountrefunded = new Field(
                $orderArticle->oxorderarticles__oxbrutprice->value
            );
            $orderArticle->save();
        }
    }
}
