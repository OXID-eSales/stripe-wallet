<h1 style="text-align: center">Stripe-Wallet for OXID eShop</h1>

## Developer Installation

´´´
git clone git@github.com:OXID-eSales/docker-eshop-sdk.git oxidshop --branch=b-8.0.x
cd oxidshop
git clone git@github.com:OXID-eSales/stripe-wallet.git stripe-install --recursive
./stripe-install/recipe/setup-twig-dev.sh

´´´´

After install is finished you may need to add 
```
error_reporting = E_ALL & ~E_WARNING & ~E_DEPRECATED
```

into ./containers/php/custom.ini

then do

```
make up
```
