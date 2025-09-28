FROM php:8.3-fpm-alpine

RUN set -eux \
    && apk add --no-cache icu-data-full icu-libs oniguruma-dev libzip-dev curl git \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring bcmath opcache \
    && rm -rf /var/cache/apk/*

RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=1"; \
      echo "opcache.jit=1255"; \
      echo "opcache.jit_buffer_size=128M"; \
      echo "opcache.memory_consumption=256"; \
      echo "opcache.interned_strings_buffer=16"; \
      echo "opcache.max_accelerated_files=100000"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

RUN { \
      echo "memory_limit=1G"; \
      echo "post_max_size=4G"; \
      echo "upload_max_filesize=4G"; \
      echo "max_execution_time=300"; \
      echo "date.timezone=Asia/Taipei"; \
    } > /usr/local/etc/php/conf.d/laravel.ini

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

USER www-data
