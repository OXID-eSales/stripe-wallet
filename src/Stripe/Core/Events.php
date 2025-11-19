<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Core;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;

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
        self::addPaymentMethods();
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
     * Get all available stripe payment methods from payment helper
     *
     * @return array<string, string>
     */
    protected static function getStripePaymentMethods()
    {
        /** @var array<string, string> */
        return Payment::getInstance()->getStripePaymentMethods();
    }

    /**
     * Adding Stripe payments.
     *
     * @return void
     */
    protected static function addPaymentMethods()
    {
        foreach (self::getStripePaymentMethods() as $sPaymentId => $sPaymentTitle) {
            self::addPaymentMethod($sPaymentId, $sPaymentTitle);
        }
    }

    /**
     * Add payment-methods and a basic configuration to the database
     *
     * @param string $sPaymentId
     * @param string $sPaymentTitle
     * @return void
     */
    protected static function addPaymentMethod($sPaymentId, $sPaymentTitle)
    {
        $blNewlyAdded = self::insertRowIfNotExists('oxpayments', array('OXID' => $sPaymentId), "INSERT INTO oxpayments(OXID,OXACTIVE,OXDESC,OXADDSUM,OXADDSUMTYPE,OXFROMBONI,OXFROMAMOUNT,OXTOAMOUNT,OXVALDESC,OXCHECKED,OXDESC_1,OXVALDESC_1,OXDESC_2,OXVALDESC_2,OXDESC_3,OXVALDESC_3,OXLONGDESC,OXLONGDESC_1,OXLONGDESC_2,OXLONGDESC_3,OXSORT) VALUES ('{$sPaymentId}', 0, '{$sPaymentTitle}', 0, 'abs', 0, 0, 1000000, '', 0, '{$sPaymentTitle}', '', '', '', '', '', '', '', '', '', 0);");

        if ($blNewlyAdded === true) {
            //Insert basic payment method configuration
            foreach (self::$aGroupsToAdd as $sGroupId) {
                DatabaseProvider::getDb()->Execute("INSERT INTO oxobject2group(OXID,OXSHOPID,OXOBJECTID,OXGROUPSID) values (REPLACE(UUID(),'-',''), :shopid, :paymentid, :groupid);", [
                    ':shopid' => Registry::getConfig()->getShopId(),
                    ':paymentid' => $sPaymentId,
                    ':groupid' => $sGroupId,
                ]);
            }

            self::insertRowIfNotExists('oxobject2payment', array('OXPAYMENTID' => $sPaymentId, 'OXTYPE' => 'oxdelset'), "INSERT INTO oxobject2payment(OXID,OXPAYMENTID,OXOBJECTID,OXTYPE) values (REPLACE(UUID(),'-',''), :paymentid, 'oxidstandard', 'oxdelset');", [':paymentid' => $sPaymentId]);
        }
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
     * Deactivates Stripe paymethods on module deactivation.
     *
     * @return void
     */
    protected static function deactivatePaymentMethods()
    {
    }
}
