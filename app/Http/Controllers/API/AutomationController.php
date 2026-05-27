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
    protected function logStep(string $step, array $context = [])
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        Log::info("[{$timestamp}] AUTOMATION_FLOW: =================== {$step} ===================", $context);
    }

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
        $this->logStep("START REGISTRATION AUTOMATION", ['email' => $request->email]);

        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'mobile' => 'required|string',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
            'gender' => 'required|string',
            'referredBy' => 'required|email',
            'gcashRef' => 'required|string|size:13',
            'gcashQrFile' => 'required|image|mimes:jpeg,png,jpg|max:4096'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid data', 'errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Check Duplicate
        if (DB::table('registrations')->where('gcash_reference_number', $validated['gcashRef'])->exists()) {
            return response()->json(['success' => false, 'errors' => ['gcashRef' => ['Reference already claimed.']]], 422);
        }

        // Save Pending
        DB::table('registrations')->insert([
            'email' => $validated['email'],
            'gcash_reference_number' => $validated['gcashRef'],
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ProcessRegistration::dispatch($validated);

        $this->logStep("END REGISTRATION AUTOMATION", ['ref' => $validated['gcashRef']]);

        return response()->json(['success' => true, 'message' => 'Registration queued.']);
    }

    public function handleGCashWebhook(Request $request)
    {
        Log::info('--- [GCASH WEBHOOK START] ---');

        $rawMessage = $request->input('message');
        if (!$rawMessage)
            return response()->json(['status' => 'empty_payload'], 400);

        $amount = 0;
        if (preg_match('/PHP\s?([0-9.,]+)/i', $rawMessage, $matches)) {
            $amount = (float) str_replace(',', '', $matches[1]);
        }

        $status = (stripos($rawMessage, 'You have received') !== false) ? 'success' : 'failed';

        if ($status !== 'success')
            return response()->json(['status' => 'ignored']);

        $registration = DB::table('registrations')
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$registration || ($amount != 1998.00 && $amount != 1.00)) {
            Log::warning("Transaction Disconnected or Invalid Amount: {$amount}");
            return response()->json(['status' => 'invalid_transaction'], 422);
        }

        DB::table('registrations')->where('id', $registration->id)->update([
            'status' => 'success',
            'redirect_url' => 'https://marketingautomation.netlify.app/dashboard?status=verified',
            'updated_at' => now()
        ]);

        return response()->json(['status' => 'processed']);
    }

    public function parseReceiptOCR(Request $request)
    {
        $this->logStep("START CLOUD OCR SCAN");

        $request->validate(['gcashQrFile' => 'required|image|mimes:jpeg,png,jpg|max:4096']);

        try {
            $imageFile = $request->file('gcashQrFile');

            $response = Http::attach(
                'file',
                file_get_contents($imageFile->getRealPath()),
                $imageFile->getClientOriginalName()
            )->post('https://api.ocr.space/parse/image', [
                        'apikey' => env('OCR_SPACE_API_KEY'),
                        'OCREngine' => '2',
                        'scale' => 'true'
                    ]);

            $result = $response->json();
            $extractedText = $result['ParsedResults'][0]['ParsedText'] ?? '';

            if (preg_match('/(?:\d[\s-]*){13}/', $extractedText, $matches)) {
                $detectedReference = preg_replace('/[^0-9]/', '', $matches[0]);

                if (strlen($detectedReference) === 13) {
                    $this->logStep("SUCCESS: Reference Detected", ['ref' => $detectedReference]);
                    return response()->json(['success' => true, 'reference_number' => $detectedReference]);
                }
            }

            Log::warning("FAILED: Could not extract 13-digit sequence.");
            return response()->json(['success' => false, 'message' => 'OCR detection failed.'], 422);

        } catch (\Exception $e) {
            Log::error("CRITICAL: Cloud OCR Failure", ['msg' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'OCR Service error'], 500);
        }
    }
}
