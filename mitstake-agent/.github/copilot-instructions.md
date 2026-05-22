# GitHub Copilot Instructions — MiTstake Agent

## Panoramica progetto

**MiTstake Agent** è un plugin WordPress (PHP 8.0+, WP 6.0+) che intercetta
errori PHP fatali ed eccezioni non gestite, costruisce un report ZIP in memoria
e lo invia via HTTP POST a un hub centralizzato di monitoraggio errori.

Codice base: un singolo file PHP (`mitstake-agent.php`) contenente la classe
statica `MiTstakeAgent`. Nessuna dipendenza esterna oltre alle API standard
di WordPress e alle estensioni PHP `ZipArchive` e `curl`.

---

## Convenzioni di codice

- **Solo metodi statici** su `MiTstakeAgent`. Non aggiungere mai proprietà
  o costruttori di istanza: la classe non deve essere istanziata.
- **PHP 8.0+ syntax**: usa union types, named arguments, `str_ends_with()`,
  `match`, `fn()` arrow functions dove appropriato.
- **Nessuna dipendenza esterna**: non aggiungere `composer.json` o librerie
  di terze parti. Usa solo WordPress core API e PHP standard.
- **Ordine metodi nella classe**: prima handler pubblici (`onShutdown`,
  `onException`), poi core privati (`handleError`, `build*`, `send*`),
  poi admin pubblici, infine aggiornamenti.
- Commenti di sicurezza con codice: `// B-1`, `// C-1` ecc. — preservarli
  quando si modifica il codice relativo.

---

## Costanti di configurazione

Tutte le costanti usano il prefisso `EHA_`. Non rinominarle: cambiarle
rompe la compatibilità con i file `config.php` già distribuiti.

| Costante | Default | Note |
|---|---|---|
| `EHA_SITE_ID` | `''` | Obbligatoria |
| `EHA_HUB_URL` | `''` | Obbligatoria, deve iniziare con `https://` |
| `EHA_API_KEY` | `''` | Obbligatoria, mai loggarla in chiaro |
| `EHA_COOLDOWN` | `60` | Secondi tra invii consecutivi, min 10 |
| `EHA_MAX_LOG_LINES` | `100` | Righe da leggere dai log, range 10–1000 |
| `EHA_MAX_SOURCE_FILES` | `10` | File PHP nello ZIP, range 1–50 |
| `EHA_CURL_TIMEOUT` | `30` | Timeout HTTP in secondi |
| `EHA_MAX_ZIP_BYTES` | `20971520` | 20 MB massimo per ZIP |
| `EHA_SEND_WP_USER` | `false` | Default off per GDPR |

**Ordine di priorità caricamento**: `wp_options` → `config.php` → default hardcoded.
Il primo `define()` vince. Non usare `define()` per sovrascrivere costanti già definite.

---

## Sicurezza — regole assolute

Queste regole NON devono mai essere violate, nemmeno per test o debug:

### 1. File bloccati (C-1)
I seguenti file **non devono mai essere inclusi nello ZIP** né letti per
nessun motivo:
```
wp-config.php | .env | .env.local | .env.production | config.php
```
Il filtro è in `BLOCKED_FILES` (array di costante privata) + regex su basename.
Se aggiungi nuovi file da includere, assicurati che passino entrambi i controlli.

### 2. Redazione URI (B-1)
Qualsiasi URI che viene loggato o incluso in un payload deve passare per
`self::redactUri()`. I parametri oscurati sono: `token`, `key`, `pass`,
`secret`, `auth`, `api_key`, `nonce` (case-insensitive).
Non aggiungere URI raw a report o log senza questa funzione.

### 3. HTTPS obbligatorio
`EHA_HUB_URL` deve iniziare con `https://`. Se non lo fa, il plugin si
auto-disabilita. Non rimuovere mai questo controllo.

### 4. Verifica TLS
`sslverify: true` (WP) e `CURLOPT_SSL_VERIFYHOST: 2` / `CURLOPT_SSL_VERIFYPEER: true`
(cURL) devono rimanere abilitati. Non disabilitare mai la verifica certificati.

### 5. Path traversal (C-1 esteso)
Ogni file sorgente da includere nello ZIP deve essere validato con `realpath()`
e verificato che il path risultante inizi con `ABSPATH`. Non includere mai file
al di fuori della webroot WordPress.

### 6. Cooldown anti-flood (B-2)
Il meccanismo di cooldown (`checkCooldown` / `updateCooldown`) deve essere
controllato **sempre** prima di costruire o inviare qualsiasi report. Non
aggiungere percorsi di codice che saltino il cooldown.

### 7. EHA_SEND_WP_USER
Il default è `false`. Qualsiasi codice che legge dati identificativi dell'utente
WP (ID, login, email, ruoli) deve essere condizionato da `EHA_SEND_WP_USER === true`.

### 8. API key nell'UI
La API key non deve mai essere mostrata in chiaro nell'interfaccia admin.
Usare il campo `type="password"` con mascheratura degli ultimi 8 caratteri.
L'hidden field `_existing_api_key` preserva la chiave se il campo viene
lasciato vuoto; sanitizzare sempre con `sanitize_text_field`.

---

## Flusso dati — dove non aggiungere output

I metodi seguenti vengono chiamati durante la gestione di un errore PHP,
potenzialmente **prima** che WordPress abbia completato il bootstrap.
Non aggiungere mai `echo`, `print_r`, `var_dump` o qualsiasi output HTTP
in questi metodi — romperebbero la risposta al client:

