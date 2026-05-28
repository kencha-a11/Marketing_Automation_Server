FROM php:8.4-apache

RUN apt-get update && apt-get install -y \
        libpng-dev libzip-dev zip unzip git curl libpq-dev supervisor \
        chromium \
        libnss3 libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 \
        libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 \
        libgbm1 libasound2 libx11-xcb1 libxcb-dri3-0 \
    && docker-php-ext-install pdo_mysql gd zip bcmath pdo_pgsql \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY package*.json ./
RUN npm install

COPY . .

ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# Puppeteer temp dirs with wide permissions
RUN mkdir -p /tmp/puppeteer_user_data && \
    chmod -R 777 /tmp && \
    chown -R www-data:www-data /tmp/puppeteer_user_data

# Verify chromium works
RUN chromium --no-sandbox --headless --disable-gpu --dump-dom about:blank > /dev/null 2>&1 && \
    echo "Chromium OK" || echo "Chromium check failed (non-fatal)"

RUN composer dump-autoload --optimize && \
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

COPY docker/supervisor.conf /etc/supervisor/conf.d/laravel-worker.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
