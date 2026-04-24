<?php
/**
 * config.php — Configurazione MiTstake Agent per questo sito WordPress
 *
 * Modifica questi valori e carica questo file (insieme a mitstake-agent.php)
 * nella cartella wp-content/plugins/mitstake-agent/ via FTP.
 *
 * NON committare questo file nel repository: contiene la API key.
 */

defined('ABSPATH') || exit;

// Identificativo univoco di questo sito (deve corrispondere al site_id creato sull'hub)
define('EHA_SITE_ID', 'nome-del-mio-sito');

// URL dell'hub (senza slash finale)
define('EHA_HUB_URL', 'https://errorhub.example.com');

// API key generata dall'hub per questo sito
define('EHA_API_KEY', 'inserisci-qui-la-tua-api-key');

// Secondi di cooldown tra un invio e il successivo
define('EHA_COOLDOWN', 60);

// Numero righe da leggere da ogni log contestuale
define('EHA_MAX_LOG_LINES', 100);

// Numero massimo di file sorgente PHP inclusi nello ZIP
define('EHA_MAX_SOURCE_FILES', 10);

// Timeout cURL in secondi
define('EHA_CURL_TIMEOUT', 30);
