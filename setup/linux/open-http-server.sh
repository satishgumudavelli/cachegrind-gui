#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORT="${BOTTLENECK_OPEN_PORT:-63343}"
ROUTER="${SCRIPT_DIR}/open-router.php"

if ! command -v php >/dev/null 2>&1; then
    echo "error: php not found" >&2
    exit 1
fi

echo "Bottleneck open server on http://127.0.0.1:${PORT}/open"
echo "Example: http://127.0.0.1:${PORT}/open?file=/var/www/html/bottleneck/index.php&line=10"
echo "Ctrl+C to stop."
exec php -S "127.0.0.1:${PORT}" "${ROUTER}"
