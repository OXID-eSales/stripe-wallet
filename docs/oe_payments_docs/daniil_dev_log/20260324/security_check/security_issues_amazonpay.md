# Security Audit: AmazonPay-Modul (osc/amazonpay) — OXID 6.5

**Datum:** 2026-03-05
**Scope:** `source/source/modules/osc/amazonpay`
**OXID Version:** 6.5 (PHP 7.4+, Smarty Templates)
**Fokus:** Frontend-Angriffsvektoren, Verifikation gegen OXID 7 Audit
**Verifiziert gegen Quellcode:** Ja

---

## Zusammenfassung

| Kategorie | Anzahl |
|-----------|--------|
| Kritisch | 1 |
| Hoch | 2 |
| Mittel | 6 |
| Niedrig | 3 |

**Gesamtbewertung:** Das AmazonPay-Modul hat eine **kritische SQL-Injection** in der
Log-Repository-Schicht sowie einen **unauthentifizierten Endpoint** (`poll`), ueber
den Order-Status geaendert werden kann. Mehrere Frontend-Controller haben keine
CSRF-Pruefung. Im Vergleich zur OXID 7 Version ist das Risiko bei Template-Ausgaben
**hoeher**, da Smarty kein Auto-Escaping bietet.

**Hinweis:** Der PHP-Code ist zwischen OXID 6.5 und OXID 7 identisch. Die Unterschiede
liegen ausschliesslich in der Template-Schicht (Smarty vs. Twig).

---

## Legende

| Tag | Bedeutung |
|-----|-----------|
| `FRONTEND` | Vom Kunden (anonym oder eingeloggt) erreichbar |
| `BACKEND` | Nur aus dem Admin-Panel erreichbar |

---

## 1. Kritische Findings

### 1.1 SQL Injection in LogRepository::deleteLogMessageByOrderId — `BACKEND`

**Datei:** `src/Core/Repository/LogRepository.php:229-235`
**Erreichbarkeit:** Wird von `Model/Order::delete()` aufgerufen (Admin-Order-Loeschung)
**Frontend-Exploitability:** Nicht direkt erreichbar
**Verifiziert:** Ja

```php
public function deleteLogMessageByOrderId(string $orderId)
{
    $sql = 'DELETE FROM ' . self::TABLE_NAME . ' WHERE OSC_AMAZON_OXORDERID =' . $orderId;
    DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->execute(
        $sql
    );
}
```

**Risiko:** `$orderId` wird **direkt** in den SQL-String konkateniert — ohne `quote()`,
ohne Prepared Statement. Alle anderen Methoden in derselben Klasse nutzen korrekt
Prepared Statements mit `?`-Platzhaltern (z.B. Zeilen 47, 71-73, 130-132). Dies ist
ein klarer Bruch des eigenen Patterns innerhalb derselben Datei.

**Angriffsvektor:** Erfordert Admin-Rechte (Order loeschen). Bei kompromittiertem
Admin-Account oder CSRF im Admin-Panel koennte beliebiges SQL ausgefuehrt werden.

**Empfehlung:**
```php
$sql = 'DELETE FROM ' . self::TABLE_NAME . ' WHERE OSC_AMAZON_OXORDERID = ?';
DatabaseProvider::getDb()->execute($sql, [$orderId]);
```

---

## 2. Hohe Findings

### 2.1 Unauthentifizierter poll-Endpoint mit Order-Status-Aenderung — `FRONTEND`

**Datei:** `src/Controller/DispatchController.php:119-125`
**Erreichbarkeit:** Frontend — ohne Login erreichbar via `?cl=amazondispatch&action=poll&orderId=<ID>`
**Frontend-Exploitability:** **Direkt ausnutzbar**
**Verifiziert:** Ja

```php
case 'poll':
    /** @var string $orderId */
    $orderId = Registry::getRequest()->getRequestParameter('orderId');
    OxidServiceProvider::getAmazonService()->checkOrderState($orderId);
    break;
```

`checkOrderState()` (`AmazonService.php:649-730`) laedt die Order, ruft die Amazon-API
ab und kann basierend auf der API-Response:
- `markOrderPaid()` aufrufen (Zeile 710-715) — markiert Order als bezahlt
- `processCancel()` aufrufen (Zeile 708, 737-738) — storniert die Order

