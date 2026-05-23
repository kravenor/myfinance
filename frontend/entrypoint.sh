#!/bin/sh
set -e

# Il volume anonimo /app/node_modules viene creato dal docker daemon come root.
# Lo riallineiamo all'utente runtime prima di passargli il controllo.
APP_UID="$(id -u app 2>/dev/null || echo 1000)"
APP_GID="$(id -g app 2>/dev/null || echo 1000)"

if [ -d /app/node_modules ]; then
  chown -R "${APP_UID}:${APP_GID}" /app/node_modules || true
fi

exec su-exec "${APP_UID}:${APP_GID}" "$@"
