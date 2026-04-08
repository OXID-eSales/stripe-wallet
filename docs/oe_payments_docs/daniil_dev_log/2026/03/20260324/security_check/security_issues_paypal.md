# Security Audit: PayPal-Modul (osc/paypal)

**Datum:** 2026-03-05
**Scope:** `source/source/modules/osc/paypal`
**OXID Version:** 6.5

---

## Zusammenfassung

| Kategorie | Anzahl |
|-----------|--------|
| Kritisch (Backend) | 2 |
| Hoch (Backend) | 6 |
| Mittel (Backend) | 4 |
| Frontend-relevant | 2 (beide niedriges Risiko) |

**Gesamtbewertung:** Die kritischen und hohen Findings betreffen ausschliesslich Backend-/Admin-Code. Die Frontend-Controller sind sauber implementiert: Inputs werden escaped, SQL-Parameter gequotet, Redirects hardcoded. Es wurden **keine direkt aus dem Frontend ausnutzbaren kritischen Schwachstellen** gefunden.

---

## Legende: Erreichbarkeit

| Tag | Bedeutung |
|-----|-----------|
| `FRONTEND` | Vom Kunden (anonym oder eingeloggt) erreichbar |
| `BACKEND` | Nur aus dem Admin-Panel erreichbar |
| `SAFE` | Theoretisches Pattern, aber in der Praxis nicht ausnutzbar |

---

## 1. Kritische Findings

### 1.1 Insecure Deserialization — `BACKEND`

**Datei:** `src/Model/PayPalPlusRefund.php:101-105`
**Erreichbarkeit:** Nur via `PayPalOrderController` (Admin)
**Frontend-Exploitability:** Nicht erreichbar

```php
public function getRefundObject(): object
{
    $oRefundObject = unserialize(
        htmlspecialchars_decode(
            $this->getFieldData('oxrefundobject')
        )
    );
    return $oRefundObject;
}
```

**Risiko:** `unserialize()` auf DB-Daten ermoeglicht PHP Object Injection, falls ein Angreifer DB-Zugriff erlangt oder Admin-Rechte hat. `htmlspecialchars_decode()` bietet keinen Schutz gegen Deserialisierungsangriffe.

**Call-Chain:** `PayPalOrderController::render()` (Admin) -> `PayPalPlusOrder` -> Template -> `getRefundObject()`

**Empfehlung:** Durch `json_decode()` ersetzen oder `unserialize($data, ['allowed_classes' => [RefundClass::class]])` mit Whitelist verwenden.

---

### 1.2 SQL-Injection via String-Konkatenation — `BACKEND`

**Dateien:**
- `src/Model/PayPalSoapOrderCommentList.php:34-41`
- `src/Model/PayPalSoapOrderPaymentList.php:34-47`

**Erreichbarkeit:** Nur via `PayPalOrderController` (Admin)
**Frontend-Exploitability:** Nicht erreichbar

```php
// PayPalSoapOrderCommentList.php
$sSelect = "select
    $sPaymentTable.`oepaypal_commentid`, ...
    from $sPaymentTable
    where $sPaymentTable.oepaypal_paymentid = " .
    DatabaseProvider::getDb()->quote($paymentId);
```

**Risiko:** `$sPaymentTable` (aus `getViewName()`) wird direkt in den SQL-String interpoliert. Der eigentliche Wert (`$paymentId` / `$orderId`) ist korrekt gequotet. Das Risiko liegt in der View-Name-Interpolation — in der OXID-Architektur ist `getViewName()` jedoch ein interner Aufruf ohne User-Input.

**Call-Chain:** `PayPalOrderController` (Admin) -> `PayPalSoapOrder::getPaymentList()` -> `PayPalSoapOrderPaymentList::load()`

**Empfehlung:** Prepared Statements oder QueryBuilder verwenden. View-Namen als quoted identifier behandeln.

---

## 2. Hohe Findings

### 2.1 SQL-Pattern mit sprintf und Feldnamen — `BACKEND`

**Dateien:**
- `src/Model/PayPalPlusPui.php:141-146`
- `src/Model/PayPalPlusOrder.php:271-276`
- `src/Model/PayPalSoapOrder.php:202-207`
- `src/Model/PayPalPlusRefund.php:119-123`

**Erreichbarkeit:** Alle nur via Admin-Controller
**Frontend-Exploitability:** Nicht erreichbar

