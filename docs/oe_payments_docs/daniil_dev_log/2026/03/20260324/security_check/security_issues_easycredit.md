# Security Audit: easyCredit-Modul (oxps/easycredit) — OXID 6.5

**Datum:** 2026-03-05
**Scope:** `source/source/modules/oxps/easycredit`
**OXID Version:** 6.5 (PHP 7.4+, Smarty Templates)
**Fokus:** Frontend-Angriffsvektoren, Verifikation gegen OXID 7 Audit
**Verifiziert gegen Quellcode:** Ja

---

## Zusammenfassung

| Kategorie | Anzahl |
|-----------|--------|
| Kritisch | 1 |
| Hoch | 2 |
| Mittel | 5 |
| Niedrig | 4 |

**Gesamtbewertung:** Das easyCredit-Modul ist kleiner als die anderen Payment-Module,
hat aber einen **kritischen Designfehler**: Es fehlt ein serverseitiger Webhook-Endpoint.
Der gesamte Zahlungsfluss haengt vom Browser-Redirect ab. Zusaetzlich gibt es
**zwei `unserialize()`-Aufrufe ohne `allowed_classes`** und **fehlende CSRF-Pruefung**
am Dispatcher-Controller.

**Vergleich mit OXID 7:** Der PHP-Code und die Smarty-Templates sind **identisch**.
Es gibt keine Unterschiede in der Sicherheitsarchitektur. Die Dateipfade unterscheiden
sich (`Application/Controller/` vs. `src/Controller/`), aber der Code ist derselbe.

---

## Legende

| Tag | Bedeutung |
|-----|-----------|
| `FRONTEND` | Vom Kunden (anonym oder eingeloggt) erreichbar |
| `BACKEND` | Nur aus dem Admin-Panel erreichbar |

---

## 1. Kritische Findings

### 1.1 Kein Server-zu-Server Webhook-Endpoint (Design-Schwachstelle) — `FRONTEND`

**Kontext:** Das gesamte Modul
**Verifiziert:** Ja — kein Webhook-/IPN-Controller vorhanden

Das Modul implementiert **keinen dedizierten Webhook-Endpoint**. Der gesamte
Zahlungsflow haengt vom Browser-Redirect ab:

1. Kunde wird zu easyCredit weitergeleitet
2. Kunde genehmigt Ratenzahlung
3. easyCredit leitet Kunde zurueck zu `?cl=EasyCreditDispatcher&fnc=getEasyCreditDetails`
4. Erst jetzt wird die Bestellung finalisiert und bei easyCredit bestaetigt

**Registrierte Controller** (aus `metadata.php:40-51`):

| Controller | Typ | Zweck |
|-----------|-----|-------|
| `EasyCreditDispatcher` | Frontend | Redirect-Handling (Init + Callback) |
| `EasyCreditPaymentController` | Frontend (Extension) | Payment-Validierung |
| `EasyCreditOrderController` | Frontend (Extension) | Order-Finalisierung |
| `EasyCreditExampleCalculation` | Widget | Ratenrechner AJAX |
| `EasyCreditExampleCalculationPopup` | Widget | Ratenrechner Popup |
| 6x Admin-Controller | Backend | Order-Management |

**Kein** `WebhookController`, `IPNController` oder `DispatcherController` mit
Server-zu-Server-Callback.

**Risiko:** Wenn der Browser-Redirect fehlschlaegt:
- Zahlung bei easyCredit **genehmigt**, im Shop **nicht finalisiert**
- Kein asynchroner Mechanismus zur Korrektur
- Chargebacks und Status-Aenderungen koennen nicht empfangen werden

**Empfehlung:** Webhook-Endpoint implementieren (analog PayPal/Unzer/AmazonPay).

---

## 2. Hohe Findings

### 2.1 Unsichere Deserialisierung von DB-Daten — `BACKEND`

**Datei:** `Application/Controller/Admin/EasyCreditOrderEasyCreditController.php:134-138`
**Erreichbarkeit:** Admin — Order-Detail-Ansicht
**Frontend-Exploitability:** Nicht direkt, aber bei kompromittierter DB ausnutzbar
**Verifiziert:** Ja

```php
$response = $this->getOrder()->oxorder__ecredconfirmresponse->value;
if ($response) {
    $response = unserialize(base64_decode($response));
    if (is_object($response)) {
```

