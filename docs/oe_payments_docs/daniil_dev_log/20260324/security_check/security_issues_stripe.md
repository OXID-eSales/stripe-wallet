# Security Audit: Stripe-Modul (osc/stripe) — OXID 6.5

**Datum:** 2026-03-05
**Scope:** `source/source/modules/osc/stripe`
**OXID Version:** 6.5 (PHP 7.4+, Smarty Templates)
**Fokus:** Frontend-Angriffsvektoren

---

## Zusammenfassung

| Kategorie | Anzahl |
|-----------|--------|
| Kritisch | 1 |
| Hoch | 2 |
| Mittel | 4 |
| Niedrig | 4 |

**Gesamtbewertung:** Das Stripe-Modul hat einen **kritischen IDOR-Designfehler** im `StripeFinishPayment`-Controller: Ein unauthentifizierter Angreifer kann ueber eine Order-ID eine fremde Bestellung laden und sich dabei als der Besteller einloggen (Session-Hijacking). Verschaerft wird dies durch die "Second Chance"-E-Mail-Funktion, die genau diese URL mit Order-ID an Kunden verschickt. Zusaetzlich ist der `createWebhookEndpoint()`-Aufruf auf dem Frontend-Controller ohne Authentifizierung erreichbar, was Webhook-Manipulation ermoeglicht. Die Webhook-Signaturpruefung selbst ist korrekt implementiert (Stripe SDK). Die Template-Schicht nutzt Smarty ohne Auto-Escaping, wobei Stripe-API-Daten teilweise unescaped ausgegeben werden.

---

## Legende

| Tag | Bedeutung |
|-----|-----------|
| `FRONTEND` | Vom Kunden (anonym oder eingeloggt) erreichbar |
| `BACKEND` | Nur aus dem Admin-Panel erreichbar |

---

## 1. Kritische Findings

### 1.1 IDOR + Session-Hijacking via StripeFinishPayment — `FRONTEND`

**Datei:** `Application/Controller/StripeFinishPayment.php:25-53`
**Erreichbarkeit:** Frontend — unauthentifiziert
**Frontend-Exploitability:** **Direkt ausnutzbar**

```php
// StripeFinishPayment.php:25-36
protected function getOrder()
{
    $sOrderId = Registry::getRequest()->getRequestParameter('id');  // Keine Auth-Pruefung
    if ($sOrderId) {
        $oOrder = oxNew(Order::class);
        $oOrder->load($sOrderId);  // Laedt beliebige Order
        if ($oOrder->getId() && $oOrder->stripeIsEligibleForPaymentFinish()) {
            return $oOrder;
        }
    }
    return false;
}

// render() ruft stripeReinitializePayment() auf:
public function render()
{
    $oOrder = $this->getOrder();
    if ($oOrder !== false) {
        $oOrder->stripeReinitializePayment();  // SESSION HIJACKING
        // ...
    }
}
```

In `extend/Application/Model/Order.php:622-650`:
```php
public function stripeReinitializePayment()
{
    // ...
    $oUser = $this->getUser();
    if (!$oUser) {
        $oUser = oxNew(\OxidEsales\Eshop\Application\Model\User::class);
        $oUser->load($this->oxorder__oxuserid->value);
        $this->setUser($oUser);
        Registry::getSession()->setVariable('usr', $this->oxorder__oxuserid->value);  // ZEILE 633: SESSION-HIJACKING
    }
    // ... erstellt neuen PaymentIntent und redirected zu Stripe
}
```

**Angriffsvektor:**

1. Angreifer erhaelt eine Order-ID (z.B. durch Abfangen der "Second Chance"-E-Mail, Social Engineering, oder Order-ID-Leak)
2. Aufruf: `?cl=stripeFinishPayment&id=ORDER_OXID`
3. `stripeIsEligibleForPaymentFinish()` prueft NUR:
   - Ist es eine Stripe-Zahlung?
   - Ist die Order unbezahlt (`oxpaid = '0000-00-00'`)?
   - Hat die Order Status `NOT_FINISHED`?
4. **Es wird NICHT geprueft, ob der aktuelle Benutzer der Besteller ist**
5. `stripeReinitializePayment()` setzt `Registry::getSession()->setVariable('usr', ...)` — der Angreifer ist nun als der Besteller eingeloggt
6. Der Angreifer wird zu Stripe weitergeleitet, aber die Session ist bereits gesetzt
7. In einem anderen Tab ist der Angreifer als der Besteller authentifiziert

