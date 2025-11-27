<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Core;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Stripe\Service\StaticContent;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;

/**
 * Activation and deactivation handler
 */
class Events
{
    /**
     * Lists of all custom-groups to add the payment-methods to
     *
     * @var array<string>
     */
    public static $aGroupsToAdd = array(
        'oxidadmin',
        'oxidcustomer',
        'oxiddealer',
        'oxidforeigncustomer',
        'oxidgoodcust',
        'oxidmiddlecust',
        'oxidnewcustomer',
        'oxidnewsletter',
        'oxidnotyetordered',
        'oxidpowershopper',
        'oxidpricea',
        'oxidpriceb',
        'oxidpricec',
        'oxidsmallcust',
    );

    /**
     * Standard checkout payment methods configuration
     * @deprecated Use StripeDefinitions::getStripeDefinitions() instead
     *
     * @var array<string, array<string, mixed>>
     */
    public static $aStandardCheckoutPaymentMethods = [];

    /**
     * List of all removed payment methods
     *
     * @var array<string>
     */
    public static $aRemovedPaymentMethods = array(
        'stripepaypal'
    );

    /**
     * Execute action on activate event.
     *
     * @return void
     */
    public static function onActivate(): void
    {
        self::addDatabaseStructure();
        self::addStandardCheckoutTables();
        self::ensureStripePaymentMethods();
        self::deleteRemovedPaymentMethods();
        self::regenerateViews();
        self::clearTmp();
    }

    /**
     * Execute action on deactivate event.
     *
     * @return void
     */
    public static function onDeactivate()
    {
        if (Registry::getConfig()->isAdmin()) { // onDeactivate is triggered in the apply-configuration console command which should not deactivate the payment methods
            self::deactivatePaymentMethods();
            self::clearTmp();
        }
    }

    /**
     * Regenerates database view-tables.
     *
     * @return void
     */
    protected static function regenerateViews()
    {
        /** @var \OxidEsales\Eshop\Application\Model\Shop $oShop */
        $oShop = oxNew('oxShop');
        $oShop->generateViews();
    }

    /**
     * Clear cache.
     *
     * @return void
     */
    protected static function clearTmp()
    {
        shell_exec(VENDOR_PATH . 'bin/oe-console oe:cache:clear');
    }

    /**
     * Ensure all Stripe payment methods are installed using StaticContent service
     *
     * @return void
     */
    protected static function ensureStripePaymentMethods(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $queryBuilderFactory = $container->get(\OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface::class);

        $staticContentService = new StaticContent($queryBuilderFactory);
        $staticContentService->ensureStripePaymentMethods();
    }

    /**
     * Deletes removed payment methods
     *
     * @return void
     */
    protected static function deleteRemovedPaymentMethods()
    {
        foreach (self::$aRemovedPaymentMethods as $sPaymentId) {
            self::deletePaymentMethod($sPaymentId);
        }
    }

    /**
     * Deletes payment method from the database
     *
     * @param  string $sPaymentId
     * @return void
     */
    protected static function deletePaymentMethod($sPaymentId)
    {
        DatabaseProvider::getDb()->Execute("DELETE FROM oxpayments WHERE oxid = ?", array($sPaymentId));
        // Note: PaymentConfig class needs to be implemented
        // Uncomment when PaymentConfig is available:
        // DatabaseProvider::getDb()->Execute("DELETE FROM " . PaymentConfig::$sTableName . " WHERE oxid = ?", array($sPaymentId));
    }

