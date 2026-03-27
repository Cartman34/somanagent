FROM php:8.4-fpm-alpine

# Dépendances système
RUN apk add --no-cache \
    git \
    curl \
    unzip \
    libpq-dev \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    nodejs \
    npm \
    supervisor

# Extensions PHP
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    intl \
    mbstring \
    zip \
    opcache

# Extension Redis (pour Symfony Messenger transport)
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Claude CLI (non-interactive agent connector)
# Installed as root so the binary is available system-wide at /usr/local/bin/claude
RUN npm install -g @anthropic-ai/claude-code

# Configuration PHP
COPY php.ini /usr/local/etc/php/conf.d/app.ini

# Supervisor config (gère php-fpm + messenger worker)
COPY supervisor.conf /etc/supervisor/conf.d/somanagent.conf

# Non-root user
RUN addgroup -g 1000 app && adduser -u 1000 -G app -s /bin/sh -D app

WORKDIR /var/www/backend

# Supervisor tourne en root pour gérer les processus, les sous-processus héritent des permissions
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/somanagent.conf"]
