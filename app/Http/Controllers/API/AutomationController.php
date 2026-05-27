<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Jobs\ProcessRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class AutomationController extends Controller
{
    public function getStatus($gcashRef)
    {
        $reg = DB::table('registrations')->where('gcash_reference_number', $gcashRef)->first();
        return response()->json([
            'status' => $reg ? $reg->status : 'not_found',
            'redirectUrl' => $reg->redirect_url ?? null
        ]);
    }

    public function automateRegistration(Request $request)
    {
        // 1. Validation kasama ang Image Binary handling rules
        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'mobile' => 'required|string',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
            'gender' => 'required|string',
            'referredBy' => 'required|email',
            'gcashRef' => 'required|string|size:13',
            'gcashQrFile' => 'required|image|mimes:jpeg,png,jpg|max:4096' // Limit up to 4MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // 2. OCR VERIFICATION ENGINE STEP
        try {
            $imageFile = $request->file('gcashQrFile');
            $realPath = $imageFile->getRealPath();

            Log::info("Starting local Windows Tesseract OCR Engine scan on file: {$realPath}");

            $ocr = new TesseractOCR($realPath);

            // Explicit configuration target for Windows environmental local folder setups
            if (str_contains(PHP_OS, 'WIN')) {
                $ocr->executable('C:\Program Files\Tesseract-OCR\tesseract.exe');
            }

            // Patakbuhin ang Text Processing loops
            $extractedText = $ocr->run();
            Log::info("Raw Text Extracted from Screenshot via OCR Engine:", ['text' => $extractedText]);

            // Linisin ang spaces at characters para mas madaling hanapin ang 13-digit sequence
            $cleanText = str_replace([' ', '-', ',', '.', ':'], '', $extractedText);

            $foundReference = null;
            if (preg_match_all('/\b\d{13}\b/', $cleanText, $matches)) {
                $foundReference = $matches[0][0]; // Kunin ang unang 13 digit number
                Log::info("OCR Reference Code Detected successfully inside image bounds:", ['extracted_ref' => $foundReference]);
            }

            // Kung may nahanap na 13-digit code pero HINDI ito tugma sa tinype ng user: Harangan ang request.
            if ($foundReference && $foundReference !== $validated['gcashRef']) {
                Log::warning("SECURITY ALERT - TRANSACTION MISMATCH: User input text field '{$validated['gcashRef']}' does not map to text extracted on image structure '{$foundReference}'");

                return response()->json([
                    'success' => false,
                    'errors' => [
                        'gcashQrFile' => ['Ang GCash Reference Number sa loob ng iyong screenshot image ay hindi tumutugma sa tinype mong Reference Code. Pakisiguradong orihinal na receipt ang iyong in-upload.']
                    ]
                ], 422);
            }

        } catch (\Exception $e) {
            // I-log ang error kung sakaling magkaproblema sa Tesseract binary pero ipagpatuloy pa rin ang processing loop para hindi mag-crash ang app
            Log::error("OCR Processing Exception encountered:", ['message' => $e->getMessage()]);
        }

        // 3. Duplicate Prevention Verification Layer
        $exists = DB::table('registrations')->where('gcash_reference_number', $validated['gcashRef'])->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'errors' => ['gcashRef' => ['This transaction reference code has already been claimed by another account layer.']]
            ], 422);
        }

        // 4. I-save muna bilang 'pending' status entry block
        DB::table('registrations')->insert([
            'email' => $validated['email'],
            'gcash_reference_number' => $validated['gcashRef'],
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ProcessRegistration::dispatch($validated);

        return response()->json([
            'success' => true,
            'message' => 'Registration data processed securely and waiting for activation pairing.'
        ], 200);
    }

    public function handleGCashWebhook(Request $request)
    {
        Log::info('--- [GCASH WEBHOOK ATTEMPT START] ---');

        $rawMessage = $request->input('message');
        Log::info('Raw Automate Message Intercepted:', ['message' => $rawMessage]);

        $amount = null;
        $status = 'failed';

        if ($rawMessage) {
            if (preg_match('/PHP\s?([0-9.,]+)/i', $rawMessage, $amountMatches)) {
                $amount = (float) str_replace(',', '', $amountMatches[1]);
                Log::info('Regex Match Found - Amount extracted:', ['amount' => $amount]);
            } else {
                Log::warning('Regex Match Failed - Could not parse Amount from message.');
            }

            if (stripos($rawMessage, 'You have received') !== false) {
                $status = 'success';
            }
            Log::info('Calculated Status based on keywords:', ['status' => $status]);
        } else {
            Log::error('Webhook triggered but the raw payload "message" key is missing.');
            return response()->json(['status' => 'empty_payload'], 400);
        }

        $request->merge([
            'amount' => $amount,
            'status' => $status
        ]);

        try {
            $validated = $request->validate([
                'amount' => 'required|numeric',
                'status' => 'required|string'
            ]);

            if ($validated['status'] !== 'success') {
                return response()->json(['status' => 'ignored', 'message' => 'Not a successful incoming payment string.']);
            }

            // 5. SECURE SAFE TIME-BOUNDED FIFO QUEUE COUPLING
            // Hinahanap ang pinakamatandang 'pending' registration form na ginawa sa loob lamang ng huling 15 minuto
            $registration = DB::table('registrations')
                ->where('status', 'pending')
                ->where('created_at', '>=', now()->subMinutes(15))
                ->orderBy('created_at', 'asc')
                ->first();

            if (!$registration) {
                Log::warning("Transaction Disconnected: Received payment of ₱{$validated['amount']}, but no matching user registrations were created within the last 15 minutes.");
                return response()->json([
                    'status' => 'disconnected_transaction',
                    'message' => 'Payment intercepted, but no users are currently waiting in the pending queue within the valid time limit.'
                ], 200);
            }

            // 6. BUSINESS LOGIC ENVIRONMENT VALUE SECURITY
            if ($validated['amount'] != 1998.00 && $validated['amount'] != 1.00) {
                Log::critical("SECURITY ALERT: Payment amount discrepancy detected. Expected 1998.00 or 1.00, received: {$validated['amount']}");

                DB::table('registrations')
                    ->where('id', $registration->id)
                    ->update([
                        'status' => 'failed',
                        'updated_at' => now()
                    ]);

                return response()->json(['status' => 'invalid_amount', 'error' => 'Incorrect payment amount.'], 422);
            }

            // 7. SUCCESS PIPELINE ACTION - I-activate na ang account at baguhin ang redirect properties
            DB::table('registrations')
                ->where('id', $registration->id)
                ->update([
                    'status' => 'success',
                    'redirect_url' => 'https://marketingautomation.netlify.app/dashboard?status=verified',
                    'updated_at' => now()
                ]);

            Log::info("--- [GCASH WEBHOOK PROCESSED SUCCESSFULLY - ACCOUNT ACTIVATED VIA SAFE FIFO] ---", [
                'paired_user_email' => $registration->email,
                'user_typed_ref' => $registration->gcash_reference_number,
                'verified_amount' => $validated['amount']
            ]);

            return response()->json(['status' => 'processed', 'amount' => $validated['amount']]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('GCash Webhook Validation Rejected!', ['errors' => $e->errors()]);
            return response()->json(['status' => 'validation_failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('System error encountered:', ['message' => $e->getMessage()]);
            return response()->json(['status' => 'server_error'], 500);
        }
    }

    public function parseReceiptOCR(Request $request)
    {
        Log::info("=================== START CLOUD OCR SCAN REQUEST ===================");

        $request->validate([
            'gcashQrFile' => 'required|image|mimes:jpeg,png,jpg|max:4096'
        ]);

        try {
            $imageFile = $request->file('gcashQrFile');
            $originalName = $imageFile->getClientOriginalName();

            Log::info("Target File Received for Cloud OCR processing.", [
                'filename' => $originalName,
                'size' => $imageFile->getSize() . ' bytes'
            ]);

            // Kuhanin ang API key mula sa ating .env config environment setup
            $apiKey = env('OCR_SPACE_API_KEY', 'helloworld');

            // I-execute ang HTTP POST Multipart Request direkta sa OCR.space servers
            $response = Http::attach(
                'file',
                file_get_contents($imageFile->getRealPath()),
                $originalName
            )->post('https://api.ocr.space/parse/image', [
                        'apikey' => $apiKey,
                        'language' => 'eng',
                        'isOverlayRequired' => 'false',
                        'scale' => 'true', // Pinapalinaw ang text extraction processing architecture sa maliliit na imahe
                        'OCREngine' => '2'  // Engine 2 ay mas mabilis at mas tumpak para sa mga resibo at digital receipts
                    ]);

            if ($response->failed()) {
                throw new \Exception("OCR.space API gateway communication fault status: " . $response->status());
            }

            $result = $response->json();

            // Pagkuha sa raw text na nakuha ng Cloud OCR Engine
            $extractedText = $result['ParsedResults'][0]['ParsedText'] ?? '';

            Log::info("Raw Text Content Extracted from Cloud OCR:", [
                'content_preview' => substr($extractedText, 0, 400)
            ]);

            // BAGONG REGEX: Naghahanap pa rin ng 13-digit sequence kahit may spaces o dashes sa pagitan
            if (preg_match('/(?:\d[\s-]*){13}/', $extractedText, $matches)) {

                // Linisin ang nahanap na numero (alisin ang mga spaces o dashes)
                $detectedReference = preg_replace('/[^0-9]/', '', $matches[0]);

                // Siguraduhing eksaktong 13 digits ang nakuha natin
                if (strlen($detectedReference) === 13) {
                    Log::info("SUCCESS: Valid 13-digit GCash reference code found.", [
                        'reference_number' => $detectedReference
                    ]);
                    Log::info("==================== END CLOUD OCR SCAN REQUEST ====================");

                    return response()->json([
                        'success' => true,
                        'reference_number' => $detectedReference
                    ], 200);
                }
            }

            Log::warning("FAILED: Cloud OCR completed but could not extract valid 13-digit sequence data.");
            Log::info("==================== END CLOUD OCR SCAN REQUEST ====================");

            return response()->json([
                'success' => false,
                'message' => 'Failed to auto-detect reference number. Please verify the image clarity or input manually.'
            ], 422);

        } catch (\Exception $e) {
            Log::error("CRITICAL EXCEPTION: Cloud OCR Processing Failure.", [
                'error_message' => $e->getMessage()
            ]);
            Log::info("==================== END CLOUD OCR SCAN REQUEST ====================");

            return response()->json([
                'success' => false,
                'message' => 'OCR Service error: ' . $e->getMessage()
            ], 500);
        }
    }
}
