FROM php:8.4-apache

# ------------------------------------------------------------------
# Install system dependencies (including Chromium for Puppeteer)
# ------------------------------------------------------------------
RUN apt-get update && apt-get install -y \
        libpng-dev \
        libzip-dev \
        zip \
        unzip \
        git \
        curl \
        libpq-dev \
        supervisor \
        chromium \
        libnss3 \
        libatk1.0-0 \
        libatk-bridge2.0-0 \
        libcups2 \
        libdrm2 \
        libxkbcommon0 \
        libxcomposite1 \
        libxdamage1 \
        libxfixes3 \
        libxrandr2 \
        libgbm1 \
        libasound2 \
        libx11-xcb1 \
        libxcb-dri3-0 \
    && docker-php-ext-install pdo_mysql gd zip bcmath pdo_pgsql exif fileinfo \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ------------------------------------------------------------------
# Install Node.js 20.x (for Puppeteer automation)
# ------------------------------------------------------------------
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# ------------------------------------------------------------------
# Install Composer (global)
# ------------------------------------------------------------------
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ------------------------------------------------------------------
# Set working directory
# ------------------------------------------------------------------
WORKDIR /var/www/html

# ------------------------------------------------------------------
# Copy Composer files first – better layer caching
# ------------------------------------------------------------------
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# ------------------------------------------------------------------
# Copy npm package files and install dependencies (if they exist)
# ------------------------------------------------------------------
COPY package*.json ./
RUN if [ -f package.json ]; then npm ci --production; fi

# ------------------------------------------------------------------
# Copy the rest of the application
# ------------------------------------------------------------------
COPY . .

# ------------------------------------------------------------------
# Puppeteer configuration (use system Chromium, skip download)
# ------------------------------------------------------------------
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# ------------------------------------------------------------------
# Create writable temp directories for Puppeteer and Laravel logs
# ------------------------------------------------------------------
RUN mkdir -p /tmp/puppeteer_user_data && \
    mkdir -p /var/www/html/storage/logs && \
    chmod -R 777 /tmp && \
    chown -R www-data:www-data /tmp/puppeteer_user_data /var/www/html/storage /var/www/html/bootstrap/cache

# ------------------------------------------------------------------
# Verify Chromium works (non‑fatal, just to confirm)
# ------------------------------------------------------------------
RUN chromium --no-sandbox --headless --disable-gpu --dump-dom about:blank > /dev/null 2>&1 && \
    echo "Chromium OK" || echo "Chromium check failed (non-fatal)"

# ------------------------------------------------------------------
# Final Laravel optimizations and permissions
# ------------------------------------------------------------------
RUN composer dump-autoload --optimize

# ------------------------------------------------------------------
# Configure Apache to serve Laravel's public directory
# ------------------------------------------------------------------
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# ------------------------------------------------------------------
# Copy Supervisor configuration (to run queue worker + Apache)
# ------------------------------------------------------------------
COPY docker/supervisor.conf /etc/supervisor/conf.d/laravel-worker.conf

# ------------------------------------------------------------------
# Copy and make entrypoint script executable
# ------------------------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# ------------------------------------------------------------------
# Expose port 80 for Apache
# ------------------------------------------------------------------
EXPOSE 80

# ------------------------------------------------------------------
# Start Supervisor (manages Apache and Laravel queue worker)
# ------------------------------------------------------------------
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
