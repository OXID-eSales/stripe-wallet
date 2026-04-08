# Security Audit: Adyen-Modul (osc/adyen) — OXID 6.5

**Datum:** 2026-03-05
**Scope:** `source/source/modules/osc/adyen`
**OXID Version:** 6.5 (PHP 7.4+, Smarty Templates)
**Fokus:** Frontend-Angriffsvektoren, Verifikation gegen OXID 7 Audit
**Verifiziert gegen Quellcode:** Ja

---

## Zusammenfassung

| Kategorie | Anzahl |
|-----------|--------|
| Kritisch | 2 |
| Hoch | 3 |
| Mittel | 5 |
| Niedrig | 4 |

**Gesamtbewertung:** Das Adyen-Modul hat die **gravierendsten Webhook-Schwachstellen**
aller auditierten Module. Die HMAC-Signaturpruefung ist doppelt umgehbar: einmal
durch den Default-Zustand (kein Key konfiguriert = Webhook akzeptiert) und einmal
durch Injection eines manipulierten `hmacSignatureUtil` im JSON-Body. Dies erlaubt
einem Angreifer, **Bestellungen als bezahlt zu markieren, Stornierungen auszuloesen
und Refunds zu triggern** — vollstaendig ohne Authentifizierung.

**Korrekturen gegenueber dem OXID 7 Audit:**
- Finding 2.2 (Session-Amount) ist **weniger kritisch** als dargestellt — es gibt
  eine Validierung gegen den Basket-Betrag, allerdings erst nach dem Schreiben in die Session
- Finding 3.1 (SQL orWhere) ist in OXID 6.5 **weniger kritisch** — Doctrine QueryBuilder
  erzeugt korrekte Klammerung
- Finding 3.5 (`|raw` auf Payment-Description) existiert in OXID 6.5 **nicht** —
  keine unescapten Zahlungsart-Beschreibungen in Smarty-Templates gefunden

---

## Legende

| Tag | Bedeutung |
|-----|-----------|
| `FRONTEND` | Vom Kunden (anonym oder eingeloggt) erreichbar |
| `BACKEND` | Nur aus dem Admin-Panel erreichbar |
| `WEBHOOK` | Von aussen erreichbar (Server-zu-Server Callback) |

---

## 1. Kritische Findings

### 1.1 HMAC-Bypass bei nicht konfiguriertem Key (Default-Zustand) — `WEBHOOK`

**Datei:** `src/Core/Webhook/Event.php:32, 162-171`
**Erreichbarkeit:** Oeffentlich erreichbar via `?cl=AdyenWebhookController`
**Frontend-Exploitability:** **Direkt ausnutzbar bei jeder Neuinstallation**
**Verifiziert:** Ja

```php
// Zeile 32: Default-Wert ist TRUE
private bool $isHMACVerified = true;

// Zeile 162-171:
protected function verifyHMACSignature(): void
{
    $moduleSettings = $this->getServiceFromContainer(ModuleSettings::class);
    $hmacKey = $moduleSettings->getHmacSignature();

    // verify the Signature if we have one
    if (!$hmacKey) {
        return;  // isHMACVerified bleibt TRUE!
    }
    // ...
}
```

**Risiko:** Der Default-Wert fuer den HMAC-Key ist ein leerer String. Bei jeder
Neuinstallation — und bei jedem Shop, der den HMAC-Key nicht explizit konfiguriert
hat — ist die Signaturpruefung **komplett deaktiviert**, aber `isHMACVerified()`
gibt `true` zurueck. Ein Angreifer kann beliebige Webhook-Events forgen:
- Bestellungen als bezahlt markieren (AUTHORISATION + CAPTURE Events)
- Stornierungen ausloesen (CANCELLATION Events)
- Refunds triggern (REFUND Events)

**Empfehlung:** Fail-Closed-Verhalten implementieren:
```php
private bool $isHMACVerified = false;  // Default: NICHT verifiziert

protected function verifyHMACSignature(): void
{
    $hmacKey = $this->getServiceFromContainer(ModuleSettings::class)->getHmacSignature();
    if (!$hmacKey) {
        $this->isHMACVerified = false;
        Registry::getLogger()->error('Adyen HMAC key not configured - webhook rejected');
        return;
    }
    // ...
}
```

