<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessRegistration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;
    public $tries = 3;
    public $failOnTimeout = true;

    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function handle(): void
    {
        Log::info("Job started for GCash Ref: " . ($this->data['gcashRef'] ?? 'unknown'));

        // Since we removed the file, we only process the registration data
        // The actual automation (Node.js script) should handle the registration
        // without needing the receipt image again

        Log::info("Processing registration for: " . json_encode([
            'email' => $this->data['email'] ?? 'unknown',
            'gcashRef' => $this->data['gcashRef'] ?? 'unknown',
            'firstName' => $this->data['firstName'] ?? 'unknown',
            'lastName' => $this->data['lastName'] ?? 'unknown',
        ]));

        // Prepare job data without the file
        $jobData = $this->data;

        // If you still need to run Node.js script, pass only the necessary data
        $payload = json_encode($jobData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $scriptPath = base_path('automate.cjs');

        // Write payload to temp file
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reg_' . uniqid() . '.json';
        file_put_contents($tmpFile, $payload);
        Log::info("Temp file created: " . $tmpFile);

        $nodePath = 'node';
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
            $this->markFailed();
            return;
        }

        fclose($pipes[0]); // close stdin

        // Read output
        $stdout = '';
        $stderr = '';
        $startTime = time();
        $maxSeconds = 150;

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

            if ($chunk1 !== false)
                $stdout .= $chunk1;
            if ($chunk2 !== false)
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
        Log::info("Stdout: " . substr($stdout, 0, 1000));
        if ($stderr) {
            Log::warning("Stderr: " . substr($stderr, 0, 1000));
        }

        // Parse last JSON line from stdout
        $lines = array_filter(array_map('trim', explode("\n", $stdout)));
        $lastLine = end($lines);
        $output = json_decode($lastLine, true);

        if ($exitCode === 0 && $output && ($output['success'] ?? false)) {
            DB::table('registrations')
                ->where('gcash_reference_number', $this->data['gcashRef'])
                ->update([
                    'status' => 'success',
                    'redirect_url' => $output['finalUrl'] ??
                        'https://marketingautomation.netlify.app/dashboard?status=verified'
                ]);
            Log::info("Registration successful for: " . $this->data['gcashRef']);
            return;
        }

        Log::error("Registration failed: " . ($output['error'] ?? $lastLine ?? 'Unknown error'));
        $this->markFailed();
    }

    private function markFailed(): void
    {
        DB::table('registrations')
            ->where('gcash_reference_number', $this->data['gcashRef'])
            ->update(['status' => 'failed']);
    }
}
