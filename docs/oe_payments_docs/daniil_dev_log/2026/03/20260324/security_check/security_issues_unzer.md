# Security Audit: Unzer-Modul (osc/unzer) — OXID 6.5

**Datum:** 2026-03-05
**Scope:** `source/source/modules/osc/unzer`
**OXID Version:** 6.5 (PHP 7.4+, Smarty Templates)
**Fokus:** Frontend-Angriffsvektoren, Verifikation gegen OXID 7 Audit
**Verifiziert gegen Quellcode:** Ja

---

## Zusammenfassung

| Kategorie | Anzahl |
|-----------|--------|
| Kritisch | 1 |
| Hoch | 3 |
| Mittel | 3 |
| Niedrig | 3 |

**Gesamtbewertung:** Das Unzer-Modul hat einen **kritischen SSRF-Vektor** ueber die
Apple-Pay-Merchant-Validierung, einen **IDOR** beim Loeschen gespeicherter
Zahlungsmittel, und einen **Webhook-Dispatcher ohne Signaturpruefung**. In 6
Smarty-Templates wird `error.message` aus dem Unzer-JS-SDK via jQuery `.html()`
ungefiltert ins DOM geschrieben (DOM-XSS).

**Korrekturen gegenueber dem OXID 7 Audit:**
- Finding 1.1 (`unserialize` in TmpOrder) ist in OXID 6.5 **NICHT verwundbar** —
  der Code nutzt korrekt `FlexibleSerializer::safeUnserialize()` mit `allowed_classes`
- Finding 3.1 (`|raw` auf CMS-Content) existiert in OXID 6.5 **nicht** —
  die Smarty-Templates verwenden Standard-Escaping
- Finding 3.2 (SQL View-Name in TransactionList) existiert in OXID 6.5 **nicht** —
  die Klasse `TransactionList` ist nicht vorhanden, alle Queries nutzen Prepared Statements

---

## Legende

| Tag | Bedeutung |
|-----|-----------|
| `FRONTEND` | Vom Kunden (anonym oder eingeloggt) erreichbar |
| `BACKEND` | Nur aus dem Admin-Panel erreichbar |
| `WEBHOOK` | Von aussen erreichbar (Server-zu-Server Callback) |

---

## 1. Kritische Findings

### 1.1 SSRF via Apple Pay merchantValidationUrl — `FRONTEND`

**Datei:** `src/Controller/ApplePayCallbackController.php:22-28`
**Erreichbarkeit:** Frontend — AJAX-Call waehrend Apple-Pay-Checkout
**Frontend-Exploitability:** **Direkt ausnutzbar**
**Verifiziert:** Ja

```php
public function validateMerchant(): void
{
    $merchValidUrlRaw = $this->getUnzerStringRequestEscapedParameter('merchantValidationUrl');
    $merchValidUrl = urldecode($merchValidUrlRaw);

    $validationResponse = $this
        ->getServiceFromContainer(ApplePaySessionHandler::class)
        ->validateMerchant($merchValidUrl);
```

**Risiko:** Der Kunde sendet eine beliebige URL als `merchantValidationUrl`. Der Server
ruft diese URL ueber das Unzer SDK ab (`UnzerSDK ApplepayAdapter`). Es gibt:
- **Keine Domain-Whitelist** (nur `apple-pay-gateway.apple.com` waere erlaubt)
- **Kein HTTPS-Zwang**
- **Keine Redirect-Validierung**

Ein Angreifer kann:
- Interne Services scannen (`http://localhost:9200`, `http://10.0.0.1/admin`)
- Cloud-Metadata auslesen (`http://169.254.169.254/latest/meta-data/iam/security-credentials/`)
- Interne Netzwerk-Ressourcen abfragen

**Empfehlung:**
```php
$allowedHosts = ['apple-pay-gateway.apple.com', 'apple-pay-gateway-nc-pod1.apple.com'];
$parsed = parse_url($merchValidUrl);
if (!isset($parsed['host']) || !in_array($parsed['host'], $allowedHosts, true)) {
    throw new \InvalidArgumentException('Invalid merchantValidationUrl');
}
```

---

## 2. Hohe Findings

### 2.1 Webhook-Dispatcher ohne Signaturpruefung — `WEBHOOK`