Der Schreib-Gegenpart in `EasyCreditOrder.php:142`:
```php
$this->oxorder__ecredconfirmresponse = new Field(base64_encode(serialize($response)), Field::T_RAW);
```

**Risiko:** `unserialize()` **ohne `allowed_classes`** auf base64-decodierte DB-Daten.
Bei SQL-Injection in einem anderen Modul (z.B. die gefundene SQL-Injection im
AmazonPay-Modul) → RCE ueber PHP-Gadget-Chains.

**Empfehlung:** `json_encode`/`json_decode` Migration.

---

### 2.2 Fehlende CSRF-Pruefung am Dispatcher-Controller — `FRONTEND`

**Datei:** `Application/Controller/EasyCreditDispatcherController.php:55, 79`
**Erreichbarkeit:** Frontend — eingeloggte Kunden
**Frontend-Exploitability:** **Direkt ausnutzbar**
**Verifiziert:** Ja

```php
// Zeile 55: Keine stoken-Pruefung
public function initializeandredirect()
{
    $this->calculateBasket(...);
    try {
        $currentInitData = $this->getCurrentInitializationData();
        $currentPaymentHash = EasyCreditInitializeRequestBuilder::generatePaymentHash($currentInitData);
        if (!$this->isInitialized($currentPaymentHash)) {
            $this->initialize($currentPaymentHash, $currentInitData);
        }
        // ...
    }
}

// Zeile 79: Keine stoken-Pruefung
public function getEasyCreditDetails()
{
    try {
        $this->processEasyCreditDetails();
        return "order";
    }
    // ...
}
```

**Angriffsvektor:**
1. Angreifer erstellt Link: `?cl=EasyCreditDispatcher&fnc=initializeandredirect`
2. Eingeloggter Kunde klickt Link (Phishing, Social Engineering)
3. Warenkorb des Kunden wird bei easyCredit zur Ratenzahlung eingereicht

**Empfehlung:** `Registry::getSession()->checkSessionChallenge()` hinzufuegen.

---

## 3. Mittlere Findings

### 3.1 Unsichere Deserialisierung von Session-Daten — `FRONTEND`

**Datei:** `Core/Domain/EasyCreditSession.php:50`
**Verifiziert:** Ja

```php
public function getStorage()
{
    $storage = unserialize((string)$this->getVariable(self::API_CONFIG_STORAGE));
    if (!empty($storage) && $storage->hasExpired()) {
        $this->clearStorage();
        $storage = null;
    }
    return $storage;
}
```

**Risiko:** `unserialize()` ohne `allowed_classes` auf Session-Daten. Bei
DB-gespeicherten Sessions und SQL-Injection andernorts → PHP-Object-Injection.

**Empfehlung:**
```php
$storage = unserialize(
    (string)$this->getVariable(self::API_CONFIG_STORAGE),
    ['allowed_classes' => [EasyCreditStorage::class]]
);
```

---

### 3.2 Session-ID in Callback-URLs an Drittanbieter — `FRONTEND`

**Datei:** `Core/Helper/EasyCreditInitializeRequestBuilder.php:381-388`
**Verifiziert:** Ja

```php
protected function getBaseUrl()
{
    $url = $this->getConfig()->getSslShopUrl();
    $url .= "index.php?lang=" . $this->getBaseLanguage();
    $url .= "&sid=" . $this->getSession()->getId();
    $url .= "&shp=" . $this->getConfig()->getShopId();
    return $url;
}
```

Wird in den Callback-URLs verwendet (Zeile 348-374):
```php
protected function getSuccessUrl()
{
    $successUrl = $this->getBaseUrl() . "&cl=EasyCreditDispatcher&fnc=getEasyCreditDetails";
    return $this->getSession()->processUrl($successUrl);
}
```

**Risiko:** Die OXID-Session-ID wird in `urlErfolg`, `urlAbbruch` und `urlAblehnung`
an easyCredit-Server gesendet. Sichtbar in:
- easyCredit-Server-Logs
- Webserver-Access-Logs
- Browser-History und Referrer-Headern

**Empfehlung:** Einmaliges, kurzlebiges Token statt Session-ID verwenden.

---

### 3.3 DOM-XSS via jQuery .html() und .replaceWith() — `FRONTEND`

**Datei:** `Application/views/widgets/oxpseasycredit_examplecalculation.tpl:26, 40`
**Erreichbarkeit:** Frontend — Produkt- und Warenkorbseiten
**Verifiziert:** Ja

