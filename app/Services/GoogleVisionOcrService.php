<?php

namespace App\Services;

use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleVisionOcrService
{
    public function extractTextFromImage(string $imagePath): string
    {
        $image = file_get_contents($imagePath);

        if ($image === false) {
            throw new RuntimeException('Unable to read the uploaded image.');
        }

        if ($this->hasApiKey()) {
            return $this->extractTextWithApiKey($image);
        }

        $imageAnnotator = $this->createClient();

        try {
            $response = $imageAnnotator->textDetection($image);
            $texts = $response->getTextAnnotations();

            return count($texts) > 0
                ? trim((string) $texts[0]->getDescription())
                : 'No text found';
        } finally {
            $imageAnnotator->close();
        }
    }

    public function resolveCredentialsPath(): string
    {
        $configuredPaths = config('services.google_cloud_vision.credentials');

        if (is_string($configuredPaths)) {
            $configuredPaths = [$configuredPaths];
        }

        if (!is_array($configuredPaths)) {
            $configuredPaths = [];
        }

        $configuredPaths = array_values(array_filter($configuredPaths, function ($path) {
            return is_string($path) && trim($path) !== '';
        }));

        if ($configuredPaths === []) {
            throw new RuntimeException(
                'Google Cloud Vision credentials are not configured. Set GOOGLE_CLOUD_VISION_CREDENTIALS or GOOGLE_APPLICATION_CREDENTIALS in .env.'
            );
        }

        $candidates = [];

        foreach ($configuredPaths as $configuredPath) {
            foreach ($this->credentialPathCandidates($configuredPath) as $candidate) {
                $candidates[] = $candidate;

                if (is_file($candidate)) {
                    return (string) realpath($candidate);
                }
            }
        }

        throw new RuntimeException(sprintf(
            'Google Cloud Vision credentials file was not found. Checked: %s',
            implode(', ', array_values(array_unique($candidates)))
        ));
    }

    protected function extractTextWithApiKey(string $image): string
    {
        $response = Http::withHeaders([
            'X-goog-api-key' => config('services.google_cloud_vision.api_key'),
        ])->withOptions([
            'verify' => $this->resolveVerifyOption(),
        ])->post('https://vision.googleapis.com/v1/images:annotate', [
            'requests' => [[
                'image' => [
                    'content' => base64_encode($image),
                ],
                'features' => [[
                    'type' => 'TEXT_DETECTION',
                ]],
            ]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                (string) ($response->json('error.message') ?? 'Google Cloud Vision API request failed.')
            );
        }

        $resultText = $response->json('responses.0.fullTextAnnotation.text')
            ?? $response->json('responses.0.textAnnotations.0.description');

        return is_string($resultText) && trim($resultText) !== ''
            ? trim($resultText)
            : 'No text found';
    }

    protected function createClient(): ImageAnnotatorClient
    {
        return new ImageAnnotatorClient([
            'credentials' => $this->resolveCredentialsPath(),
            'transportConfig' => [
                'rest' => [
                    'guzzle' => [
                        'verify' => $this->resolveVerifyOption(),
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    protected function credentialPathCandidates(string $configuredPath): array
    {
        $configuredPath = trim($configuredPath);
        $normalizedRelativePath = ltrim($configuredPath, '\\/');
        $candidates = [
            base_path($normalizedRelativePath),
        ];

        if ($this->isAbsolutePath($configuredPath)) {
            $candidates[] = $configuredPath;
        }

        return array_values(array_unique($candidates));
    }

    protected function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '/');
    }

    protected function hasApiKey(): bool
    {
        $apiKey = config('services.google_cloud_vision.api_key');

        return is_string($apiKey) && trim($apiKey) !== '';
    }

    protected function resolveVerifyOption(): string|bool
    {
        if (config('services.google_cloud_vision.verify_ssl', true) === false) {
            return false;
        }

        $configuredCaBundle = config('services.google_cloud_vision.ca_bundle');

        if (!is_string($configuredCaBundle) || trim($configuredCaBundle) === '') {
            return true;
        }

        $candidates = $this->credentialPathCandidates($configuredCaBundle);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return (string) realpath($candidate);
            }
        }

        throw new RuntimeException(sprintf(
            'Google Cloud Vision CA bundle file was not found. Checked: %s',
            implode(', ', $candidates)
        ));
    }
}
