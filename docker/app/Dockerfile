FROM composer:2 AS composer
FROM node:26.10.0 AS node

FROM dunglas/frankenphp:1.13.0-builder-php8.5.11

RUN apt-get update && apt-get install -y --no-install-recommends \
    nano \
    supervisor \
    zip \
    unzip \
  && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    gd \
    pcntl \
    pdo \
    pdo_mysql \
    redis

COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

RUN groupadd -g 1000 koncewicz \
 && useradd -m -s /bin/bash -u 1000 -g 1000 koncewicz

WORKDIR /var/www

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

COPY start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

ENTRYPOINT ["/usr/local/bin/start-container"]