Es gibt:
- **Keine Session-Token-Validierung** (kein `stoken`-Check)
- **Keine Authentifizierungspruefung** (kein Login erforderlich)
- **Keine Rate-Limiting**

**Angriffsvektor:**
1. Angreifer kennt oder erraet OXID-Order-IDs (32-Zeichen-Hex)
2. Angreifer ruft `?cl=amazondispatch&action=poll&orderId=<ORDER_ID>` auf
3. Das Modul fragt den Charge-Status bei Amazon ab und aendert ggf. den Shop-Order-Status

**Praktisches Risiko:** Ein Angreifer kann damit zwar keinen Payment-Status bei Amazon
aendern, aber er kann die Amazon-API pro Order-Abfrage triggern (API-Limit-Belastung).
Schwerwiegender: Falls eine Order bei Amazon als "Canceled" gefuehrt wird, der Shop
aber noch nicht synchronisiert hat, kann der Angreifer gezielt die Stornierung im
Shop ausloesen.

**Empfehlung:**
```php
case 'poll':
    if (!Registry::getSession()->checkSessionChallenge()) {
        break;
    }
    $orderId = Registry::getRequest()->getRequestParameter('orderId');
    OxidServiceProvider::getAmazonService()->checkOrderState($orderId);
    break;
```

---

### 2.2 Open Redirect ueber Amazon API-Response — `FRONTEND`

**Datei:** `src/Controller/OrderController.php:345-349`
**Erreichbarkeit:** Frontend — waehrend des Checkout-Flows
**Frontend-Exploitability:** Abhaengig von Amazon-API-Kompromittierung
**Verifiziert:** Ja

```php
$redirectUrl = PhpHelper::getArrayValue('amazonPayRedirectUrl', $response) ?: '';
if ($redirectUrl !== '') {
    Registry::getUtils()->redirect($redirectUrl, false, 301);
}
```

**Risiko:** Die URL kommt aus der Amazon-API-Response und wird **ohne jede Validierung**
als 301-Redirect verwendet. Kein Whitelist-Check gegen Amazon-Domains, keine Pruefung
auf HTTPS.

**Empfehlung:**
```php
if ($redirectUrl !== '' && preg_match('#^https://(pay|payments)\.amazon\.(com|de|co\.\w+)/#', $redirectUrl)) {
    Registry::getUtils()->redirect($redirectUrl, false, 302);
}
```

---

## 3. Mittlere Findings

### 3.1 Fehlende CSRF-Pruefung in AmazonCheckoutController::createCheckout — `FRONTEND`

**Datei:** `src/Controller/AmazonCheckoutController.php:27-64`
**Erreichbarkeit:** `?cl=amazoncheckout&fnc=createCheckout&anid=<ARTIKEL_ID>`
**Verifiziert:** Ja

Die Methode nimmt den Parameter `anid` aus dem Request, fuegt den Artikel zum Warenkorb
hinzu und startet eine Amazon Checkout Session — ohne `stoken`-Validierung.

**Angriffsvektor:** Ein Angreifer kann einem eingeloggten Nutzer per CSRF-Link einen
Artikel in den Warenkorb legen und einen Amazon-Checkout starten.

**Empfehlung:** `stoken`-Pruefung hinzufuegen oder den Endpoint auf POST beschraenken.

---

### 3.2 Fehlende CSRF-Pruefung in AmazonCheckoutAjaxController — `FRONTEND`

**Datei:** `src/Controller/AmazonCheckoutAjaxController.php:18-35`
**Erreichbarkeit:** `?cl=amazoncheckoutajax&fnc=confirmAGB&confirm=1`
**Verifiziert:** Ja

Die Methoden `confirmAGB()`, `confirmDPA()`, `confirmSPA()` setzen AGB-Zustimmungen
in der Session ohne Token-Validierung. Ein Angreifer kann via CSRF die AGB-Zustimmung
fuer einen Nutzer setzen.

**Risiko:** In Kombination mit anderen Angriffen koennte die T&C-Validierung umgangen
werden.

---

### 3.3 Amazon-API-Daten ohne Escaping in Smarty-Template — `FRONTEND`

**Datei:** `views/elements/shippingandpayment_wave.tpl:54`
**Source:** `src/Core/ViewConfig.php:232-236`
**Verifiziert:** Ja

