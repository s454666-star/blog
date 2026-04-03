<?php

namespace Tests\Feature;

use App\Services\GoogleVisionOcrService;
use Illuminate\Http\UploadedFile;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OCRControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_renders_the_ocr_workspace_page(): void
    {
        $response = $this->get('/ocr');

        $response->assertOk();
        $response->assertSee('Vision OCR Workspace');
        $response->assertSee('Upload and Recognize Text');
        $response->assertSee('Recognized Text');
    }

    public function test_it_redirects_back_with_recognized_text_for_browser_requests(): void
    {
        $mock = Mockery::mock(GoogleVisionOcrService::class);
        $mock->shouldReceive('extractTextFromImage')
            ->once()
            ->andReturn("Hello OCR\nSecond line");

        $this->app->instance(GoogleVisionOcrService::class, $mock);

        $response = $this->from('/ocr')->post('/ocr', [
            'image' => UploadedFile::fake()->image('ocr-test.png'),
        ]);

        $response->assertRedirect('/ocr');
        $response->assertSessionHas('text', "Hello OCR\nSecond line");
    }

    public function test_it_redirects_back_with_errors_when_ocr_service_fails(): void
    {
        $mock = Mockery::mock(GoogleVisionOcrService::class);
        $mock->shouldReceive('extractTextFromImage')
            ->once()
            ->andThrow(new RuntimeException('Google Cloud Vision credentials are not configured.'));

        $this->app->instance(GoogleVisionOcrService::class, $mock);

        $response = $this->from('/ocr')->post('/ocr', [
            'image' => UploadedFile::fake()->image('ocr-test.png'),
        ]);

        $response->assertRedirect('/ocr');
        $response->assertSessionHasErrors([
            'image' => 'Google Cloud Vision credentials are not configured.',
        ]);
    }

    public function test_it_returns_json_for_json_requests(): void
    {
        $mock = Mockery::mock(GoogleVisionOcrService::class);
        $mock->shouldReceive('extractTextFromImage')
            ->once()
            ->andReturn('Hello OCR');

        $this->app->instance(GoogleVisionOcrService::class, $mock);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->post('/ocr', [
            'image' => UploadedFile::fake()->image('ocr-test.png'),
        ]);

        $response->assertOk();
        $response->assertExactJson([
            'text' => 'Hello OCR',
        ]);
    }
}