    /**
     * Add new tables and add columns to existing tables
     *
     * @return void
     */
    protected static function addDatabaseStructure()
    {
        //CREATE NEW TABLES
        // Note: PaymentConfig, RequestLog, and Cronjob classes need to be implemented
        // For now, we rely on Doctrine migrations to create the necessary tables
        // Uncomment these lines when the respective classes are available:
        // self::addTableIfNotExists(PaymentConfig::$sTableName, PaymentConfig::getTableCreateQuery());
        // self::addTableIfNotExists(RequestLog::$sTableName, RequestLog::getTableCreateQuery());
        // self::addTableIfNotExists(Cronjob::$sTableName, Cronjob::getTableCreateQuery());

        //ADD NEW COLUMNS
        self::addColumnIfNotExists('oxorder', 'STRIPEDELCOSTREFUNDED', "ALTER TABLE `oxorder` ADD COLUMN `STRIPEDELCOSTREFUNDED` DOUBLE NOT NULL DEFAULT '0';");
        self::addColumnIfNotExists('oxorder', 'STRIPEPAYCOSTREFUNDED', "ALTER TABLE `oxorder` ADD COLUMN `STRIPEPAYCOSTREFUNDED` DOUBLE NOT NULL DEFAULT '0';");
        self::addColumnIfNotExists('oxorder', 'STRIPEWRAPCOSTREFUNDED', "ALTER TABLE `oxorder` ADD COLUMN `STRIPEWRAPCOSTREFUNDED` DOUBLE NOT NULL DEFAULT '0';");
        self::addColumnIfNotExists('oxorder', 'STRIPEGIFTCARDREFUNDED', "ALTER TABLE `oxorder` ADD COLUMN `STRIPEGIFTCARDREFUNDED` DOUBLE NOT NULL DEFAULT '0';");
        self::addColumnIfNotExists('oxorder', 'STRIPEVOUCHERDISCOUNTREFUNDED', "ALTER TABLE `oxorder` ADD COLUMN `STRIPEVOUCHERDISCOUNTREFUNDED` DOUBLE NOT NULL DEFAULT '0';");
        self::addColumnIfNotExists('oxorder', 'STRIPEDISCOUNTREFUNDED', "ALTER TABLE `oxorder` ADD COLUMN `STRIPEDISCOUNTREFUNDED` DOUBLE NOT NULL DEFAULT '0';");
        self::addColumnIfNotExists('oxorder', 'STRIPEMODE', "ALTER TABLE `oxorder` ADD COLUMN `STRIPEMODE` VARCHAR(32) CHARSET utf8 COLLATE utf8_general_ci DEFAULT '' NOT NULL;");
        self::addColumnIfNotExists('oxorder', 'STRIPESECONDCHANCEMAILSENT', "ALTER TABLE `oxorder` ADD COLUMN `STRIPESECONDCHANCEMAILSENT` datetime NOT NULL default '0000-00-00 00:00:00';");
        self::addColumnIfNotExists('oxorder', 'STRIPEEXTERNALTRANSID', "ALTER TABLE `oxorder` ADD COLUMN `STRIPEEXTERNALTRANSID` VARCHAR(64) CHARSET utf8 COLLATE utf8_general_ci DEFAULT '' NOT NULL;");
        self::addColumnIfNotExists('oxorderarticles', 'STRIPEQUANTITYREFUNDED', "ALTER TABLE `oxorderarticles` ADD COLUMN `STRIPEQUANTITYREFUNDED` INT(11) NOT NULL DEFAULT '0';");
        self::addColumnIfNotExists('oxorderarticles', 'STRIPEAMOUNTREFUNDED', "ALTER TABLE `oxorderarticles` ADD COLUMN `STRIPEAMOUNTREFUNDED` DOUBLE NOT NULL DEFAULT '0';");

        $aShipmentSentQuery = ["UPDATE `oxorder` SET STRIPESHIPMENTHASBEENMARKED = 1 WHERE oxpaymenttype LIKE 'stripe%' AND oxsenddate > '1970-01-01 00:00:01';"];
        self::addColumnIfNotExists('oxorder', 'STRIPESHIPMENTHASBEENMARKED', "ALTER TABLE `oxorder` ADD COLUMN `STRIPESHIPMENTHASBEENMARKED` tinyint(1) UNSIGNED NOT NULL DEFAULT  '0';", $aShipmentSentQuery);

        self::addColumnIfNotExists('oxuser', 'STRIPECUSTOMERID', "ALTER TABLE `oxuser` ADD COLUMN `STRIPECUSTOMERID` VARCHAR(32) CHARSET utf8 COLLATE utf8_general_ci DEFAULT '' NOT NULL;");
    }

    /**
     * Add a database table.
     *
     * @param string $sTableName table to add
     * @param string $sQuery     sql-query to add table
     *
     * @return boolean true or false
     */
    protected static function addTableIfNotExists($sTableName, $sQuery)
    {
        $aTables = DatabaseProvider::getDb()->getAll("SHOW TABLES LIKE ?", array($sTableName));
        if (empty($aTables)) {
            DatabaseProvider::getDb()->Execute($sQuery);
            return true;
        }
        return false;
    }

