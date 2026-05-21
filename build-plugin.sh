#!/usr/bin/env bash
# build-plugin.sh — Crea gli ZIP del plugin mitstake-agent
#
# Modalità 1 — ZIP specifico per sito (include config.php precompilata):
#   ./build-plugin.sh <site_id> <api_key> [hub_url]
#
# Modalità 2 — ZIP generico per GitHub releases / installazione manuale:
#   ./build-plugin.sh --release
#   Crea mitstake-agent.zip senza config.php (configurazione via pannello WP)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$SCRIPT_DIR/mitstake-agent"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

# ── Ricava la versione dal plugin header ──────────────────────────────────────
PLUGIN_VERSION="$(grep -m1 '^\s*\*\s*Version:' "$PLUGIN_DIR/mitstake-agent.php" | grep -oP '[\d.]+')"

# =============================================================================
# Modalità --release: ZIP generico (nessuna config.php)
# =============================================================================
if [[ "${1:-}" == "--release" ]]; then
    OUTPUT_ZIP="$SCRIPT_DIR/mitstake-agent.zip"
    cp -r "$PLUGIN_DIR" "$TMP_DIR/mitstake-agent"
    # Rimuove file specifici per il sito e file di sviluppo
    rm -f "$TMP_DIR/mitstake-agent/config.php"
    (cd "$TMP_DIR" && zip -qr "$OUTPUT_ZIP" mitstake-agent/ \
        --exclude "mitstake-agent/.git*" \
        --exclude "mitstake-agent/*.md")
    echo "✅ ZIP release v${PLUGIN_VERSION} creato: $OUTPUT_ZIP"
    echo "   → Carica questo file come asset su GitHub releases con tag v${PLUGIN_VERSION}"
    exit 0
fi

# =============================================================================
# Modalità sito-specifico: ZIP con config.php precompilata
# =============================================================================
SITE_ID="${1:-}"
API_KEY="${2:-}"
HUB_URL="${3:-https://mitstake.apps.mitdev.it}"

if [[ -z "$SITE_ID" || -z "$API_KEY" ]]; then
    echo "Uso:"
    echo "  $0 <site_id> <api_key> [hub_url]   # ZIP specifico per sito"
    echo "  $0 --release                        # ZIP generico per GitHub releases"
    exit 1
fi

OUTPUT_ZIP="$SCRIPT_DIR/mitstake-agent-${SITE_ID}.zip"
cp -r "$PLUGIN_DIR" "$TMP_DIR/mitstake-agent"

# Genera config.php con i valori reali
cat > "$TMP_DIR/mitstake-agent/config.php" <<PHP
<?php
/**
 * config.php — Configurazione MiTstake Agent
 * Generato automaticamente per il sito: ${SITE_ID}
 */

defined('ABSPATH') || exit;

defined('EHA_SITE_ID')          || define('EHA_SITE_ID',          '${SITE_ID}');
defined('EHA_HUB_URL')          || define('EHA_HUB_URL',          '${HUB_URL}');
defined('EHA_API_KEY')          || define('EHA_API_KEY',          '${API_KEY}');
defined('EHA_COOLDOWN')         || define('EHA_COOLDOWN',         60);
defined('EHA_MAX_LOG_LINES')    || define('EHA_MAX_LOG_LINES',    100);
defined('EHA_MAX_SOURCE_FILES') || define('EHA_MAX_SOURCE_FILES', 10);
defined('EHA_CURL_TIMEOUT')     || define('EHA_CURL_TIMEOUT',     30);
PHP

# Rimuove config-example.php dallo ZIP finale (non serve al sito)
rm -f "$TMP_DIR/mitstake-agent/config-example.php"

# Crea lo ZIP
(cd "$TMP_DIR" && zip -qr "$OUTPUT_ZIP" mitstake-agent/)

echo "✅ ZIP v${PLUGIN_VERSION} creato per sito '${SITE_ID}': $OUTPUT_ZIP"