---

### 1.2 HMAC-Bypass via injiziertem hmacSignatureUtil — `WEBHOOK`

**Datei:** `src/Core/Webhook/Event.php:159, 173-180`
**Erreichbarkeit:** Oeffentlich erreichbar via `?cl=AdyenWebhookController`
**Frontend-Exploitability:** **Direkt ausnutzbar auch bei konfiguriertem HMAC-Key**
**Verifiziert:** Ja

```php
// Zeile 159: hmacSignatureUtil wird aus den Webhook-Rohdaten gelesen!
$this->hmacSignatureUtil = $this->rawData['hmacSignatureUtil'] ?? new HmacSignature();

// Zeile 173-180: Exception laesst isHMACVerified auf true
try {
    $this->isHMACVerified = $this->hmacSignatureUtil->isValidNotificationHMAC(
        $hmacKey,
        $this->item
    );
} catch (AdyenException $exception) {
    Registry::getLogger()->error($exception->getMessage(), [$exception]);
    // isHMACVerified bleibt true (Default von Zeile 32)!
}
```

**Risiko:** Ein Angreifer kann `hmacSignatureUtil` im JSON-Payload mitsenden.
Da `json_decode` den Wert als String/Array liefert (nicht als Objekt), wird
`isValidNotificationHMAC()` auf einem Nicht-Objekt aufgerufen → TypeError →
Exception wird gefangen → `isHMACVerified` bleibt `true` (Default).

**Angriffsvektor:**
1. Angreifer sendet Webhook mit `"hmacSignatureUtil": "anything"` im JSON-Body
2. `isValidNotificationHMAC()` schlaegt fehl (TypeError auf String)
3. Catch-Block faengt die Exception, `isHMACVerified` bleibt `true`
4. Webhook wird als authentisch akzeptiert — **auch bei konfiguriertem HMAC-Key**

Zusaetzlich: Auskommentierter Bypass-Code in Zeile 75:
```php
public function isHMACVerified(): bool
{
    //return true;    // ← Debug-Relikt
    return $this->isHMACVerified;
}
```

**Empfehlung:** Zwei Fixes noetig:
```php
// Fix 1: hmacSignatureUtil NICHT aus Raw-Data lesen
$this->hmacSignatureUtil = new HmacSignature();

// Fix 2: Exception muss isHMACVerified auf false setzen
} catch (\Throwable $exception) {
    $this->isHMACVerified = false;
    Registry::getLogger()->error($exception->getMessage(), [$exception]);
}
```

---

## 2. Hohe Findings

### 2.1 AdyenJSController ohne CSRF-Schutz — `FRONTEND`

**Datei:** `src/Controller/AdyenJSController.php:24-79`
**Erreichbarkeit:** Frontend — eingeloggte Kunden im Checkout
**Frontend-Exploitability:** Ausnutzbar bei vorhandener XSS-Schwachstelle
**Verifiziert:** Ja

```php
public function payments(): void
{
    $response = $this->getServiceFromContainer(ResponseHandler::class)->response();
    $sessionSettings = $this->getServiceFromContainer(SessionSettings::class);
    $paymentJSControllerService = $this->getServiceFromContainer(PaymentJSControllerService::class);

    $basket = $sessionSettings->getBasket();
    $amount = $basket->getPrice()->getBruttoPrice();
    $orderReference = $sessionSettings->getOrderReference();
    // ... keine stoken-Pruefung ...
    $postData = $this->jsonToArray($this->getJsonPostData());
```

**Hinweis:** Im Smarty-Template (`adyen_assets.tpl:153`) wird das `stoken` zwar im
Fetch-URL mitgesendet, aber der Controller **validiert es nicht**:
```javascript
fetch('[{$sSelfLink}]cl=adyenjscontroller&fnc=' + endpoint + '&stoken=[{$sToken}]...', {
```

**Risiko:** Die Endpoints `payments()` und `details()` akzeptieren JSON-POST-Daten
und initiieren Adyen-Payment-API-Calls ohne serverseitige CSRF-Validierung.

**Empfehlung:** `Registry::getSession()->checkSessionChallenge()` im Controller pruefen.

---

