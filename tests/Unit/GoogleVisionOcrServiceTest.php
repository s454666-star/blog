<?php

namespace Tests\Unit;

use App\Services\GoogleVisionOcrService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GoogleVisionOcrServiceTest extends TestCase
{
    public function test_it_resolves_project_relative_credential_paths_with_a_leading_slash(): void
    {
        config()->set('services.google_cloud_vision.credentials', '/tests/Fixtures/google-vision.json');

        $service = new GoogleVisionOcrService();

        $this->assertSame(
            realpath(base_path('tests/Fixtures/google-vision.json')),
            $service->resolveCredentialsPath()
        );
    }

    public function test_it_throws_a_clear_error_when_the_credentials_file_is_missing(): void
    {
        config()->set('services.google_cloud_vision.credentials', '/tests/Fixtures/missing-google-vision.json');

        $service = new GoogleVisionOcrService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Cloud Vision credentials file was not found.');

        $service->resolveCredentialsPath();
    }

    public function test_it_falls_back_to_the_next_configured_credentials_path(): void
    {
        config()->set('services.google_cloud_vision.credentials', [
            'C:\\missing\\google-vision.json',
            '/tests/Fixtures/google-vision.json',
        ]);

        $service = new GoogleVisionOcrService();

        $this->assertSame(
            realpath(base_path('tests/Fixtures/google-vision.json')),
            $service->resolveCredentialsPath()
        );
    }

    public function test_it_resolves_a_configured_ca_bundle_path(): void
    {
        config()->set('services.google_cloud_vision.ca_bundle', '/tests/Fixtures/cacert.pem');

        $service = new class extends GoogleVisionOcrService
        {
            public function exposeVerifyOption(): string|bool
            {
                return $this->resolveVerifyOption();
            }
        };

        $this->assertSame(
            realpath(base_path('tests/Fixtures/cacert.pem')),
            $service->exposeVerifyOption()
        );
    }

    public function test_it_can_disable_ssl_verification_explicitly(): void
    {
        config()->set('services.google_cloud_vision.verify_ssl', false);

        $service = new class extends GoogleVisionOcrService
        {
            public function exposeVerifyOption(): string|bool
            {
                return $this->resolveVerifyOption();
            }
        };

        $this->assertFalse($service->exposeVerifyOption());
    }

    public function test_it_can_extract_text_with_an_api_key(): void
    {
        config()->set('services.google_cloud_vision.api_key', 'test-api-key');

        Http::fake([
            'https://vision.googleapis.com/v1/images:annotate' => Http::response([
                'responses' => [[
                    'fullTextAnnotation' => [
                        'text' => "OCR smoke test 123\n",
                    ],
                ]],
            ], 200),
        ]);

        $service = new GoogleVisionOcrService();

        $this->assertSame(
            'OCR smoke test 123',
            $service->extractTextFromImage(base_path('tests/Fixtures/google-vision.json'))
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://vision.googleapis.com/v1/images:annotate'
                && $request->hasHeader('X-goog-api-key', 'test-api-key');
        });
    }

    public function test_it_throws_the_api_error_message_when_api_key_ocr_fails(): void
    {
        config()->set('services.google_cloud_vision.api_key', 'test-api-key');

        Http::fake([
            'https://vision.googleapis.com/v1/images:annotate' => Http::response([
                'error' => [
                    'message' => 'This API method requires billing to be enabled.',
                ],
            ], 403),
        ]);

        $service = new GoogleVisionOcrService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This API method requires billing to be enabled.');

        $service->extractTextFromImage(base_path('tests/Fixtures/google-vision.json'));
    }
}