```php
$sSelect = sprintf(
    "SELECT * FROM `%s` WHERE `%s` = %s",
    $this->getCoreTableName(),   // Klassen-Konstante — sicher
    $sFieldName,                  // Whitelist-geprueft — sicher
    $db->quote($sFieldValue)      // Gequotet — sicher
);
```

**Risiko:** Das Pattern ist grundsaetzlich fragil (`$sFieldName` in SQL interpoliert), aber durch Whitelist-Validierung (`in_array()`) abgesichert. Die Werte (`$sFieldValue`) sind korrekt gequotet. `getCoreTableName()` ist eine Klassen-Konstante.

**Bewertung:** Funktional sicher durch Whitelist, aber das Pattern sollte langfristig auf QueryBuilder migriert werden.

---

### 2.2 Schwache Kryptografie (Nonce-Fallback) — `BACKEND`

**Datei:** `src/Core/PartnerConfig.php:19-29`
**Erreichbarkeit:** Nur via `ModuleConfiguration` (Admin-Onboarding)
**Frontend-Exploitability:** Nicht erreichbar

```php
public function createNonce(): string
{
    try {
        $nonce = bin2hex(random_bytes(42));
    } catch (\Exception $e) {
        // SCHWACHER FALLBACK:
        $nonce = md5(uniqid('', true) . '|' . microtime())
               . substr(md5((string)mt_rand()), 0, 24);
    }
}
```

**Risiko:** Der Fallback nutzt vorhersagbare Quellen (`uniqid`, `mt_rand`, `microtime`). In der Praxis wird `random_bytes()` auf modernen Systemen nie fehlschlagen — der Fallback ist dennoch ein Anti-Pattern.

**Empfehlung:** Exception werfen statt schwachen Fallback. Auf PHP 7.4+ ist `random_bytes()` immer verfuegbar.

---

### 2.3 Schwache Tracking-ID-Generierung — `BACKEND`

**Datei:** `src/Service/OrderProcessTrackingService.php:32`
**Erreichbarkeit:** Wird im Frontend aufgerufen, aber...
**Frontend-Exploitability:** Nicht ausnutzbar (nur Session-intern, kein Security-Zweck)

```php
$this->trackingId = substr(md5(uniqid()), 0, 6);
```

**Risiko:** MD5 + `uniqid()` = vorhersagbar, nur 6 Zeichen. Wird aber ausschliesslich als interner Logging-/Tracking-Identifier in der Session verwendet — nicht fuer Autorisierung oder CSRF-Schutz.

**Bewertung:** Niedriges Risiko. Trotzdem empfohlen: `bin2hex(random_bytes(8))`.

---

## 3. Mittlere Findings

### 3.1 XSS in Admin-Templates — `BACKEND`

**Dateien:**
- `views/admin/tpl/oscpaypalorder_ppplus.tpl:10` — Error-Output ohne Escaping
- `views/admin/tpl/oscpaypalorder_ppplus.tpl:25,29,34` — Model-Getter ohne Escaping
- `views/blocks/admin/admin_order_main_form_shipping.tpl:109,121,131` — Unescapte Variablen

**Erreichbarkeit:** Nur Admin-Panel
**Frontend-Exploitability:** Nicht erreichbar

```smarty
<!-- Error ohne Escaping -->
[{if $error}]
    <div class="errorbox">[{$error}]</div>
[{/if}]

<!-- Model-Daten ohne Escaping -->
[{$payPalOrder->getStatus()}]
[{$oPaymentInstructions->getAccountHolder()}]
```

**Risiko:** Stored XSS moeglich, falls ein Angreifer DB-Werte manipulieren kann. Da nur im Admin sichtbar, ist der Angriffsvektor begrenzt.

**Empfehlung:** `|escape:'html'` Filter konsequent einsetzen.

---

### 3.2 DOM-XSS via innerHTML — `BACKEND`

**Datei:** `views/blocks/admin/admin_order_main_form_shipping.tpl:16-18`
**Erreichbarkeit:** Nur Admin-Panel
**Frontend-Exploitability:** Nicht erreichbar

```javascript
providerHtml += '<option value="' + provider.id + '">'
             + provider.title + '</option>';
document.getElementById("paypaltrackingcarrierprovider").innerHTML = providerHtml;
```

**Risiko:** `provider.id` und `provider.title` werden ohne Sanitisierung in HTML eingefuegt. Daten stammen aus der DB (Carrier-Liste), nicht aus User-Input.

**Empfehlung:** `document.createElement()` + `textContent` statt `innerHTML`.

---

### 3.3 Open Redirect (theoretisch) — `FRONTEND`