**Datei:** `src/Controller/DispatcherController.php:52-96`
**Erreichbarkeit:** Oeffentlich erreichbar via `?cl=unzer_dispatcher`
**Frontend-Exploitability:** **Direkt ausnutzbar** (jeder kann HTTP-Requests senden)
**Verifiziert:** Ja

```php
public function updatePaymentTransStatus(): void
{
    $jsonRequest = file_get_contents('php://input');
    // ...
    $aJson = $this->decodeJson($jsonRequest);
    $url = parse_url($aJson['retrieveUrl']);

    if ($this->isInvalidRequest($url, $typeid)) {
        $this->exitWithMessage("Invalid Webhook call");
        return;
    }
    // ... verarbeitet das Event
}
```

Die einzige Validierung (`isInvalidRequest`, Zeile 134-139):

```php
protected function isInvalidRequest(array $url, string $typeid): bool
{
    return $url['scheme'] !== "https" ||
        ($url['host'] !== "api.unzer.com" && $url['host'] !== "sbx-api.heidelpay.com") ||
        !$this->transaction->isValidTransactionTypeId($typeid);
}
```

**Vorhandener Schutz:**
- URL-Schema muss HTTPS sein
- Host muss `api.unzer.com` oder `sbx-api.heidelpay.com` sein
- TypeID muss in der DB existieren

**Fehlender Schutz:**
- Keine kryptografische Signaturpruefung (Unzer bietet keine an)
- Kein Shared Secret / Bearer Token
- Kein IP-Whitelisting
- Kein Replay-Schutz (Timestamp-Validierung)

**Risiko:** Ein Angreifer kann gefaelschte Webhook-Events senden, solange er eine
gueltige TypeID erraten oder ermitteln kann. Die Domain-Validierung schuetzt nicht
vor einem Angreifer, der den Request selbst craftet — die `retrieveUrl` wird zwar
gegen `api.unzer.com` geprueft, aber der Webhook-Body selbst wird verarbeitet
bevor die retrieveUrl abgerufen wird.

**Empfehlung:**
- Zahlungsstatus nach Webhook **nochmals direkt ueber Unzer-API verifizieren**
  (die `retrieveUrl` reicht nicht — der gesamte Payment-Status sollte abgeglichen werden)
- IP-Whitelisting fuer bekannte Unzer-IP-Ranges

---

### 2.2 IDOR bei gespeicherten Zahlungsmitteln — `FRONTEND`

**Datei:** `src/Controller/AccountSavedPaymentController.php:48-59`
**Erreichbarkeit:** Frontend — eingeloggte Kunden
**Frontend-Exploitability:** **Direkt ausnutzbar**
**Verifiziert:** Ja

```php
public function deletePayment(): void
{
    $savedPaymentUserId = $this->getUnzerStringRequestParameter('savedPaymentUserId');
    $loadService = $this->getServiceFromContainer(SavedPaymentLoadService::class);
    $transactionsIds = $loadService->getSavedPaymentTransactionsByUserId(
        $savedPaymentUserId
    );

    if (count($transactionsIds) > 0) {
        $this->getServiceFromContainer(SavedPaymentSaveService::class)
            ->unsetSavedPayments($transactionsIds);
    }
}
```

Die zugehoerige SQL-Query (`SavedPaymentLoadService.php:74-85`) filtert ausschliesslich
nach `savedPaymentUserId` aus dem Request — **ohne Abgleich mit dem eingeloggten User**:

```sql
SELECT transactionBeforeOrder.OXID
FROM oscunzertransaction as transactionBeforeOrder
INNER JOIN oscunzertransaction as transactionAfterOrder
    ON transactionBeforeOrder.SHORTID = transactionAfterOrder.SHORTID
WHERE transactionAfterOrder.SAVEPAYMENTUSERID = :savedPaymentUserId
AND transactionBeforeOrder.SAVEPAYMENT = 1
```

**Risiko:** Ein eingeloggter Benutzer kann die `savedPaymentUserId` eines **anderen
Benutzers** angeben und dessen gespeicherte Zahlungsmittel loeschen. Es fehlt die
Pruefung, ob der aktuelle User Eigentuemer des Zahlungsmittels ist (IDOR).

Zusaetzlich fehlt in der zugehoerigen Template-Form **der CSRF-Token** (`stoken`):

