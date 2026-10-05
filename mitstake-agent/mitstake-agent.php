<?php
/**
 * Plugin Name: MiTstake Agent
 * Plugin URI:  https://github.com/MadeInTomorrow/mitstake
 * Description: Intercetta errori PHP/500 e invia report all'MiTstake centrale.
 * Version:     1.1.0
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
if (isset($_eha['disk_heartbeat']))          define('EHA_DISK_HEARTBEAT',          $_eha['disk_heartbeat'] === '1');
if (isset($_eha['disk_heartbeat_interval'])) define('EHA_DISK_HEARTBEAT_INTERVAL', (int) $_eha['disk_heartbeat_interval']);
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
// Heartbeat disco: invio periodico dello stato disco all'hub (indipendente dagli errori)
defined('EHA_DISK_HEARTBEAT')          || define('EHA_DISK_HEARTBEAT',          true);
defined('EHA_DISK_HEARTBEAT_INTERVAL') || define('EHA_DISK_HEARTBEAT_INTERVAL', 60); // minuti

// ---------------------------------------------------------------------------
// Pagina impostazioni admin — registrata SEMPRE, anche se la config è incompleta
// ---------------------------------------------------------------------------
if (is_admin()) {
    add_action('admin_menu',    [MiTstakeAgent::class, 'addSettingsPage']);
    add_action('admin_init',    [MiTstakeAgent::class, 'registerSettings']);
    add_action('admin_notices', [MiTstakeAgent::class, 'adminNotices']);
    // Aggiornamenti automatici tramite GitHub releases (Update URI header, WP 5.8+)
    add_filter('update_plugins_github.com', [MiTstakeAgent::class, 'checkForUpdates'], 10, 3);
}

// Pulizia cron alla disattivazione (sempre registrata, anche se config incompleta)
register_deactivation_hook(__FILE__, [MiTstakeAgent::class, 'deactivate']);

// ---------------------------------------------------------------------------
// Validazione configurazione a startup
// ---------------------------------------------------------------------------
if (empty(EHA_SITE_ID) || empty(EHA_HUB_URL) || empty(EHA_API_KEY)) {
    // Non bloccare il sito: scrivi solo in log WP
    error_log('[MiTstakeAgent] Configurazione incompleta: configurare il plugin da Impostazioni > MiTstake Agent.');
    return;
}

// Forza HTTPS per non inviare la API key in chiaro
if (stripos(EHA_HUB_URL, 'https://') !== 0) {
    error_log('[MiTstakeAgent] EHA_HUB_URL deve usare HTTPS. Plugin disabilitato.');
    return;
}

// ---------------------------------------------------------------------------
// Registrazione handler errori
// ---------------------------------------------------------------------------
register_shutdown_function([MiTstakeAgent::class, 'onShutdown']);
set_exception_handler([MiTstakeAgent::class, 'onException']);

// Heartbeat disco: invio periodico indipendente dagli errori (WP-Cron)
if (EHA_DISK_HEARTBEAT) {
    add_filter('cron_schedules', [MiTstakeAgent::class, 'addCronSchedules']);
    add_action('eha_disk_heartbeat', [MiTstakeAgent::class, 'sendDiskHeartbeat']);
    add_action('init', [MiTstakeAgent::class, 'scheduleDiskHeartbeat']);
}

/**
 * Classe principale del plugin.
 * Usa solo metodi statici per evitare dipendenze dall'ordine di init WP.
 */