- `onShutdown()`
- `onException()` — può chiamare `wp_die()` solo alla fine
- `handleError()`
- `buildLogLine()`
- `buildZip()`
- `tailFile()`
- `extractPhpFiles()`
- `buildRequestInfo()`
- `redactUri()`
- `sendReport()`
- `sendViaCurl()`
- `buildMultipartBody()`
- `checkCooldown()`
- `updateCooldown()`

---

## API endpoint hub

```
POST {EHA_HUB_URL}/api/v1/report
Authorization: Bearer {EHA_API_KEY}
Content-Type: multipart/form-data; boundary=----EHABoundary{hex}
```

Campi form-data attesi dall'hub:

| Campo | Tipo | Max |
|---|---|---|
| `log_line` | string | 2048 char |
| `error_timestamp` | string ISO-8601 UTC | — |
| `ip` | string | — |
| `method` | string | — |
| `path` | string (redacted) | — |
| `useragent` | string | — |
| `report_zip` | file (application/zip) | `EHA_MAX_ZIP_BYTES` |

Risposta attesa: `200` o `201`. Qualsiasi altro codice → `error_log` del sito.

---

## Struttura ZIP

```
report.zip
├── report.json           ← schema fisso (vedi sotto)
├── logs/
│   ├── php_error.log     ← tail di ini_get('error_log')
│   ├── wp_debug.log      ← tail di WP_CONTENT_DIR/debug.log
│   ├── stacktrace.txt    ← trace completo o "(fatal error)"
│   └── request.txt       ← variabili $_SERVER + opzionale WP user
└── sources/
    └── …/path/file.php   ← max EHA_MAX_SOURCE_FILES, max 512 KB ciascuno
```

Schema `report.json`:
```json
{
  "site_id":   "string",
  "timestamp": "2026-01-15T10:30:00Z",
  "log_line":  "Combined Log Format",
  "method":    "GET",
  "path":      "/path?token=[REDACTED]",
  "ip":        "1.2.3.4",
  "useragent": "Mozilla/5.0 …"
}
```

---

## Aggiornamenti automatici

Il plugin usa WordPress Update URI (WP 5.8+).
Il filtro `update_plugins_github.com` interroga
`https://api.github.com/repos/{owner}/{repo}/releases/latest`.

- Il repo viene estratto dall'header `Update URI` del plugin (non hardcoded).
- Preferisce asset `.zip` allegati al release; fallback su `zipball_url`.
- Il confronto versione usa `version_compare($latest, $installed, '>')`.
- Timeout: 10 secondi.

---

## Pannello admin

- Hook: `admin_menu` → `addSettingsPage()`, `admin_init` → `registerSettings()`
- Option name: `eha_settings` (array) nel gruppo `eha_settings_group`
- Capability richiesta: `manage_options`
- Validazione `hub_url`: HTTPS forzato; se invalido → ripristina il vecchio valore
- Validazione `cooldown`: min 10
- Validazione `max_log_lines`: 10–1000
- Validazione `max_source_files`: 1–50

---

## Pattern da seguire per nuove feature

### Aggiungere un campo alla pagina admin
1. Aggiungi il campo in `registerSettings()` nell'array `$main_fields` o `$advanced_fields`.
2. Aggiungi la sanitizzazione in `sanitizeSettings()`.
3. Definisci la costante corrispondente nella sezione "3. Valori di default" di `mitstake-agent.php`.
4. Carica il valore da `wp_options` nella sezione "1. Leggi da wp_options".

### Aggiungere un file al payload ZIP
1. Aggiungi la logica di raccolta in `buildZip()`.
2. Controlla che il file non rientri in `BLOCKED_FILES` o nel filtro regex.
3. Controlla che il path sia dentro `ABSPATH` (se è un file del sito).
4. Documenta il nuovo entry in questa istruzione sotto "Struttura ZIP".

### Aggiungere dati al `report.json`
1. Modifica l'array passato a `json_encode()` in `buildZip()`.
2. Se il dato proviene da `$_SERVER`, passarlo sempre per `sanitize_text_field()`.
3. Se il dato è un URI, passarlo sempre per `self::redactUri()`.
4. Aggiorna lo schema in questa istruzione sotto "Schema `report.json`".

---

## Anti-pattern — cosa NON fare

- ❌ Non aggiungere `composer.json` o dipendenze esterne.
- ❌ Non istanziare `MiTstakeAgent` (tutti i metodi sono statici).
- ❌ Non disabilitare `sslverify` o `CURLOPT_SSL_VERIFYPEER` nemmeno nei test.
- ❌ Non includere `wp-config.php` o file `.env` nello ZIP in nessun caso.
- ❌ Non loggare `EHA_API_KEY` in chiaro in `error_log` o in qualsiasi output.
- ❌ Non aggiungere `echo` nei metodi del flusso di gestione errori.
- ❌ Non rinominare le costanti `EHA_*` (compatibilità con `config.php` esistenti).
- ❌ Non saltare `redactUri()` quando si scrivono URI nel payload.
- ❌ Non aggiungere dati utente WP senza il controllo `EHA_SEND_WP_USER`.
- ❌ Non usare `file_get_contents()` su URL remoti (usare `wp_remote_get` o cURL).
