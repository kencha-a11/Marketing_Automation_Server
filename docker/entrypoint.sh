#!/bin/bash
set -e

log() {
    echo "[LOG] $(date '+%Y-%m-%d %H:%M:%S') - $1"
}

log "=========================================="
log " Starting Laravel Application Initialization"
log "=========================================="

# ------------------------------------------------------------------
# Wait for database to become available
# ------------------------------------------------------------------
wait_for_db() {
    local max_attempts=30
    local attempt=1
    local delay=2

    log "Waiting for database to become available..."

    while [ $attempt -le $max_attempts ]; do
        if php artisan migrate:status >/dev/null 2>&1; then
            log "Database is ready (attempt $attempt/$max_attempts)."
            return 0
        else
            log "Database not ready yet (attempt $attempt/$max_attempts) - sleeping ${delay}s..."
            sleep $delay
            ((attempt++))
        fi
    done

    log "ERROR: Database did not become ready in time."
    log "Please check your DB_* environment variables (especially DB_HOST)."
    exit 1
}

wait_for_db

# ------------------------------------------------------------------
# Generate app key if not already set
# ------------------------------------------------------------------
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:..." ]; then
    log "APP_KEY is missing or invalid. Generating new key..."
    php artisan key:generate --force
else
    log "APP_KEY already set."
fi

# ------------------------------------------------------------------
# Run migrations (idempotent)
# ------------------------------------------------------------------
log "Running database migrations..."
if php artisan migrate --force; then
    log "Migrations completed successfully."
else
    log "ERROR: Database migrations failed."
    exit 1
fi

# ------------------------------------------------------------------
# Clear and cache configuration (run as www-data for security)
# ------------------------------------------------------------------
log "Caching Laravel configuration..."
if su -s /bin/bash www-data -c "php artisan config:cache"; then
    log "Configuration cached successfully."
else
    log "ERROR: Configuration caching failed."
    exit 1
fi

log "Caching routes..."
if su -s /bin/bash www-data -c "php artisan route:cache"; then
    log "Routes cached successfully."
else
    log "ERROR: Route caching failed."
    exit 1
fi

log "Caching views..."
if su -s /bin/bash www-data -c "php artisan view:cache"; then
    log "Views cached successfully."
else
    log "WARNING: View caching failed (non-critical)."
fi

# ------------------------------------------------------------------
# Create storage/logs directory if it doesn't exist (for worker logs)
# ------------------------------------------------------------------
mkdir -p /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage/logs

# ------------------------------------------------------------------
# Environment info (for debugging)
# ------------------------------------------------------------------
log "Current PHP version: $(php -v | head -n1)"
log "Laravel environment: $(php artisan env | head -n1)"

# ------------------------------------------------------------------
# Start Supervisor (which manages Apache + queue worker)
# ------------------------------------------------------------------
log "=========================================="
log " Starting Supervisor (Apache + Queue Worker)"
log "=========================================="
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
