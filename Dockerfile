# TicketsCAD NewUI v4 — application image (PHP 8.2 + Apache).
# Paired with docker-compose.yml, which adds MariaDB and persistent volumes.
FROM php:8.2-apache-bookworm

# ── System libraries + PHP extensions ──────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
    libxml2-dev libonig-dev libcurl4-openssl-dev libssl-dev zlib1g-dev \
    git unzip default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli pdo pdo_mysql gd zip xml mbstring bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers proxy proxy_http proxy_wstunnel \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# ── Apache: allow .htaccess (clean-URL API dispatcher) + no dir listing ─────
#    Plus the optional voice-feature WebSocket paths: when the `voice` compose
#    profile is running, /zello-ws and /dmr-ws are wstunnel-proxied to the
#    zello-proxy / dmr-proxy containers. When that profile is NOT up, those two
#    hostnames don't resolve and only those paths 503 — the core app is unaffected.
RUN printf '<Directory /var/www/html/>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
<Location /zello-ws>\n\
    ProxyPass        ws://zello-proxy:8090/ keepalive=On\n\
    ProxyPassReverse ws://zello-proxy:8090/\n\
</Location>\n\
<Location /dmr-ws>\n\
    ProxyPass        ws://dmr-proxy:8092/ keepalive=On\n\
    ProxyPassReverse ws://dmr-proxy:8092/\n\
</Location>\n' > /etc/apache2/conf-available/newui.conf \
    && a2enconf newui

# ── PHP runtime configuration ───────────────────────────────────────────────
RUN { \
    echo 'display_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_log = /dev/stderr'; \
    echo 'error_reporting = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED'; \
    echo 'max_execution_time = 300'; \
    echo 'memory_limit = 256M'; \
    echo 'post_max_size = 50M'; \
    echo 'upload_max_filesize = 30M'; \
    echo 'session.cookie_httponly = 1'; \
    echo 'date.timezone = UTC'; \
} > /usr/local/etc/php/conf.d/newui.ini

# ── Composer (vendored PHP deps: ratchet, web-push, php-jwt) ─────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html

# Install PHP dependencies first so the vendor layer caches across app changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

# ── Application code ─────────────────────────────────────────────────────────
COPY . /var/www/html/
RUN composer dump-autoload --no-dev --optimize \
    && chmod +x /var/www/html/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
