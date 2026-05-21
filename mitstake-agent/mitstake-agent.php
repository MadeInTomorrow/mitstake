<?php
/**
 * Plugin Name: MiTstake Agent
 * Plugin URI:  https://github.com/MadeInTomorrow/mitstake
 * Description: Intercetta errori PHP/500 e invia report all'MiTstake centrale.
 * Version:     1.0.2
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      MiTstake
 * License:     MIT
 * Text Domain: mitstake-agent
 * Update URI:  https://github.com/MadeInTomorrow/mitstake
 */

defined('ABSPATH') || exit;

// ---------------------------------------------------------------------------
// Carica configurazione — ordine di priorità:
//   1. Impostazioni salvate nel pannello Admin (wp_options)
//   2. config.php (per installazioni esistenti / deploy automatizzati)
//   3. Valori di default
// ---------------------------------------------------------------------------

// 1. Leggi da wp_options (disponibile già nella fase plugins_loaded)
$_eha = function_exists('get_option') ? (array) get_option('eha_settings', []) : [];
if (!empty($_eha['site_id']))          define('EHA_SITE_ID',          $_eha['site_id']);
if (!empty($_eha['hub_url']))          define('EHA_HUB_URL',          $_eha['hub_url']);
if (!empty($_eha['api_key']))          define('EHA_API_KEY',          $_eha['api_key']);
if (isset($_eha['cooldown']))          define('EHA_COOLDOWN',         (int) $_eha['cooldown']);
if (isset($_eha['max_log_lines']))     define('EHA_MAX_LOG_LINES',    (int) $_eha['max_log_lines']);
if (isset($_eha['max_source_files'])) define('EHA_MAX_SOURCE_FILES', (int) $_eha['max_source_files']);
if (isset($_eha['send_wp_user']))      define('EHA_SEND_WP_USER',     $_eha['send_wp_user'] === '1');
unset($_eha);

// 2. config.php come fallback (usa defined() || define(), non sovrascrive le WP options)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// 3. Valori di default
defined('EHA_SITE_ID')         || define('EHA_SITE_ID',         '');
defined('EHA_HUB_URL')         || define('EHA_HUB_URL',         '');
defined('EHA_API_KEY')         || define('EHA_API_KEY',         '');
defined('EHA_COOLDOWN')        || define('EHA_COOLDOWN',        60);
defined('EHA_MAX_LOG_LINES')   || define('EHA_MAX_LOG_LINES',   100);
defined('EHA_MAX_SOURCE_FILES')|| define('EHA_MAX_SOURCE_FILES',10);
defined('EHA_CURL_TIMEOUT')    || define('EHA_CURL_TIMEOUT',    30);
defined('EHA_MAX_ZIP_BYTES')   || define('EHA_MAX_ZIP_BYTES',   20 * 1024 * 1024);
// M-1: default false — non inviare dati identificativi dell'utente WP senza consenso esplicito.
defined('EHA_SEND_WP_USER')    || define('EHA_SEND_WP_USER',    false);

// ---------------------------------------------------------------------------
// Pagina impostazioni admin — registrata SEMPRE, anche se la config è incompleta
// ---------------------------------------------------------------------------
if (is_admin()) {
    add_action('admin_menu',    [ErrorHubAgent::class, 'addSettingsPage']);
    add_action('admin_init',    [ErrorHubAgent::class, 'registerSettings']);
    add_action('admin_notices', [ErrorHubAgent::class, 'adminNotices']);
    // Aggiornamenti automatici tramite GitHub releases (Update URI header, WP 5.8+)
    add_filter('update_plugins_github.com', [ErrorHubAgent::class, 'checkForUpdates'], 10, 3);
}

// ---------------------------------------------------------------------------
// Validazione configurazione a startup
// ---------------------------------------------------------------------------
if (empty(EHA_SITE_ID) || empty(EHA_HUB_URL) || empty(EHA_API_KEY)) {
    // Non bloccare il sito: scrivi solo in log WP
    error_log('[ErrorHubAgent] Configurazione incompleta: configurare il plugin da Impostazioni > MiTstake Agent.');
    return;
}

