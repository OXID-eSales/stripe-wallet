<?php

// STRP-176 spike: rebuild the basket from a saved order and render both order mails without sending.
require '/var/www/source/bootstrap.php';

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;

class SpikeCaptureEmail extends \OxidEsales\Eshop\Core\Email
{
    public array $captured = [];

    public function send()
    {
        $this->captured[] = [
            'to' => $this->getRecipient(),
            'subject' => $this->getSubject(),
            'body' => $this->getBody(),
            'alt' => $this->getAltBody(),
        ];
        $this->clearAllRecipients();

        return true;
    }
}

$ids = array_slice($argv, 1);
$out = [];
foreach ($ids as $id) {
    foreach (['reload' => true, 'stored' => false] as $mode => $reload) {
        $order = oxNew(Order::class);
        if (!$order->load($id)) {
            $out[] = ['id' => $id, 'error' => 'not found'];
            continue 2;
        }
        $lang = (int) $order->getFieldData('oxlang');
        Registry::getLang()->setBaseLanguage($lang);
        Registry::getLang()->setTplLanguage($lang);
        $order->reloadDelivery($reload);
        $order->reloadDiscount($reload);

        $row = ['id' => $id, 'nr' => $order->getFieldData('oxordernr'), 'mode' => $mode, 'lang' => $lang,
            'payment' => $order->getFieldData('oxpaymenttype')];
        try {
            $basket = (function () {
                $basket = $this->getOrderBasket(false);
                $this->addOrderArticlesToBasket($basket, $this->getOrderArticles(true));
                $basket->calculateBasket(true);
                $this->_oUser = $this->getOrderUser();
                $this->_oBasket = $basket;
                $this->_oPayment = $this->getPaymentType();

                return $basket;
            })->call($order);

            $row['db'] = [
                'total' => (float) $order->getFieldData('oxtotalordersum'),
                'del' => (float) $order->getFieldData('oxdelcost'),
                'voucher' => (float) $order->getFieldData('oxvoucherdiscount'),
                'discount' => (float) $order->getFieldData('oxdiscount'),
                'paycost' => (float) $order->getFieldData('oxpaycost'),
                'vat1' => (float) $order->getFieldData('oxartvat1'),
                'brut' => (float) $order->getFieldData('oxtotalbrutsum'),
            ];
            $row['basket'] = [
                'total' => $basket->getPrice()->getBruttoPrice(),
                'del' => $basket->getDeliveryCost() ? $basket->getDeliveryCost()->getBruttoPrice() : null,
                'voucher' => $basket->getVoucherDiscount() ? $basket->getVoucherDiscount()->getBruttoPrice() : 0.0,
                'discounts' => count((array) $basket->getDiscounts()),
                'paycost' => $basket->getPaymentCost() ? $basket->getPaymentCost()->getBruttoPrice() : null,
                'vats' => $basket->getProductVats(false),
                'brut' => $basket->getBruttoSum(),
                'items' => count($basket->getContents()),
                'vouchers' => count($basket->getVouchers()),
            ];
            $row['voucherList'] = $order->getVoucherList() === null ? null : count($order->getVoucherList());

            $mail = new SpikeCaptureEmail();
            $row['userSent'] = $mail->sendOrderEmailToUser($order);
            $row['ownerSent'] = $mail->sendOrderEmailToOwner($order);
            $row['mails'] = array_map(fn ($m) => [
                'to' => $m['to'][0][0] ?? null,
                'subject' => $m['subject'],
                'bytes' => strlen($m['body']),
                'body' => $m['body'],
            ], $mail->captured);
        } catch (\Throwable $e) {
            $row['error'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
        }
        $out[] = $row;
    }
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
