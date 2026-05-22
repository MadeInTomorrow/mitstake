# MiTstake Agent

Plugin WordPress per il monitoraggio automatico degli errori PHP fatali (HTTP 500).
Intercetta eccezioni non gestite e fatal error, costruisce un report compresso (ZIP)
e lo invia a un hub centralizzato tramite HTTP POST.

---

## Indice

1. [Funzionamento](#funzionamento)
2. [Architettura](#architettura)
3. [Configurazione](#configurazione)
4. [Sicurezza](#sicurezza)
5. [Payload ZIP](#payload-zip)
6. [API endpoint hub](#api-endpoint-hub)
7. [Riferimento costanti](#riferimento-costanti)
8. [Pannello admin](#pannello-admin)
9. [Aggiornamenti automatici](#aggiornamenti-automatici)
10. [Requisiti](#requisiti)
11. [Installazione](#installazione)

---

## Funzionamento

```
Richiesta HTTP
     │
     ▼
WordPress bootstrap
     │
     ▼
mitstake-agent.php (plugin_loaded)
  ├── Carica configurazione (wp_options → config.php → default)
  ├── Registra register_shutdown_function → MiTstakeAgent::onShutdown()
  └── Registra set_exception_handler   → MiTstakeAgent::onException()
     │
     ▼ (in caso di errore fatale / eccezione non gestita)
MiTstakeAgent::handleError()
  ├── checkCooldown()      — evita flood di report
  ├── buildLogLine()       — riga Combined Log Format (sintetica)
  ├── buildZip()           — pacchetto ZIP in memoria
  │     ├── report.json
  │     ├── logs/php_error.log
  │     ├── logs/wp_debug.log
  │     ├── logs/stacktrace.txt
  │     ├── logs/request.txt
  │     └── sources/…/*.php
  └── sendReport()         — POST multipart/form-data all'hub
```

### Tipi di errore intercettati

| Handler | Trigger | Livelli PHP |
|---|---|---|
| `onShutdown` | `register_shutdown_function` | `E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR` |
| `onException` | `set_exception_handler` | Qualsiasi `Throwable` non gestito |

---

## Architettura

### File

| File | Ruolo |
|---|---|
| `mitstake-agent.php` | Entry point del plugin; classe `MiTstakeAgent` (metodi statici) |
| `config.php` | Configurazione opzionale via costanti PHP (non committare) |
| `config-example.php` | Template di esempio per `config.php` |
| `index.php` | Silencer (evita directory listing) |

### Classe `MiTstakeAgent`

La classe usa **solo metodi statici** per evitare dipendenze dall'ordine di inizializzazione
di WordPress. Non richiede istanziazione e non ha stato di istanza.

| Metodo | Visibilità | Scopo |
|---|---|---|
| `onShutdown()` | `public static` | Handler shutdown — intercetta fatal error |
| `onException(Throwable)` | `public static` | Handler eccezione — intercetta Throwable |
| `handleError(...)` | `private static` | Core: orchestra raccolta dati e invio |
| `buildLogLine(string)` | `private static` | Genera riga Combined Log Format |
| `buildZip(...)` | `private static` | Costruisce ZIP in memoria su `/tmp` |
| `tailFile(string)` | `private static` | Legge ultime N righe di un file |
| `extractPhpFiles(string)` | `private static` | Estrae path `.php` dallo stack trace |
| `buildRequestInfo()` | `private static` | Serializza `$_SERVER` rilevanti |
| `redactUri(string)` | `private static` | Oscura query param sensibili (token, key, pass…) |
| `sendReport(string, string)` | `private static` | Invia via `wp_remote_post` o cURL fallback |
| `sendViaCurl(string, string)` | `private static` | Fallback cURL puro (pre-WP init) |
| `buildMultipartBody(...)` | `private static` | Costruisce body `multipart/form-data` manualmente |
| `checkCooldown()` | `private static` | Controlla se il cooldown è scaduto |
| `updateCooldown()` | `private static` | Aggiorna il timestamp del cooldown |
| `checkForUpdates(...)` | `public static` | Auto-aggiornamenti da GitHub releases |
| `addSettingsPage()` | `public static` | Registra voce menu admin |
| `registerSettings()` | `public static` | Registra campi e sezioni settings |
| `renderField(array)` | `public static` | Renderizza singolo campo settings |
| `sanitizeSettings(array)` | `public static` | Validazione e sanitizzazione input admin |
| `adminNotices()` | `public static` | Mostra errori di validazione in admin |
| `renderSettingsPage()` | `public static` | Renderizza la pagina impostazioni |

---

## Configurazione

La configurazione segue un **ordine di priorità decrescente**:

```
1. wp_options (Impostazioni Admin WordPress)  ← priorità massima
2. config.php (file fisico nella cartella plugin)
3. Valori di default hardcoded               ← priorità minima
```

Le costanti vengono definite con `define()` e non sono sovrascrivibili
una volta fissate: il primo `define()` vince sempre.

### Configurazione tramite pannello Admin

Percorso: **Impostazioni → MiTstake Agent**

I valori vengono salvati in `wp_options` con chiave `eha_settings` (array).

### Configurazione tramite `config.php`

Copia `config-example.php` come `config.php` nella stessa cartella del plugin
e compila i valori. Il file viene letto **solo se non esiste già una configurazione
in wp_options** per quella costante.

> **Non committare `config.php`**: contiene la API key.
> Aggiungilo a `.gitignore`.

---

## Sicurezza

Il plugin implementa diverse misure di sicurezza documentate con codici (es. `B-1`, `C-1`).

### B-1 — Redazione parametri sensibili nell'URI

`redactUri()` oscura i valori dei seguenti query parameter prima che
vengano inclusi in qualsiasi output o payload:

```
token | key | pass | secret | auth | api_key | nonce
```

Esempio: `?token=abc123` → `?token=[REDACTED]`

Il pattern è case-insensitive e si applica a `REQUEST_URI`,
alla riga di log sintetica e al `report.json` all'interno dello ZIP.

### B-2 — Cooldown anti-flood

Ogni invio aggiorna un timestamp. Prima di inviare un nuovo report,
il plugin verifica che siano trascorsi almeno `EHA_COOLDOWN` secondi dall'ultimo.

Meccanismo di storage (in ordine di preferenza):
1. **WP Transient API** (`set_transient` / `get_transient`) — disponibile dopo WP init.
   Il nome del transient è `eha_cd_` + `md5(EHA_SITE_ID . EHA_API_KEY)`, reso non
   prevedibile senza conoscere le credenziali del sito.
2. **File in `/tmp`** — fallback pre-WP-init.

### C-1 — Blocco file con credenziali

I seguenti file non vengono **mai** inclusi nello ZIP, indipendentemente
dal fatto che compaiano nello stack trace:

```
wp-config.php | .env | .env.local | .env.production | config.php
```

Viene applicato anche un filtro regex per basename che termina con
`pass`, `secret`, `credential`, `auth_key`, `.pem`, `.key`.

### Altre misure

| Misura | Dettaglio |
|---|---|
| HTTPS obbligatorio | Il plugin si disabilita se `EHA_HUB_URL` non inizia con `https://` |
| Path traversal | Ogni file sorgente viene validato con `realpath()` e deve stare dentro `ABSPATH` |
| Dimensione file | File sorgenti > 512 KB non vengono inclusi |
| Dimensione ZIP | ZIP > `EHA_MAX_ZIP_BYTES` (default 20 MB) viene scartato |
| `sslverify: true` | `wp_remote_post` e cURL verificano sempre il certificato TLS del hub |
| `GDPR / EHA_SEND_WP_USER` | Dati identificativi dell'utente WP (ID, login, ruoli) sono esclusi di default |
| Capability check | La pagina admin richiede `manage_options` |
| API key mascherata | Nell'UI admin la chiave è mostrata oscurata (`••••••••last8chars`) |

---

## Payload ZIP

Lo ZIP viene costruito **interamente in memoria** su un file temporaneo in `sys_get_temp_dir()`
e cancellato subito dopo la lettura.

```
report.zip
├── report.json          ← metadati richiesta (site_id, timestamp, ip, path, ua…)
├── logs/
│   ├── php_error.log    ← ultime EHA_MAX_LOG_LINES righe del log PHP
│   ├── wp_debug.log     ← ultime EHA_MAX_LOG_LINES righe di WP_CONTENT_DIR/debug.log
│   ├── stacktrace.txt   ← stack trace completo (o "fatal error" se non disponibile)
│   └── request.txt      ← variabili $_SERVER rilevanti + dati utente WP (se abilitato)
└── sources/
    └── …path/to/file.php  ← fino a EHA_MAX_SOURCE_FILES file PHP dallo stack trace
```

### `report.json` — schema

```json
{
  "site_id":   "string",
  "timestamp": "ISO-8601 UTC",
  "log_line":  "Combined Log Format string",
  "method":    "GET|POST|…",
  "path":      "/path?param=[REDACTED]",
  "ip":        "1.2.3.4",
  "useragent": "Mozilla/…"
}
```

---

## API endpoint hub

### `POST /api/v1/report`

**Headers:**

```
Authorization: Bearer <EHA_API_KEY>
Content-Type:  multipart/form-data; boundary=----EHABoundary<hex>
```

**Campi form-data:**

| Campo | Tipo | Descrizione |
|---|---|---|
| `log_line` | `string` (max 2048 char) | Riga Combined Log Format |
| `error_timestamp` | `string` ISO-8601 UTC | Timestamp errore |
| `ip` | `string` | IP client |
| `method` | `string` | Metodo HTTP |
| `path` | `string` | URI redacted |
| `useragent` | `string` | User-Agent |
| `report_zip` | `file` (`application/zip`) | Pacchetto dati completo |

**Risposte attese:** `200` o `201` = successo. Qualsiasi altro codice
viene loggato in `error_log` con il body della risposta.

---

## Riferimento costanti

| Costante | Default | Descrizione |
|---|---|---|
| `EHA_SITE_ID` | `''` | ID univoco del sito sull'hub |
| `EHA_HUB_URL` | `''` | URL base dell'hub (senza slash finale, HTTPS obbligatorio) |
| `EHA_API_KEY` | `''` | Bearer token per l'autenticazione all'hub |
| `EHA_COOLDOWN` | `60` | Secondi minimi tra due invii consecutivi |
| `EHA_MAX_LOG_LINES` | `100` | Righe lette dalla coda dei file di log |
| `EHA_MAX_SOURCE_FILES` | `10` | File PHP massimi nello ZIP |
| `EHA_CURL_TIMEOUT` | `30` | Timeout cURL/wp_remote_post in secondi |
| `EHA_MAX_ZIP_BYTES` | `20971520` | Dimensione massima ZIP (20 MB) |
| `EHA_SEND_WP_USER` | `false` | Includere dati utente WP nel report |

---

## Pannello admin

Percorso: **Impostazioni → MiTstake Agent** (richiede `manage_options`).

### Sezione "Connessione all'hub"

- **Site ID** — deve corrispondere al `site_id` configurato sull'hub.
- **Hub URL** — URL HTTPS dell'hub. Viene validato lato server: se non inizia
  con `https://` la vecchia impostazione viene ripristinata e viene mostrato
  un notice di errore.
- **API Key** — campo `type="password"`. Se lasciato vuoto, la chiave
  esistente viene preservata tramite un hidden field `_existing_api_key`.
  Nell'UI viene mostrata oscurata per gli ultimi 8 caratteri.

### Sezione "Impostazioni avanzate"

- **Cooldown** — minimo 10 secondi.
- **Max righe log** — tra 10 e 1000.
- **Max file sorgente** — tra 1 e 50.
- **Dati utente WP** — checkbox; di default disabilitato (GDPR).

### Notice di stato

In cima alla pagina viene mostrato:
- ✅ **Plugin attivo** se `site_id`, `hub_url` e `api_key` sono configurati
  (sia da wp_options che da `config.php`).
- ⚠️ **Configurazione incompleta** altrimenti.

---

## Aggiornamenti automatici

Il plugin usa il meccanismo **Update URI** introdotto in WordPress 5.8
(header `Update URI: https://github.com/MadeInTomorrow/mitstake`).

Il filtro `update_plugins_github.com` chiama `GET https://api.github.com/repos/{owner}/{repo}/releases/latest`
e restituisce i dati aggiornamento se la versione del release è maggiore
della versione installata.

Il download preferisce un asset `.zip` allegato al release; in assenza
usa il `zipball_url` generato da GitHub.

---

## Requisiti

- **WordPress** ≥ 6.0
- **PHP** ≥ 8.0
- Estensione PHP **ZipArchive** (abilitata di default nella maggior parte degli host)
- **cURL** abilitato (o `wp_remote_post` disponibile)
- Accesso HTTPS all'endpoint hub

---

## Installazione

### Via FTP (manuale)

1. Copia la cartella `mitstake-agent/` in `wp-content/plugins/`.
2. Attiva il plugin da **Plugin → Plugin installati**.
3. Vai in **Impostazioni → MiTstake Agent** e configura Site ID, Hub URL e API Key.

### Via `config.php`

1. Copia `config-example.php` come `config.php` nella cartella del plugin.
2. Compila `EHA_SITE_ID`, `EHA_HUB_URL`, `EHA_API_KEY`.
3. Aggiungi `config.php` al `.gitignore` del sito.

### Via deploy automatizzato (CI/CD)

Definisci le costanti in `config.php` al momento del deploy. Il file
viene letto prima che WordPress carichi qualsiasi altro plugin, quindi
la configurazione è disponibile immediatamente.