// Forza HTTPS per non inviare la API key in chiaro
if (stripos(EHA_HUB_URL, 'https://') !== 0) {
    error_log('[ErrorHubAgent] EHA_HUB_URL deve usare HTTPS. Plugin disabilitato.');
    return;
}

// ---------------------------------------------------------------------------
// Registrazione handler errori
// ---------------------------------------------------------------------------
register_shutdown_function([ErrorHubAgent::class, 'onShutdown']);
set_exception_handler([ErrorHubAgent::class, 'onException']);

/**
 * Classe principale del plugin.
 * Usa solo metodi statici per evitare dipendenze dall'ordine di init WP.
 */
class ErrorHubAgent
{
    /** Livelli PHP che consideriamo fatali per un 500. */
    private const FATAL_LEVELS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /**
     * File che non devono MAI essere inclusi nello ZIP, indipendentemente
     * da dove compaiono nello stack trace.
     * C-1: wp-config.php può contenere DB_PASSWORD e chiavi segrete WP.
     */
    private const BLOCKED_FILES = [
        'wp-config.php',
        '.env',
        '.env.local',
        '.env.production',
        'config.php',
    ];

    // -----------------------------------------------------------------------
    // Handler shutdown: intercetta fatal errors
    // -----------------------------------------------------------------------
    public static function onShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }
        if (!in_array($error['type'], self::FATAL_LEVELS, true)) {
            return;
        }
        self::handleError(
            "PHP Fatal Error: {$error['message']} in {$error['file']}:{$error['line']}",
            $error['file'],
            $error['line'],
        );
    }

    // -----------------------------------------------------------------------
    // Handler eccezioni non gestite
    // -----------------------------------------------------------------------
    public static function onException(Throwable $e): void
    {
        self::handleError(
            get_class($e) . ': ' . $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );
        // Mostra la pagina di errore WP standard
        wp_die(
            esc_html__('Si è verificato un errore. Riprova più tardi.', 'mitstake-agent'),
            esc_html__('Errore', 'mitstake-agent'),
            ['response' => 500]
        );
    }

    // -----------------------------------------------------------------------
    // Core: raccoglie dati e invia al hub
    // -----------------------------------------------------------------------
    private static function handleError(
        string $message,
        string $file,
        int    $line,
        string $trace = ''
    ): void {
        if (!self::checkCooldown()) {
            error_log('[ErrorHubAgent] Cooldown attivo — errore non inviato.');
            return;
        }
        self::updateCooldown();

        $logLine  = self::buildLogLine($message);
        $zipData  = self::buildZip($message, $file, $line, $trace);

        if ($zipData === null) {
            error_log('[ErrorHubAgent] Impossibile creare ZIP report.');
            return;
        }

        self::sendReport($logLine, $zipData);
    }

    // -----------------------------------------------------------------------
    // Rimuove valori di query parameter sensibili dall'URI (B-1)
    // -----------------------------------------------------------------------
    private static function redactUri(string $uri): string
    {
        return preg_replace_callback(
            '/([?&])(token|key|pass|secret|auth|api_?key|nonce)=([^&\s#]*)/i',
            static fn($m) => $m[1] . $m[2] . '=[REDACTED]',
            $uri
        ) ?? $uri;
    }

    // -----------------------------------------------------------------------
    // Costruisce una riga di log sintetica compatibile Combined Log Format
    // -----------------------------------------------------------------------
    private static function buildLogLine(string $message): string
    {
        $ip        = sanitize_text_field($_SERVER['REMOTE_ADDR']   ?? '0.0.0.0');
        $method    = sanitize_text_field($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri       = self::redactUri(sanitize_text_field($_SERVER['REQUEST_URI'] ?? '/'));
        $ua        = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ts        = date('d/M/Y:H:i:s O');
        // Tronca il messaggio errore per la riga sintetica
        $shortMsg  = substr($message, 0, 200);
        return sprintf(
            '%s - - [%s] "%s %s HTTP/1.1" 500 0 "-" "%s" [%s]',
            $ip, $ts, $method, $uri, addslashes($ua), addslashes($shortMsg)
        );
    }

    // -----------------------------------------------------------------------
    // Costruisce lo ZIP in memoria
    // -----------------------------------------------------------------------
    private static function buildZip(
        string $message,
        string $errorFile,
        int    $errorLine,
        string $trace
    ): ?string {
        // Richiede ZipArchive (PHP extension standard)
        if (!class_exists('ZipArchive')) {
            error_log('[ErrorHubAgent] ZipArchive non disponibile.');
            return null;
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'eha_');
        $tmpZip  = $tmpBase . '.zip';
        $zip     = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpBase);
            return null;
        }

        $ts = gmdate('Y-m-d\TH:i:s\Z');

        // ── report.json ──────────────────────────────────────────────────
        $reportJson = json_encode([
            'site_id'   => EHA_SITE_ID,
            'timestamp' => $ts,
            'log_line'  => self::buildLogLine($message),
            'method'    => sanitize_text_field($_SERVER['REQUEST_METHOD'] ?? ''),
            'path'      => self::redactUri(sanitize_text_field($_SERVER['REQUEST_URI'] ?? '')),
            'ip'        => sanitize_text_field($_SERVER['REMOTE_ADDR']    ?? ''),
            'useragent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $zip->addFromString('report.json', $reportJson);

        // ── PHP error log ─────────────────────────────────────────────────
        $phpErrorLogPath = ini_get('error_log');
        if ($phpErrorLogPath && is_readable($phpErrorLogPath)) {
            $zip->addFromString('logs/php_error.log', self::tailFile($phpErrorLogPath));
        }

        // ── WP debug.log ──────────────────────────────────────────────────
        if (defined('WP_CONTENT_DIR')) {
            $debugLog = WP_CONTENT_DIR . '/debug.log';
            if (is_readable($debugLog)) {
                $zip->addFromString('logs/wp_debug.log', self::tailFile($debugLog));
            }
        }

        // ── Stack trace completo ──────────────────────────────────────────
        $fullTrace = sprintf(
            "Error: %s\nFile: %s:%d\n\nStack trace:\n%s",
            $message, $errorFile, $errorLine,
            $trace ?: '(non disponibile — fatal error)'
        );
        $zip->addFromString('logs/stacktrace.txt', $fullTrace);

        // ── Informazioni richiesta HTTP ───────────────────────────────────
        $requestInfo = self::buildRequestInfo();
        $zip->addFromString('logs/request.txt', $requestInfo);

        // ── File sorgente PHP dallo stack trace ───────────────────────────
        // Includi sempre il file principale dell'errore + quelli dallo stack trace
        $phpFiles = self::extractPhpFiles($trace);
        if (!empty($errorFile)) {
            array_unshift($phpFiles, $errorFile);
            $phpFiles = array_unique($phpFiles);
        }
        $webRoot    = defined('ABSPATH') ? rtrim(ABSPATH, '/') : '';
        $addedCount = 0;
        foreach ($phpFiles as $srcPath) {
            if ($addedCount >= EHA_MAX_SOURCE_FILES) {
                break;
            }
            $realSrc = realpath($srcPath);
            // Blocca path traversal e file fuori dalla webroot
            if ($realSrc === false) {
                continue;
            }
            if ($webRoot !== '' && strpos($realSrc, $webRoot) !== 0) {
                continue;
            }
            // C-1: blocca file con credenziali o chiavi segrete
            $basename = basename($realSrc);
            if (in_array(strtolower($basename), self::BLOCKED_FILES, true)) {
                continue;
            }
            if (preg_match('/(?:pass|secret|credential|auth_key|\.pem|\.key)$/i', $basename)) {
                continue;
            }
            if (!is_readable($realSrc)) {
                continue;
            }
            // Limita la dimensione del singolo file sorgente a 512 KB
            if (filesize($realSrc) > 512 * 1024) {
                continue;
            }
            // Path sicuro: rimuove slash iniziale, usa come percorso dentro ZIP
            $zipEntry = 'sources/' . ltrim($realSrc, '/');
            $zip->addFromString($zipEntry, file_get_contents($realSrc) ?: '');
            $addedCount++;
        }

        $zip->close();

        // Cancella il file base lasciato da tempnam
        @unlink($tmpBase);

        // Leggi il file e cancellalo
        if (!is_readable($tmpZip)) {
            return null;
        }
        $data = file_get_contents($tmpZip);
        unlink($tmpZip);

        if ($data === false || strlen($data) > EHA_MAX_ZIP_BYTES) {
            return null;
        }

        return $data;
    }

    // -----------------------------------------------------------------------
    // Legge le ultime N righe di un file
    // -----------------------------------------------------------------------
    private static function tailFile(string $path): string
    {
        $lines    = EHA_MAX_LOG_LINES;
        $handle   = @fopen($path, 'rb');
        if (!$handle) {
            return "[file non leggibile: {$path}]";
        }
        fseek($handle, 0, SEEK_END);
        $size   = ftell($handle);
        $chunk  = $lines * 200;
        $offset = max(0, $size - $chunk);
        fseek($handle, $offset);
        $content = fread($handle, $size - $offset) ?: '';
        fclose($handle);
        $all = explode("\n", $content);
        if ($offset > 0 && count($all) > 1) {
            array_shift($all); // scarta prima riga potenzialmente parziale
        }
        return implode("\n", array_slice($all, -$lines));
    }

    // -----------------------------------------------------------------------
    // Estrae percorsi file PHP dallo stack trace
    // -----------------------------------------------------------------------
    private static function extractPhpFiles(string $trace): array
    {
        $webRoot  = defined('ABSPATH') ? rtrim(ABSPATH, '/') : '/var/www/html';
        $pattern  = '/(?:in |(?:PHP\s+\d+\.\s+\S+\(\)\s+))(\/[^\s:]+\.php)/';
        preg_match_all($pattern, $trace, $matches);
        $files = array_unique($matches[1] ?? []);
        return array_filter($files, fn($f) => strpos($f, $webRoot) === 0);
    }

    // -----------------------------------------------------------------------
    // Raccoglie informazioni sulla richiesta HTTP corrente
    // -----------------------------------------------------------------------
    private static function buildRequestInfo(): string
    {
        $keys = [
            'REQUEST_METHOD', 'REQUEST_URI', 'HTTP_HOST',
            'REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_REFERER',
            'SERVER_SOFTWARE', 'PHP_SELF',
        ];
        $lines = [];
        foreach ($keys as $k) {
            $lines[] = $k . ': ' . sanitize_text_field($_SERVER[$k] ?? '—');
        }
        if (function_exists('get_current_user_id') && EHA_SEND_WP_USER) {
            $uid = get_current_user_id();
            $lines[] = 'WP_USER_ID: ' . $uid;
            if ($uid) {
                $user    = get_userdata($uid);
                $lines[] = 'WP_USER_LOGIN: ' . ($user->user_login ?? '—');
                $roles   = $user->roles ?? [];
                $lines[] = 'WP_USER_ROLES: ' . implode(', ', $roles);
            }
        }
        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------------
    // Invia il report all'hub via wp_remote_post (usa cURL di WP)
    // -----------------------------------------------------------------------
    private static function sendReport(string $logLine, string $zipData): void
    {
        if (!function_exists('wp_remote_post')) {
            // Prima di WP init: usa cURL direttamente
            self::sendViaCurl($logLine, $zipData);
            return;
        }

        $boundary = '----EHABoundary' . bin2hex(random_bytes(8));
        $body     = self::buildMultipartBody($boundary, $logLine, $zipData);

        $response = wp_remote_post(EHA_HUB_URL . '/api/v1/report', [
            'headers'   => [
                'Authorization' => 'Bearer ' . EHA_API_KEY,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body'      => $body,
            'timeout'   => EHA_CURL_TIMEOUT,
            'sslverify' => true,
            'blocking'  => true,
        ]);

        if (is_wp_error($response)) {
            error_log('[ErrorHubAgent] Errore invio: ' . $response->get_error_message());
            return;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200 && $code !== 201) {
            error_log('[ErrorHubAgent] Hub ha risposto HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
        } else {
            error_log('[ErrorHubAgent] Report inviato con successo (HTTP ' . $code . ').');
        }
    }

    // -----------------------------------------------------------------------
    // Fallback cURL puro (before WP init)
    // -----------------------------------------------------------------------
    private static function sendViaCurl(string $logLine, string $zipData): void
    {
        if (!function_exists('curl_init')) {
            error_log('[ErrorHubAgent] cURL non disponibile.');
            return;
        }
        $boundary = '----EHABoundary' . bin2hex(random_bytes(8));
        $body     = self::buildMultipartBody($boundary, $logLine, $zipData);

        $ch = curl_init(EHA_HUB_URL . '/api/v1/report');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . EHA_API_KEY,
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => EHA_CURL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // -----------------------------------------------------------------------
    // Costruisce il body multipart/form-data manualmente
    // -----------------------------------------------------------------------
    private static function buildMultipartBody(
        string $boundary,
        string $logLine,
        string $zipData
    ): string {
        $ts = gmdate('Y-m-d\TH:i:s\Z');
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');

        $parts  = '';
        $fields = [
            'log_line'        => substr($logLine, 0, 2048),
            'error_timestamp' => $ts,
            'ip'              => $ip,
            'method'          => sanitize_text_field($_SERVER['REQUEST_METHOD'] ?? ''),
            'path'            => self::redactUri(sanitize_text_field($_SERVER['REQUEST_URI'] ?? '')),
            'useragent'       => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ];

        foreach ($fields as $name => $value) {
            $parts .= "--{$boundary}\r\n";
            $parts .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $parts .= "{$value}\r\n";
        }

        // ZIP come file
        $parts .= "--{$boundary}\r\n";
        $parts .= "Content-Disposition: form-data; name=\"report_zip\"; filename=\"report.zip\"\r\n";
        $parts .= "Content-Type: application/zip\r\n\r\n";
        $parts .= $zipData . "\r\n";
        $parts .= "--{$boundary}--\r\n";

        return $parts;
    }

    // -----------------------------------------------------------------------
    // Cooldown: usa la transient API di WP se disponibile, altrimenti file
    // -----------------------------------------------------------------------
    private static function checkCooldown(): bool
    {
        if (function_exists('get_transient')) {
            // B-2: nome del transient non prevedibile senza conoscere la API key
            return !get_transient('eha_cd_' . md5(EHA_SITE_ID . EHA_API_KEY));
        }
        // Fallback file
        $f = sys_get_temp_dir() . '/eha_cooldown_' . md5(EHA_SITE_ID);
        if (!file_exists($f)) {
            return true;
        }
        return (time() - (int) file_get_contents($f)) >= EHA_COOLDOWN;
    }

    private static function updateCooldown(): void
    {
        if (function_exists('set_transient')) {
            set_transient('eha_cd_' . md5(EHA_SITE_ID . EHA_API_KEY), 1, EHA_COOLDOWN);
            return;
        }
        $f = sys_get_temp_dir() . '/eha_cooldown_' . md5(EHA_SITE_ID);
        file_put_contents($f, (string) time());
    }

    // =========================================================================
    // Aggiornamenti automatici — GitHub releases (Update URI / WP 5.8+)
    // =========================================================================

    /**
     * Controlla se esiste una versione più recente su GitHub releases.
     * Viene chiamato da WordPress durante il ciclo standard di controllo aggiornamenti.
     *
     * @param array|false $update    Dati aggiornamento esistenti (false = nessuno).
     * @param array       $plugin_data Header del plugin (Version, UpdateURI, ecc.)
     * @param string      $plugin_file Basename del plugin (cartella/file.php).
     * @return array|false
     */
    public static function checkForUpdates(
        array|false $update,
        array       $plugin_data,
        string      $plugin_file
    ): array|false {
        // Intercetta solo questo plugin
        if (plugin_basename(__FILE__) !== $plugin_file) {
            return $update;
        }

        // Ricava "owner/repo" dall'Update URI (es. https://github.com/owner/repo)
        $update_uri = $plugin_data['UpdateURI'] ?? '';
        $repo = ltrim(parse_url($update_uri, PHP_URL_PATH), '/');
        if (!$repo) {
            return $update;
        }

        $api_url  = "https://api.github.com/repos/{$repo}/releases/latest";
        $response = wp_remote_get($api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return $update;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($release['tag_name'])) {
            return $update;
        }

        $latest_version = ltrim($release['tag_name'], 'v');
        if (!version_compare($latest_version, $plugin_data['Version'], '>')) {
            return $update; // nessun aggiornamento disponibile
        }

        // Cerca il file mitstake-agent.zip negli asset del release,
        // altrimenti usa il zipball generato da GitHub
        $download_url = $release['zipball_url'] ?? '';
        foreach ($release['assets'] ?? [] as $asset) {
            if (str_ends_with($asset['name'], '.zip')) {
                $download_url = $asset['browser_download_url'];
                break;
            }
        }

        if (!$download_url) {
            return $update;
        }

        return [
            'id'          => $update_uri,
            'slug'        => dirname(plugin_basename(__FILE__)),
            'plugin'      => $plugin_file,
            'version'     => $latest_version,
            'url'         => $plugin_data['PluginURI'] ?? $update_uri,
            'package'     => $download_url,
            'icons'       => [],
            'banners'     => [],
            'banners_rtl' => [],
            'requires'    => $plugin_data['RequiresWP']  ?? '6.0',
            'requires_php'=> $plugin_data['RequiresPHP'] ?? '8.0',
            'tested'      => '',
        ];
    }

    // =========================================================================
    // Admin — Pagina Impostazioni
    // =========================================================================

    public static function addSettingsPage(): void
    {
        add_options_page(
            'MiTstake Agent',
            'MiTstake Agent',
            'manage_options',
            'mitstake-agent',
            [self::class, 'renderSettingsPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting('eha_settings_group', 'eha_settings', [
            'sanitize_callback' => [self::class, 'sanitizeSettings'],
        ]);

        add_settings_section('eha_main', 'Connessione all\'hub', null, 'mitstake-agent');
        add_settings_section('eha_advanced', 'Impostazioni avanzate', null, 'mitstake-agent');

        $main_fields = [
            ['site_id', 'Site ID',   'text',     'eha_main',     'ID univoco di questo sito — deve corrispondere al site_id creato sull\'hub.'],
            ['hub_url', 'Hub URL',   'url',      'eha_main',     'URL dell\'hub MiTstake (es. https://hub.example.com). Deve usare HTTPS.'],
            ['api_key', 'API Key',   'api_key',  'eha_main',     'Chiave API generata dall\'hub per questo sito.'],
        ];
        $advanced_fields = [
            ['cooldown',         'Cooldown (sec)',    'number',   'eha_advanced', 'Secondi di attesa minimi tra un invio e il successivo (default: 60).'],
            ['max_log_lines',    'Max righe log',     'number',   'eha_advanced', 'Quante righe finali leggere dai file di log contestuali (default: 100).'],
            ['max_source_files', 'Max file sorgente', 'number',   'eha_advanced', 'Numero massimo di file PHP inclusi nello ZIP (default: 10).'],
            ['send_wp_user',     'Dati utente WP',    'checkbox', 'eha_advanced', 'Includi nel report username e ruolo dell\'utente WP loggato. Off di default (GDPR).'],
        ];

        foreach (array_merge($main_fields, $advanced_fields) as [$id, $label, $type, $section, $desc]) {
            add_settings_field(
                'eha_' . $id,
                $label,
                [self::class, 'renderField'],
                'mitstake-agent',
                $section,
                ['id' => $id, 'type' => $type, 'desc' => $desc]
            );
        }
    }

    public static function renderField(array $args): void
    {
        $opts  = (array) get_option('eha_settings', []);
        $id    = esc_attr($args['id']);
        $type  = $args['type'];
        $desc  = esc_html($args['desc']);
        $value = (string) ($opts[$id] ?? '');

        if ($type === 'checkbox') {
            printf(
                '<label><input type="checkbox" name="eha_settings[%s]" value="1"%s> %s</label>',
                $id,
                checked($value, '1', false),
                $desc
            );
        } elseif ($type === 'api_key') {
            // Campo separato: inserisci solo per cambiare la chiave
            // La chiave esistente è preservata da un hidden field se il campo viene lasciato vuoto
            $masked = $value
                ? str_repeat('•', max(0, strlen($value) - 8)) . substr($value, -8)
                : '';
            printf(
                '<input type="password" name="eha_settings[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="%s">',
                $value ? 'Lascia vuoto per mantenere la chiave attuale' : 'Incolla qui la API key'
            );
            // Hidden field che preserva la chiave esistente se il campo viene lasciato vuoto
            printf(
                '<input type="hidden" name="eha_settings[_existing_api_key]" value="%s">',
                esc_attr($value)
            );
            if ($masked) {
                echo '<p class="description">Chiave attuale: <code>' . esc_html($masked) . '</code></p>';
            }
            echo '<p class="description">' . $desc . '</p>';
            return; // desc già stampata sopra
        } else {
            printf(
                '<input type="%s" name="eha_settings[%s]" value="%s" class="%s">',
                esc_attr($type),
                $id,
                esc_attr($value),
                $type === 'number' ? 'small-text' : 'regular-text'
            );
        }
        echo '<p class="description">' . $desc . '</p>';
    }

    public static function sanitizeSettings(array $input): array
    {
        $existing = (array) get_option('eha_settings', []);
        $clean    = [];

        $clean['site_id'] = sanitize_text_field($input['site_id'] ?? '');

        $hub_url = esc_url_raw(trim($input['hub_url'] ?? ''));
        if ($hub_url && stripos($hub_url, 'https://') !== 0) {
            add_settings_error('eha_settings', 'hub_url_https', 'Hub URL deve usare HTTPS.', 'error');
            $hub_url = $existing['hub_url'] ?? '';
        }
        $clean['hub_url'] = rtrim($hub_url, '/');

        // API key: usa la nuova se compilata, altrimenti preserva l'esistente
        $new_key = trim($input['api_key'] ?? '');
        $clean['api_key'] = $new_key !== ''
            ? sanitize_text_field($new_key)
            : sanitize_text_field($input['_existing_api_key'] ?? ($existing['api_key'] ?? ''));

        $clean['cooldown']         = max(10, (int) ($input['cooldown']         ?? 60));
        $clean['max_log_lines']    = max(10,  min(1000, (int) ($input['max_log_lines']    ?? 100)));
        $clean['max_source_files'] = max(1,   min(50,   (int) ($input['max_source_files'] ?? 10)));
        $clean['send_wp_user']     = !empty($input['send_wp_user']) ? '1' : '0';

        return $clean;
    }

    public static function adminNotices(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_mitstake-agent') {
            return;
        }
        settings_errors('eha_settings');
    }

    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non hai i permessi per accedere a questa pagina.', 'mitstake-agent'));
        }

        $opts       = (array) get_option('eha_settings', []);
        $configured = !empty($opts['site_id']) && !empty($opts['hub_url']) && !empty($opts['api_key']);
        // Verifica anche se la config proviene da config.php
        if (!$configured) {
            $configured = !empty(EHA_SITE_ID) && !empty(EHA_HUB_URL) && !empty(EHA_API_KEY);
        }
        ?>
        <div class="wrap">
            <h1>
                <span style="vertical-align:middle">⚡</span> MiTstake Agent
            </h1>

            <?php if ($configured): ?>
                <div class="notice notice-success inline">
                    <p>✅ <strong>Plugin attivo</strong> — gli errori 500 vengono monitorati e inviati all'hub.</p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning inline">
                    <p>⚠️ <strong>Configurazione incompleta</strong> — inserisci Site ID, Hub URL e API Key per attivare il monitoraggio.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php" style="margin-top:1.5em">
                <?php
                settings_fields('eha_settings_group');
                do_settings_sections('mitstake-agent');
                submit_button('Salva impostazioni');
                ?>
            </form>

            <?php if (defined('EHA_SITE_ID') && EHA_SITE_ID && file_exists(__DIR__ . '/config.php')): ?>
                <hr>
                <p class="description">
                    ℹ️ Questo sito ha anche un file <code>config.php</code>. Le impostazioni salvate qui sopra hanno la precedenza su quelle nel file.
                    Se non usi più il file, puoi eliminarlo.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