```smarty
<form name="uzr" id="uzr_collect" action="[{$oViewConf->getSelfLink()}]" method="post">
    <input type="hidden" name="cl" value="unzer_saved_payments">
    <input type="hidden" name="fnc" value="deletePayment">
    <input type="hidden" name="savedPaymentUserId" value="[{$savedPaymentUserId}]">
    <!-- KEIN stoken-Feld! -->
    <button type="submit">[{oxmultilang ident="DD_DELETE"}]</button>
</form>
```

**Kombination IDOR + fehlender CSRF = kritisch:** Ein Angreifer kann einem beliebigen
eingeloggten Nutzer per CSRF-Link die Zahlungsmittel eines dritten Nutzers loeschen.

**Empfehlung:**
```php
$userId = Registry::getSession()->getUser()->getId();
if ($savedPaymentUserId !== $userId) {
    throw new \OxidEsales\Eshop\Core\Exception\AccessRightException();
}
```
Plus `stoken`-Feld im Template hinzufuegen und im Controller pruefen.

---

### 2.3 DOM-XSS in Payment-Templates via jQuery .html() — `FRONTEND`

**Erreichbarkeit:** Frontend — Checkout-Seite
**Frontend-Exploitability:** Ausnutzbar bei manipulierter SDK-Response
**Verifiziert:** Ja — 6 betroffene Templates

Betroffene Dateien (alle in `views/frontend/tpl/order/`):

| Datei | Zeile | Pattern |
|-------|-------|---------|
| `unzer_sepa.tpl` | 187 | `$('#error-holder').html(error.message)` |
| `unzer_installment_paylater.tpl` | 131 | `$('#error-holder').html(error.message)` |
| `unzer_ideal.tpl` | 42 | `$('#error-holder').html(error.message)` |
| `unzer_sepa_secured.tpl` | 117 | `$('#error-holder').html(error.message)` |
| `unzer_eps_charge.tpl` | 43 | `$('#error-holder').html(error.message)` |
| `unzer_installment.tpl` | 125 | `$('#error-holder').html(error.message)` |

Alle verwenden dasselbe Pattern:

```javascript
.catch(function(error) {
    $('#error-holder').html(error.message);
});
```

**Positiv:** `unzer_invoice.tpl:205` nutzt sicheres `.innerText`:
```javascript
document.getElementById('error-holder').innerText = error.customerMessage || error.message || 'Error';
```

**Risiko:** `error.message` aus dem Unzer-JS-SDK wird via jQuery `.html()` ins DOM
geschrieben. Falls das SDK eine Fehlermeldung mit HTML/Script-Tags zurueckgibt
(MITM, kompromittiertes CDN, XSS im SDK), wird JavaScript im Browser des Kunden
ausgefuehrt.

**Empfehlung:** `.text()` statt `.html()` verwenden:
```javascript
$('#error-holder').text(error.message);
```

---

## 3. Mittlere Findings

### 3.1 Race Condition bei Webhook-/Order-Finalisierung — `FRONTEND`/`WEBHOOK`

**Datei:** `src/Controller/DispatcherController.php:248-269`
**Verifiziert:** Ja

```php
private function handleTmpOrder(Payment $unzerPayment): string
{
    $tmpOrder = oxNew(TmpOrder::class);
    $orderId = $unzerPayment->getBasket() ? $unzerPayment->getBasket()->getOrderId() : '';
    $tmpData = $tmpOrder->getTmpOrderByUnzerId($orderId);   // READ

    if (isset($tmpData['OXID']) && $tmpOrder->load($tmpData['OXID'])
        && $this->hasExceededTimeLimit($tmpOrder)            // CHECK
    ) {
        return $this->finalizeTmpOrder(...);                  // WRITE
    }
}
```

**Risiko:** Kein Datenbank-Lock (SELECT FOR UPDATE) oder Mutex. Bei gleichzeitigem
Webhook-Callback und Frontend-Redirect koennen:
- Doppelte Captures entstehen
- Bestellstatus inkonsistent gesetzt werden

**Empfehlung:** `SELECT FOR UPDATE` oder Mutex bei Order-Finalisierung.

---

### 3.2 Admin DOM-XSS in order_list Template — `BACKEND`

**Datei:** `views/admin/blocks/admin_order_list_item.tpl:7`
**Verifiziert:** Ja

```javascript
unzer_order[0].innerHTML = "[{$listitem->oxorder__oxordernr->value}] ...
    [{$listitem->oxorder__oxunzerordernr->value}]";
```