```javascript
// Zeile 26:
$('#easycredit-example-dialog').html(data);

// Zeile 40:
$('#[{$oView->getViewParameter('placeholderId')}]').replaceWith(data);
```

**Risiko:** AJAX-Responses werden unvalidiert per jQuery `.html()` und `.replaceWith()`
ins DOM eingefuegt. Die Responses kommen von Shop-internen Widget-Controllern.
Bei Cache-Poisoning oder Schwachstelle im Widget-Controller → XSS.

**Empfehlung:** `.text()` verwenden oder DOMPurify-Filter.

---

### 3.4 Stored XSS via getRawValue() auf Payment-Beschreibung — `FRONTEND`

**Dateien:**
- `Application/views/page/checkout/inc/oxpseasycredit_payment_easycreditinstallment.tpl:35`
- `Application/Controller/EasyCreditOrderController.php:152-166`

**Verifiziert:** Ja

```smarty
[{* Smarty-Template: *}]
[{$paymentmethod->oxpayments__oxlongdesc->getRawValue()}]
```

```php
// EasyCreditOrderController.php:163
$paymentDescription .= "<p>" . $paymentPlanTxt . "</p>";
$payment->oxpayments__oxdesc->value = new Field($paymentDescription, Field::T_RAW);
```

**Risiko:** `$paymentPlanTxt` stammt aus der easyCredit-API (ueber `EasyCreditStorage`).
`getRawValue()` gibt den Wert ohne HTML-Escaping aus (Smarty-Aequivalent zu `|raw`).
`Field::T_RAW` verhindert zusaetzlich jedes Framework-Escaping. Bei manipulierter
API-Response → Stored XSS auf der Checkout-Seite.

**Empfehlung:** API-Daten escapen bevor sie in HTML eingefuegt werden:
```php
$paymentDescription .= "<p>" . htmlspecialchars($paymentPlanTxt, ENT_QUOTES, 'UTF-8') . "</p>";
```

---

### 3.5 Keine Double-Confirmation-Prevention — `FRONTEND`

**Datei:** `Core/Domain/EasyCreditOrder.php:134-154`
**Verifiziert:** Ja

```php
protected function confirmOrder($result)
{
    try {
        $response = $this->getConfirmResponse();
        $isConfirmed = $this->isConfirmed($response);
        $this->oxorder__ecredconfirmresponse = new Field(base64_encode(serialize($response)), Field::T_RAW);
        $this->oxorder__ecredpaymentstatus = new Field($this->getPaymentStatus($isConfirmed), Field::T_RAW);
        // ... keine Pruefung ob bereits aufgerufen ...
        $this->save();
    }
    catch (\Exception $ex) {
        $this->handleException($ex);
    }
    return $result;
}
```

**Risiko:** Kein Datenbank-Lock, keine Idempotenz-Pruefung. Bei Doppelklick oder
parallelen Tabs → doppelte easyCredit-API-Aufrufe.

**Empfehlung:** DB-Lock oder Confirmation-Token.

---

## 4. Niedrige Findings

### 4.1 MD5 fuer Payment-Integritaets-Hash — `FRONTEND`

**Datei:** `Core/Helper/EasyCreditInitializeRequestBuilder.php:765-769`
**Verifiziert:** Ja

```php
public static function generatePaymentHash($initializationData)
{
    $paymentHash = md5(json_encode($initializationData));
    return $paymentHash;
}
```

**Risiko:** MD5 ist kryptografisch gebrochen. Der Hash wird zum Erkennen von
Warenkorb-Manipulationen verwendet, ist aber nur Session-intern gespeichert.

**Empfehlung:** `hash('sha256', ...)` verwenden.

---

### 4.2 User-Profildaten-Aenderung ohne CSRF — `FRONTEND`

**Datei:** `Application/Controller/EasyCreditPaymentController.php:429-453`
**Verifiziert:** Ja

```php
protected function addProfileData()
{
    $user = $this->getUser();
    $profileData = $this->getConfig()->getRequestParameter('ecred', true);

    $dateOfBirth = $this->getValidatedDateOfBirth($profileData, $user);
    if ($dateOfBirth) {
        $user->oxuser__oxbirthdate = new Field($dateOfBirth, Field::T_RAW);
        $hasChanged = true;
    }

    $salutation = $this->getValidatedSalutation($profileData);
    if ($salutation) {
        $user->oxuser__oxsal = new Field($salutation, Field::T_RAW);
        $hasChanged = true;
    }

    if ($hasChanged) {
        $user->save();
    }
}
```

