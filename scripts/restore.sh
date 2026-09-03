#!/usr/bin/env bash
# Ripristina un dump prodotto da scripts/backup.sh.
# DISTRUTTIVO: sovrascrive le tabelle del database corrente.
#
#   ./scripts/restore.sh backups/finance-20260903-030000.sql.gz [-y]
#
# Stessa selezione dello stack di backup.sh (COMPOSE_FILE / COMPOSE_ENV_FILES).
#
# ponytail: il dump contiene `DROP TABLE IF EXISTS` per ogni tabella, non un DROP
# DATABASE: una tabella creata dopo il dump sopravvive al restore.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

FILE=${1:-}
[ -n "$FILE" ] || { echo "uso: $0 <file.sql.gz> [-y]" >&2; exit 2; }
[ -f "$FILE" ] || { echo "file non trovato: $FILE" >&2; exit 2; }
gzip -t "$FILE" 2>/dev/null || { echo "archivio non valido: $FILE" >&2; exit 2; }

if [ "${2:-}" != "-y" ]; then
    read -r -p "Sovrascrivo il database con $FILE? [scrivi 'si'] " answer < /dev/tty
    [ "$answer" = "si" ] || { echo "annullato"; exit 1; }
fi

gzip -dc "$FILE" | docker compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE"'

echo "✓ database ripristinato da $FILE"
