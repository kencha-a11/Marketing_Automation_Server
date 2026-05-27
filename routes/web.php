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
