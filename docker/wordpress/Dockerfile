FROM composer:2 AS composer

FROM dunglas/frankenphp:1.13.0-php8.5.11

RUN apt-get update && apt-get install -y --no-install-recommends \
    nano \
    supervisor \
    cron \
    zip \
    unzip \
    less \
  && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    gd \
    imagick \
    exif \
    intl \
    mysqli \
    pdo \
    pdo_mysql \
    opcache \
    redis \
    zip

COPY php.ini $PHP_INI_DIR/conf.d/99-custom.ini

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN groupadd -g 1000 koncewicz \
 && useradd -m -s /bin/bash -u 1000 -g 1000 koncewicz

RUN echo "* * * * * su -s /bin/bash -c 'cd /var/www && /usr/local/bin/php vendor/wp-cli/wp-cli/php/boot-fs.php cron event run --due-now' koncewicz >> /proc/1/fd/1 2>/proc/1/fd/2" | crontab -

WORKDIR /var/www

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

COPY start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

ENTRYPOINT ["/usr/local/bin/start-container"]
