#!/bin/sh
# Quitt-Entrypoint: Port setzen, /data-Rechte korrigieren, Apache starten.
set -e
PORT="${QUITT_PORT:-8080}"
case "$PORT" in ''|*[!0-9]*) echo "QUITT_PORT ungueltig: $PORT" >&2; exit 1 ;; esac

sed -ri "s/^(\s*Listen\s+)[0-9]+/\1${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

DATA="${QUITT_DATA_DIR:-/data}"
mkdir -p "$DATA"
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data "$DATA" 2>/dev/null || echo "Hinweis: Rechte fuer $DATA konnten nicht gesetzt werden." >&2
    chmod 750 "$DATA" 2>/dev/null || true
fi

exec docker-php-entrypoint "$@"