**Datei:** `src/Controller/OrderController.php:528-534`
**Erreichbarkeit:** Frontend (Checkout-Flow)
**Frontend-Exploitability:** Theoretisch, aber nicht direkt ausnutzbar

```php
if (($redirectLink = PayPalSession::getSessionRedirectLink())) {
    PayPalSession::unsetSessionRedirectLink();
    throw new Redirect($redirectLink);
}
```

**Call-Chain:** `OrderController::_getNextStep()` -> `PayPalSession::getSessionRedirectLink()`

**Analyse:** Der Redirect-Link wird serverseitig in der Session gesetzt — durch `PaymentService::doExecuteUAPMPayment()` basierend auf PayPal-API-Responses. Ein Angreifer muesste die PayPal-API-Antwort manipulieren (MITM) oder die Session kompromittieren, um den Link zu kontrollieren.

**Bewertung:** Kein direkt ausnutzbarer Angriffsvektor. Trotzdem empfohlen: URL-Validierung gegen Shop-Domain vor dem Redirect.

---

### 3.4 MD5-Hash auf Session-ID — `BACKEND`

**Datei:** `src/Core/Onboarding/Onboarding.php:176`
**Erreichbarkeit:** Nur Admin-Onboarding
**Frontend-Exploitability:** Nicht erreichbar

```php
$actionHash = md5($sessionId);
```

**Risiko:** MD5 ist kryptografisch gebrochen. Der Hash wird an die PayPal-API gesendet. Praktisches Risiko gering, da Session-IDs serverseitig generiert werden.

**Empfehlung:** `hash('sha256', $sessionId)` verwenden.

---

## 4. Frontend-Controller-Analyse

Alle Frontend-Controller wurden auf unsichere Eingabeverarbeitung geprueft:

| Controller | User-Input | Validierung | Status |
|-----------|-----------|-------------|--------|
| `OrderController` | `getRequestParameter('token')`, `getRequestParameter('fallbackfinalize')` | Session-Abgleich | Sicher |
| `ProxyController` | `getRequestParameter('paymentid')`, JSON-Body | Escaped/validiert | Sicher |
| `AjaxPaymentController` | JSON-Body (`trackingId`, `paymentId`, etc.) | Session-basiert | Sicher |
| `PaymentController` | Session-basierte Vaulting-Optionen | Kein direkter Input | Sicher |
| `WebhookController` | Raw POST von PayPal | Signatur-Verifikation | Sicher |
| `PayPalVaultingController` | Account-basiert | OXID Auth | Sicher |
| `PayPalVaultingCardController` | Account-basiert | OXID Auth | Sicher |

**Ergebnis:** Alle Frontend-Controller verarbeiten User-Input sicher. SQL-Parameter werden gequotet, Redirects sind hardcoded, JSON-Daten werden strukturiert gemappt.

---

## 5. Empfehlungen nach Prioritaet

### Prioritaet 1 — Sollte behoben werden
1. **`unserialize()` in PayPalPlusRefund.php** durch `json_decode()` oder Whitelist ersetzen
2. **Schwachen Nonce-Fallback** in PartnerConfig.php entfernen (Exception werfen)

### Prioritaet 2 — Sollte mittelfristig adressiert werden
3. **SQL-Queries auf Prepared Statements** migrieren (PayPalSoap*-Klassen)
4. **Template-Escaping** in Admin-Templates konsequent einsetzen
5. **innerHTML** durch sichere DOM-APIs ersetzen
6. **MD5-Hashes** durch SHA-256 ersetzen

### Prioritaet 3 — Nice to have
7. **URL-Validierung** vor Session-basierten Redirects
8. **Tracking-ID** mit `random_bytes()` generieren
9. **Langfristige Migration** aller Model-Queries auf Doctrine QueryBuilder

---

## 6. Fazit

Das PayPal-Modul hat eine **solide Frontend-Sicherheit**. Alle kritischen Schwachstellen sind hinter dem Admin-Login geschuetzt. Die gefaehrlichsten Patterns (`unserialize`, rohe SQL-Konkatenation) sind ausschliesslich aus dem Backend erreichbar.

Fuer einen gezielten Angriff ueber das Frontend bietet das Modul **keine offensichtliche Angriffsflaeche**. Die Controller validieren Eingaben korrekt, nutzen Session-Abgleiche und escaped Parameter.

Die Backend-Findings sollten dennoch behoben werden — ein kompromittierter Admin-Account oder eine SQL-Injection an anderer Stelle koennte sie als Stepping-Stone nutzen.
