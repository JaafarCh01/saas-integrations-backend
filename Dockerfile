# Use the official PHP image with Apache
FROM php:8.2-apache-bookworm

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

# Enable Apache mod_rewrite and ensure only mpm_prefork is active
# (mod_php requires mpm_prefork; bookworm leaves other MPMs wired up which
# causes "More than one MPM loaded" on boot — scrub every mpm symlink AND
# comment out any stray `LoadModule mpm_...` line hiding in conf files)
RUN set -e && \
    rm -f /etc/apache2/mods-enabled/mpm_*.load \
          /etc/apache2/mods-enabled/mpm_*.conf && \
    find /etc/apache2 -type f \( -name "*.conf" -o -name "*.load" \) \
        -exec sed -i -E '/^[[:space:]]*LoadModule[[:space:]]+mpm_/ s|^|# DISABLED-BY-DOCKERFILE: |' {} \; && \
    a2enmod mpm_prefork && \
    a2enmod rewrite && \
    echo "=== final mods-enabled ===" && ls -la /etc/apache2/mods-enabled/ && \
    echo "=== remaining uncommented mpm LoadModule lines ===" && \
    (grep -rnE "^[^#]*LoadModule[[:space:]]+mpm_" /etc/apache2/ || echo "none — good")

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

# Run Apache in foreground
CMD ["apache2-foreground"]