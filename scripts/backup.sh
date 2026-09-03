#!/usr/bin/env bash
# Dump gzippato del database MySQL, con retention.
#
#   ./scripts/backup.sh [dir_destinazione]
#
# Su uno stack diverso da quello di sviluppo si usano le variabili native di
# compose, così qui non serve nessun flag:
#   COMPOSE_FILE=docker-compose.vps.yml COMPOSE_ENV_FILES=.env.production ./scripts/backup.sh
#
# Variabili: BACKUP_DIR (default `backups`), BACKUP_KEEP_DAYS (default 14).
#
# ponytail: Redis non viene salvato — contiene solo cache, sessioni e code, tutti
# dati derivati e ricostruibili. Quando arriveranno gli allegati, aggiungere qui
# un tar di storage/app.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

DEST=${1:-${BACKUP_DIR:-backups}}
KEEP_DAYS=${BACKUP_KEEP_DAYS:-14}
FILE="$DEST/finance-$(date +%Y%m%d-%H%M%S).sql.gz"

mkdir -p "$DEST"

# Le credenziali restano dentro il container (env del servizio mysql): non
# passano dalla riga di comando dell'host, dove sarebbero visibili in `ps`.
if ! docker compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' \
    | gzip > "$FILE.part"; then
    rm -f "$FILE.part"
    echo "✗ backup FALLITO: mysqldump non ha completato" >&2
    exit 1
fi

# Un dump troncato è peggio di nessun dump: mysqldump chiude con "Dump completed",
# quindi la sua presenza certifica che il file è integro fino alla fine.
if ! gzip -dc "$FILE.part" 2>/dev/null | tail -5 | grep -q 'Dump completed'; then
    rm -f "$FILE.part"
    echo "✗ backup FALLITO: dump incompleto o corrotto" >&2
    exit 1
fi

mv "$FILE.part" "$FILE"
echo "✓ $FILE ($(du -h "$FILE" | cut -f1))"

find "$DEST" -maxdepth 1 -name 'finance-*.sql.gz' -mtime "+$KEEP_DAYS" -print -delete