**Verschaerfung durch Second-Chance-Feature:**
Die URL wird per E-Mail verschickt (`Order.php:523-527`):
```php
public function stripeGetPaymentFinishUrl()
{
    return $config->getCurrentShopUrl()."index.php?cl=stripeFinishPayment&id=" . $this->getId() . "&shp=" . $config->getShopId();
}
```

Der `handleStripeReturn()` in `OrderController.php:100-101` loescht die Session-Variable `usr` erst NACH dem Stripe-Redirect — zwischen dem initialen Aufruf und der Rueckkehr bleibt die Session aktiv.

**Empfehlung:**
1. **User-Verifizierung:** Pruefen, ob der eingeloggte User der Besteller ist
2. **Einmal-Token:** Statt der Order-ID ein kurzlebiges, signiertes Token verwenden:
```php
protected function getOrder()
{
    $sToken = Registry::getRequest()->getRequestParameter('token');
    // Token gegen Session oder DB validieren
    // Nur den eigenen User zulassen
}
```
3. **Keine Session-Variable setzen:** `stripeReinitializePayment()` sollte NICHT `setVariable('usr', ...)` aufrufen, wenn kein User eingeloggt ist

---

## 2. Hohe Findings

### 2.1 Unauthentifizierte Webhook-Endpoint-Manipulation — `FRONTEND`

**Datei:** `Application/Controller/StripeWebhook.php:31-93`
**Erreichbarkeit:** Frontend — unauthentifiziert
**Frontend-Exploitability:** **Direkt ausnutzbar**

```php
class StripeWebhook extends FrontendController  // KEIN AdminController!
{
    public function createWebhookEndpoint()  // Oeffentliche Methode
    {
        $blDeleted = $this->stripeDeleteWebhookEndpoint();  // LOESCHT bestehenden Webhook!
        if (!$blDeleted) {
            echo json_encode([...]);
            exit();
        }

        try {
            $sMode = Registry::getConfig()->getRequestEscapedParameter('mode') ?? '';
            $sPrivateKey = $sMode == 'test' ? ... : ...;
            $oApi = $oPaymentHelper->loadStripeApiWithToken($sPrivateKey);
            // Erstellt neuen Webhook-Endpoint
            $oWebhookEndpoint = $oApi->webhookEndpoints->create([...]);
            // Speichert Endpoint-ID und Secret in Shop-Config
        }
    }
}
```

**Angriffsvektor:**

1. Aufruf: `?cl=stripeWebhook&fnc=createWebhookEndpoint&mode=test`
2. In OXID 6.x kann jede oeffentliche Methode auf einem FrontendController via `fnc`-Parameter aufgerufen werden
3. `stripeDeleteWebhookEndpoint()` (Zeile 33) loescht den **bestehenden** Webhook-Endpoint ueber die Stripe-API
4. Falls die Neuerstellung fehlschlaegt (z.B. falscher Mode), ist der Webhook dauerhaft geloescht
5. **Konsequenz:** Keine Webhook-Benachrichtigungen mehr — Bestellungen werden nicht als bezahlt markiert

Zusaetzlich: Die Methode gibt in der JSON-Response die Webhook-Endpoint-ID zurueck (Zeile 71), was Informationsleckage darstellt.

**Empfehlung:** `createWebhookEndpoint()` in einen AdminController verschieben oder zumindest eine Admin-Session-Pruefung hinzufuegen:
```php
public function createWebhookEndpoint()
{
    if (!Registry::getConfig()->isAdmin() || !Registry::getSession()->checkSessionChallenge()) {
        http_response_code(403);
        exit();
    }
    // ...
}
```

---

### 2.2 Session-ID, stoken und rtoken in Redirect-URLs an Stripe — `FRONTEND`

**Datei:** `extend/Application/Model/PaymentGateway.php:53-89`
**Erreichbarkeit:** Frontend — Checkout-Flow
**Frontend-Exploitability:** Indirekt (Informationsleckage)

```php
protected function stripeGetAdditionalParameters()
{
    // ...
    $sSid = $oSession->sid(true);
    if ($sSid != '') {
        $sAddParams .= '&'.$sSid;                                    // SESSION-ID
    }
    $sAddParams .= '&stoken='.$oSession->getSessionChallengeToken(); // CSRF-TOKEN
    $sAddParams .= '&rtoken='.$oSession->getRemoteAccessToken();     // REMOTE ACCESS TOKEN
    return $sAddParams;
}

protected function getRedirectUrl()
{
    return $config->getCurrentShopUrl() . 'index.php?cl=order&fnc=handleStripeReturn&shp='
           . $config->getShopId() . $this->stripeGetAdditionalParameters();
}
```

