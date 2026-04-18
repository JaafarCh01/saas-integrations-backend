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

# Enable Apache mod_rewrite (mpm_prefork is the default on bullseye,
# no extra surgery needed)
RUN a2enmod rewrite

# Sanity-check Apache config at build time — fail fast if broken
RUN apache2ctl -t

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

# Run Apache in foreground, dumping MPM state to logs first so we can
# see what Apache actually sees at runtime (not build time)
CMD ["sh", "-c", "echo '=== runtime mods-enabled ===' && ls -la /etc/apache2/mods-enabled/ && echo '=== runtime apache2ctl -M ===' && apache2ctl -M 2>&1 ; echo '=== starting apache ===' ; exec apache2-foreground"]