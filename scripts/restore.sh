#!/usr/bin/env bash
# Ripristina un dump prodotto da scripts/backup.sh.
# DISTRUTTIVO: sovrascrive le tabelle del database corrente, poi chiude tutte le
# sessioni (anche i cookie «Ricordami») e svuota il DB Redis di sessioni e code.
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

# Il dump riporta password, remember_token e 2FA di quando è stato fatto: senza questo
# passo un «Esci dagli altri dispositivi» o un cambio password successivi al dump
# verrebbero annullati e un cookie «Ricordami» o una sessione rubati tornerebbero validi.
docker compose exec -T mysql sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE" -e "UPDATE users SET remember_token = NULL; DELETE FROM sessions;"'
# Con SESSION_DRIVER=redis le sessioni stanno nella connessione Redis di default
# (REDIS_DB), la stessa delle code: si svuota tutto il DB. I job in coda persi puntano
# comunque a righe di prima del restore.
docker compose exec -T php sh -c 'printf "%s\n%s\n" "${REDIS_DB:-0}" "${REDIS_PASSWORD:-}"' | {
    read -r REDIS_DB
    read -r REDIS_PASSWORD || true
    [ "$REDIS_PASSWORD" = "null" ] && REDIS_PASSWORD=
    docker compose exec -T -e REDISCLI_AUTH="$REDIS_PASSWORD" redis redis-cli -n "$REDIS_DB" FLUSHDB > /dev/null
}

echo "✓ database ripristinato da $FILE"
echo "! sessioni e cookie «Ricordami» invalidati: tutti gli utenti devono rifare l'accesso." >&2
echo "! cambi di password, 2FA ed «Esci dagli altri dispositivi» fatti dopo il dump sono persi: avvisa gli utenti." >&2