Diese Return-URL wird an die Stripe-API gesendet als `return_url` des PaymentIntents. Die URL enthaelt:
- **OXID Session-ID** (`sid`)
- **CSRF Challenge Token** (`stoken`)
- **Remote Access Token** (`rtoken`)

Diese Tokens sind sichtbar in:
- Stripe-Server-Logs (Drittpartei)
- Browser-History und Browser-Cache
- HTTP-Referrer-Header bei Navigation nach dem Redirect
- Proxy-Logs

**Risiko:** Bei Kompromittierung der Stripe-Infrastruktur oder eines Netzwerk-Proxys → Session-Hijacking moeglich.

**Empfehlung:** Ein einmaliges, kurzlebiges Callback-Token generieren und in der Session speichern. Das Token identifiziert den Callback, ohne Session-ID, stoken oder rtoken preiszugeben:
```php
$sCallbackToken = bin2hex(random_bytes(16));
Registry::getSession()->setVariable('stripeCallbackToken', $sCallbackToken);
$sAddParams = '&stripeCallback='.$sCallbackToken;
```

---

## 3. Mittlere Findings

### 3.1 DOM-XSS via innerHTML in stripe_issuers.tpl — `FRONTEND`

**Datei:** `Application/views/frontend/tpl/stripe_issuers.tpl:35`
**Erreichbarkeit:** Frontend — Checkout-Seite (iDEAL, EPS, Sofort, Przelewy24, Bancontact)

```javascript
bankElement.on('change', function(event) {
    if (event.error) {
        document.getElementById('[{$sInputName}]_error').innerHTML = event.error;  // ZEILE 35
    }
});
```

**Vergleich:** Das `stripecreditcard.tpl` (Zeile 79) nutzt korrekt `textContent`:
```javascript
displayError.textContent = error.message;  // SICHER
```

**Risiko:** `event.error` von Stripe.js wird ueber `innerHTML` ins DOM geschrieben statt ueber `textContent`. Bei kompromittiertem Stripe.js CDN oder einer Schwachstelle in der Stripe-Bibliothek koennte HTML/JavaScript eingeschleust werden.

**Empfehlung:** `innerHTML` durch `textContent` ersetzen:
```javascript
document.getElementById('[{$sInputName}]_error').textContent = event.error;
```

---

### 3.2 Stripe-API-Daten ohne Escaping in Smarty-Templates — `FRONTEND`

**Datei:** `Application/views/frontend/tpl/stripecreditcard.tpl:18, 23`
**Erreichbarkeit:** Frontend — Checkout-Seite (Kreditkarte)

```smarty
[{foreach from=$oView->stripeGetUsedCards() item=card}]
    <option value="[{$card.id}]">[{$card.title}] ([{$card.expire}])</option>
[{/foreach}]
[{foreach from=$oView->stripeGetUsedCards() item=card}]
    <input type="hidden" ... value="[{$card.holder}]" />
[{/foreach}]
```

Die Kartendaten kommen aus der Stripe-API (`PaymentController.php:199-204`):
```php
$this->aStripeUsedCards[] = [
    'id'     => $oPaymentMethod->id,
    'title'  => 'XXXX XXXX XXXX ' . $oPaymentMethod->card->last4,
    'expire' => $oPaymentMethod->card->exp_month . '/' . $oPaymentMethod->card->exp_year,
    'holder' => $oPaymentMethod->billing_details->name  // Benutzername aus Stripe
];
```

**Risiko:** Smarty hat **kein Auto-Escaping**. `[{$card.holder}]` enthaelt den Karteninhabernamen aus der Stripe-API. Wenn ein Benutzer seinen Namen auf `"><script>alert(1)</script>` setzt, wird dies unescaped gerendert. Da `stripeGetUsedCards()` nur eigene Karten zurueckgibt, ist dies primaer Self-XSS — aber bei Kompromittierung der Stripe-API waeren alle Felder betroffen.

**Empfehlung:** Escaping hinzufuegen:
```smarty
<option value="[{$card.id|escape}]">[{$card.title|escape}] ([{$card.expire|escape}])</option>
<input type="hidden" ... value="[{$card.holder|escape}]" />
```

---

### 3.3 Exception-Messages ohne Encoding in Webhook-Responses — `FRONTEND`