    /**
     * Add a column to a database table.
     *
     * @param string $sTableName            table name
     * @param string $sColumnName           column name
     * @param string $sQuery                sql-query to add column to table
     * @param array<string>  $aNewColumnDataQueries  array of queries to execute when column was added
     *
     * @return boolean true or false
     */
    public static function addColumnIfNotExists($sTableName, $sColumnName, $sQuery, $aNewColumnDataQueries = array())
    {
        $aColumns = DatabaseProvider::getDb()->getAll("SHOW COLUMNS FROM {$sTableName} LIKE ?", array($sColumnName));
        if (empty($aColumns)) {
            try {
                DatabaseProvider::getDb()->Execute($sQuery);
                foreach ($aNewColumnDataQueries as $sQuery) {
                    DatabaseProvider::getDb()->Execute($sQuery);
                }
                return true;
            } catch (\Exception $e) {
                // do nothing as of yet
            }
        }
        return false;
    }

    /**
     * Insert a database row to an existing table.
     *
     * @param string $sTableName database table name
     * @param array<string, string>  $aKeyValue  keys of rows to add for existance check
     * @param string $sQuery     sql-query to insert data
     * @param array<string, mixed>  $aParams    sql-query insert parameters
     *
     * @return boolean true or false
     */
    protected static function insertRowIfNotExists($sTableName, $aKeyValue, $sQuery, $aParams = [])
    {
        $sCheckQuery = "SELECT * FROM {$sTableName} WHERE 1";
        foreach ($aKeyValue as $key => $value) {
            $sCheckQuery .= " AND $key = '" . (string)$value . "'";
        }

        if (!DatabaseProvider::getDb()->getOne($sCheckQuery)) { // row not existing yet?
            DatabaseProvider::getDb()->Execute($sQuery, $aParams);
            return true;
        }
        return false;
    }

    /**
     * Deactivates Stripe payment methods on module deactivation
     *
     * @return void
     */
    protected static function deactivatePaymentMethods(): void
    {
        // Optionally deactivate payment methods here if needed
    }