```smarty
[{$oViewConf->getPaymentDescriptor()}]
```

```php
public function getPaymentDescriptor(): string
{
    $amazonSession = OxidServiceProvider::getAmazonService()->getCheckoutSession();
    return $amazonSession['response']['paymentPreferences'][0]['paymentDescriptor'];
}
```

**Risiko:** `paymentDescriptor` stammt direkt aus der Amazon-API-Response und wird
ohne Sanitisierung in HTML ausgegeben. **Im Gegensatz zu OXID 7 (Twig mit
Auto-Escaping) bietet Smarty hier keinen automatischen Schutz.** Falls die
Amazon-API-Response manipuliert wird (MITM, kompromittierte API), ist XSS moeglich.

**Bewertung:** In OXID 6.5 (Smarty) **nicht** durch Auto-Escaping geschuetzt —
hoeher einzustufen als in der OXID 7 Version.

**Empfehlung:**
```smarty
[{$oViewConf->getPaymentDescriptor()|escape:'html'}]
```

---

### 3.4 Checkout-Session-ID — teilweise validiert — `FRONTEND`

**Datei:** `src/Controller/DispatchController.php:179-188`
**Verifiziert:** Ja

```php
protected function getRequestAmazonSessionId(): string
{
    $amazonSessionIdRequest = Registry::getRequest()->getRequestParameter(
        Constants::CHECKOUT_REQUEST_PARAMETER_ID
    );
    $amazonSessionIdService = OxidServiceProvider::getAmazonService()->getCheckoutSessionId();
    return
        $amazonSessionIdRequest === $amazonSessionIdService ? $amazonSessionIdRequest : '';
}
```

**Risiko:** Es gibt einen **Session-Abgleich** (`$amazonSessionIdRequest === $amazonSessionIdService`),
der verhindert, dass beliebige Session-IDs akzeptiert werden. Allerdings fehlt eine
Format-Validierung beim initialen `storeAmazonSession()`-Aufruf — dort wird jeder
beliebige String in die Session geschrieben.

**Bewertung:** Grundschutz vorhanden, aber keine Defense-in-Depth.

**Empfehlung:** Regex-Validierung gegen Amazon-Session-ID-Format beim Speichern.

---

### 3.5 Schwache Kryptografie (UUID-Fallback) — `FRONTEND`

**Datei:** `src/Core/Config.php:469-478`
**Verifiziert:** Ja

```php
public function getUuid(): string
{
    try {
        $uuid = bin2hex(random_bytes(16));
    } catch (Exception $ex) {
        $uuid = md5(uniqid('', true) . '|' . microtime())
              . substr(md5((string)mt_rand()), 0, 24);
    }
    return $uuid;
}
```

**Risiko:** Identisches Pattern wie im PayPal-Modul. Der Fallback nutzt `md5()`,
`uniqid()` und `mt_rand()` — alles kryptografisch schwach.

**Empfehlung:** Fallback entfernen, Exception durchwerfen.

---

### 3.6 TOCTOU bei IPN-Verarbeitung — `FRONTEND`

**Datei:** `src/Controller/DispatchController.php:90-116`
**Verifiziert:** Ja

```php
case 'ipn':
    $message = Message::fromRawPostData();       // liest php://input
    $validator = new MessageValidator();
    if ($validator->isValid($message)) {
        $post = PhpHelper::getPost();             // liest ERNEUT aus $_POST oder php://input
        $message = PhpHelper::jsonToArray($post['Message']);
        // ... verarbeitet $message
    }
```

`PhpHelper::getPost()` (Zeile 59-73) prueft zuerst `$_POST`, dann `php://input`:

```php
public static function getPost(): array
{
    if (!empty($_POST)) {
        return $_POST;      // ← andere Quelle als die validierte!
    }
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    // ...
}
```

**Risiko:** Nach erfolgreicher SNS-Signatur-Validierung werden die Daten erneut aus
einer separaten Quelle gelesen. Bei speziellen Content-Type-Headern koennte `$_POST`
andere Daten enthalten als `php://input`. In der Praxis schwer ausnutzbar, da
Amazon SNS als `application/json` sendet.

**Empfehlung:** Das bereits validierte `$message`-Objekt verwenden statt erneut zu parsen.