**Datei:** `Application/Controller/StripeWebhook.php:110, 115, 119`
**Erreichbarkeit:** Frontend — Webhook-Endpoint

```php
// Zeile 110:
echo Registry::getLang()->translateString('STRIPE_WEBHOOK_EVENT_UNEXPECTED').':'.$oEx->getMessage();
// Zeile 115:
echo Registry::getLang()->translateString('STRIPE_WEBHOOK_SIGNATURE_FAILED').':'.$oEx->getMessage();
// Zeile 119:
echo $oEx->getMessage();
```

**Risiko:** Exception-Messages werden direkt per `echo` ausgegeben, ohne HTML-Escaping und ohne Content-Type-Header (`text/plain`). Wenn Exception-Messages HTML enthalten (z.B. von der Stripe-SDK oder PHP-Fehlern), koennte ein Browser dies als HTML interpretieren (Reflected XSS via Fehlermeldung).

**Empfehlung:** Content-Type setzen und Ausgabe escapen:
```php
header('Content-Type: text/plain; charset=UTF-8');
echo htmlspecialchars($oEx->getMessage(), ENT_QUOTES, 'UTF-8');
```

---

### 3.4 Publishable Key ohne JavaScript-Encoding in Templates — `FRONTEND`

**Dateien:**
- `Application/views/frontend/tpl/stripecreditcard.tpl:48`
- `Application/views/frontend/tpl/stripe_issuers.tpl:16`

```javascript
const pubKey = '[{$oPaymentModel->getPublishableKey()}]';   // stripecreditcard.tpl:48
var pubKey = '[{$oPaymentModel->getPublishableKey()}]';      // stripe_issuers.tpl:16
```

**Risiko:** Der Publishable Key wird direkt in einen JavaScript-String eingefuegt. Stripe-Keys enthalten normalerweise nur alphanumerische Zeichen und Unterstriche (`pk_test_...`), aber wenn der Konfigurationswert manipuliert wird (DB-Kompromittierung, Admin-Manipulation), koennte ein Wert wie `'; alert(1); '` zu JavaScript-Injection fuehren.

**Empfehlung:** JavaScript-Escaping verwenden:
```javascript
const pubKey = '[{$oPaymentModel->getPublishableKey()|escape:'javascript'}]';
```

---

## 4. Niedrige Findings

### 4.1 SQL-Concatenation ohne Prepared Statements in Events.php — `BACKEND`

**Datei:** `Core/Events.php:280-282`
**Erreichbarkeit:** Backend — nur bei Modul-Aktivierung

```php
protected static function insertRowIfNotExists($sTableName, $aKeyValue, $sQuery, $aParams = [])
{
    $sCheckQuery = "SELECT * FROM {$sTableName} WHERE 1";
    foreach ($aKeyValue as $key => $value) {
        $sCheckQuery .= " AND $key = '$value'";  // Direkte Concatenation
    }
    DatabaseProvider::getDb()->getOne($sCheckQuery);
}
```

Zusaetzlich in Zeile 152:
```php
"INSERT INTO oxpayments(...) VALUES ('{$sPaymentId}', 0, '{$sPaymentTitle}', ...)"
```

**Risiko:** Alle Werte sind hardcoded (Payment-IDs und -Titel aus `Payment::getStripePaymentMethods()`). Die Methode laeuft nur bei Modul-Aktivierung im Admin. **Nicht direkt exploitabel**, aber schlechte Praxis — bei zukuenftiger Erweiterung mit dynamischen Werten entsteht eine SQL-Injection.

**Empfehlung:** Prepared Statements verwenden:
```php
$sCheckQuery .= " AND $key = ?";
$aCheckParams[] = $value;
```

---

### 4.2 Timing-Attack bei Cron secureKey-Vergleich — `FRONTEND`

**Datei:** `cron.php:16`

```php
if (!empty($sSecureKey) && $sSecureKey == \OxidEsales\Eshop\Core\Registry::getConfig()->getShopConfVar('sStripeCronSecureKey')) {
    return true;
}
```

**Risiko:** Der `==`-Operator fuehrt einen Byte-fuer-Byte-Vergleich durch, der bei Nichtmatch frueher abbricht (Timing Side-Channel). Ein Angreifer koennte den secureKey Zeichen fuer Zeichen erraten.

**Empfehlung:** `hash_equals()` verwenden:
```php
if (!empty($sSecureKey) && hash_equals($sStripeCronSecureKey, $sSecureKey)) {
```