### 2.2 Session-Payment-Daten aus HTTP-Request — `FRONTEND`

**Datei:** `src/Controller/PaymentController.php:169-180`
**Erreichbarkeit:** Frontend — Checkout-Flow
**Frontend-Exploitability:** Teilweise ausnutzbar
**Verifiziert:** Ja

```php
protected function saveAdyenPaymentInSession(): void
{
    $session = $this->getServiceFromContainer(SessionSettings::class);
    $pspReference = $this->getStringRequestData(Module::ADYEN_HTMLPARAM_PSPREFERENCE_NAME);
    $resultCode = $this->getStringRequestData(Module::ADYEN_HTMLPARAM_RESULTCODE_NAME);
    $amountCurrency = $this->getStringRequestData(Module::ADYEN_HTMLPARAM_AMOUNTCURRENCY_NAME);
    $amountValue = $this->getFloatRequestData(Module::ADYEN_HTMLPARAM_AMOUNTVALUE_NAME);
    $session->setPspReference($pspReference);
    $session->setResultCode($resultCode);
    $session->setAmountCurrency($amountCurrency);
    $session->setAmountValue($amountValue);
}
```

**Vorhandene Validierung** (Zeile 87-93):
```php
public function isValidAdyenAuthorisation(): bool
{
    $session = $this->getServiceFromContainer(SessionSettings::class);
    $validSessionAmount = $session->getAdyenBasketAmount() <= $session->getAmountValue();
    return $this->isActiveAdyenSession() && $validSessionAmount;
}
```

**Risiko:** `amountValue`, `pspReference` und `resultCode` werden direkt aus dem
HTTP-Request in die Session geschrieben. Die Validierung prueft nur, ob der
Session-Amount >= Basket-Amount ist — ein Angreifer kann einen kuenstlich hohen
Wert senden. Kritischer ist, dass `pspReference` und `resultCode` ohne jede
Pruefung gegen die Adyen-API uebernommen werden.

**Bewertung:** Weniger kritisch als im OXID 7 Audit dargestellt, da die Amount-
Validierung existiert. Aber `pspReference` und `resultCode` werden blind vertraut.

**Empfehlung:** Alle sicherheitskritischen Adyen-Daten serverseitig ueber die
Adyen-API verifizieren, nicht aus Client-Requests uebernehmen.

---

### 2.3 JSON-Konfiguration in JavaScript-Kontext — `FRONTEND`

**Datei:** `views/frontend/tpl/payment/adyen_assets_configuration.tpl:6-7`
**Erreichbarkeit:** Frontend — Checkout-Seite
**Verifiziert:** Ja

```smarty
const configuration = {
    [{$configFields}],
```

`configFields` enthaelt JSON-encodierte Benutzerdaten (E-Mail, Name, Adresse, IP),
erzeugt via `JSAPITemplateConfiguration::getConfigFieldsJsonFormatted()`. Die Methode
nutzt `json_encode()` + Regex-Nachbearbeitung (Entfernung aeusserer Klammern,
Entquotierung von Keys) und gibt das Ergebnis direkt in den JavaScript-Kontext aus.

**Risiko:** Waehrend `json_encode()` grundsaetzlich XSS-sicher ist, macht die
Regex-Nachbearbeitung das Pattern fragil. Falls `json_encode()` unerwartete Ausgaben
produziert oder die Regex fehlschlaegt, koennte JavaScript injiziert werden.

**Empfehlung:** Sicheres Pattern verwenden — Konfiguration als JSON-Block ausgeben:
```html
<script type="application/json" id="adyen-config">[{$configFieldsJson}]</script>
<script>var config = JSON.parse(document.getElementById('adyen-config').textContent);</script>
```

---

## 3. Mittlere Findings

### 3.1 SQL orWhere-Logikfehler — Cross-Shop Data Leakage — `WEBHOOK`

**Datei:** `src/Model/AdyenHistoryList.php:104-117`
**Verifiziert:** Ja

```php
$queryBuilder->select('oxorderid')
    ->from(Module::ADYEN_HISTORY_TABLE)
    ->where('pspreference = :pspreference')
    ->orWhere('parentpspreference = :parentpspreference');

if (!$this->config->getConfigParam('blMallUsers')) {
    $queryBuilder->andWhere('oxshopid = :oxshopid');
}
```

