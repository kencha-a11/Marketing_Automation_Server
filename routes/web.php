<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;


Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'message' => 'Server is awake!'], 200);
});

Route::get('/debug-logs', function (Request $request) {
    // 1. Security check
    if ($request->query('key') !== env('DEBUG_KEY')) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $logPath = storage_path('logs/laravel.log');

    // 2. Kung wala ang log file, gawin ito
    if (!File::exists($logPath)) {
        // Subukang gumawa ng directory kung wala pa
        $logDir = dirname($logPath);
        if (!File::exists($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }
        // Lumikha ng empty file
        File::put($logPath, '');
        // Siguraduhing writable ng web server (www-data)
        chmod($logPath, 0664);

        // Opsyonal: Maglagay ng initial log entry para may laman agad
        try {
            \Log::info('Log file auto-created via debug-logs endpoint');
        } catch (\Exception $e) {
            // Kapag hindi pa gumagana ang Log, direct write na lang
            File::put($logPath, "[" . date('Y-m-d H:i:s') . "] Log file initialized.\n");
        }
    }

    // 3. Basahin ang log file
    $logs = file($logPath);

    // 4. Kunin ang huling 50 linya
    $logs = array_slice($logs, -50);

    // 5. Ibalik bilang plain text
    return response(implode("", $logs), 200)
        ->header('Content-Type', 'text/plain');
});

Route::get('/debug-config', function (Request $request) {
    // 1. Mandatory Security Check
    if ($request->query('key') !== env('DEBUG_KEY')) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    // 2. I-filter ang mga sensitibong impormasyon (walang password o secret keys)
    $config = [
        // App settings
        'APP_NAME' => config('app.name'),
        'APP_ENV' => config('app.env'),
        'APP_DEBUG' => config('app.debug'),
        'APP_LOCALE' => config('app.locale'),
        'APP_FALLBACK_LOCALE' => config('app.fallback_locale'),
        'APP_FAKER_LOCALE' => config('app.faker_locale'),
        'APP_MAINTENANCE_DRIVER' => env('APP_MAINTENANCE_DRIVER'),

        // Logging
        'LOG_CHANNEL' => config('logging.default'),
        'LOG_STACK' => env('LOG_STACK'),
        'LOG_DEPRECATIONS_CHANNEL' => env('LOG_DEPRECATIONS_CHANNEL'),
        'LOG_LEVEL' => config('logging.level'),

        // Database
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => env('DB_HOST'),
        'DB_PORT' => env('DB_PORT'),
        'DB_DATABASE' => env('DB_DATABASE'),
        'DB_USERNAME' => env('DB_USERNAME'),
        // 'DB_PASSWORD' ay HINDI isinama (sensitive)

        // Session
        'SESSION_DRIVER' => config('session.driver'),
        'SESSION_LIFETIME' => config('session.lifetime'),
        'SESSION_ENCRYPT' => config('session.encrypt'),
        'SESSION_PATH' => config('session.path'),
        'SESSION_DOMAIN' => config('session.domain'),

        // Broadcast
        'BROADCAST_CONNECTION' => config('broadcasting.default'),

        // Filesystem & Queue & Cache
        'FILESYSTEM_DISK' => config('filesystems.default'),
        'QUEUE_CONNECTION' => config('queue.default'),
        'CACHE_STORE' => config('cache.default'),

        // Memcached
        'MEMCACHED_HOST' => env('MEMCACHED_HOST'),

        // Redis (walang password)
        'REDIS_CLIENT' => config('database.redis.client'),
        'REDIS_HOST' => config('database.redis.default.host'),
        'REDIS_PORT' => config('database.redis.default.port'),
        // 'REDIS_PASSWORD' ay HINDI isinama (sensitive)

        // Mail
        'MAIL_MAILER' => config('mail.default'),
        'MAIL_SCHEME' => env('MAIL_SCHEME'),
        'MAIL_HOST' => config('mail.mailers.smtp.host'),
        'MAIL_PORT' => config('mail.mailers.smtp.port'),
        'MAIL_USERNAME' => config('mail.mailers.smtp.username'),
        // 'MAIL_PASSWORD' ay HINDI isinama (sensitive)
        'MAIL_FROM_ADDRESS' => config('mail.from.address'),
        'MAIL_FROM_NAME' => config('mail.from.name'),

        // Sanctum
        'SANCTUM_STATEFUL_DOMAINS' => config('sanctum.stateful_domains'),

        // Additional info
        'php_version' => PHP_VERSION,
    ];

    return response()->json([
        'status' => 'success',
        'data' => $config
    ]);
});