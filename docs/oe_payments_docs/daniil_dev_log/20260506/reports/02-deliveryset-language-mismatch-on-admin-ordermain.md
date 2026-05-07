# 02 — `oxv_oxdeliveryset_1_en.oxactive` "unknown column" on admin OrderMain

**Date:** 2026-05-06
**Author:** Daniil Tkachev
**Scope:** `extensions/one-page-checkout` (consumer-side fix); root
cause is in OXID core `DeliverySetList::getFilterSelect()`.

## Symptom

Log line:

```
[2026-05-06 16:19:33] OXID Logger.ERROR:
Unknown column 'oxv_oxdeliveryset_1_en.oxactive' in 'where clause'
```

…fired during admin **OrderMain → render → Order::getShippingSetList →
Basket::calculateBasket(true) → Basket::calcDeliveryCost →
DeliveryList::getDeliveryList → DeliverySetList**. The generated SQL
reads:

```sql
select oxv_oxdeliveryset_1_de.* from oxv_oxdeliveryset_1_de
where ( oxv_oxdeliveryset_1_en.oxactive = 1 or ... )
  and ( ... )
```

Note the FROM clause uses the `_de` view, the WHERE uses the `_en`
view. MySQL rightly rejects it (1054).

## Root cause

`Registry::get(DeliverySetList::class)` is a per-process **singleton**
(`source/Application/Model/DeliveryList.php:235`). Its lazily-cached
`_oBaseObject` (an `oxDeliverySet` model that `extends
MultiLanguageModel`) keeps whatever language was active when it was
first touched.

Core `DeliverySetList::getFilterSelect()`
(`source/Application/Model/DeliverySetList.php:124-129`) then mixes
two different view-name sources in a single SQL statement:

```php
$sTable = $tableViewNameGenerator->getViewName('oxdeliveryset');     // request lang
$sQ = "select $sTable.* from $sTable ";
$sQ .= "where " . $this->getBaseObject()->getSqlActiveSnippet() . ' '; // base-obj lang
```

`getSqlActiveSnippet()` in turn calls `$this->getViewName()` against
the base object's `_iLanguage`. As long as the request language never
changes during the lifetime of the singleton this is fine; the moment
something switches the language (admin "edit language" toggle,
`Registry::getLang()->setBaseLanguage(...)` from a deeper code path),
the FROM and WHERE halves of the SQL drift.

In the reported case the singleton had been touched first while the
EN locale was active, then admin OrderMain rendered the order page in
DE — the singleton still pointed to `oxv_..._en` for the active
filter, hence 1054.

## Fix

Consumer-side override in
`extensions/one-page-checkout/src/Application/Model/DeliverySetList.php`:

```php
protected function getFilterSelect($oUser, $sCountryId)
{
    $sCountryId = $this->extractCountryId($sCountryId);
    $this->syncBaseObjectLanguageWithRequest();
    return parent::getFilterSelect($oUser, $sCountryId);
}

protected function syncBaseObjectLanguageWithRequest(): void
{
    $baseObject = $this->getBaseObject();
    if (!is_object($baseObject) || !method_exists($baseObject, 'setLanguage')) {
        return;
    }
    $baseObject->setLanguage(Registry::getLang()->getBaseLanguage());
}
```

`MultiLanguageModel::setLanguage()` resets the model's `_sViewTable`,
so the next `getViewName()` call recomputes against the current
language. After the override both halves of the SQL resolve to the
same view.

The fix is intentionally consumer-side: OXID core's behaviour is not
modified, and OPC already has a `DeliverySetList` extension class
registered via `metadata.php`, so the override is reached for every
caller — including admin OrderMain, frontend order, and basket
recalculation paths.

## Reproduction (inline)

```php
Registry::getLang()->setBaseLanguage(1);              // EN
$list = Registry::get(DeliverySetList::class);
$list->getBaseObject();                                // caches _iLanguage = 1

Registry::getLang()->setBaseLanguage(0);              // switch to DE
// pre-fix: getFilterSelect() now returns SQL with _de FROM and _en WHERE
// post-fix: both halves use _de
```

## Live verification

```bash
docker compose exec php php vendor/bin/phpunit \
  -c extensions/one-page-checkout/tests/phpunit.xml \
  --testsuite Integration \
  --filter DeliverySetListLanguageMismatchTest
# OK (3 tests, 5 assertions)
```

The new test
`tests/Integration/Application/Model/DeliverySetListLanguageMismatchTest.php`
covers:

- EN → DE switch produces SQL with consistent `_de` views (regression).
- DE → EN switch is symmetric.
- The fixed SQL executes against the live DB without `42S22`.

## Files touched

```
M  source/extensions/one-page-checkout/src/Application/Model/DeliverySetList.php
A  source/extensions/one-page-checkout/tests/Integration/Application/Model/DeliverySetListLanguageMismatchTest.php
```

## Why this should also be reported upstream

The core `DeliverySetList::getFilterSelect()` is the actual offender
(it builds SQL with two un-synchronised view-name sources). A clean
upstream fix would be to either:

1. Pass `$sTable` into the active-snippet builder so both halves
   share the exact computed view (e.g. switch `getSqlActiveSnippet()`
   to a `($tableName)` overload; `Application/Model/DeliverySetList`
   passes the `$sTable` it just computed), or
2. Eagerly call `$this->getBaseObject()->setLanguage(... current ...)`
   inside `getFilterSelect()` itself.

Either fix removes the consumer-side workaround. Until then the OPC
override carries the fix locally.