**Risiko:** Geburtsdatum und Anrede werden aus Request-Parametern persistiert,
ohne CSRF-Pruefung. Validierung vorhanden (Anrede nur "MR"/"MRS"), aber
fehlende CSRF-Pruefung ermoeglicht Manipulation.

---

### 4.3 Veraltete Bootstrap-CDN-Version im Admin — `BACKEND`

**Datei:** `Application/views/admin/tpl/oxpseasycredit_order_easycredit.tpl`
**Verifiziert:** Ja

```html
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.1/dist/css/bootstrap.min.css" ...>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.1/dist/js/bootstrap.bundle.min.js" ...>
```

Bootstrap 5.0.1 (Juni 2021) — stark veraltet. SRI-Hashes vorhanden (gut).

---

### 4.4 API-URLs in Log-Ausgaben — `BACKEND`

**Datei:** `Core/CrossCutting/EasyCreditLogging.php:55-82`
**Verifiziert:** Ja

```php
public function logRestRequest($encodedData, $encodedResponse, $serviceUrl, $duration)
{
    if ($this->isLogEnabled()) {
        return $this->log($this->buildRequestString($encodedData, $encodedResponse, $serviceUrl, $duration));
    }
}

protected function buildRequestString($encodedData, $encodedResponse, $serviceUrl, $duration)
{
    $result = $serviceUrl . PHP_EOL;        // Service-URL mit WebShop-ID
    $result .= str_repeat('=', 60) . PHP_EOL;
    if ($encodedData) {
        $result .= 'data:' . PHP_EOL;
        $result .= $this->buildPrettyJsonString($encodedData);  // Request-Daten
    }
    if ($encodedResponse) {
        $result .= 'response:' . PHP_EOL;
        $result .= $this->buildPrettyJsonString($encodedResponse);  // Response-Daten
    }
    // ...
}
```

**Risiko:** Service-URLs (mit WebShop-ID) und vollstaendige Request/Response-Daten
werden bei aktiviertem Logging in Logdateien geschrieben.

---

## 5. Vergleich OXID 6.5 vs OXID 7

| Aspekt | OXID 7 | OXID 6.5 | Unterschied |
|--------|--------|---------|-------------|
| PHP-Code | Identisch | Identisch | Nur Pfade verschieden |
| Templates | Smarty (`.tpl`) | Smarty (`.tpl`) | **Identisch** (kein Twig!) |
| `unserialize()` | Verwundbar | Verwundbar | Kein Unterschied |
| CSRF-Luecken | Vorhanden | Vorhanden | Kein Unterschied |
| jQuery `.html()` | Verwundbar | Verwundbar | Kein Unterschied |
| `getRawValue()` | Verwendet | Verwendet | Kein Unterschied |
| Bootstrap CDN | 5.0.1 | 5.0.1 | Kein Unterschied |
| Session-ID in URLs | Vorhanden | Vorhanden | Kein Unterschied |

**Fazit:** Das easyCredit-Modul ist in beiden OXID-Versionen **identisch** —
sowohl im PHP-Code als auch in den Templates (beide Versionen nutzen Smarty,
nicht Twig). Alle Findings gelten 1:1 fuer beide Versionen.

---

## 6. Frontend-Controller-Analyse

| Controller | Endpoint | User-Input | Auth | CSRF | Status |
|-----------|----------|-----------|------|------|--------|
| `EasyCreditDispatcher` | `?cl=EasyCreditDispatcher&fnc=initializeandredirect` | Basket | Login | **Nein** | **HOCH** |
| `EasyCreditDispatcher` | `?cl=EasyCreditDispatcher&fnc=getEasyCreditDetails` | Callback | Login | **Nein** | **HOCH** |
| `EasyCreditPaymentController` | `validatePayment()` Extension | `ecred[]` POST | Login | Teilweise | Risiko |
| `EasyCreditOrderController` | `execute()` Extension | Session | Login | stoken (Parent) | Sicher |
| `EasyCreditExampleCalculation` | Widget AJAX | `oxid` (Artikel-ID) | Nein | Nein | Sicher (Read-only) |
| `EasyCreditExampleCalculationPopup` | Widget AJAX | `oxid` (Artikel-ID) | Nein | Nein | Sicher (Read-only) |

