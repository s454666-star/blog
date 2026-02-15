<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Http;
    use Throwable;

    class TelegramBotPaginationController extends Controller
    {
        private const BOT_USERNAME = 'ShowFiles3Bot';

        private const API_1_BASE_URL = 'http://127.0.0.1:8000';
        private const API_2_BASE_URL = 'http://127.0.0.1:8001';

        public function runAllPagesByBot(Request $request): JsonResponse
        {
            $apiRaw = $request->query('api', null);

            if ($apiRaw === null || $apiRaw === '') {
                return response()->json([
                    'status' => 'fail',
                    'reason' => 'missing parameter: api (1 or 2)',
                ], 422);
            }

            $api = (int)$apiRaw;
            if (!in_array($api, [1, 2], true)) {
                return response()->json([
                    'status' => 'fail',
                    'reason' => 'invalid api; must be 1 or 2',
                    'got' => $apiRaw,
                ], 422);
            }

            $apiUrl = $this->resolveApiUrlByApi($api);

            $payload = $this->buildPayloadFromRequest($request);

            try {
                $resp = Http::timeout((int)$payload['http_timeout_seconds'])
                    ->acceptJson()
                    ->asJson()
                    ->post($apiUrl, $payload['body']);

                $statusCode = (int)$resp->status();

                if (!$resp->ok()) {
                    return response()->json([
                        'status' => 'fail',
                        'reason' => 'upstream_api_failed',
                        'upstream' => [
                            'api' => $api,
                            'url' => $apiUrl,
                            'http_status' => $statusCode,
                            'body' => $this->safeDecodeJsonOrText($resp->body()),
                        ],
                    ], 502);
                }

                $json = $resp->json();

                return response()->json([
                    'status' => 'ok',
                    'api' => $api,
                    'url' => $apiUrl,
                    'bot_username' => self::BOT_USERNAME,
                    'result' => $json,
                ], 200);
            } catch (Throwable $e) {
                return response()->json([
                    'status' => 'fail',
                    'reason' => 'request_exception',
                    'upstream' => [
                        'api' => $api,
                        'url' => $apiUrl,
                        'bot_username' => self::BOT_USERNAME,
                    ],
                    'error' => $e->getMessage(),
                ], 502);
            }
        }

        private function resolveApiUrlByApi(int $api): string
        {
            $base = $api === 1 ? self::API_1_BASE_URL : self::API_2_BASE_URL;
            return rtrim($base, '/') . '/bots/run-all-pages-by-bot';
        }

        private function buildPayloadFromRequest(Request $request): array
        {
            $clearPreviousReplies = $this->toBool($request->query('clear_previous_replies', '1'));
            $delaySeconds = $this->toInt($request->query('delay_seconds', '0'), 0, 60);
            $maxSteps = $this->toInt($request->query('max_steps', '120'), 1, 3000);
            $waitFirst = $this->toInt($request->query('wait_first_callback_timeout_seconds', '25'), 5, 300);
            $waitEach = $this->toInt($request->query('wait_each_page_timeout_seconds', '25'), 5, 300);
            $debug = $this->toBool($request->query('debug', '1'));
            $debugMaxLogs = $this->toInt($request->query('debug_max_logs', '2000'), 0, 200000);

            $includeFiles = $this->toBool($request->query('include_files_in_response', '1'));
            $maxReturnFiles = $this->toInt($request->query('max_return_files', '500'), 1, 5000);
            $maxRawBytes = $this->toInt($request->query('max_raw_payload_bytes', '0'), 0, 5000000);

            $bootstrapClickGetAll = $this->toBool($request->query('bootstrap_click_get_all', '1'));
            $waitAfterBootstrap = $this->toInt($request->query('wait_after_bootstrap_timeout_seconds', '25'), 5, 300);

            $textNextFallbackEnabled = $this->toBool($request->query('text_next_fallback_enabled', '1'));
            $textNextCommand = (string)$request->query('text_next_command', '下一頁');

            $stopWhenNoNewFilesRounds = $this->toInt($request->query('stop_when_no_new_files_rounds', '4'), 1, 50);
            $stopWhenReachedTotalItems = $this->toBool($request->query('stop_when_reached_total_items', '1'));
            $maxInvalidCallbackRounds = $this->toInt($request->query('max_invalid_callback_rounds', '2'), 0, 50);

            $stopNeedConfirmPaginationDone = $this->toBool($request->query('stop_need_confirm_pagination_done', '1'));
            $stopNeedLastPageOrAllPages = $this->toBool($request->query('stop_need_last_page_or_all_pages', '1'));

            $cleanupAfterDone = $this->toBool($request->query('cleanup_after_done', '1'));
            $cleanupScope = (string)$request->query('cleanup_scope', 'run');
            $cleanupLimit = $this->toInt($request->query('cleanup_limit', '500'), 0, 5000);

            $callbackMessageMaxAgeSeconds = $this->toInt($request->query('callback_message_max_age_seconds', '25'), 1, 300);
            $callbackCandidateScanLimit = $this->toInt($request->query('callback_candidate_scan_limit', '30'), 1, 300);

            $initialWaitForControlsSeconds = $this->toInt($request->query('initial_wait_for_controls_seconds', '6'), 0, 120);

            $observeWhenNoControlsSeconds = $this->toInt($request->query('observe_when_no_controls_seconds', '10'), 0, 300);
            $observeWhenNoControlsPollSeconds = $this->toFloat($request->query('observe_when_no_controls_poll_seconds', '0.5'), 0.1, 10.0);
            $observeSendGetAllWhenNoControls = $this->toBool($request->query('observe_send_get_all_when_no_controls', '1'));
            $observeGetAllCommand = (string)$request->query('observe_get_all_command', '獲取全部');
            $observeSendNextWhenNoControls = $this->toBool($request->query('observe_send_next_when_no_controls', '0'));

            $httpTimeoutSeconds = $this->toInt($request->query('http_timeout_seconds', '240'), 10, 3600);

            $body = [
                'bot_username' => self::BOT_USERNAME,
                'clear_previous_replies' => $clearPreviousReplies,

                'delay_seconds' => $delaySeconds,
                'max_steps' => $maxSteps,

                'wait_first_callback_timeout_seconds' => $waitFirst,
                'wait_each_page_timeout_seconds' => $waitEach,

                'debug' => $debug,
                'debug_max_logs' => $debugMaxLogs,

                'include_files_in_response' => $includeFiles,
                'max_return_files' => $maxReturnFiles,
                'max_raw_payload_bytes' => $maxRawBytes,

                'bootstrap_click_get_all' => $bootstrapClickGetAll,
                'wait_after_bootstrap_timeout_seconds' => $waitAfterBootstrap,

                'text_next_fallback_enabled' => $textNextFallbackEnabled,
                'text_next_command' => $textNextCommand,

                'stop_when_no_new_files_rounds' => $stopWhenNoNewFilesRounds,
                'stop_when_reached_total_items' => $stopWhenReachedTotalItems,
                'max_invalid_callback_rounds' => $maxInvalidCallbackRounds,

                'stop_need_confirm_pagination_done' => $stopNeedConfirmPaginationDone,
                'stop_need_last_page_or_all_pages' => $stopNeedLastPageOrAllPages,

                'cleanup_after_done' => $cleanupAfterDone,
                'cleanup_scope' => $cleanupScope,
                'cleanup_limit' => $cleanupLimit,

                'callback_message_max_age_seconds' => $callbackMessageMaxAgeSeconds,
                'callback_candidate_scan_limit' => $callbackCandidateScanLimit,

                'initial_wait_for_controls_seconds' => $initialWaitForControlsSeconds,

                'observe_when_no_controls_seconds' => $observeWhenNoControlsSeconds,
                'observe_when_no_controls_poll_seconds' => $observeWhenNoControlsPollSeconds,
                'observe_send_get_all_when_no_controls' => $observeSendGetAllWhenNoControls,
                'observe_get_all_command' => $observeGetAllCommand,
                'observe_send_next_when_no_controls' => $observeSendNextWhenNoControls,
            ];

            return [
                'http_timeout_seconds' => $httpTimeoutSeconds,
                'body' => $body,
            ];
        }

        private function toBool($v): bool
        {
            if (is_bool($v)) {
                return $v;
            }
            $s = strtolower(trim((string)$v));
            if ($s === '1' || $s === 'true' || $s === 'yes' || $s === 'y' || $s === 'on') {
                return true;
            }
            return false;
        }

        private function toInt($v, int $min, int $max): int
        {
            $n = (int)$v;
            if ($n < $min) {
                return $min;
            }
            if ($n > $max) {
                return $max;
            }
            return $n;
        }

        private function toFloat($v, float $min, float $max): float
        {
            $n = (float)$v;
            if ($n < $min) {
                return $min;
            }
            if ($n > $max) {
                return $max;
            }
            return $n;
        }

        private function safeDecodeJsonOrText(string $raw)
        {
            $rawTrim = trim($raw);
            if ($rawTrim === '') {
                return '';
            }

            $decoded = json_decode($rawTrim, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            if (mb_strlen($rawTrim) > 4000) {
                return mb_substr($rawTrim, 0, 4000);
            }

            return $rawTrim;
        }
    }
