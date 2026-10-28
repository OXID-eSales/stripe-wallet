<?php

// STRP-176 spike: in a fresh process, unserialize the basket and render the customer mail without sending.
require '/var/www/source/bootstrap.php';

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;

class CaptureEmail extends \OxidEsales\Eshop\Core\Email
{
    public array $captured = [];

    public function send()
    {
        $this->captured[] = [$this->getSubject(), $this->getBody()];
        $this->clearAllRecipients();

        return true;
    }
}

$lang = (int) $argv[1];
$voucherNr = $argv[2];
Registry::getLang()->setBaseLanguage($lang);
Registry::getLang()->setTplLanguage($lang);

$basket = unserialize(base64_decode(stream_get_contents(STDIN)));
$summary = [
    'total' => $basket->getPrice()->getBruttoPrice(),
    'del' => $basket->getDeliveryCost()?->getBruttoPrice(),
    'voucher' => $basket->getVoucherDiscount()?->getBruttoPrice(),
    'vouchers' => count($basket->getVouchers()),
    'vats' => $basket->getProductVats(false),
    'items' => count($basket->getContents()),
    'brut' => $basket->getBruttoSum(),
];

$order = oxNew(Order::class);
$order->load('d4e2d9fc1653a645d07ef091f56473f6');
(function ($basket) {
    $this->_oUser = $this->getOrderUser();
    $this->_oBasket = $basket;
    $this->_oPayment = $this->getPaymentType();
    // Same shape markVouchers() leaves behind (Voucher models), without marking them used again.
    $this->_aVoucherList = [];
    foreach (array_keys($basket->getVouchers()) as $voucherId) {
        $voucher = oxNew(\OxidEsales\Eshop\Application\Model\Voucher::class);
        $voucher->load($voucherId);
        $this->_aVoucherList[$voucherId] = $voucher;
    }
})->call($order, $basket);

$mail = new CaptureEmail();
$mail->sendOrderEmailToUser($order);
[$subject, $body] = $mail->captured[0];
$lang = Registry::getLang();
$total = $lang->formatCurrency($basket->getPrice()->getBruttoPrice(), $basket->getBasketCurrency());
$voucher = $lang->formatCurrency($basket->getVoucherDiscount()->getBruttoPrice(), $basket->getBasketCurrency());

echo json_encode([
    'lang' => $argv[1],
    'unserialized' => $summary,
    'subject' => $subject,
    'bodyHasTotal' => str_contains($body, $total),
    'total' => $total,
    'bodyHasVoucherAmount' => str_contains($body, $voucher),
    'voucher' => $voucher,
    'bodyHasVoucherNr' => str_contains($body, $voucherNr),
], JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents('/tmp/strp176-body-' . $argv[1] . '.html', $body);