**Risiko:** SQL-Operator-Praezedenz: `WHERE (psp = ? OR parentpsp = ?) AND shopid = ?`
vs. `WHERE psp = ? OR (parentpsp = ? AND shopid = ?)`. In Multi-Shop-Setups koennen
Bestellungen aus anderen Shops gelesen werden.

**Bewertung:** In OXID 6.5 weniger kritisch als im OXID 7 Audit dargestellt — Doctrine
QueryBuilder erzeugt in der Regel korrekte Klammerung. Dennoch ist das Pattern
missverstaendlich und sollte explizit gruppiert werden.

**Empfehlung:** `$queryBuilder->expr()->orX()` Gruppierung verwenden.

---

### 3.2 Fehlende Merchant-Pruefung im Webhook-Controller — `WEBHOOK`

**Datei:** `src/Controller/AdyenWebhookController.php:48-55`
**Verifiziert:** Ja

```php
$event = new Event($data);
$eventDispatcher = $this->getServiceFromContainer(OxNewService::class)->oxNew(EventDispatcher::class);
$eventDispatcher->dispatch($event);

if (!$event->isHMACVerified()) {
    throw WebhookEventException::hmacValidationFailed();
}
// Kein Check auf isMerchantVerified()!
```

**Risiko:** Der Controller prueft nur `isHMACVerified()`. Die Merchant-Pruefung
findet erst in `WebhookHandlerBase::handle()` statt (Zeile 60). Falls ein Custom-
Handler die Parent-Methode ueberspringt, fehlt der Merchant-Check.

**Empfehlung:** Merchant-Pruefung auch im Controller erzwingen.

---

### 3.3 Race Condition bei Webhook-Verarbeitung — `WEBHOOK`

**Datei:** `src/Core/Webhook/Handler/WebhookHandlerBase.php:137-164`
**Verifiziert:** Ja

```php
protected function setHistoryEntry(...): void
{
    $adyenHistory = $oxNewService->oxNew(AdyenHistory::class);
    $adyenHistory->setOrderId($orderId);
    $adyenHistory->setPSPReference($pspReference);
    // ... weitere Felder ...
    $adyenHistory->save();  // Kein Duplikat-Check!
}
```

**Risiko:** Kein Datenbank-Lock oder Duplikat-Pruefung. Bei Adyen-Retries werden
redundante Eintraege erzeugt. Parallele Webhooks koennen auf stale Order-State
lesen und inkonsistente Updates schreiben.

**Empfehlung:** Unique-Index auf `pspreference` + `eventcode`, Duplikat-Check
vor Insert.

---

### 3.4 Session-Daten ohne Integritaetspruefung — `FRONTEND`

**Datei:** `src/Service/SessionSettings.php`
**Verifiziert:** Ja

Neben dem Amount (Finding 2.2) werden `pspReference` und `resultCode` aus dem
HTTP-Request in die Session geschrieben. Ein Angreifer koennte eine gefaelschte
`pspReference` injizieren, die als gueltiger Adyen-Zahlungsnachweis behandelt wird.

**Empfehlung:** Alle sicherheitskritischen Werte serverseitig ueber die Adyen-API
verifizieren.

---

### 3.5 Direkter $_GET-Zugriff in OrderReturnService — `FRONTEND`

**Datei:** `src/Service/OrderReturnService.php:20-22, 31`
**Verifiziert:** Ja

```php
$redirectResult = $_GET['redirectResult'] ?? '';
$controller = $_GET['cl'] ?? '';
$function = $_GET['fnc'] ?? '';
```

**Risiko:** OXID's Input-Filtering (`Registry::getRequest()`) wird umgangen. Die
Werte werden an die Adyen-API gesendet — kein direktes SQL-/HTML-Injection-Risiko,
aber das Umgehen des Frameworks ist schlechte Praxis und verhindert globale
Input-Sanitisierung.

**Empfehlung:** `Registry::getRequest()->getRequestParameter()` verwenden.

---

## 4. Niedrige Findings

### 4.1 XDEBUG_SESSION_START in Sandbox-Mode — `FRONTEND`

**Datei:** `views/frontend/tpl/payment/adyen_assets.tpl:153`
**Verifiziert:** Ja

