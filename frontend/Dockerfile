FROM node:20-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache bash git \
 && (deluser --remove-home node 2>/dev/null || true) \
 && addgroup -g ${GID} -S app \
 && adduser -u ${UID} -S app -G app \
 && mkdir -p /app \
 && chown -R app:app /app

WORKDIR /app
USER app

EXPOSE 5173
CMD ["sh", "-c", "if [ -f package.json ]; then npm install && npm run dev -- --host 0.0.0.0; else tail -f /dev/null; fi"]
