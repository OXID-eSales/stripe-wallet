<?php

// STRP-176 spike: build a basket with a voucher the way checkout does, print it serialized.
require '/var/www/source/bootstrap.php';

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;

$user = oxNew(User::class);
$user->load('2e70fd3e6a8a38c84f6e6afb2497685b');
Registry::getSession()->setUser($user);

$basket = oxNew(Basket::class);
$basket->enableSaveToDataBase(false);
$basket->setBasketUser($user);
$basket->addToBasket('066e3ce119c43c81cc0e46d4f1681eed', 2);
// 34 x 1200 clears the "Test" voucher series' 40,000 minimum order value.
$basket->addToBasket('0757c381b5c2efea14b10d34822c67ed', 34);
$basket->setPayment('oxidinvoice');
$basket->calculateBasket(true);
$basket->addVoucher($argv[1]);
$basket->calculateBasket(true);

$summary = [
    'total' => $basket->getPrice()->getBruttoPrice(),
    'del' => $basket->getDeliveryCost()?->getBruttoPrice(),
    'voucher' => $basket->getVoucherDiscount()?->getBruttoPrice(),
    'vouchers' => count($basket->getVouchers()),
    'vats' => $basket->getProductVats(false),
    'items' => count($basket->getContents()),
    'brut' => $basket->getBruttoSum(),
];
fwrite(STDERR, json_encode(['built' => $summary]) . "\n");
echo base64_encode(serialize($basket));