```javascript
fetch('[{$sSelfLink}]cl=adyenjscontroller&fnc=' + endpoint
    + '&stoken=[{$sToken}][{if $oViewConf->isAdyenSandboxMode()}]&XDEBUG_SESSION_START=1[{/if}]', {
```

**Risiko:** Bei versehentlich aktivem Sandbox-Mode in Produktion und installiertem
Xdebug koennte Information Disclosure auftreten.

**Empfehlung:** Xdebug-Parameter entfernen.

---

### 4.2 Keine Idempotenz-Pruefung bei Webhooks — `WEBHOOK`

**Datei:** `src/Core/Webhook/Handler/WebhookHandlerBase.php:137-164`
**Verifiziert:** Ja

`setHistoryEntry()` erstellt bei jedem Webhook-Aufruf einen neuen Eintrag ohne
Duplikat-Pruefung. Bei Adyen-Retries werden redundante Eintraege erzeugt.

**Empfehlung:** Unique-Index auf `pspreference` + `eventcode`.

---

### 4.3 Auskommentierter HMAC-Bypass als Code-Smell — `WEBHOOK`

**Datei:** `src/Core/Webhook/Event.php:75`
**Verifiziert:** Ja

```php
public function isHMACVerified(): bool
{
    //return true;
    return $this->isHMACVerified;
}
```

**Risiko:** Debug-Relikt, das auf eine laxe Handhabung der Webhook-Sicherheit
hinweist. Ein versehentliches Einkommentieren deaktiviert die gesamte HMAC-Pruefung.

**Empfehlung:** Auskommentierten Code entfernen.

---

### 4.4 JSON-Konfiguration in Smarty ohne explizites Escaping — `FRONTEND`

**Datei:** `views/frontend/tpl/payment/adyen_assets_configuration.tpl`
**Verifiziert:** Ja

Mehrere Stellen geben JSON-encodierte Konfigurationsdaten (GooglePay, ApplePay)
direkt in JavaScript-Kontext aus:

```smarty
googlepay: [{$googlePayConfigurationJson}],
```

**Risiko:** Funktional sicher durch `json_encode()`, aber das Pattern ist fragil.
Kein explizites Escaping fuer den JavaScript-Kontext.

---

## 5. Korrekturen gegenueber dem OXID 7 Audit

| OXID 7 Finding | Status in OXID 6.5 | Begruendung |
|----------------|--------------------|-----------------------|
| 2.2 Session-Amount | **Weniger kritisch** | Validierung `getAdyenBasketAmount() <= getAmountValue()` existiert (Zeile 87-93). Der Amount wird zwar aus dem Request gelesen, aber gegen den Basket geprueft. |
| 3.1 SQL orWhere | **Weniger kritisch** | Doctrine QueryBuilder in OXID 6.5 erzeugt in der Regel korrekte SQL-Klammerung. Das Pattern ist trotzdem missverstaendlich. |
| 3.5 `\|raw` Payment-Description | **Existiert nicht** | Keine unescapten Zahlungsart-Beschreibungen in Smarty-Templates gefunden. |

---

## 6. Frontend-Controller-Analyse

| Controller | Endpoint | User-Input | Auth | CSRF | Status |
|-----------|----------|-----------|------|------|--------|
| `AdyenWebhookController` | `?cl=AdyenWebhookController` | JSON Body | **Nein** | **HMAC (umgehbar!)** | **KRITISCH** |
| `AdyenJSController` | `?cl=adyenjscontroller&fnc=payments` | JSON Body | Login | **Nein** | **HOCH** |
| `AdyenJSController` | `?cl=adyenjscontroller&fnc=details` | JSON Body | Login | **Nein** | **HOCH** |
| `PaymentController` | Extension (validatePayment) | POST-Params | Login | stoken | Teilweise sicher |
| `OrderController` | Extension (execute) | Session | Login | stoken | Sicher |
| `OrderReturnService` | Redirect-Handling | `$_GET` direkt | Nein | Nein | **Risiko** |

---

## 7. Empfehlungen nach Prioritaet

