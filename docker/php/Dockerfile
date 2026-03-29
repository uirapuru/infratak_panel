FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        librabbitmq-dev \
        libicu-dev \
        libpq-dev \
        libonig-dev \
    && docker-php-ext-install pdo_mysql intl zip \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/infratak-entrypoint

RUN chmod +x /usr/local/bin/infratak-entrypoint

WORKDIR /app

ENTRYPOINT ["/usr/local/bin/infratak-entrypoint"]
CMD ["php-fpm"]