---

## 4. Niedrige Findings

### 4.1 SQL-Injection in ORDER BY (intern) — `BACKEND`

**Datei:** `src/Core/Repository/LogRepository.php:112-120`
**Verifiziert:** Ja

```php
public function findLogMessageForChargePermissionId(
    string $chargePermissionId,
    string $orderBy = 'OXTIMESTAMP'
): array {
    return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll(
        'SELECT * FROM ' . self::TABLE_NAME
        . ' WHERE OSC_AMAZON_CHARGE_PERMISSION_ID = ? ORDER BY ' . $orderBy,
        [$chargePermissionId]
    );
}
```

**Risiko:** `$orderBy` wird direkt in SQL konkateniert. Aktuell nur mit Default-Wert
`'OXTIMESTAMP'` aufgerufen, aber das Pattern ist fragil. Falls zukuenftig
User-Input in den Parameter fliesst, ist SQL-Injection moeglich.

**Empfehlung:** Whitelist fuer erlaubte Spaltennamen.

---

### 4.2 json_decode ohne Tiefenlimit — `FRONTEND`

**Dateien:**
- `src/Core/Helper/PhpHelper.php:19` (`jsonToArray`)
- `src/Core/Helper/PhpHelper.php:67` (`getPost`)
- `src/Core/AmazonResponseService.php:15`

```php
$decoded = json_decode($json, true);  // Standard-Tiefe: 512
```

**Risiko:** DoS durch tief verschachtelte JSON-Payloads. In der Praxis durch
Amazon-API-Antworten und IPN-Nachrichten begrenzt.

**Empfehlung:** `json_decode($json, true, 16, JSON_THROW_ON_ERROR)` verwenden.

---

### 4.3 Smarty-Templates ohne Escaping (weitere Stellen)

**Dateien:** Diverse `.tpl`-Dateien im `views/`-Verzeichnis

Im Gegensatz zu OXID 7 (Twig) hat Smarty **kein Auto-Escaping**. Alle
`[{$variable}]`-Ausgaben muessen manuell escaped werden. Dies betrifft
insbesondere Stellen, an denen Amazon-API-Daten oder DB-Werte ausgegeben
werden.

**Empfehlung:** Alle dynamischen Ausgaben in Smarty-Templates auf
`|escape:'html'` pruefen.

---

## 5. Twig vs. Smarty — Unterschiede zur OXID 7 Version

| Aspekt | OXID 7 (Twig) | OXID 6.5 (Smarty) |
|--------|---------------|-------------------|
| Auto-Escaping | Standardmaessig aktiv | **Nicht vorhanden** |
| `getPaymentDescriptor()` | Geschuetzt (Auto-Escape) | **Ungeschuetzt** |
| `\|raw`-Risiko | Explizit deaktivierbar | Nicht relevant (nie aktiv) |
| API-Daten in HTML | Durch Twig abgesichert | **Manuelles Escaping noetig** |

**Fazit:** Alle Smarty-Templates mit dynamischen Ausgaben sind potenzielle
XSS-Vektoren, sofern nicht explizit `|escape` verwendet wird. Das ergibt
fuer OXID 6.5 ein **hoeheres Grundrisiko** als in der OXID 7 Version.

---

## 6. Frontend-Controller-Analyse

| Controller | Endpoint | User-Input | Auth | CSRF | Status |
|-----------|----------|-----------|------|------|--------|
| `DispatchController` (result) | `?cl=amazondispatch&action=result` | `amazonCheckoutSessionId` | Nein | Nein | Risiko |
| `DispatchController` (ipn) | `?cl=amazondispatch&action=ipn` | SNS POST Body | Nein (Webhook) | SNS-Signatur | Sicher |
| `DispatchController` (poll) | `?cl=amazondispatch&action=poll` | `orderId` | **Nein** | **Nein** | **KRITISCH** |
| `DispatchController` (signin) | `?cl=amazondispatch&action=signin` | `buyerToken` | Nein | Nein | Risiko |
| `AmazonCheckoutController` | `?cl=amazoncheckout&fnc=createCheckout` | `anid` | Login | **Nein** | **Risiko** |
| `AmazonCheckoutAjaxController` | `?cl=amazoncheckoutajax&fnc=confirmAGB` | `confirm` | Session | **Nein** | **Risiko** |
| `OrderController` | `?cl=order` (execute) | `amazonCheckoutSessionId` | Login | stoken | Sicher |
| `UserComponent` | Extension | Session-basiert | Login | stoken | Sicher |