### Prioritaet 0 — Sofort beheben

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 1 | **HMAC Default TRUE** | `Event.php:32` | `isHMACVerified = false` + Fail-Closed bei leerem Key |
| 2 | **HMAC Injection** | `Event.php:159` | `hmacSignatureUtil` nicht aus rawData lesen + `catch → false` |

### Prioritaet 1 — Zeitnah adressieren

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 3 | CSRF in AdyenJSController | `AdyenJSController.php:24` | `checkSessionChallenge()` validieren |
| 4 | Session-Daten aus Request | `PaymentController.php:169` | pspReference + resultCode ueber Adyen-API verifizieren |
| 5 | JSON-Config in JS-Kontext | `adyen_assets_configuration.tpl:6` | `<script type="application/json">` Pattern |

### Prioritaet 2 — Mittelfristig

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 6 | SQL orWhere-Logik | `AdyenHistoryList.php:104` | `expr()->orX()` Gruppierung |
| 7 | Merchant-Check im Controller | `AdyenWebhookController.php:53` | `isMerchantVerified()` pruefen |
| 8 | Race Condition Webhooks | `WebhookHandlerBase.php:137` | SELECT FOR UPDATE + Duplikat-Index |
| 9 | $_GET-Zugriff | `OrderReturnService.php:20` | `Registry::getRequest()` verwenden |

### Prioritaet 3 — Nice to have

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 10 | XDEBUG in Sandbox | `adyen_assets.tpl:153` | Entfernen |
| 11 | Keine Idempotenz | `WebhookHandlerBase.php` | Unique-Index + Duplikat-Check |
| 12 | Kommentierter Bypass | `Event.php:75` | Auskommentierten Code loeschen |

---

## 8. Vergleich mit anderen Modulen (OXID 6.5)

| Aspekt | PayPal | AmazonPay | Unzer | **Adyen** |
|--------|--------|-----------|-------|-----------|
| SQL Injection | View-Name (mittel) | **Direkte Konkat.** | Keine | orWhere-Logik (niedrig) |
| unserialize() | Ja (kritisch) | Nein | Nein (sicher) | Nein |
| SSRF | Nein | Nein | **Apple Pay** | Nein |
| IDOR | Nein | Nein | **deletePayment** | Nein |
| Webhook-Signatur | EventVerifier | SNS Validator | Domain-Check | **HMAC umgehbar!** |
| DOM-XSS | Nicht in 6.5 | Nein | **6 Templates** | Nein |
| CSRF-Luecken | Keine | 3 Endpoints | 1 Endpoint | **2 Endpoints** |
| Session-Manipulation | Nein | Nein | Nein | **pspReference + Amount** |
| Unauthentifizierte Endpoints | Webhook (signiert) | **poll ohne Auth** | Webhook (Domain) | **Webhook (HMAC umgehbar)** |
| Race Conditions | Kein Schutz | Kein Schutz | Kein Schutz | Kein Schutz |

---

## 9. Fazit

Das Adyen-Modul hat die **gefaehrlichste Webhook-Implementation** aller auditierten
Module. Waehrend PayPal einen funktionierenden EventVerifier, AmazonPay eine
SNS-Signaturpruefung und Unzer zumindest eine Domain-Whitelist implementiert,
hat Adyen eine **doppelt umgehbare HMAC-Pruefung**:

1. **Kein HMAC-Key konfiguriert** (Default nach Installation) → Webhooks werden
   blind akzeptiert
2. **HMAC-Key konfiguriert** → Angreifer injiziert `hmacSignatureUtil` im JSON-Body
   → Exception → `isHMACVerified` bleibt `true`

Dies bedeutet: **Jeder Shop mit dem Adyen-Modul akzeptiert derzeit gefaelschte
Webhooks**, unabhaengig davon, ob ein HMAC-Key konfiguriert ist oder nicht.

Die Kombination aus Webhook-Bypass + Session-Manipulation macht das Adyen-Modul
zum **hoechsten Risiko** unter allen auditierten Payment-Modulen.

**Handlungsempfehlung:** Findings 1.1 und 1.2 (HMAC-Bypass) haben die hoechste
Prioritaet ueber alle Module hinweg. Der Fix ist trivial (3 Zeilen Code), aber
die Auswirkung ist maximal — ein Angreifer kann Bestellungen als bezahlt markieren.
