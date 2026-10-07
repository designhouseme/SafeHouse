FROM wordpress:cli-php8.3
USER root
RUN apk add --no-cache --virtual .redis-build $PHPIZE_DEPS \
    && pecl install redis-6.1.0 \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pcntl \
    && apk del .redis-build
USER 65534:65534
ENTRYPOINT ["php"]
