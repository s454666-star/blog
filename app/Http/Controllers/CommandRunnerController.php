<?php

namespace App\Http\Controllers;

use App\Services\PresetCommandRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommandRunnerController extends Controller
{
    public function __construct(
        private readonly PresetCommandRunnerService $runner,
    ) {
    }

    public function index(): View
    {
        return view('command-runner.index', [
            'presets' => $this->runner->presets(),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        @set_time_limit(0);

        $validated = $this->validateRunRequest($request);

        try {
            $input = $this->extractRunnerInput($validated);
            $result = $this->runner->run($validated['preset'], $input);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    public function stream(Request $request): StreamedResponse|JsonResponse
    {
        @set_time_limit(0);

        $validated = $this->validateRunRequest($request);
        $input = $this->extractRunnerInput($validated);

        try {
            $this->runner->getPreset($validated['preset'], $input);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->stream(function () use ($validated, $input): void {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');

            $sendEvent = function (string $event, array $payload = []): void {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

                @ob_flush();
                flush();
            };

            try {
                $this->runner->stream($validated['preset'], $input, $sendEvent, $validated['run_token'] ?? null);
            } catch (\Throwable $e) {
                $sendEvent('error', [
                    'message' => $e->getMessage(),
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function stop(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'run_token' => ['required', 'string', 'max:100'],
        ]);

        return response()->json(
            $this->runner->requestStop($validated['run_token'])
        );
    }

    private function validateRunRequest(Request $request): array
    {
        $validated = $request->validate([
            'preset' => ['required', 'string'],
            'run_token' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        foreach ($request->except(['preset', 'run_token']) as $key => $value) {
            if (!is_string($value) && $value !== null) {
                abort(response()->json([
                    'message' => sprintf('欄位 %s 只能傳字串。', $key),
                ], 422));
            }

            if (mb_strlen((string) $value) > 1000) {
                abort(response()->json([
                    'message' => sprintf('欄位 %s 長度不可超過 1000。', $key),
                ], 422));
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    private function extractRunnerInput(array $validated): array
    {
        return array_diff_key($validated, array_flip(['preset', 'run_token']));
    }
}
