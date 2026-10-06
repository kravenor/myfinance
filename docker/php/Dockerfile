FROM php:8.3-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
    bash \
    git \
    curl \
    unzip \
    icu-dev \
    icu-data-full \
    libzip-dev \
    oniguruma-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    mysql-client \
    autoconf \
    g++ \
    make \
    linux-headers

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_mysql \
    mbstring \
    intl \
    zip \
    bcmath \
    gd \
    opcache \
    pcntl \
    sockets

RUN pecl install redis \
 && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Come frontend/Dockerfile: su macOS GID=20 (staff) è già «dialout» in Alpine, si riusa il gruppo esistente.
RUN if ! getent group ${GID} >/dev/null; then addgroup -g ${GID} -S app; fi \
 && adduser -u ${UID} -S app -G "$(getent group ${GID} | cut -d: -f1)" \
 && mkdir -p /var/www/html \
 && chown -R ${UID}:${GID} /var/www/html

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
USER app

EXPOSE 9000
CMD ["php-fpm"]
