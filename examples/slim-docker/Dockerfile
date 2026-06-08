FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/amtgard-idp-php-client

# Library sources (path repository target for the example app).
COPY composer.json composer.lock* ./
COPY src ./src

WORKDIR /opt/amtgard-idp-php-client/examples/slim-docker

COPY examples/slim-docker/composer.json examples/slim-docker/.env.example ./
COPY examples/slim-docker/config ./config
COPY examples/slim-docker/public ./public
COPY examples/slim-docker/src ./src

RUN cp .env.example .env \
    && composer install --no-interaction --prefer-dist --no-progress

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