---

## 7. Empfehlungen nach Prioritaet

### Prioritaet 1 — Sofort beheben

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 1 | **SQL Injection** | `LogRepository.php:231` | Prepared Statement verwenden |
| 2 | **Unauthentifizierter poll-Endpoint** | `DispatchController.php:119-125` | Session-Token oder Login-Pruefung |

### Prioritaet 2 — Zeitnah adressieren

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 3 | Open Redirect | `OrderController.php:347` | URL gegen Amazon-Domain validieren |
| 4 | CSRF createCheckout | `AmazonCheckoutController.php:27` | stoken-Pruefung |
| 5 | CSRF AGB-Confirm | `AmazonCheckoutAjaxController.php` | stoken-Pruefung |
| 6 | XSS paymentDescriptor (Smarty) | `shippingandpayment_wave.tpl:54` | `\|escape:'html'` |
| 7 | TOCTOU bei IPN | `DispatchController.php:91-97` | Validiertes Message-Objekt wiederverwenden |

### Prioritaet 3 — Nice to have

| # | Finding | Datei | Aktion |
|---|---------|-------|--------|
| 8 | SQL ORDER BY Injection | `LogRepository.php:117` | Whitelist fuer Spaltennamen |
| 9 | Session-ID Validierung | `DispatchController.php:224` | Regex auf Amazon-Session-Format |
| 10 | json_decode Tiefenlimit | `PhpHelper.php:19, 67` | depth-Parameter setzen |
| 11 | UUID-Fallback | `Config.php:475` | Fallback entfernen |
| 12 | Smarty-Escaping allgemein | `views/**/*.tpl` | Alle Ausgaben pruefen |

---

## 8. Vergleich mit PayPal-Modul (OXID 6.5)

| Aspekt | PayPal-Modul | AmazonPay-Modul |
|--------|-------------|-----------------|
| SQL Injection | View-Name-Interpolation (mittel) | **Direkte Konkatenation** (kritisch) |
| DOM-XSS (innerHTML) | Nicht vorhanden (kein Apple Pay in 6.5) | Nicht vorhanden (positiv) |
| unserialize() | Ja (PayPalPlusRefund) — kritisch | Nein (positiv) |
| CSRF-Schutz | Weitgehend vorhanden | **Fehlt bei 3 Endpoints** |
| Unauthentifizierte Endpoints | Webhook (mit Signatur) | **poll ohne Auth** (kritisch) |
| Open Redirect | Theoretisch (Session-basiert) | **Direkt aus API-Response** |
| Smarty-Escaping | Teilweise fehlend (Admin) | Fehlend bei API-Daten (Frontend) |
| Webhook-Signatur | PayPal EventVerifier | AWS SNS MessageValidator |

---

## 9. Fazit

Das AmazonPay-Modul hat eine **kleinere Template-Angriffsflaeche** als das
PayPal-Modul (kein `innerHTML`, kein `unserialize()`), aber dafuer
**schwerwiegendere serverseitige Schwachstellen**:

**Positiv:**
- Keine DOM-XSS-Vektoren
- Kein `unserialize()`
- SNS-Webhook-Signatur korrekt implementiert

**Negativ:**
- **SQL Injection** in `deleteLogMessageByOrderId()` — die einzige echte SQL-Injection
  ueber alle auditierten Module hinweg
- **Unauthentifizierter `poll`-Endpoint** — das gravierendste Frontend-Finding,
  kann Order-Status aendern
- **Fehlende CSRF-Pruefung** bei drei Endpoints
- **Open Redirect** ohne URL-Validierung
- **Smarty-Templates** ohne Escaping bei API-Daten (OXID 6.5 spezifisch)

**Handlungsempfehlung:** Findings 1.1 (SQL Injection) und 2.1 (poll-Endpoint) haben
die hoechste Prioritaet. Der poll-Endpoint ist der gefaehrlichste Frontend-Vektor
ueber alle bisher auditierten Module hinweg, da er ohne jede Authentifizierung
Order-Stornierungen und Payment-Markierungen ausloesen kann.
