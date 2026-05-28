<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Jobs\ProcessRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Throwable;

class ElevateAutomationController extends Controller
{
    protected function logStep(string $step, array $context = [])
    {
        $timestamp = now()->format('Y-m-d H:i:s.u');
        Log::channel('stack')->info("[{$timestamp}] AUTOMATION_FLOW: =================== {$step} ===================", $context);
    }

    public function getStatus($gcashRef)
    {
        $this->logStep("GET_STATUS_START", ['gcash_ref' => $gcashRef]);

        $reg = DB::table('registrations')->where('gcash_reference_number', $gcashRef)->first();

        $this->logStep("GET_STATUS_RESULT", [
            'gcash_ref' => $gcashRef,
            'found' => !is_null($reg),
            'status' => $reg->status ?? 'not_found',
            'redirect_url_exists' => isset($reg->redirect_url)
        ]);

        return response()->json([
            'status' => $reg ? $reg->status : 'not_found',
            'redirectUrl' => $reg->redirect_url ?? null
        ]);
    }

    public function automateRegistration(Request $request)
    {
        $startTime = microtime(true);
        $this->logStep("START REGISTRATION AUTOMATION", [
            'email' => $request->email,
            'gcashRef' => $request->gcashRef,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        // Log raw input (mask password)
        $maskedPayload = $request->all();
        if (isset($maskedPayload['password'])) {
            $maskedPayload['password'] = '******';
        }
        // Remove file from log to avoid clutter
        if (isset($maskedPayload['gcashQrFile'])) {
            $maskedPayload['gcashQrFile'] = '[FILE_UPLOADED]';
        }
        $this->logStep("REQUEST_PAYLOAD", $maskedPayload);

        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'mobile' => 'required|string',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
            'gender' => 'required|string',
            'referredBy' => 'required|email',
            'gcashRef' => 'required|string|min:12|max:13|regex:/^\d+$/',
            'gcashQrFile' => 'required|image|mimes:jpeg,png,jpg|max:4096'
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            $this->logStep("VALIDATION_FAILED", ['errors' => $errors]);
            return response()->json(['message' => 'Invalid data', 'errors' => $errors], 422);
        }

        $validated = $validator->validated();
        $this->logStep("VALIDATION_PASSED", ['email' => $validated['email'], 'gcashRef' => $validated['gcashRef']]);

        // Check Duplicate
        $exists = DB::table('registrations')->where('gcash_reference_number', $validated['gcashRef'])->exists();
        if ($exists) {
            $this->logStep("DUPLICATE_REFERENCE", ['gcashRef' => $validated['gcashRef']]);
            return response()->json(['success' => false, 'errors' => ['gcashRef' => ['Reference already claimed.']]], 422);
        }

        // Save Pending
        $insertData = [
            'email' => $validated['email'],
            'gcash_reference_number' => $validated['gcashRef'],
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $inserted = DB::table('registrations')->insert($insertData);
        $this->logStep("DB_PENDING_SAVE", ['inserted' => $inserted, 'data' => $insertData]);

        if (!$inserted) {
            $this->logStep("DB_INSERT_FAILED", $insertData);
            return response()->json(['success' => false, 'message' => 'Database error'], 500);
        }

        // ========== FIX: Remove file from job data ==========
        // The file cannot be serialized, so we remove it before dispatching
        $jobData = $validated;
        unset($jobData['gcashQrFile']); // Remove the file object

        // Optional: Store file somewhere if needed by the job
        // For now, the job doesn't need the file since it only processes the reference number

        $this->logStep("JOB_DATA_PREPARED", [
            'has_file' => isset($validated['gcashQrFile']),
            'job_data_keys' => array_keys($jobData)
        ]);

        // Dispatch job without the file
        try {
            ProcessRegistration::dispatch($jobData);
            $this->logStep("JOB_DISPATCHED", [
                'job_class' => ProcessRegistration::class,
                'gcashRef' => $validated['gcashRef'],
                'queue' => config('queue.default')
            ]);
        } catch (Throwable $e) {
            $this->logStep("JOB_DISPATCH_FAILED", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['success' => false, 'message' => 'Job dispatch failed: ' . $e->getMessage()], 500);
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->logStep("END REGISTRATION AUTOMATION", [
            'ref' => $validated['gcashRef'],
            'duration_ms' => $duration
        ]);

        return response()->json(['success' => true, 'message' => 'Registration queued.']);
    }

    public function handleGCashWebhook(Request $request)
    {
        $webhookStart = microtime(true);
        $rawPayload = $request->getContent();

        Log::channel('webhook')->info('--- [GCASH WEBHOOK START] ---', [
            'ip' => $request->ip(),
            'timestamp' => now()->toIso8601String()
        ]);

        $this->logStep("WEBHOOK_RECEIVED", [
            'headers' => $request->headers->all(),
            'input_keys' => array_keys($request->all())
        ]);

        $rawMessage = $request->input('message');
        if (!$rawMessage) {
            Log::channel('webhook')->warning('WEBHOOK_EMPTY_PAYLOAD', ['payload' => $rawPayload]);
            return response()->json(['status' => 'empty_payload'], 400);
        }

        Log::channel('webhook')->info('WEBHOOK_MESSAGE', ['raw_message' => $rawMessage]);

        // Extract amount
        $amount = 0;
        if (preg_match('/PHP\s?([0-9.,]+)/i', $rawMessage, $matches)) {
            $amount = (float) str_replace(',', '', $matches[1]);
            Log::channel('webhook')->debug('Amount extracted', ['amount' => $amount, 'matched' => $matches[0]]);
        } else {
            Log::channel('webhook')->warning('Amount not found in message', ['message_sample' => substr($rawMessage, 0, 200)]);
        }

        $status = (stripos($rawMessage, 'You have received') !== false) ? 'success' : 'failed';
        Log::channel('webhook')->info('Transaction status', ['status' => $status, 'amount' => $amount]);

        if ($status !== 'success') {
            Log::channel('webhook')->info('Ignored non-success webhook', ['status' => $status]);
            return response()->json(['status' => 'ignored']);
        }

        // Find pending registration (last 15 minutes)
        $cutoff = now()->subMinutes(15);
        $this->logStep("WEBHOOK_LOOKUP_PENDING", ['cutoff' => $cutoff->toDateTimeString()]);

        $registration = DB::table('registrations')
            ->where('status', 'pending')
            ->where('created_at', '>=', $cutoff)
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$registration) {
            Log::channel('webhook')->warning('No pending registration found', [
                'cutoff_minutes' => 15,
                'amount_received' => $amount
            ]);
            return response()->json(['status' => 'invalid_transaction'], 422);
        }

        $this->logStep("WEBHOOK_FOUND_REGISTRATION", [
            'id' => $registration->id,
            'email' => $registration->email,
            'gcashRef' => $registration->gcash_reference_number,
            'status' => $registration->status,
            'created_at' => $registration->created_at
        ]);

        // Validate amount (1998.00 or 1.00)
        $allowedAmounts = [1998, 1998.00, 1, 1.00];
        if (!in_array((float) $amount, $allowedAmounts)) {
            Log::channel('webhook')->warning("Invalid amount for registration", [
                'registration_id' => $registration->id,
                'amount_received' => $amount,
                'expected_amounts' => $allowedAmounts
            ]);
            return response()->json(['status' => 'invalid_transaction'], 422);
        }

        // Update registration
        $updateData = [
            'status' => 'success',
            'redirect_url' => 'https://marketingautomation.netlify.app/dashboard?status=verified',
            'updated_at' => now()
        ];

        $updated = DB::table('registrations')->where('id', $registration->id)->update($updateData);

        $this->logStep("WEBHOOK_DB_UPDATE", [
            'registration_id' => $registration->id,
            'updated' => $updated,
            'new_status' => 'success',
            'redirect_url' => $updateData['redirect_url']
        ]);

        $duration = round((microtime(true) - $webhookStart) * 1000, 2);
        Log::channel('webhook')->info('--- [GCASH WEBHOOK END] ---', [
            'registration_id' => $registration->id,
            'duration_ms' => $duration,
            'amount' => $amount
        ]);

        return response()->json(['status' => 'processed']);
    }

    public function parseReceiptOCR(Request $request)
    {
        $ocrStart = microtime(true);
        $this->logStep("START CLOUD OCR SCAN", [
            'has_file' => $request->hasFile('gcashQrFile'),
            'client_ip' => $request->ip()
        ]);

        $validator = Validator::make($request->all(), [
            'gcashQrFile' => 'required|image|mimes:jpeg,png,jpg|max:4096'
        ]);

        if ($validator->fails()) {
            $this->logStep("OCR_VALIDATION_FAILED", ['errors' => $validator->errors()->toArray()]);
            return response()->json(['success' => false, 'message' => 'Invalid file'], 422);
        }

        try {
            $imageFile = $request->file('gcashQrFile');
            $fileInfo = [
                'original_name' => $imageFile->getClientOriginalName(),
                'size_bytes' => $imageFile->getSize(),
                'mime_type' => $imageFile->getMimeType(),
                'extension' => $imageFile->getClientOriginalExtension()
            ];
            $this->logStep("OCR_FILE_DETAILS", $fileInfo);

            // Prepare API call
            $apiKey = env('OCR_SPACE_API_KEY');
            if (empty($apiKey)) {
                Log::error('OCR_SPACE_API_KEY is missing in .env');
                return response()->json(['success' => false, 'message' => 'OCR service misconfigured'], 500);
            }

            $apiUrl = 'https://api.ocr.space/parse/image';
            $this->logStep("OCR_API_CALL_START", ['url' => $apiUrl, 'engine' => '2']);

            $response = Http::timeout(30)->attach(
                'file',
                file_get_contents($imageFile->getRealPath()),
                $imageFile->getClientOriginalName()
            )->post($apiUrl, [
                        'apikey' => $apiKey,
                        'OCREngine' => '2',
                        'scale' => 'true'
                    ]);

            $httpStatus = $response->status();
            $responseTime = round((microtime(true) - $ocrStart) * 1000, 2);

            $this->logStep("OCR_API_RESPONSE", [
                'http_status' => $httpStatus,
                'response_time_ms' => $responseTime,
                'content_type' => $response->header('Content-Type')
            ]);

            if ($httpStatus !== 200) {
                Log::error('OCR_API_HTTP_ERROR', ['status' => $httpStatus, 'body' => substr($response->body(), 0, 500)]);
                return response()->json(['success' => false, 'message' => 'OCR service unavailable'], 502);
            }

            $result = $response->json();
            $parsedResults = $result['ParsedResults'][0] ?? null;
            $extractedText = $parsedResults['ParsedText'] ?? '';
            $ocrExitCode = $parsedResults['FileParseExitCode'] ?? 'unknown';

            $this->logStep("OCR_TEXT_EXTRACTED", [
                'text_length' => strlen($extractedText),
                'exit_code' => $ocrExitCode,
                'text_sample' => substr($extractedText, 0, 300) . (strlen($extractedText) > 300 ? '...' : '')
            ]);

            // Search for 12-13 digit reference number
            $detectedReference = null;
            if (preg_match('/(?:\d[\s-]*){12,13}/', $extractedText, $matches)) {
                $detectedReference = preg_replace('/[^0-9]/', '', $matches[0]);
                $this->logStep("OCR_REFERENCE_CANDIDATE", ['raw_match' => $matches[0], 'cleaned' => $detectedReference]);
            }

            if ($detectedReference && in_array(strlen($detectedReference), [12, 13])) {
                $this->logStep("OCR_SUCCESS", [
                    'reference_number' => $detectedReference,
                    'total_duration_ms' => $responseTime
                ]);
                return response()->json(['success' => true, 'reference_number' => $detectedReference]);
            }

            Log::warning("OCR_FAILED_NO_VALID_REFERENCE", [
                'detected_length' => $detectedReference ? strlen($detectedReference) : 'none',
                'extracted_text_preview' => substr($extractedText, 0, 500),
                'full_response_parsed' => isset($result['ErrorMessage']) ? $result['ErrorMessage'] : 'no error message'
            ]);

            return response()->json(['success' => false, 'message' => 'OCR detection failed: No valid 12-13 digit reference found.'], 422);

        } catch (Throwable $e) {
            Log::error("OCR_CRITICAL_FAILURE", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['success' => false, 'message' => 'OCR Service error: ' . $e->getMessage()], 500);
        }
    }
}
