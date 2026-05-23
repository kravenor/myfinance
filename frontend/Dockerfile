FROM node:20-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache bash git su-exec \
 && (deluser --remove-home node 2>/dev/null || true) \
 && if ! getent group ${GID} >/dev/null; then addgroup -g ${GID} -S app; fi \
 && GROUP_NAME=$(getent group ${GID} | cut -d: -f1) \
 && if ! getent passwd ${UID} >/dev/null; then adduser -u ${UID} -S -D -G "${GROUP_NAME}" app; fi \
 && APP_USER=$(getent passwd ${UID} | cut -d: -f1) \
 && if [ "${APP_USER}" != "app" ]; then ln -sf "/home/${APP_USER}" /home/app 2>/dev/null || true; fi \
 && mkdir -p /app \
 && chown -R ${UID}:${GID} /app

WORKDIR /app

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 5173
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["sh", "-c", "if [ -f package.json ]; then npm install && npm run dev -- --host 0.0.0.0; else tail -f /dev/null; fi"]