**Risiko:** Smarty-Variablen (`oxordernr`, `oxunzerordernr`) werden via `innerHTML`
ins DOM geschrieben. Bei manipulierten DB-Werten (z.B. durch gefaelschte Webhooks)
Stored XSS im Admin-Panel moeglich.

**Empfehlung:** `textContent` statt `innerHTML`, oder Smarty-Variablen mit
`|escape:'javascript'` filtern.

---

### 3.3 php://input ohne Laengenbegrenzung — `WEBHOOK`

**Datei:** `src/Controller/DispatcherController.php:54`
**Verifiziert:** Ja

```php
$jsonRequest = file_get_contents('php://input');
```

**Risiko:** Kein Limit auf Request-Body-Groesse. DoS-Vektor bei fehlkonfiguriertem
`post_max_size`.

**Empfehlung:** `file_get_contents('php://input', false, null, 0, 1048576)` (1 MB Limit).

---

## 4. Niedrige Findings

### 4.1 MD5 als Transaction-ID-Generator — `FRONTEND`

**Datei:** `src/Service/Transaction.php:211-217`
**Verifiziert:** Ja

```php
protected function prepareTransactionOxid(array $params): string
{
    unset($params['oxactiondate'], $params['serialized_basket'], $params['customertype']);
    $jsonEncode = json_encode($params, JSON_THROW_ON_ERROR);
    return md5($jsonEncode);
}
```

**Risiko:** MD5 ist kryptografisch gebrochen. Wird hier als Deduplizierungs-Hash
verwendet (identische Params → identische ID). Funktional ausreichend, aber nicht
Best Practice.

**Empfehlung:** `hash('sha256', $jsonEncode)` oder UUID v4.

---

### 4.2 Fehlende CSRF-Pruefung bei deletePayment — `FRONTEND`

Siehe Finding 2.2 — das Template `account_saved_payments.tpl:26-31` enthaelt
**kein `stoken`-Feld** im Formular. In Kombination mit dem IDOR ergibt sich ein
erhoehtes Risiko.

---

### 4.3 urldecode() auf bereits decodierten Parameter — `FRONTEND`

**Datei:** `src/Controller/ApplePayCallbackController.php:23`
**Verifiziert:** Ja

```php
$merchValidUrlRaw = $this->getUnzerStringRequestEscapedParameter('merchantValidationUrl');
$merchValidUrl = urldecode($merchValidUrlRaw);
```

**Risiko:** `getRequestEscapedParameter` decoded den Wert bereits. Ein zusaetzliches
`urldecode()` ermoeglicht Double-Encoding-Angriffe, die URL-Validierungen umgehen
koennten (z.B. `%2568ttp://evil.com` → `%68ttp://evil.com` → `http://evil.com`).
Verstaerkt das SSRF-Risiko aus Finding 1.1.

---

## 5. Korrekturen gegenueber dem OXID 7 Audit

| OXID 7 Finding | Status in OXID 6.5 | Begruendung |
|----------------|--------------------|-----------------------|
| 1.1 `unserialize()` in TmpOrder | **NICHT VERWUNDBAR** | OXID 6.5 nutzt `FlexibleSerializer::safeUnserialize()` mit `allowed_classes: [Order::class, Field::class]`. Der Code ist sicher. |
| 3.1 `\|raw` auf CMS-Content | **EXISTIERT NICHT** | Smarty-Templates nutzen Standard-Escaping. Kein `\|raw`-Aequivalent gefunden. |
| 3.2 SQL View-Name in TransactionList | **EXISTIERT NICHT** | Die Klasse `TransactionList` ist in OXID 6.5 nicht vorhanden. Alle DB-Queries nutzen korrekt Prepared Statements. |

**Wichtig:** Falls Finding 1.1 im OXID 7 Modul tatsaechlich verwundbar ist
(andere Code-Version?), waere dies eine **Regression** gegenueber OXID 6.5,
wo der Code korrekt implementiert ist.

---

## 6. Frontend-Controller-Analyse

