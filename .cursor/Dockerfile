FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Asia/Jakarta

# Base tooling plus the PHP 8.4 toolchain (the composer.lock pins Symfony 8
# components that require php >= 8.4.1) and Node.js 22 for the Vite build.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git gnupg unzip software-properties-common \
    && add-apt-repository -y ppa:ondrej/php \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        nodejs \
        redis-server \
        php8.4-cli \
        php8.4-common \
        php8.4-mbstring \
        php8.4-xml \
        php8.4-curl \
        php8.4-sqlite3 \
        php8.4-gd \
        php8.4-bcmath \
        php8.4-intl \
        php8.4-zip \
        php8.4-mysql \
        php8.4-redis \
    && curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

USER ubuntu
