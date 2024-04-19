<?php

namespace App\Http\Controllers;

use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Illuminate\Http\Request;
use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;

class OCRController extends Controller
{
    public function recognizeText(Request $request)
    {
        // 確認有檔案被上傳
        if (!$request->hasFile('image')) {
            return response()->json(['error' => 'No image uploaded'], 400);
        }

        $imagePath = $request->file('image')->path();

        // 指定 GOOGLE_APPLICATION_CREDENTIALS 環境變量
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . env('GOOGLE_APPLICATION_CREDENTIALS'));

        // 初始化 Google Cloud Vision API 客戶端，禁用 SSL 驗證
        $config = [
            'transportConfig' => [
                'rest' => [
                    'guzzle' => [
                        'verify' => false  // Disables SSL certificate verification
                    ]
                ]
            ]
        ];
        $imageAnnotator = new ImageAnnotatorClient($config);

        try {
            $image = file_get_contents($imagePath);
            $response = $imageAnnotator->textDetection($image);
            $texts = $response->getTextAnnotations();

            $resultText = count($texts) > 0 ? $texts[0]->getDescription() : 'No text found';
        } catch (ApiException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            $imageAnnotator->close();
        }

        return $resultText;
    }
}