| Controller | Endpoint | User-Input | Auth | CSRF | Status |
|-----------|----------|-----------|------|------|--------|
| `ApplePayCallbackController` | `?cl=unzer_applepay&fnc=validateMerchant` | `merchantValidationUrl` | Nein | Nein | **KRITISCH (SSRF)** |
| `DispatcherController` | `?cl=unzer_dispatcher` | JSON Body (php://input) | **Nein** | **Nein** | **HOCH (Webhook)** |
| `AccountSavedPaymentController` | `?cl=unzer_saved_payments&fnc=deletePayment` | `savedPaymentUserId` | Login | **Nein** | **HOCH (IDOR+CSRF)** |
| `InstallmentController` | Session-basiert | Keine Request-Parameter | Session | — | Sicher |
| `PaymentController` | Extension | Session-basiert | Session | — | Sicher |
| `OrderController` | Extension | Session-basiert | Login | stoken | Sicher |
| `ThankYouController` | Extension | Read-only | Login | — | Sicher |

---

## 7. Empfehlungen nach Prioritaet

### Prioritaet 0 — Sofort beheben

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 1 | **SSRF Apple Pay** | `ApplePayCallbackController.php:22` | URL-Whitelist auf `apple-pay-gateway.apple.com` |
| 2 | **IDOR deletePayment** | `AccountSavedPaymentController.php:48` | User-Ownership-Check + `stoken`-Validierung |

### Prioritaet 1 — Zeitnah adressieren

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 3 | Webhook ohne Signatur | `DispatcherController.php:52` | Payment-Status nach Webhook nochmals via API verifizieren |
| 4 | DOM-XSS (6 Templates) | `views/frontend/tpl/order/*.tpl` | `.text()` statt `.html()` |
| 5 | Admin DOM-XSS | `admin_order_list_item.tpl:7` | `textContent` statt `innerHTML` |

### Prioritaet 2 — Mittelfristig

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 6 | Race Condition | `DispatcherController.php:248` | DB-Lock bei Order-Finalisierung |
| 7 | php://input Limit | `DispatcherController.php:54` | Laengenbegrenzung setzen |
| 8 | Double urldecode | `ApplePayCallbackController.php:23` | Redundantes `urldecode()` entfernen |

### Prioritaet 3 — Nice to have

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 9 | MD5 Transaction-ID | `Transaction.php:217` | SHA-256 oder UUID v4 |

---

## 8. Vergleich mit anderen Modulen (OXID 6.5)

| Aspekt | PayPal | AmazonPay | Unzer |
|--------|--------|-----------|-------|
| SQL Injection | View-Name (mittel) | **Direkte Konkat.** (kritisch) | Keine (sicher) |
| unserialize() | Ja (kritisch) | Nein | Nein (sicher via FlexibleSerializer) |
| SSRF | Nein | Nein | **Apple Pay** (kritisch) |
| IDOR | Nein | Nein | **deletePayment** (hoch) |
| Webhook-Signatur | EventVerifier | SNS Validator | **Fehlt** (nur Domain-Check) |
| DOM-XSS | Nicht in 6.5 | Nein | **6 Templates** (hoch) |
| CSRF-Luecken | Keine | 3 Endpoints | 1 Endpoint (+ IDOR) |
| Open Redirect | Theoretisch | Aus API-Response | Nein |
| Unauthentifizierte Endpoints | Webhook (signiert) | **poll ohne Auth** | Webhook (Domain-Check) |

---

## 9. Fazit

Das Unzer-Modul hat in OXID 6.5 ein **anderes Risikoprofil** als in OXID 7:

**Besser als in OXID 7:**
- `unserialize()` in TmpOrder ist **sicher** (FlexibleSerializer mit allowed_classes)
- Keine `|raw`-Problematik (Smarty nutzt Standard-Escaping)
- Keine SQL-View-Name-Interpolation (TransactionList existiert nicht)

**Gleich kritisch wie in OXID 7:**
- SSRF ueber Apple Pay (kritischster Frontend-Vektor)
- IDOR bei gespeicherten Zahlungsmitteln
- Webhook ohne Signaturpruefung
- DOM-XSS in 6 Payment-Templates (jQuery `.html()`)

**Spezifisch fuer OXID 6.5:**
- Double `urldecode()` in ApplePayCallbackController (verstaerkt SSRF)

**Handlungsempfehlung:** Die SSRF-Schwachstelle (Finding 1.1) ist der gefaehrlichste
Vektor — ein Angreifer kann ohne Authentifizierung interne Netzwerk-Ressourcen und
Cloud-Credentials abfragen. Der IDOR (Finding 2.2) ist der zweitkritischste Punkt,
da er die Manipulation fremder Zahlungsmittel erlaubt.