    /**
     * Create standard checkout database tables
     * ✅ Uses Component-compatible transaction table structure
     * ✅ Adds Stripe-specific details in separate table
     *
     * @return void
     */
    protected static function addStandardCheckoutTables()
    {
        // ✅ COMPONENT-COMPATIBLE: Create payment transaction table (shared across all payment methods)
        self::addTableIfNotExists('osc_payment_transaction', "
            CREATE TABLE `osc_payment_transaction` (
                `OXID` char(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'Transaction ID',
                `OXSHOPID` int(11) NOT NULL DEFAULT 1 COMMENT 'Shop ID',
                `OXORDERID` char(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'FK: oxorder.OXID',
                `OXCONTRACTID` char(32) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL COMMENT 'FK: osc_payment_contract.OXID (NULL for standard checkout)',
                `OXPROVIDER` varchar(50) NOT NULL COMMENT 'Payment provider (stripe, paypal, etc)',
                `OXPROVIDERORDERID` varchar(255) DEFAULT NULL COMMENT 'Provider order/payment ID (e.g., PaymentIntent ID)',
                `OXTRANSACTIONID` varchar(255) DEFAULT NULL COMMENT 'Provider transaction ID (e.g., Charge ID)',
                `OXTYPE` varchar(50) NOT NULL COMMENT 'Transaction type (payment, refund, authorization)',
                `OXSTATUS` varchar(50) NOT NULL COMMENT 'Transaction status (pending, completed, failed)',
                `OXAMOUNT` decimal(10,2) NOT NULL COMMENT 'Transaction amount',
                `OXCURRENCY` varchar(3) NOT NULL COMMENT 'Currency code (ISO 4217)',
                `OXPAYMENTMETHODID` varchar(255) DEFAULT NULL COMMENT 'Payment method ID',
                `OXPAYMENTMETHODTYPE` varchar(50) DEFAULT NULL COMMENT 'Payment method type',
                `OXPARENTTRANSACTIONID` varchar(255) DEFAULT NULL COMMENT 'Parent transaction ID (for refunds)',
                `OXCREATED` datetime NOT NULL COMMENT 'Created timestamp',
                `OXUPDATED` datetime NOT NULL COMMENT 'Updated timestamp',
                PRIMARY KEY (`OXID`),
                KEY `IDX_SHOPID` (`OXSHOPID`),
                KEY `IDX_ORDERID` (`OXORDERID`),
                KEY `IDX_CONTRACTID` (`OXCONTRACTID`),
                KEY `IDX_PROVIDERORDERID` (`OXPROVIDERORDERID`),
                KEY `IDX_TRANSACTIONID` (`OXTRANSACTIONID`),
                KEY `IDX_TYPE_STATUS` (`OXTYPE`, `OXSTATUS`),
                KEY `IDX_CREATED` (`OXCREATED`),
                CONSTRAINT `FK_TRANSACTION_ORDER`
                    FOREIGN KEY (`OXORDERID`)
                    REFERENCES `oxorder` (`OXID`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Component payment transactions (provider-agnostic)';
        ");

        // ➕ STRIPE-SPECIFIC: Payment details table (card info, 3DS, risk)
        self::addTableIfNotExists('osc_stripe_payment_details', "
            CREATE TABLE `osc_stripe_payment_details` (
                `OXID` char(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'Primary key',
                `OXTRANSACTIONID` char(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'FK: osc_payment_transaction.OXID',
                `OXCARDLAST4` varchar(4) DEFAULT NULL COMMENT 'Last 4 digits of card',
                `OXCARDBRAND` varchar(20) DEFAULT NULL COMMENT 'Card brand (visa, mastercard, amex)',
                `OXCARDEXPMONTH` varchar(2) DEFAULT NULL COMMENT 'Card expiration month',
                `OXCARDEXPYEAR` varchar(4) DEFAULT NULL COMMENT 'Card expiration year',
                `OXCARDFUNDING` varchar(20) DEFAULT NULL COMMENT 'Card funding (credit, debit, prepaid)',
                `OXCARDCOUNTRY` varchar(2) DEFAULT NULL COMMENT 'Card country code',
                `OX3DSECURE` tinyint(1) DEFAULT 0 COMMENT '3D Secure used (0=no, 1=yes)',
                `OX3DSVERSION` varchar(10) DEFAULT NULL COMMENT '3DS version (1.0, 2.0)',
                `OX3DSAUTHENTICATED` tinyint(1) DEFAULT NULL COMMENT '3DS authentication result',
                `OXRISKSCORE` int(3) DEFAULT NULL COMMENT 'Risk score (0-100)',
                `OXRISKLEVEL` varchar(20) DEFAULT NULL COMMENT 'Risk level (normal, elevated, highest)',
                `OXMETADATA` text COMMENT 'Additional metadata (JSON)',
                `OXCREATED` datetime NOT NULL COMMENT 'Created timestamp',
                `OXUPDATED` datetime DEFAULT NULL COMMENT 'Updated timestamp',
                PRIMARY KEY (`OXID`),
                UNIQUE KEY `UNQ_TRANSACTION` (`OXTRANSACTIONID`),
                CONSTRAINT `FK_DETAILS_TRANSACTION`
                    FOREIGN KEY (`OXTRANSACTIONID`)
                    REFERENCES `osc_payment_transaction` (`OXID`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Stripe-specific payment details';
        ");

        // ➕ STANDARD CHECKOUT: Payment order state table (authorization, capture, refund tracking)
        self::addTableIfNotExists('osc_payment_order_state', "
            CREATE TABLE `osc_payment_order_state` (
                `OXID` char(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'Primary key',
                `OXSHOPID` int(11) NOT NULL DEFAULT 1 COMMENT 'Shop ID',
                `OXORDERID` char(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'FK: oxorder.OXID',
                `OXPAYMENTSTATE` varchar(50) NOT NULL DEFAULT 'pending' COMMENT 'Payment state (pending, authorized, paid, refunded)',
                `OXPAYMENTMETHOD` varchar(50) NOT NULL COMMENT 'Payment method',
                `OXAUTHORIZED` tinyint(1) DEFAULT 0 COMMENT 'Payment authorized',
                `OXAUTHORIZEDAMOUNT` decimal(10,2) DEFAULT NULL COMMENT 'Authorized amount',
                `OXAUTHORIZEDAT` datetime DEFAULT NULL COMMENT 'Authorization timestamp',
                `OXCAPTURED` tinyint(1) DEFAULT 0 COMMENT 'Payment captured',
                `OXCAPTUREDAMOUNT` decimal(10,2) DEFAULT NULL COMMENT 'Captured amount',
                `OXCAPTUREDAT` datetime DEFAULT NULL COMMENT 'Capture timestamp',
                `OXREFUNDED` tinyint(1) DEFAULT 0 COMMENT 'Payment refunded',
                `OXREFUNDEDAMOUNT` decimal(10,2) DEFAULT 0.00 COMMENT 'Total refunded amount',
                `OXREFUNDEDAT` datetime DEFAULT NULL COMMENT 'Last refund timestamp',
                `OXCREATED` datetime NOT NULL COMMENT 'Created timestamp',
                `OXUPDATED` datetime DEFAULT NULL COMMENT 'Updated timestamp',
                PRIMARY KEY (`OXID`),
                UNIQUE KEY `UNQ_ORDERID` (`OXORDERID`),
                KEY `IDX_SHOPID` (`OXSHOPID`),
                KEY `IDX_PAYMENTSTATE` (`OXPAYMENTSTATE`),
                CONSTRAINT `FK_STATE_ORDER`
                    FOREIGN KEY (`OXORDERID`)
                    REFERENCES `oxorder` (`OXID`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Payment state per order (1:1 relationship)';
        ");

        // ➕ STRIPE-SPECIFIC: Customer mapping table (OXID user → Stripe customer)
        self::addTableIfNotExists('osc_stripe_customer_mapping', "
            CREATE TABLE `osc_stripe_customer_mapping` (
                `OXID` char(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'Primary key',
                `OXSHOPID` int(11) NOT NULL DEFAULT 1 COMMENT 'Shop ID',
                `OXUSERID` char(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'FK: oxuser.OXID',
                `OXSTRIPECUSTOMERID` varchar(255) NOT NULL COMMENT 'Stripe Customer ID (cus_xxxxx)',
                `OXCREATED` datetime NOT NULL COMMENT 'Created timestamp',
                `OXUPDATED` datetime DEFAULT NULL COMMENT 'Updated timestamp',
                PRIMARY KEY (`OXID`),
                UNIQUE KEY `UNQ_USERID` (`OXUSERID`),
                KEY `IDX_STRIPECUSTOMERID` (`OXSTRIPECUSTOMERID`),
                KEY `IDX_SHOPID` (`OXSHOPID`),
                CONSTRAINT `FK_CUSTOMER_USER`
                    FOREIGN KEY (`OXUSERID`)
                    REFERENCES `oxuser` (`OXID`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Stripe customer mapping (provider-specific)';
        ");

        // ➕ SHARED: Webhook log table (all payment providers)
        self::addTableIfNotExists('osc_payment_webhook_log', "
            CREATE TABLE `osc_payment_webhook_log` (
                `OXID` char(32) CHARACTER SET latin1 COLLATE latin1_general_ci NOT NULL COMMENT 'Primary key',
                `OXSHOPID` int(11) NOT NULL DEFAULT 1 COMMENT 'Shop ID',
                `OXEVENTID` varchar(255) NOT NULL COMMENT 'Webhook event ID (idempotency key)',
                `OXEVENTTYPE` varchar(100) NOT NULL COMMENT 'Event type (payment_intent.succeeded, etc)',
                `OXPROVIDER` varchar(50) NOT NULL COMMENT 'Payment provider (stripe, paypal, etc)',
                `OXPAYLOAD` mediumtext NOT NULL COMMENT 'Full webhook payload (JSON)',
                `OXSTATUS` varchar(50) NOT NULL DEFAULT 'received' COMMENT 'Processing status (received, processed, failed)',
                `OXERRORMESSAGE` text COMMENT 'Error message if processing failed',
                `OXCREATED` datetime NOT NULL COMMENT 'Received timestamp',
                `OXUPDATED` datetime DEFAULT NULL COMMENT 'Updated timestamp',
                PRIMARY KEY (`OXID`),
                UNIQUE KEY `UNQ_EVENTID` (`OXEVENTID`),
                KEY `IDX_SHOPID` (`OXSHOPID`),
                KEY `IDX_EVENTTYPE` (`OXEVENTTYPE`),
                KEY `IDX_PROVIDER` (`OXPROVIDER`),
                KEY `IDX_STATUS` (`OXSTATUS`),
                KEY `IDX_CREATED` (`OXCREATED`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Webhook event log (shared across providers)';
        ");
    }
}
