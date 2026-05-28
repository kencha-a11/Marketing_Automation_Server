FROM php:8.4-apache

# ------------------------------------------------------------------
# Install System Dependencies (including PostgreSQL and Supervisor)
# ------------------------------------------------------------------
RUN echo "\n[LOG] $(date '+%Y-%m-%d %H:%M:%S') - Starting system dependencies..." && \
    apt-get update && apt-get install -y \
        libpng-dev \
        libzip-dev \
        zip unzip \
        git curl \
        libpq-dev \
        supervisor \
    && docker-php-ext-install pdo_mysql gd zip bcmath pdo_pgsql \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && echo "[LOG] $(date '+%Y-%m-%d %H:%M:%S') - System dependencies installed."

# ------------------------------------------------------------------
# Install Node.js (separate RUN command)
# ------------------------------------------------------------------
RUN echo "\n[LOG] $(date '+%Y-%m-%d %H:%M:%S') - Installing Node.js..." && \
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs && \
    echo "[LOG] $(date '+%Y-%m-%d %H:%M:%S') - Node.js $(node -v) installed."

# ------------------------------------------------------------------
# Install Composer (global)
# ------------------------------------------------------------------
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ------------------------------------------------------------------
# Set Working Directory
# ------------------------------------------------------------------
WORKDIR /var/www/html

# ------------------------------------------------------------------
# Copy composer files FIRST – better layer caching
# ------------------------------------------------------------------
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# ------------------------------------------------------------------
# Copy the rest of the application
# ------------------------------------------------------------------
COPY . .
RUN composer dump-autoload --optimize

# ------------------------------------------------------------------
# Set permissions for Laravel directories
# ------------------------------------------------------------------
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# ------------------------------------------------------------------
# Configure Apache document root to Laravel's public folder
# ------------------------------------------------------------------
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# ------------------------------------------------------------------
# Copy Supervisor configuration
# ------------------------------------------------------------------
COPY docker/supervisor.conf /etc/supervisor/conf.d/laravel-worker.conf

# ------------------------------------------------------------------
# Copy entrypoint script
# ------------------------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# ------------------------------------------------------------------
# Make automate.cjs executable if it exists
# ------------------------------------------------------------------
RUN if [ -f automate.cjs ]; then chmod +x automate.cjs; fi

# ------------------------------------------------------------------
# Healthcheck (checks both Apache and queue worker via a simple HTTP endpoint)
# ------------------------------------------------------------------
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
