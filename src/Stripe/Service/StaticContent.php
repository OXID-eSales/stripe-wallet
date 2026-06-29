<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use PDO;
use Doctrine\DBAL\Query\QueryBuilder;
use OxidEsales\Eshop\Core\Registry as EshopRegistry;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Eshop\Application\Model\Payment as EshopModelPayment;
use OxidEsales\Eshop\Core\Model\BaseModel as EshopBaseModel;
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;

/**
 * Static Content Service
 *
 * Handles installation and management of Stripe payment methods during module activation.
 * Similar to PayPal's StaticContent service for consistency.
 */
class StaticContent implements StaticContentInterface
{
    public function __construct(
        private QueryBuilderFactoryInterface $queryBuilderFactory
    ) {
    }

    /**
     * Ensure all Stripe payment methods are created and configured
     *
     * @return void
     */
    public function ensureStripePaymentMethods(): void
    {
        foreach (StripeDefinitions::getStripeDefinitions() as $paymentId => $paymentDefinitions) {
            $paymentMethod = $this->makePaymentModel();
            if ($paymentMethod->load($paymentId)) {
                // Already installed: refresh the multilingual descriptions so
                // title and wording changes in StripeDefinitions propagate on
                // re-activation. oxactive, amount constraints and delivery-set
                // assignments are left untouched to preserve admin changes.
                $this->assignDescriptions($paymentId, $paymentDefinitions);
                continue;
            }

            $this->createPaymentMethod($paymentId, $paymentDefinitions);
            $this->assignPaymentToActiveDeliverySets($paymentId);
        }
    }

    /**
     * Factory seam for the OXID payment model.
     *
     * Isolated so tests can substitute a spy without a live database.
     *
     * @return EshopModelPayment
     */
    protected function makePaymentModel(): EshopModelPayment
    {
        /** @var EshopModelPayment $model */
        $model = oxNew(EshopModelPayment::class);
        return $model;
    }

    /**
     * Assign payment method to all active delivery sets
     *
     * @param string $paymentId
     * @return void
     */
    protected function assignPaymentToActiveDeliverySets(string $paymentId): void
    {
        $deliverySetIds = $this->getActiveDeliverySetIds();
        foreach ($deliverySetIds as $deliverySetId) {
            $this->assignPaymentToDelivery($paymentId, $deliverySetId);
        }
    }

    /**
     * Assign payment method to a specific delivery set
     *
     * @param string $paymentId
     * @param string $deliverySetId
     * @return void
     */
    protected function assignPaymentToDelivery(string $paymentId, string $deliverySetId): void
    {
        /** @var EshopBaseModel $object2Payment */
        $object2Payment = oxNew(EshopBaseModel::class);
        $object2Payment->init('oxobject2payment');
        $object2Payment->assign(
            [
                'oxpaymentid' => $paymentId,
                'oxobjectid'  => $deliverySetId,
                'oxtype'      => 'oxdelset'
            ]
        );
        $object2Payment->save();
    }

    /**
     * Create a payment method with multilingual support
     *
     * @param string $paymentId
     * @param array<string, mixed> $definitions
     * @return void
     */
    protected function createPaymentMethod(string $paymentId, array $definitions): void
    {
        $paymentModel = $this->makePaymentModel();
        $paymentModel->setId($paymentId);

        // Extract constraints
        /** @var array{oxfromamount?: float, oxtoamount?: float, oxaddsumtype?: string} $constraints */
        $constraints = is_array($definitions['constraints'] ?? null) ? $definitions['constraints'] : [];

        // Assign base payment data
        $paymentModel->assign(
            [
               'oxactive' => (bool) ($definitions['defaulton'] ?? false),
               'oxfromamount' => (float) ($constraints['oxfromamount'] ?? 0),
               'oxtoamount' => (float) ($constraints['oxtoamount'] ?? 1000000),
               'oxaddsumtype' => (string) ($constraints['oxaddsumtype'] ?? 'abs')
            ]
        );
        $paymentModel->save();

        $this->assignDescriptions($paymentId, $definitions);
    }

    /**
     * Write the multilingual oxdesc/oxlongdesc fields for a payment method.
     *
     * Idempotent — used both when first creating a method and when refreshing an
     * already-installed one, so StripeDefinitions stays the single source of truth
     * for payment titles and descriptions. Only the description fields are touched;
     * oxactive and constraints are left to the caller to preserve admin changes.
     *
     * @param string $paymentId
     * @param array<string, mixed> $definitions
     * @return void
     */
    protected function assignDescriptions(string $paymentId, array $definitions): void
    {
        /** @var array<int|string, int> $iso2LanguageId */
        $iso2LanguageId = array_flip($this->getLanguageIds());

        /** @var array<string, array{desc?: string, longdesc?: string}> $descriptions */
        $descriptions = is_array($definitions['descriptions'] ?? null) ? $definitions['descriptions'] : [];

        foreach ($descriptions as $langAbbr => $data) {
            if (!is_string($langAbbr) || !isset($iso2LanguageId[$langAbbr])) { // @phpstan-ignore function.alreadyNarrowedType
                continue;
            }

            $paymentModel = $this->makePaymentModel();
            if (!$paymentModel->loadInLang($iso2LanguageId[$langAbbr], $paymentId)) {
                continue;
            }

            $paymentModel->assign(
                [
                    'oxdesc' => $data['desc'] ?? '',
                    'oxlongdesc' => $data['longdesc'] ?? ''
                ]
            );
            $paymentModel->save();
        }
    }

    /**
     * Get all active delivery set IDs
     *
     * @return array<string>
     */
    protected function getActiveDeliverySetIds(): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->queryBuilderFactory->create();
        $statement = $queryBuilder
            ->select('oxid')
            ->from('oxdeliveryset')
            ->where('oxactive = 1')
            ->execute();

        /** @var array<int, array{oxid: string}> $fromDb */
        $fromDb = is_object($statement) ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];

        $result = [];
        foreach ($fromDb as $row) {
            $oxid = $row['oxid'];
            $result[$oxid] = $oxid;
        }

        return $result;
    }

    /**
     * Get the language IDs
     *
     * @return array<int, string> Language ID => Language Abbreviation
     */
    protected function getLanguageIds(): array
    {
        /** @var array<int, string> $ids */
        $ids = EshopRegistry::getLang()->getLanguageIds();
        return $ids;
    }
}
