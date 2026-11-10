FROM php:8.4-fpm-alpine

ARG UID=1000
ARG GID=1000
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer
RUN apk add --no-cache shadow \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS openssl-dev \
    && pecl install redis mongodb \
    && docker-php-ext-enable redis mongodb \
    && apk del .build-deps \
    && groupmod -o -g ${GID} www-data \
    && usermod -o -u ${UID} -g ${GID} www-data

WORKDIR /var/www
