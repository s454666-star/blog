<?php

namespace App\Http\Controllers;

use App\Services\GoogleVisionOcrService;
use Illuminate\Http\Request;
use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;
use RuntimeException;

class OCRController extends Controller
{
    public function recognizeText(Request $request, GoogleVisionOcrService $ocrService)
    {
        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'max:10240'],
        ]);

        try {
            $resultText = $ocrService->extractTextFromImage($validated['image']->path());
        } catch (ApiException|ValidationException|RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 500);
            }

            return back()
                ->withInput()
                ->withErrors(['image' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['text' => $resultText]);
        }

        return back()->with('text', $resultText);
    }
}
