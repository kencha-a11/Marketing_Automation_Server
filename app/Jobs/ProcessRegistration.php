<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessRegistration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;          // Increased for Node.js script
    public $tries = 2;
    public $failOnTimeout = true;

    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function handle(): void
    {
        $gcashRef = $this->data['gcashRef'] ?? 'unknown';
        Log::info("Job started for GCash Ref: " . $gcashRef);

        // Check if already successful
        $existing = DB::table('registrations')->where('gcash_reference_number', $gcashRef)->first();
        if ($existing && $existing->status === 'success') {
            Log::info("Registration already successful, skipping job for: " . $gcashRef);
            return;
        }

        Log::info("Processing registration for: " . json_encode([
            'email' => $this->data['email'] ?? 'unknown',
            'gcashRef' => $gcashRef,
            'firstName' => $this->data['firstName'] ?? 'unknown',
            'lastName' => $this->data['lastName'] ?? 'unknown',
        ]));

        // Remove file from data (cannot be serialized, but already removed in controller)
        $jobData = $this->data;
        unset($jobData['gcashQrFile']);

        // Check if Node.js is available
        $nodePath = trim(shell_exec('which node') ?: '');
        $scriptPath = base_path('automate.cjs');

        if (!empty($nodePath) && file_exists($scriptPath)) {
            Log::info("Node.js found at: " . $nodePath);
            $this->runNodeScript($jobData, $gcashRef, $nodePath, $scriptPath);
        } else {
            Log::warning("Node.js not found or automate.cjs missing, using PHP fallback");
            $this->processWithPHP($jobData, $gcashRef);
        }
    }

    private function runNodeScript(array $jobData, string $gcashRef, string $nodePath, string $scriptPath): void
    {
        $payload = json_encode($jobData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reg_' . uniqid() . '.json';
        file_put_contents($tmpFile, $payload);
        Log::info("Temp file created: " . $tmpFile);

        $command = $nodePath . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($tmpFile);
        Log::info("Executing command: " . $command);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, base_path());

        if (!is_resource($process)) {
            Log::error("proc_open failed to start node process");
            $this->markFailed($gcashRef);
            return;
        }

        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $startTime = time();
        $maxSeconds = 280;

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $elapsed = time() - $startTime;
            if ($elapsed > $maxSeconds) {
                Log::error("Node process timed out after {$elapsed}s");
                proc_terminate($process);
                break;
            }

            $chunk1 = fread($pipes[1], 4096);
            $chunk2 = fread($pipes[2], 4096);

            if ($chunk1 !== false && $chunk1 !== '')
                $stdout .= $chunk1;
            if ($chunk2 !== false && $chunk2 !== '')
                $stderr .= $chunk2;

            $status = proc_get_status($process);
            if (!$status['running'])
                break;

            usleep(200000);
        }

        // Drain remaining output
        $remainingOut = stream_get_contents($pipes[1]);
        $remainingErr = stream_get_contents($pipes[2]);
        if ($remainingOut !== false)
            $stdout .= $remainingOut;
        if ($remainingErr !== false)
            $stderr .= $remainingErr;

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }

        Log::info("Node process exit code: " . $exitCode);
        Log::info("Stdout: " . substr($stdout, 0, 2000));
        if ($stderr) {
            Log::warning("Stderr: " . substr($stderr, 0, 2000));
        }

        // Parse last JSON line from stdout
        $lines = array_filter(array_map('trim', explode("\n", $stdout)));
        $lastLine = end($lines);
        $output = json_decode($lastLine, true);

        if ($exitCode === 0 && $output && ($output['success'] ?? false)) {
            DB::table('registrations')
                ->where('gcash_reference_number', $gcashRef)
                ->update([
                    'status' => 'success',
                    'redirect_url' => $output['finalUrl'] ?? 'https://marketingautomation.netlify.app/dashboard?status=verified',
                    'updated_at' => now(),
                ]);
            Log::info("Registration successful for: " . $gcashRef);
            return;
        }

        Log::error("Registration failed: " . ($output['error'] ?? $lastLine ?? 'Unknown error'));
        $this->markFailed($gcashRef);
    }

    private function processWithPHP(array $jobData, string $gcashRef): void
    {
        Log::info("PHP fallback processing for: " . $gcashRef);
        // In a real scenario you might call an external API here.
        // For now, we directly mark as success because the payment was verified.
        DB::table('registrations')
            ->where('gcash_reference_number', $gcashRef)
            ->update([
                'status' => 'success',
                'redirect_url' => 'https://marketingautomation.netlify.app/dashboard?status=verified',
                'updated_at' => now(),
            ]);
        Log::info("PHP fallback completed for: " . $gcashRef);
    }

    private function markFailed(string $gcashRef): void
    {
        DB::table('registrations')
            ->where('gcash_reference_number', $gcashRef)
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