class MiTstakeAgent
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

        // In contesto REST API (es. batch/v1), wp_die() interferisce con
        // WP_REST_Server e può causare "Call to undefined method WP_Error::get_method()".
        // Rilanciamo l'eccezione per lasciare che WordPress la gestisca nativamente.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            error_log('[MiTstakeAgent] Eccezione in contesto REST — rilascio a WP_REST_Server: ' . $e->getMessage());
            throw $e;
        }

        // Mostra la pagina di errore WP standard (solo per richieste non-REST)
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
        if (!self::checkAndUpdateCooldown()) {
            error_log('[MiTstakeAgent] Cooldown attivo — errore non inviato.');
            return;
        }

        $logLine  = self::buildLogLine($message);
        $zipData  = self::buildZip($message, $file, $line, $trace);

        if ($zipData === null) {
            error_log('[MiTstakeAgent] Impossibile creare ZIP report.');
            return;
        }

        self::sendReport($logLine, $zipData);
    }

    // -----------------------------------------------------------------------
    // Rimuove valori di query parameter sensibili dall'URI (B-1)
    // -----------------------------------------------------------------------
    private static function redactUri(string $uri): string
    {
        // B-1: oscura query param sensibili
        $uri = preg_replace_callback(
            '/([?&])(token|key|pass|secret|auth|api_?key|nonce)=([^&\s#]*)/i',
            static fn($m) => $m[1] . $m[2] . '=[REDACTED]',
            $uri
        ) ?? $uri;
        // B-1: oscura JWT nei segmenti di path
        $uri = preg_replace(
            '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\b/',
            '[JWT-REDACTED]',
            $uri
        ) ?? $uri;
        // B-1: oscura token in path di magic link, reset password, verify, ecc.
        $uri = preg_replace(
            '#/(verify|reset|confirm|auth|activate|token|magic|unsubscribe)/([A-Za-z0-9_\-]{16,})#i',
            '/$1/[REDACTED]',
            $uri
        ) ?? $uri;
        return $uri;
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
    // Recupera la versione di WordPress (con fallback pre-init)
    // -----------------------------------------------------------------------
    private static function getWpVersion(): string
    {
        if (function_exists('get_bloginfo')) {
            $version = get_bloginfo('version');
            if ($version) {
                return $version;
            }
        }
        // Fallback: legge $wp_version da wp-includes/version.php
        // (funziona anche se l'errore avviene prima dell'init di WP)
        $file = defined('ABSPATH') ? ABSPATH . 'wp-includes/version.php' : '';
        if ($file && is_readable($file)) {
            $content = (string) file_get_contents($file);
            if (preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $content, $m)) {
                return $m[1];
            }
        }
        return '';
    }

    // -----------------------------------------------------------------------
    // Recupera la versione del plugin dall'header (senza hardcodarla)
    // -----------------------------------------------------------------------
    private static function getPluginVersion(): string
    {
        if (function_exists('get_file_data')) {
            $data = get_file_data(__FILE__, ['Version' => 'Version']);
            if (!empty($data['Version'])) {
                return $data['Version'];
            }
        }
        return '';
    }

    // -----------------------------------------------------------------------
    // Raccoglie le informazioni sull'ambiente (WordPress / PHP / server / disco)
    // -----------------------------------------------------------------------
    private static function getEnvironment(): array
    {
        return [
            'wordpress' => self::getWpVersion(),
            'php'       => PHP_VERSION,
            'php_sapi'  => PHP_SAPI,
            'server'    => sanitize_text_field($_SERVER['SERVER_SOFTWARE'] ?? ''),
            'plugin'    => self::getPluginVersion(),
            'disk'      => self::getDiskInfo(),
        ];
    }

    // -----------------------------------------------------------------------
    // Raccoglie informazioni sullo spazio disco del filesystem o sulla quota
    // -----------------------------------------------------------------------
    private static function getDiskInfo(): array
    {
        // Directory valida: la webroot se disponibile, altrimenti la cartella del plugin
        $path = defined('ABSPATH') && is_dir(ABSPATH) ? ABSPATH : __DIR__;

        // Su server con quota per-utente (es. Virtualmin, cPanel) lo spazio
        // realmente disponibile è il limite di quota, non il disco fisico.
        $quota = self::getQuotaInfo($path);
        if ($quota !== null) {
            return $quota;
        }

        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);

        if ($total === false || $free === false) {
            return ['available' => false];
        }

        $used = $total - $free;
        $pct  = $total > 0 ? round(($used / $total) * 100, 2) : 0.0;

        return [
            'available'    => true,
            'path'         => $path,
            'total'        => $total,
            'free'         => $free,
            'used'         => $used,
            'used_percent' => $pct,
            'total_human'  => self::formatBytes($total),
            'free_human'   => self::formatBytes($free),
            'used_human'   => self::formatBytes($used),
        ];
    }

    // -----------------------------------------------------------------------
    // Rileva la quota disco dell'utente corrente (Virtualmin/cPanel).
    // Restituisce l'array disk_* se trova una quota attiva, altrimenti null.
    // -----------------------------------------------------------------------
    private static function getQuotaInfo(string $path): ?array
    {
        // La quota è per-utente: serve l'utente con cui gira PHP. In Virtualmin
        // ogni vhost gira con il proprio utente (php-fpm pool dedicato).
        if (!function_exists('posix_getuid') || !function_exists('posix_getpwuid')) {
            return null;
        }
        $uid = posix_getuid();
        if ($uid === 0) {
            return null; // root: nessuna quota significativa
        }
        $info = posix_getpwuid($uid);
        $user = is_array($info) ? ($info['name'] ?? '') : '';
        if ($user === '') {
            return null;
        }

        if (!self::canExec()) {
            return null;
        }

        $out = self::runCommand(['quota', '-u', $user, '-w']);
        if ($out === null || trim($out) === '') {
            return null;
        }

        // Output tipico di `quota -u user -w` (valori in blocchi da 1 KiB):
        //   Disk quotas for user user (uid 1001):
        //        Filesystem  blocks   quota   limit   grace   files   quota   limit   grace
        //         /dev/sda1      999    5000    5500               123       0       0
        $usedBytes  = null;
        $limitBytes = null;
        foreach (preg_split('/\r?\n/', $out) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, 'Filesystem') === 0) {
                continue;
            }
            if (strpos($line, 'Disk quotas for') === 0) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 4) {
                continue;
            }
            if (!is_numeric($parts[1]) || !is_numeric($parts[2]) || !is_numeric($parts[3])) {
                continue;
            }
            $blocks = (float) $parts[1];   // blocchi usati
            $soft   = (float) $parts[2];   // soft quota
            $hard   = (float) $parts[3];   // hard limit
            $limit  = $hard > 0 ? $hard : ($soft > 0 ? $soft : 0);
            if ($limit <= 0) {
                continue; // nessuna quota attiva su questo filesystem
            }
            // Prima entry con quota attiva (il setup Virtualmin tipico ne ha una sola).
            $usedBytes  = $blocks * 1024;
            $limitBytes = $limit * 1024;
            break;
        }

        if ($usedBytes === null || $limitBytes === null || $limitBytes <= 0) {
            return null;
        }

        $free = max(0, $limitBytes - $usedBytes);
        $pct  = round(($usedBytes / $limitBytes) * 100, 2);

        return [
            'available'    => true,
            'path'         => $path,
            'total'        => (int) $limitBytes,
            'free'         => (int) $free,
            'used'         => (int) $usedBytes,
            'used_percent' => $pct,
            'total_human'  => self::formatBytes($limitBytes),
            'free_human'   => self::formatBytes($free),
            'used_human'   => self::formatBytes($usedBytes),
        ];
    }

    // -----------------------------------------------------------------------
    // Verifica che exec() sia disponibile e non disabilitato
    // -----------------------------------------------------------------------
    private static function canExec(): bool
    {
        static $allowed;
        if ($allowed !== null) {
            return $allowed;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $allowed  = function_exists('exec') && !in_array('exec', $disabled, true);
        return $allowed;
    }

    // -----------------------------------------------------------------------
    // Esegue un comando esterno in modo sicuro e restituisce l'output
    // -----------------------------------------------------------------------
    private static function runCommand(array $cmd): ?string
    {
        $cmdline = implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null';
        $output  = [];
        $rc      = 0;
        @exec($cmdline, $output, $rc);
        if ($rc !== 0) {
            return null;
        }
        return implode("\n", $output);
    }

    // -----------------------------------------------------------------------
    // Formatta un valore in byte in formato leggibile (B, KB, MB, …)
    // -----------------------------------------------------------------------
    private static function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = max(0, (float) $bytes);
        $i     = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return round($value, $precision) . ' ' . $units[$i];
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
            error_log('[MiTstakeAgent] ZipArchive non disponibile.');
            return null;
        }

        // Usa una directory temporanea dedicata per evitare TOCTOU su file condivisi in /tmp
        $tmpDir = sys_get_temp_dir() . '/eha_' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0700, true)) {
            return null;
        }
        $tmpZip = $tmpDir . '/report.zip';
        $zip    = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            rmdir($tmpDir);
            return null;
        }

        $ts = gmdate('Y-m-d\TH:i:s\Z');

        // ── Ambiente (WordPress / PHP / server / disco) ─────────────────
        $env = self::getEnvironment();

        // ── report.json ──────────────────────────────────────────────────
        $reportJson = json_encode([
            'site_id'     => EHA_SITE_ID,
            'timestamp'   => $ts,
            'log_line'    => self::buildLogLine($message),
            'method'      => sanitize_text_field($_SERVER['REQUEST_METHOD'] ?? ''),
            'path'        => self::redactUri(sanitize_text_field($_SERVER['REQUEST_URI'] ?? '')),
            'ip'          => sanitize_text_field($_SERVER['REMOTE_ADDR']    ?? ''),
            'useragent'   => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'environment' => $env,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $zip->addFromString('report.json', $reportJson);

        // ── Ambiente (WordPress / PHP / server / disco) ─────────────────
        $envLines = [
            'WordPress: '       . $env['wordpress'],
            'PHP: '             . $env['php'],
            'PHP SAPI: '        . $env['php_sapi'],
            'Server software: ' . $env['server'],
            'MiTstake Agent: '  . $env['plugin'],
        ];
        $disk = $env['disk'] ?? ['available' => false];
        if (!empty($disk['available'])) {
            $envLines[] = 'Disk path: '   . $disk['path'];
            $envLines[] = 'Disk total: '  . $disk['total_human'] . ' (' . (int) $disk['total'] . ' bytes)';
            $envLines[] = 'Disk used: '   . $disk['used_human']  . ' (' . (int) $disk['used']  . ' bytes)';
            $envLines[] = 'Disk free: '   . $disk['free_human']  . ' (' . (int) $disk['free']  . ' bytes)';
            $envLines[] = 'Disk used %: ' . $disk['used_percent'] . '%';
        } else {
            $envLines[] = 'Disk: [non disponibile]';
        }
        $zip->addFromString('logs/environment.txt', implode("\n", $envLines));

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
        // C-1: usa realpath su ABSPATH per evitare bypass via prefix match su directory adiacenti
        $webRoot    = defined('ABSPATH') ? rtrim((string)(realpath(ABSPATH) ?: ABSPATH), '/') : '';
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
            if ($webRoot !== '' && strpos($realSrc, $webRoot . '/') !== 0) {
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

        // Leggi il file e cancella la directory temporanea
        if (!is_readable($tmpZip)) {
            @unlink($tmpZip);
            rmdir($tmpDir);
            return null;
        }
        $data = file_get_contents($tmpZip);
        unlink($tmpZip);
        rmdir($tmpDir);

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
            return '[log non disponibile]';
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
        // C-1: usa realpath su ABSPATH per evitare bypass via prefix match su directory adiacenti
        $webRoot = defined('ABSPATH') ? rtrim((string)(realpath(ABSPATH) ?: ABSPATH), '/') : '/var/www/html';
        $pattern = '/(?:in |(?:PHP\s+\d+\.\s+\S+\(\)\s+))(\/[^\s:]+\.php)/';
        preg_match_all($pattern, $trace, $matches);
        $candidates = array_unique($matches[1] ?? []);
        $files = [];
        foreach ($candidates as $f) {
            $real = realpath($f);
            if ($real !== false && strpos($real, $webRoot . '/') === 0) {
                $files[] = $real;
            }
        }
        return $files;
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
            error_log('[MiTstakeAgent] Errore invio: ' . $response->get_error_message());
            return;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200 && $code !== 201) {
            error_log('[MiTstakeAgent] Hub ha risposto HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
        } else {
            error_log('[MiTstakeAgent] Report inviato con successo (HTTP ' . $code . ').');
        }
    }

    // -----------------------------------------------------------------------
    // Fallback cURL puro (before WP init)
    // -----------------------------------------------------------------------
    private static function sendViaCurl(string $logLine, string $zipData): void
    {
        if (!function_exists('curl_init')) {
            error_log('[MiTstakeAgent] cURL non disponibile.');
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
        $result = curl_exec($ch);
        if ($result === false) {
            error_log('[MiTstakeAgent] cURL errore invio: ' . curl_error($ch));
        } else {
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode && $httpCode !== 200 && $httpCode !== 201) {
                error_log('[MiTstakeAgent] Hub ha risposto HTTP ' . $httpCode);
            }
        }
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

        $parts = '';
        $disk  = self::getDiskInfo();
        $fields = [
            'log_line'          => substr($logLine, 0, 2048),
            'error_timestamp'   => $ts,
            'ip'                => $ip,
            'method'            => sanitize_text_field($_SERVER['REQUEST_METHOD'] ?? ''),
            'path'              => self::redactUri(sanitize_text_field($_SERVER['REQUEST_URI'] ?? '')),
            'useragent'         => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'wp_version'        => self::getWpVersion(),
            'php_version'       => PHP_VERSION,
            'disk_available'    => !empty($disk['available']) ? '1' : '0',
            'disk_path'         => $disk['path'] ?? '',
            'disk_total'        => isset($disk['total']) ? (string) (int) $disk['total'] : '',
            'disk_used'         => isset($disk['used'])  ? (string) (int) $disk['used']  : '',
            'disk_free'         => isset($disk['free'])  ? (string) (int) $disk['free']  : '',
            'disk_used_percent' => isset($disk['used_percent']) ? (string) $disk['used_percent'] : '',
            'disk_total_human'  => $disk['total_human'] ?? '',
            'disk_used_human'   => $disk['used_human'] ?? '',
            'disk_free_human'   => $disk['free_human'] ?? '',
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
    // Heartbeat disco: invio periodico dello stato disco (WP-Cron)
    // -----------------------------------------------------------------------
    public static function addCronSchedules(array $schedules): array
    {
        $minutes = max(5, (int) EHA_DISK_HEARTBEAT_INTERVAL);
        $schedules['eha_disk_heartbeat'] = [
            'interval' => $minutes * 60,
            'display'  => sprintf('Ogni %d minuti (MiTstake disco)', $minutes),
        ];
        return $schedules;
    }

    public static function scheduleDiskHeartbeat(): void
    {
        if (!EHA_DISK_HEARTBEAT) {
            wp_clear_scheduled_hook('eha_disk_heartbeat');
            return;
        }
        if (!wp_next_scheduled('eha_disk_heartbeat')) {
            wp_schedule_event(time() + 60, 'eha_disk_heartbeat', 'eha_disk_heartbeat');
        }
    }

    public static function sendDiskHeartbeat(): void
    {
        if (empty(EHA_SITE_ID) || empty(EHA_HUB_URL) || empty(EHA_API_KEY)) {
            return;
        }

        $disk    = self::getDiskInfo();
        $payload = [
            'disk_available'    => !empty($disk['available']) ? '1' : '0',
            'disk_path'         => $disk['path'] ?? '',
            'disk_total'        => isset($disk['total']) ? (string) (int) $disk['total'] : '',
            'disk_used'         => isset($disk['used'])  ? (string) (int) $disk['used']  : '',
            'disk_free'         => isset($disk['free'])  ? (string) (int) $disk['free']  : '',
            'disk_used_percent' => isset($disk['used_percent']) ? (string) $disk['used_percent'] : '',
            'disk_total_human'  => $disk['total_human'] ?? '',
            'disk_used_human'   => $disk['used_human'] ?? '',
            'disk_free_human'   => $disk['free_human'] ?? '',
        ];

        if (!function_exists('wp_remote_post')) {
            self::sendDiskHeartbeatViaCurl($payload);
            return;
        }

        $response = wp_remote_post(EHA_HUB_URL . '/api/v1/disk', [
            'headers'   => [
                'Authorization' => 'Bearer ' . EHA_API_KEY,
                'Content-Type'  => 'application/json',
            ],
            'body'      => json_encode($payload),
            'timeout'   => EHA_CURL_TIMEOUT,
            'sslverify' => true,
            'blocking'  => true,
        ]);

        if (is_wp_error($response)) {
            error_log('[MiTstakeAgent] Heartbeat disco fallito: ' . $response->get_error_message());
            return;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200 && $code !== 201 && $code !== 202) {
            error_log('[MiTstakeAgent] Heartbeat disco: hub ha risposto HTTP ' . $code);
        }
    }

    private static function sendDiskHeartbeatViaCurl(array $payload): void
    {
        if (!function_exists('curl_init')) {
            error_log('[MiTstakeAgent] cURL non disponibile per heartbeat disco.');
            return;
        }
        $ch = curl_init(EHA_HUB_URL . '/api/v1/disk');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . EHA_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => EHA_CURL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('eha_disk_heartbeat');
    }

    // -----------------------------------------------------------------------
    // Cooldown: usa la transient API di WP se disponibile, altrimenti file
    // -----------------------------------------------------------------------
    /**
     * Verifica e aggiorna il cooldown in un'unica operazione atomica (B-2).
     * Ritorna true se è consentito inviare, false se il cooldown è ancora attivo.
     */
    private static function checkAndUpdateCooldown(): bool
    {
        $cdKey = md5(EHA_SITE_ID . EHA_API_KEY);

        if (function_exists('get_transient')) {
            // B-2: chiave non prevedibile senza conoscere la API key
            if (get_transient('eha_cd_' . $cdKey)) {
                return false;
            }
            set_transient('eha_cd_' . $cdKey, 1, EHA_COOLDOWN);
            return true;
        }

        // Fallback file con lock esclusivo per evitare race condition (B-2)
        $f  = sys_get_temp_dir() . '/eha_cooldown_' . $cdKey;
        $fh = @fopen($f, 'c+');
        if (!$fh) {
            return false;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return false;
        }
        $content = (string) fread($fh, 20);
        $ts      = is_numeric(trim($content)) ? (int) trim($content) : 0;
        if ($ts && (time() - $ts) < EHA_COOLDOWN) {
            flock($fh, LOCK_UN);
            fclose($fh);
            return false;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) time());
        flock($fh, LOCK_UN);
        fclose($fh);
        return true;
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
            ['disk_heartbeat',          'Heartbeat disco',           'checkbox', 'eha_advanced', 'Invia periodicamente lo stato del disco all\'hub anche senza errori (WP-Cron).'],
            ['disk_heartbeat_interval', 'Intervallo heartbeat (min)', 'number',  'eha_advanced', 'Ogni quanti minuti inviare lo stato disco (min 5, default 60).'],
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
            $checkedVal = $value;
            // Il default effettivo dell'heartbeat disco è "attivo": mostra il flag
            // selezionato finché l'utente non salva esplicitamente una scelta.
            if ($id === 'disk_heartbeat' && $value === '') {
                $checkedVal = EHA_DISK_HEARTBEAT ? '1' : '0';
            }
            printf(
                '<label><input type="checkbox" name="eha_settings[%s]" value="1"%s> %s</label>',
                $id,
                checked($checkedVal, '1', false),
                $desc
            );
        } elseif ($type === 'api_key') {
            // Campo separato: inserisci solo per cambiare la chiave
            // La chiave esistente è preservata da un hidden field se il campo viene lasciato vuoto
            $masked = $value
                ? str_repeat('•', max(4, strlen($value) - 4)) . substr($value, -4)
                : '';
            printf(
                '<input type="password" name="eha_settings[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="%s">',
                $value ? 'Lascia vuoto per mantenere la chiave attuale' : 'Incolla qui la API key'
            );
            // Nota: la chiave esistente NON viene mai esposta in un campo hidden (rischio XSS da altri plugin).
            // La logica di preservazione è gestita interamente in sanitizeSettings() dal valore nel DB.
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
        // Blocca SSRF: rifiuta URL puntanti a indirizzi IP privati o di loopback
        if ($hub_url) {
            $host   = (string) parse_url($hub_url, PHP_URL_HOST);
            $parsed = $host ? gethostbyname($host) : '';
            if ($parsed && preg_match(
                '/^(127\.|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|169\.254\.)/',
                $parsed
            )) {
                add_settings_error('eha_settings', 'hub_url_ssrf', 'Hub URL non può puntare a un indirizzo privato o di loopback.', 'error');
                $hub_url = $existing['hub_url'] ?? '';
            }
        }
        $clean['hub_url'] = rtrim($hub_url, '/');

        // API key: usa la nuova se compilata, altrimenti legge esclusivamente dal DB (mai dall'input HTML)
        $new_key = trim($input['api_key'] ?? '');
        $clean['api_key'] = $new_key !== ''
            ? sanitize_text_field($new_key)
            : sanitize_text_field($existing['api_key'] ?? '');

        $clean['cooldown']         = max(10, (int) ($input['cooldown']         ?? 60));
        $clean['max_log_lines']    = max(10,  min(1000, (int) ($input['max_log_lines']    ?? 100)));
        $clean['max_source_files'] = max(1,   min(50,   (int) ($input['max_source_files'] ?? 10)));
        $clean['send_wp_user']     = !empty($input['send_wp_user']) ? '1' : '0';
        $clean['disk_heartbeat']          = !empty($input['disk_heartbeat']) ? '1' : '0';
        $clean['disk_heartbeat_interval'] = max(5, min(10080, (int) ($input['disk_heartbeat_interval'] ?? 60)));

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
