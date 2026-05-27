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
    public $tries = 1;
    public $failOnTimeout = true;

    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function handle(): void
    {
        Log::info("Job started for: " . $this->data['gcashRef']);

        $payload = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $scriptPath = base_path('automate.cjs');

        // Write payload to temp file
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reg_' . uniqid() . '.json';
        file_put_contents($tmpFile, $payload);
        Log::info("Temp file: " . $tmpFile);

        $nodePath = 'node'; // or full path like 'C:\\Program Files\\nodejs\\node.exe'
        $command = $nodePath . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($tmpFile);
        Log::info("Command: " . $command);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, base_path());

        if (!is_resource($process)) {
            Log::error("proc_open failed to start node");
            $this->markFailed();
            return;
        }

        fclose($pipes[0]); // close stdin

        // Read output with timeout
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

            if ($chunk1)
                $stdout .= $chunk1;
            if ($chunk2)
                $stderr .= $chunk2;

            $status = proc_get_status($process);
            if (!$status['running'])
                break;

            usleep(200000); // 200ms poll
        }

        // Drain remaining output
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (file_exists($tmpFile))
            @unlink($tmpFile);

        Log::info("Exit code: " . $exitCode);
        Log::info("Stdout: " . $stdout);
        if ($stderr)
            Log::info("Stderr: " . $stderr);

        // Parse last JSON line from stdout
        $lines = array_filter(array_map('trim', explode("\n", $stdout)));
        $lastLine = end($lines);
        $output = json_decode($lastLine, true);

        if ($exitCode === 0 && $output && ($output['success'] ?? false)) {
            DB::table('registrations')
                ->where('gcash_reference_number', $this->data['gcashRef'])
                ->update(['status' => 'success', 'redirect_url' => $output['finalUrl'] ?? '']);
            Log::info("Success for: " . $this->data['gcashRef']);
            return;
        }

        Log::error("Failed: " . ($output['error'] ?? $lastLine));
        $this->markFailed();
    }

    private function markFailed(): void
    {
        DB::table('registrations')
            ->where('gcash_reference_number', $this->data['gcashRef'])
            ->update(['status' => 'failed']);
    }
}