---

### 4.3 getRawValue() in E-Mail-Headern — `FRONTEND`

**Datei:** `extend/Core/Email.php:65, 83, 86`

```php
$subject = Registry::getLang()->translateString('...') . " " . $shop->oxshops__oxname->getRawValue() . " (#" . $oOrder->oxorder__oxordernr->value . ")";
$fullName = $oOrder->oxorder__oxbillfname->getRawValue() . " " . $oOrder->oxorder__oxbilllname->getRawValue();
$this->setReplyTo($shop->oxshops__oxorderemail->value, $shop->oxshops__oxname->getRawValue());
```

**Risiko:** `getRawValue()` umgeht das OXID-Encoding. In E-Mail-Subjects und Reply-To-Headern koennten bei manipulierten Shopnamen oder Kundennamen Header-Injection-Angriffe moeglich sein (CRLF-Injection in Headern). Das Risiko ist gering, da PHPMailer/SwiftMailer typischerweise CRLF in Headern filtern.

**Empfehlung:** `->value` statt `getRawValue()` verwenden, oder explizit filtern.

---

### 4.4 Floating-Point-Vergleich bei Betragsvalidierung — `FRONTEND`

**Datei:** `Application/Model/TransactionHandler/Payment.php:39`

```php
if (abs($oTransaction->amount_received - PaymentHelper::getInstance()->priceInCent($oOrder->oxorder__oxtotalordersum->value)) < 0.01) {
    $oOrder->stripeMarkAsPaid();
}
```

**Risiko:** `amount_received` (Integer in Cents von Stripe) wird mit dem Ergebnis von `priceInCent()` (ebenfalls Integer) verglichen. Der `abs() < 0.01`-Vergleich erlaubt theoretisch eine Abweichung von weniger als 1 Cent, was fuer Integer-Vergleiche unerheblich ist. Dennoch waere ein exakter Vergleich (`== 0` oder `=== 0`) praeziser und weniger fehleranfaellig.

**Empfehlung:**
```php
if ($oTransaction->amount_received === PaymentHelper::getInstance()->priceInCent($oOrder->oxorder__oxtotalordersum->value)) {
```

---

## Positive Aspekte

| Aspekt | Bewertung |
|--------|-----------|
| Webhook-Signaturpruefung | Korrekt via Stripe SDK `Webhook::constructEvent()` |
| SQL-Queries in Cronjobs | Sauber parametrisiert |
| Admin-Controller CSRF | `StripeConnect::stripeFinishOnBoarding()` prueft `checkSessionChallenge()` |
| Payment-Betraege | `priceInCent()` konvertiert korrekt in Integer |
| Cron-Authentifizierung | CLI-Erkennung + secureKey fuer Web-Aufrufe |
| Kreditkarten-Template | Nutzt `textContent` statt `innerHTML` fuer Fehleranzeige |

---

## Uebersicht der Empfehlungen (Prioritaet)

| Prio | Finding | Aufwand | Quick-Fix |
|------|---------|---------|-----------|
| P0 | 1.1 IDOR + Session-Hijacking | Mittel | User-Verifizierung + signiertes Token statt Order-ID |
| P1 | 2.1 Unauthentifizierte Webhook-Manipulation | Niedrig | Admin-Session-Check in createWebhookEndpoint() |
| P1 | 2.2 Session-Tokens in Redirect-URLs | Mittel | Einmal-Callback-Token statt sid/stoken/rtoken |
| P2 | 3.1 DOM-XSS via innerHTML | Niedrig | `innerHTML` → `textContent` |
| P2 | 3.2 Stripe-API-Daten ohne Escaping | Niedrig | `\|escape` in Smarty-Templates |
| P2 | 3.3 Exception-Messages ohne Encoding | Niedrig | Content-Type + htmlspecialchars |
| P2 | 3.4 Publishable Key ohne JS-Encoding | Niedrig | `\|escape:'javascript'` |
| P3 | 4.1 SQL-Concatenation in Events.php | Niedrig | Prepared Statements |
| P3 | 4.2 Timing-Attack Cron secureKey | Niedrig | `hash_equals()` |
| P3 | 4.3 getRawValue() in Email-Headern | Niedrig | `->value` verwenden |
| P3 | 4.4 Floating-Point Betragsvergleich | Niedrig | Exakter Integer-Vergleich |

---

*Erstellt: 2026-03-05 | Auditor: Claude Code | Methode: Statische Code-Analyse*
