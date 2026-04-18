# Use the official PHP image with Apache (Debian 11 — ships mpm_prefork
# by default, which is what mod_php needs. The bookworm variant defaults
# to mpm_event and requires surgery to replace it.)
FROM php:8.2-apache-bullseye

# Install system dependencies (ADDED: libc-client-dev, libkrb5-dev for IMAP)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    curl \
    libzip-dev \
    ffmpeg \
    libc-client-dev \
    libkrb5-dev

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Configure IMAP (REQUIRED for Email Agent)
RUN docker-php-ext-configure imap --with-kerberos --with-imap-ssl

# Install PHP extensions (ADDED: imap)
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip imap

# The php:8.2-apache-bullseye base image ships with BOTH mpm_event and
# mpm_prefork symlinked in mods-enabled/. rm and a2dismod both appear
# to fail silently in this environment (symlinks reappear at runtime).
# Workaround: overwrite the SOURCE files in mods-available with empty
# content — the symlinks still exist but now resolve to files that
# contain no LoadModule directive, so Apache loads no event MPM.
RUN set -ex && \
    echo "# disabled by dockerfile (mod_php needs mpm_prefork)" \
        | tee /etc/apache2/mods-available/mpm_event.load \
              /etc/apache2/mods-available/mpm_event.conf \
              /etc/apache2/mods-available/mpm_worker.load \
              /etc/apache2/mods-available/mpm_worker.conf \
        > /dev/null && \
    a2enmod mpm_prefork && \
    a2enmod rewrite && \
    echo "=== mpm_event.load contents (should be comment only) ===" && \
    cat /etc/apache2/mods-available/mpm_event.load && \
    apache2ctl -t

# Set working directory
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . /var/www/html

# Force remove any config cache that might have been copied
RUN rm -rf /var/www/html/bootstrap/cache/*.php

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Configure Apache DocumentRoot to point to public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Use the default production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Install dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Expose port 8080 (Cloud Run default)
EXPOSE 8080
ENV PORT=8080

# Update Apache ports configuration to listen on PORT env var
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Run migrations inside Railway's network (where mysql.railway.internal
# resolves), then start Apache. If migrations fail, the deploy fails loudly
# instead of serving a broken app.
CMD ["sh", "-c", "php artisan migrate --force && exec apache2-foreground"]