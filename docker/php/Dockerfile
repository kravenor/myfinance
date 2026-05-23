FROM php:8.3-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
    bash \
    git \
    curl \
    unzip \
    icu-dev \
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

RUN addgroup -g ${GID} -S app \
 && adduser -u ${UID} -S app -G app \
 && mkdir -p /var/www/html \
 && chown -R app:app /var/www/html

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
USER app

EXPOSE 9000
CMD ["php-fpm"]