---

## 7. Empfehlungen nach Prioritaet

### Prioritaet 0 — Sofort beheben

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 1 | **Kein Webhook-Endpoint** | Gesamtes Modul | Server-zu-Server Callback implementieren |

### Prioritaet 1 — Zeitnah adressieren

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 2 | `unserialize()` auf DB-Daten | `EasyCreditOrderEasyCreditController.php:136` | `json_encode`/`json_decode` Migration |
| 3 | CSRF fehlt am Dispatcher | `EasyCreditDispatcherController.php:55, 79` | `checkSessionChallenge()` |

### Prioritaet 2 — Mittelfristig

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 4 | `unserialize()` in Session | `EasyCreditSession.php:50` | `allowed_classes` hinzufuegen |
| 5 | Session-ID in Callback-URLs | `EasyCreditInitializeRequestBuilder.php:385` | Einmal-Token statt SID |
| 6 | DOM-XSS via `.html()` | `oxpseasycredit_examplecalculation.tpl:26, 40` | `.text()` oder DOMPurify |
| 7 | Stored XSS via `getRawValue()` | `oxpseasycredit_payment_easycreditinstallment.tpl:35` | `htmlspecialchars()` auf API-Daten |
| 8 | Double-Confirmation | `EasyCreditOrder.php:134` | DB-Lock oder Idempotenz-Token |

### Prioritaet 3 — Nice to have

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 9 | MD5-Hash | `EasyCreditInitializeRequestBuilder.php:767` | SHA-256 verwenden |
| 10 | Profildaten ohne CSRF | `EasyCreditPaymentController.php:429` | stoken-Check |
| 11 | Bootstrap CDN veraltet | Admin-Template | Version aktualisieren |
| 12 | API-URLs im Log | `EasyCreditLogging.php:55` | Sensitive Parameter maskieren |

---

## 8. Vergleich mit anderen Modulen (OXID 6.5)

| Aspekt | PayPal | AmazonPay | Unzer | Adyen | **easyCredit** |
|--------|--------|-----------|-------|-------|---------------|
| SQL Injection | View-Name | **Direkte Konkat.** | Keine | orWhere-Logik | Keine |
| unserialize() | Ja (kritisch) | Nein | Nein (sicher) | Nein | **Ja (2 Stellen)** |
| SSRF | Nein | Nein | **Apple Pay** | Nein | Nein |
| IDOR | Nein | Nein | **deletePayment** | Nein | Nein |
| Webhook-Signatur | EventVerifier | SNS Validator | Domain-Check | **HMAC umgehbar!** | **Kein Webhook** |
| DOM-XSS | Nicht in 6.5 | Nein | **6 Templates** | Nein | **2 Templates** |
| CSRF-Luecken | Keine | 3 Endpoints | 1 Endpoint | 2 Endpoints | **3 Stellen** |
| Session-ID-Leak | Nein | Nein | Nein | Nein | **An easyCredit** |
| Stored XSS | Nein | Nein | Nein | Nein | **getRawValue() + API** |

---

## 9. Fazit

Das easyCredit-Modul hat ein **anderes Risikoprofil** als die anderen Payment-Module:

**Positiv:**
- Keine SQL-Injection
- Keine SSRF-Vektoren
- Keine IDOR-Schwachstellen
- Kleinste Codebase (geringere Angriffsflaeche)

**Negativ:**
- **Kein Webhook-Endpoint** — einziges Modul ohne Server-zu-Server-Callback
- **Zwei `unserialize()` ohne `allowed_classes`** — beide ausnutzbar bei DB-Kompromittierung
- **Session-ID-Leak** an Drittanbieter — einziges Modul mit diesem Problem
- **Stored XSS** via API-Daten + `getRawValue()` + `Field::T_RAW` — fragiles Pattern
- **CSRF-Luecken** am Dispatcher und bei Profildaten-Aenderung

**Handlungsempfehlung:** Das fehlende Webhook ist das gravierendste Design-Problem —
es betrifft die Zuverlaessigkeit des gesamten Zahlungsflows. Die `unserialize()`-
Findings sind die hoechsten technischen Risiken, da sie bei Kombination mit
SQL-Injection in anderen Modulen zu RCE fuehren koennen.
