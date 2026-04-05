<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

class PresetCommandRunnerService
{
    private const WORKDIR = 'C:\\www\\blog';
    private const PHP_BINARY = 'C:\\php\\php.exe';
    private const RUN_STATE_DIR = 'app\\command-runner-runs';
    private const STOP_EXIT_CODE = 130;

    public function presets(): array
    {
        $presets = [];
        $order = [
            'scan_group_tokens' => 0,
            'dispatch_remaining_tokens' => 1,
            'move_video_duplicates' => 2,
            'move_folder_duplicates' => 3,
            'scan_video_duplicates' => 4,
            'reencode_video_medium_high' => 5,
            'sync_rerun_video_sources' => 6,
        ];

        foreach (self::catalog() as $id => $preset) {
            if (($preset['hidden'] ?? false) === true) {
                continue;
            }

            $presets[] = $this->decoratePreset($id, $preset);
        }

        usort($presets, static function (array $left, array $right) use ($order): int {
            $leftOrder = $order[$left['id']] ?? PHP_INT_MAX;
            $rightOrder = $order[$right['id']] ?? PHP_INT_MAX;

            return $leftOrder <=> $rightOrder;
        });

        return $presets;
    }

    public function run(string $id, array $input = []): array
    {
        $preset = $this->getPreset($id, $input);
        return $this->executePreset($preset);
    }

    public function stream(string $id, array $input = [], callable $onEvent = null, ?string $runToken = null): void
    {
        $preset = $this->getPreset($id, $input);

        $this->executePreset($preset, $onEvent, $runToken);
    }

    public function requestStop(string $runToken): array
    {
        $state = $this->readRunState($runToken);

        if ($state === null) {
            return [
                'accepted' => false,
                'message' => '目前沒有可停止的執行。',
            ];
        }

        $this->mergeRunState($runToken, [
            'status' => 'stop-requested',
            'stop_requested' => true,
            'stop_requested_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $pid = (int) ($state['pid'] ?? 0);
        $signalSent = $pid > 0 ? $this->terminateProcessByPid($pid) : false;

        return [
            'accepted' => true,
            'message' => $signalSent
                ? '已送出停止要求，正在中止目前指令。'
                : '已記錄停止要求，會在目前程序可中斷時停止。',
        ];
    }

    public function getPreset(string $id, array $input = []): array
    {
        $catalog = self::catalog();

        if (!isset($catalog[$id])) {
            throw new InvalidArgumentException('Unknown preset command.');
        }

        return $this->decoratePreset($id, $this->resolvePreset($id, $catalog[$id], $input));
    }

    private function decoratePreset(string $id, array $preset): array
    {
        $preset['id'] = $id;
        $preset['workdir'] = self::WORKDIR;
        $preset['php_binary'] = self::PHP_BINARY;
        $preset['inputs'] = $this->preparePresetInputs(
            $preset['inputs'] ?? (isset($preset['path_input']) && is_array($preset['path_input']) ? [$preset['path_input']] : [])
        );
        $preset['command_preview'] = $preset['command_preview']
            ?? (
                !empty($preset['command_preview_template']) && $preset['inputs'] !== []
                    ? $this->fillCommandPreviewTemplate((string) $preset['command_preview_template'], $preset['inputs'])
                    : $this->buildCommandPreview($preset['steps'] ?? [])
            );

        return $preset;
    }

    private function resolvePreset(string $id, array $preset, array $input): array
    {
        if (!isset($preset['command_name'])) {
            return $preset;
        }

        $resolvedInputs = [];
        foreach ($this->preparePresetInputs(
            $preset['inputs'] ?? (isset($preset['path_input']) && is_array($preset['path_input']) ? [$preset['path_input']] : [])
        ) as $field) {
            $name = (string) ($field['name'] ?? '');
            $default = (string) ($field['default'] ?? '');
            $required = (bool) ($field['required'] ?? true);

            $field['value'] = $this->normalizeTextInput($input[$name] ?? $default, $default, $required);
            $resolvedInputs[] = $field;
        }

        if ($resolvedInputs === []) {
            return $preset;
        }

        $arguments = $this->buildPresetArguments($resolvedInputs, $preset['argument_order'] ?? []);
        $preset['inputs'] = $resolvedInputs;
        $preset['command_preview'] = $this->fillCommandPreviewTemplate(
            (string) ($preset['command_preview_template'] ?? ''),
            $resolvedInputs
        );
        $preset['steps'] = [
            [
                'display' => $this->buildArtisanDisplay((string) $preset['command_name'], $arguments),
                'command' => array_merge([self::PHP_BINARY, 'artisan', (string) $preset['command_name']], $arguments),
            ],
        ];

        return $preset;
    }

    private function preparePresetInputs(array $inputs): array
    {
        $prepared = [];

        foreach ($inputs as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $field['name'] = $name;
            $field['preview_token'] = (string) ($field['preview_token'] ?? ('__' . strtoupper($name) . '__'));
            $prepared[] = $field;
        }

        return $prepared;
    }

    private function normalizeTextInput(mixed $value, string $fallback = '', bool $required = true): string
    {
        $path = trim((string) $value);
        $path = trim($path, " \t\n\r\0\x0B\"");

        if ($path === '') {
            $path = trim($fallback);
        }

        if ($required && $path === '') {
            throw new InvalidArgumentException('Path is required.');
        }

        if (mb_strlen($path) > 1000) {
            throw new InvalidArgumentException('Path is too long.');
        }

        return $path;
    }

    private function buildPresetArguments(array $inputs, array $argumentOrder = []): array
    {
        $byName = [];
        foreach ($inputs as $field) {
            $byName[(string) $field['name']] = $field;
        }

        $orderedNames = $argumentOrder !== []
            ? $argumentOrder
            : array_map(static fn (array $field): string => (string) $field['name'], $inputs);

        $arguments = [];
        foreach ($orderedNames as $name) {
            if (!isset($byName[$name])) {
                continue;
            }

            $field = $byName[$name];
            $value = (string) ($field['value'] ?? '');
            $includeWhenEmpty = (bool) ($field['include_when_empty'] ?? false);

            if ($value === '' && !$includeWhenEmpty) {
                continue;
            }

            $arguments[] = $value;
        }

        return $arguments;
    }

    private function fillCommandPreviewTemplate(string $template, array $inputs): string
    {
        $replacements = [];
        foreach ($inputs as $field) {
            $replacements[(string) ($field['preview_token'] ?? '')] = (string) ($field['value'] ?? '');
        }

        return strtr($template, $replacements);
    }

    private function buildArtisanDisplay(string $commandName, array $arguments = []): string
    {
        return $this->stringifyCommand(array_merge(['php', 'artisan', $commandName], $arguments));
    }

    private function buildCommandPreview(array $steps): string
    {
        $lines = ['cd ' . self::WORKDIR];

        foreach ($steps as $step) {
            $lines[] = (string) ($step['display'] ?? $this->stringifyCommand($step['command']));
        }

        return implode("\n", $lines);
    }

    private function stringifyCommand(array $command): string
    {
        return implode(' ', array_map(static function (string $part): string {
            return str_contains($part, ' ') ? '"' . $part . '"' : $part;
        }, $command));
    }

    private function normalizeOutput(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return $text;
    }

    private function executePreset(array $preset, callable $onEvent = null, ?string $runToken = null): array
    {
        $startedAt = microtime(true);
        $timestamp = now();
        $output = '';
        $cancelled = false;
        $emit = function (string $event, array $payload = []) use ($onEvent): void {
            if ($onEvent !== null) {
                $onEvent($event, $payload);
            }
        };

        if ($runToken !== null) {
            $this->writeRunState($runToken, [
                'run_token' => $runToken,
                'preset_id' => $preset['id'],
                'preset_title' => $preset['title'],
                'status' => 'starting',
                'stop_requested' => false,
                'started_at' => $timestamp->format('Y-m-d H:i:s'),
                'pid' => null,
            ]);
        }

        $header = $this->normalizeOutput(sprintf(
            "[%s] Preset: %s\nWorking directory: %s\n\n",
            $timestamp->format('Y-m-d H:i:s'),
            $preset['title'],
            self::WORKDIR
        ));

        $output .= $header;
        $emit('start', [
            'preset' => [
                'id' => $preset['id'],
                'title' => $preset['title'],
                'summary' => $preset['summary'],
            ],
            'run_token' => $runToken,
        ]);
        $emit('chunk', ['text' => $header]);

        $success = true;
        $exitCode = 0;
        $totalSteps = count($preset['steps']);
        try {
            foreach ($preset['steps'] as $index => $step) {
                if ($runToken !== null && $this->isStopRequested($runToken)) {
                    $cancelled = true;
                    $stopChunk = $this->normalizeOutput("[stop requested] 已收到停止要求，跳過尚未開始的步驟。\n");
                    $output .= $stopChunk;
                    $emit('chunk', [
                        'text' => $stopChunk,
                        'stream' => 'stderr',
                    ]);
                    break;
                }

                $stepStartedAt = microtime(true);
                $commandLine = (string) ($step['display'] ?? $this->stringifyCommand($step['command']));
                $stepHeader = $this->normalizeOutput(sprintf(
                    ">>> Step %d/%d\n%s\n\n",
                    $index + 1,
                    $totalSteps,
                    $commandLine
                ));

                $output .= $stepHeader;
                $emit('chunk', ['text' => $stepHeader]);

                try {
                    $process = new Process($step['command'], self::WORKDIR, null, null, null);
                    $process->setTimeout(null);
                    $process->setIdleTimeout(null);
                    $process->start();

                    if ($runToken !== null) {
                        $this->mergeRunState($runToken, [
                            'status' => 'running',
                            'pid' => $process->getPid(),
                            'step_index' => $index + 1,
                            'step_total' => $totalSteps,
                            'current_command' => $commandLine,
                        ]);
                    }

                    while ($process->isRunning()) {
                        $this->drainProcessOutput($process, $output, $emit);

                        if (!$cancelled && $runToken !== null && $this->isStopRequested($runToken)) {
                            $cancelled = true;
                            $stopChunk = $this->normalizeOutput("\n[stop requested] 正在停止目前指令...\n");
                            $output .= $stopChunk;
                            $emit('chunk', [
                                'text' => $stopChunk,
                                'stream' => 'stderr',
                            ]);

                            $this->mergeRunState($runToken, [
                                'status' => 'stopping',
                            ]);

                            $this->terminateProcess($process);
                        }

                        usleep(100000);
                    }

                    $this->drainProcessOutput($process, $output, $emit);

                    $exitCode = (int) ($process->getExitCode() ?? ($cancelled ? self::STOP_EXIT_CODE : 1));

                    if ($cancelled) {
                        $exitCode = self::STOP_EXIT_CODE;
                    }
                } catch (Throwable $e) {
                    $success = false;
                    $exitCode = $cancelled ? self::STOP_EXIT_CODE : 1;

                    if (!$cancelled) {
                        $errorChunk = $this->normalizeOutput('Process exception: ' . $e->getMessage() . "\n");
                        $output .= $errorChunk;
                        $emit('chunk', [
                            'text' => $errorChunk,
                            'stream' => 'stderr',
                        ]);
                    }
                }

                if ($runToken !== null) {
                    $this->mergeRunState($runToken, [
                        'pid' => null,
                        'last_exit_code' => $exitCode,
                    ]);
                }

                $stepDurationMs = (int) round((microtime(true) - $stepStartedAt) * 1000);
                $stepFooter = $this->normalizeOutput(sprintf(
                    "\n[step %d finished] exit=%d%s duration=%sms\n\n",
                    $index + 1,
                    $exitCode,
                    $cancelled ? ' cancelled=yes' : '',
                    $stepDurationMs
                ));

                $output .= $stepFooter;
                $emit('chunk', ['text' => $stepFooter]);

                if ($exitCode !== 0) {
                    $success = false;
                    break;
                }
            }

            if ($cancelled) {
                $success = false;
            }

            if (!$success && $exitCode === 0) {
                $exitCode = $cancelled ? self::STOP_EXIT_CODE : 1;
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $finishedAt = now()->format('Y-m-d H:i:s');
            $footer = $this->normalizeOutput(sprintf(
                "[finished] success=%s%s exit=%d total_duration=%sms\n",
                $success ? 'yes' : 'no',
                $cancelled ? ' cancelled=yes' : '',
                $exitCode,
                $durationMs
            ));

            $output .= $footer;
            $emit('chunk', ['text' => $footer]);

            $result = [
                'preset' => [
                    'id' => $preset['id'],
                    'title' => $preset['title'],
                    'summary' => $preset['summary'],
                ],
                'success' => $success,
                'cancelled' => $cancelled,
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
                'finished_at' => $finishedAt,
                'output' => $output,
            ];

            if ($runToken !== null) {
                $this->mergeRunState($runToken, [
                    'status' => $cancelled ? 'cancelled' : ($success ? 'completed' : 'failed'),
                    'pid' => null,
                    'finished_at' => $finishedAt,
                    'exit_code' => $exitCode,
                    'cancelled' => $cancelled,
                ]);
            }

            $emit('complete', [
                'preset' => $result['preset'],
                'success' => $result['success'],
                'cancelled' => $result['cancelled'],
                'exit_code' => $result['exit_code'],
                'duration_ms' => $result['duration_ms'],
                'finished_at' => $result['finished_at'],
                'run_token' => $runToken,
            ]);

            return $result;
        } finally {
            if ($runToken !== null) {
                $this->deleteRunState($runToken);
            }
        }
    }

    private function drainProcessOutput(Process $process, string &$output, callable $emit): void
    {
        $stdout = $this->normalizeOutput($process->getIncrementalOutput());
        if ($stdout !== '') {
            $output .= $stdout;
            $emit('chunk', [
                'text' => $stdout,
                'stream' => 'stdout',
            ]);
        }

        $stderr = $this->normalizeOutput($process->getIncrementalErrorOutput());
        if ($stderr !== '') {
            $output .= $stderr;
            $emit('chunk', [
                'text' => $stderr,
                'stream' => 'stderr',
            ]);
        }
    }

    private function runStatePath(string $runToken): string
    {
        return storage_path(self::RUN_STATE_DIR . DIRECTORY_SEPARATOR . hash('sha256', $runToken) . '.json');
    }

    private function writeRunState(string $runToken, array $state): void
    {
        $path = $this->runStatePath($runToken);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function readRunState(string $runToken): ?array
    {
        $path = $this->runStatePath($runToken);

        if (!File::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function mergeRunState(string $runToken, array $state): void
    {
        $current = $this->readRunState($runToken) ?? [];

        $this->writeRunState($runToken, array_merge($current, $state, [
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]));
    }

    private function deleteRunState(string $runToken): void
    {
        $path = $this->runStatePath($runToken);

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    private function isStopRequested(string $runToken): bool
    {
        return (bool) (($this->readRunState($runToken) ?? [])['stop_requested'] ?? false);
    }

    private function terminateProcess(Process $process): void
    {
        $pid = (int) ($process->getPid() ?? 0);

        if ($pid > 0 && $this->terminateProcessByPid($pid)) {
            return;
        }

        try {
            $process->stop(1);
        } catch (Throwable) {
        }
    }

    private function terminateProcessByPid(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        try {
            if (DIRECTORY_SEPARATOR === '\\') {
                $graceful = new Process(['taskkill', '/PID', (string) $pid, '/T']);
                $graceful->setTimeout(5);
                $graceful->run();

                if ($graceful->isSuccessful()) {
                    return true;
                }

                $forced = new Process(['taskkill', '/PID', (string) $pid, '/T', '/F']);
                $forced->setTimeout(5);
                $forced->run();

                return $forced->isSuccessful();
            }

            if (function_exists('posix_kill')) {
                return posix_kill($pid, defined('SIGINT') ? SIGINT : 15);
            }

            $fallback = new Process(['kill', '-INT', (string) $pid]);
            $fallback->setTimeout(5);
            $fallback->run();

            return $fallback->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private static function catalog(): array
    {
        return [
            'scan_group_tokens' => [
                'eyebrow' => 'Telegram Scan',
                'title' => '掃群組 token 並送去指定 port',
                'summary' => '先掃描預設 Telegram 群組中的 token，再依你選的 port 把待處理項目派送出去。',
                'details' => '適合剛補完群組內容或想立刻把新 token 推進 bot 流程時使用。這組流程會先更新 dialogues / token_scan_items，再依你選擇的 8000 或 8001 把處理完成項目送出。',
                'highlights' => [
                    '先跑 tg:scan-group-tokens，把群組訊息中的 token 去重後寫入資料表。',
                    '第二步可以選 8000 或 8001，把 token 派給對應的 Telegram FastAPI。',
                    '適合先清最新掃描結果，再直接推到你要的 port。',
                ],
                'tags' => ['dialogues', 'token_scan_items', 'port 8000', 'port 8001'],
                'accent_from' => '#ec5f9b',
                'accent_to' => '#ff9fba',
                'accent_soft' => 'rgba(236, 95, 155, 0.18)',
                'steps' => [
                    [
                        'display' => 'C:\\php\\php.exe artisan tg:scan-group-tokens',
                        'command' => [self::PHP_BINARY, 'artisan', 'tg:scan-group-tokens'],
                    ],
                    [
                        'display' => 'C:\\php\\php.exe artisan tg:dispatch-token-scan-items --done-action=delete --port=8000',
                        'command' => [self::PHP_BINARY, 'artisan', 'tg:dispatch-token-scan-items', '--done-action=delete', '--port=8000'],
                    ],
                ],
                'command_preview' => implode("\n", [
                    'cd ' . self::WORKDIR,
                    'C:\\php\\php.exe artisan tg:scan-group-tokens',
                    '',
                    '[8000 PORT]',
                    'C:\\php\\php.exe artisan tg:dispatch-token-scan-items --done-action=delete --port=8000',
                    '',
                    '[8001 PORT]',
                    'C:\\php\\php.exe artisan tg:dispatch-token-scan-items --done-action=delete --port=8001',
                ]),
                'button_variants' => [
                    [
                        'preset' => 'scan_group_tokens_port_8000',
                        'label' => '8000 PORT跑',
                        'title' => '掃群組 token：跑 8000',
                    ],
                    [
                        'preset' => 'scan_group_tokens_port_8001',
                        'label' => '8001 PORT跑',
                        'title' => '掃群組 token：跑 8001',
                    ],
                ],
            ],
            'scan_group_tokens_port_8000' => [
                'hidden' => true,
                'eyebrow' => 'Telegram Scan',
                'title' => '掃群組 token：跑 8000',
                'summary' => '先掃描 token，再把待處理項目派送到 8000。',
                'steps' => [
                    [
                        'display' => 'C:\\php\\php.exe artisan tg:scan-group-tokens',
                        'command' => [self::PHP_BINARY, 'artisan', 'tg:scan-group-tokens'],
                    ],
                    [
                        'display' => 'C:\\php\\php.exe artisan tg:dispatch-token-scan-items --done-action=delete --port=8000',
                        'command' => [self::PHP_BINARY, 'artisan', 'tg:dispatch-token-scan-items', '--done-action=delete', '--port=8000'],
                    ],
                ],
            ],
            'scan_group_tokens_port_8001' => [
                'hidden' => true,
                'eyebrow' => 'Telegram Scan',
                'title' => '掃群組 token：跑 8001',
                'summary' => '先掃描 token，再把待處理項目派送到 8001。',
                'steps' => [
                    [
                        'display' => 'C:\\php\\php.exe artisan tg:scan-group-tokens',
                        'command' => [self::PHP_BINARY, 'artisan', 'tg:scan-group-tokens'],
                    ],
                    [
                        'display' => 'C:\\php\\php.exe artisan tg:dispatch-token-scan-items --done-action=delete --port=8001',
                        'command' => [self::PHP_BINARY, 'artisan', 'tg:dispatch-token-scan-items', '--done-action=delete', '--port=8001'],
                    ],
                ],
            ],
            'move_video_duplicates' => [
                'eyebrow' => 'Video DB Compare',
                'title' => '掃描並搬移和 video_features 重複的影片',
                'summary' => '把你輸入的資料夾中的影片拿去和既有 video_features 資料庫比對，命中的就搬去疑似重複檔案。',
                'details' => '這組可以直接輸入要掃描的資料夾路徑，利用既有影片特徵做重複偵測。命中後除了搬檔，還會留下外部重複比對記錄，方便後續在頁面檢視。',
                'highlights' => [
                    '目標是 DB 內已抽過特徵的影片，不是只比同資料夾內彼此。',
                    '命中後會搬到原資料夾下的「疑似重複檔案」目錄。',
                    '輸入資料夾後按 Enter 就可以直接開始跑。',
                ],
                'tags' => ['video_features', '自訂資料夾', '搬移重複'],
                'accent_from' => '#ff8fb1',
                'accent_to' => '#ffc29d',
                'accent_soft' => 'rgba(255, 143, 177, 0.18)',
                'command_name' => 'video:move-duplicates',
                'path_input' => [
                    'name' => 'path',
                    'label' => '資料夾位置',
                    'placeholder' => 'C:\\Users\\User\\Downloads\\Video',
                    'default' => 'C:\\Users\\User\\Downloads\\Video',
                ],
                'command_preview_template' => implode("\n", [
                    'cd ' . self::WORKDIR,
                    'php artisan video:move-duplicates "__PATH__"',
                ]),
                'steps' => [],
            ],
            'move_folder_duplicates' => [
                'eyebrow' => 'Folder Internal Compare',
                'title' => '掃描指定資料夾內彼此重複的影片',
                'summary' => '只比你輸入的指定資料夾裡面的影片彼此是否重複，先掃到的保留，後掃到的重複檔搬走。',
                'details' => '這組只看同一個資料夾批次內的影片，不會拿去跟主資料庫全部比。你可以直接輸入要掃的資料夾路徑，適合處理單一群組內重複下載、重傳、改名的影片。',
                'highlights' => [
                    '掃描路徑可以直接改成你現在要處理的資料夾。',
                    '只比同資料夾內彼此重複，不碰 DB 主庫去重。',
                    '重複檔同樣會搬到「疑似重複檔案」資料夾，輸入路徑後按 Enter 就能開始。',
                ],
                'tags' => ['folder batch', '自訂資料夾', '疑似重複檔案'],
                'accent_from' => '#d86cc2',
                'accent_to' => '#f2a5ce',
                'accent_soft' => 'rgba(216, 108, 194, 0.18)',
                'command_name' => 'video:move-folder-duplicates',
                'path_input' => [
                    'name' => 'path',
                    'label' => '資料夾位置',
                    'placeholder' => 'C:\\Users\\User\\Pictures\\train\\downloads\\group_2457763530_赞助群 补群_正在补内容\\videos',
                    'default' => 'C:\\Users\\User\\Pictures\\train\\downloads\\group_2457763530_赞助群 补群_正在补内容\\videos',
                ],
                'command_preview_template' => implode("\n", [
                    'cd ' . self::WORKDIR,
                    'php artisan video:move-folder-duplicates "__PATH__"',
                ]),
                'steps' => [],
            ],
            'scan_video_duplicates' => [
                'eyebrow' => 'Folder Scan Only',
                'title' => '掃描資料夾重複影片（只掃描不搬移）',
                'summary' => '直接掃你指定的資料夾，找出重複影片並輸出比對結果，不會搬動檔案。',
                'details' => '這組會直接跑 video:scan-duplicates，適合先盤點整包資料夾的疑似重複影片，再決定後續要不要搬移或人工檢查。',
                'highlights' => [
                    '預設直接帶入 Z:\\FC2-2026(new)，你也可以改成其他資料夾。',
                    '這組只做掃描與比對，不會幫你移動原始檔案。',
                    '一樣支援輸入資料夾後按 Enter 立即執行。',
                ],
                'tags' => ['scan only', '自訂資料夾', 'video:scan-duplicates'],
                'accent_from' => '#d892f4',
                'accent_to' => '#ffd0ff',
                'accent_soft' => 'rgba(216, 146, 244, 0.22)',
                'command_name' => 'video:scan-duplicates',
                'path_input' => [
                    'name' => 'path',
                    'label' => '資料夾位置',
                    'placeholder' => 'Z:\\FC2-2026(new)',
                    'default' => 'Z:\\FC2-2026(new)',
                ],
                'command_preview_template' => implode("\n", [
                    'cd ' . self::WORKDIR,
                    'php artisan video:scan-duplicates "__PATH__"',
                ]),
                'steps' => [],
            ],
            'reencode_video_medium_high' => [
                'eyebrow' => 'FFmpeg Reencode',
                'title' => '影片重新編碼（中高碼率）',
                'summary' => '用 ffmpeg 把單支影片或整個資料夾重新編碼成 H.264/AAC，中高碼率輸出成新的 mp4。',
                'details' => '資料夾預設帶入 C:\\Users\\User\\Videos\\暫。影片欄位可以留白，留白就會整個資料夾一起跑；如果只想處理單一檔案，直接填檔名或絕對路徑。',
                'highlights' => [
                    '影片欄位留空時，會掃資料夾內所有影片逐支重編碼。',
                    '輸出檔會寫成原檔名加上 _mhq.mp4，不會直接覆蓋原始檔。',
                    '碼率會依解析度自動抓中高檔位，適合整理暫存影片做後續使用。',
                ],
                'tags' => ['ffmpeg', 'H.264', 'AAC', '中高碼率'],
                'accent_from' => '#ff8a5b',
                'accent_to' => '#ffd166',
                'accent_soft' => 'rgba(255, 138, 91, 0.20)',
                'command_name' => 'video:reencode-medium-high',
                'argument_order' => ['path', 'video'],
                'inputs' => [
                    [
                        'name' => 'path',
                        'label' => '資料夾位置',
                        'placeholder' => 'C:\\Users\\User\\Videos\\暫',
                        'default' => 'C:\\Users\\User\\Videos\\暫',
                    ],
                    [
                        'name' => 'video',
                        'label' => '影片檔名（留空 = 整個資料夾）',
                        'placeholder' => '',
                        'default' => '',
                        'required' => false,
                    ],
                ],
                'command_preview_template' => implode("\n", [
                    'cd ' . self::WORKDIR,
                    'php artisan video:reencode-medium-high "__PATH__" "__VIDEO__"',
                    '',
                    '# 影片欄位留空時，會處理整個資料夾',
                ]),
                'steps' => [],
            ],
            'sync_rerun_video_sources' => [
                'eyebrow' => 'Rerun Sync',
                'title' => '比對 DB / 重跑 / Eagle 三邊差異',
                'summary' => '掃 video_master(type=1)、Z:\\video(重跑)、Eagle 重跑資源，同檔不同名會用檔案指紋歸成同一組。',
                'details' => '這組是增量掃描。已跑過且未變動的檔案會直接跳過，只重算新增或異動來源。跑完去差異頁可以選擇刪除多出或補齊缺少。',
                'highlights' => [
                    'A 來源只比對 video_type=1，並從 DB 實際路徑抓原始檔。',
                    'B 來源掃 Z:\\video(重跑)，C 來源透過 Eagle API + library 實體檔掃描。',
                    '跑完到 /videos/rerun-sync 只看差異，並可直接批次刪除或補齊。',
                ],
                'tags' => ['檔案指紋', '增量掃描', '差異頁'],
                'accent_from' => '#0f9d8e',
                'accent_to' => '#f2a541',
                'accent_soft' => 'rgba(15, 157, 142, 0.20)',
                'steps' => [
                    [
                        'display' => 'C:\\php\\php.exe artisan video:sync-rerun-sources',
                        'command' => [self::PHP_BINARY, 'artisan', 'video:sync-rerun-sources'],
                    ],
                ],
                'command_preview' => implode("\n", [
                    'cd ' . self::WORKDIR,
                    'C:\\php\\php.exe artisan video:sync-rerun-sources',
                    '',
                    '# 差異頁',
                    'https://blog.test/videos/rerun-sync',
                ]),
            ],
            'dispatch_remaining_tokens' => [
                'eyebrow' => 'Backlog Flush',
                'title' => '補跑剩餘 token：選 port 執行',
                'summary' => '把 token_scan_items 裡還沒處理完的項目送到指定 port，適合分開清 8000 或 8001 的 backlog。',
                'details' => '這組不重新掃群組，只處理已經在 token_scan_items 裡排隊的 token。你可以直接在同一張卡上選擇跑 8000 或 8001。',
                'highlights' => [
                    '可以直接選 8000 port 或 8001 port 其中一個來跑。',
                    '不需要拆成兩張卡，操作還是集中在同一區。',
                    '成功處理後一樣會依 --done-action=delete 刪除已完成列。',
                ],
                'tags' => ['backlog', 'port 8000', 'port 8001'],
                'accent_from' => '#ff6f98',
                'accent_to' => '#ffb07f',
                'accent_soft' => 'rgba(255, 111, 152, 0.18)',
                'steps' => [
                    [
                        'display' => 'C:\\php\\php.exe artisan tg:dispatch-token-scan-items --done-action=delete --port=8001',
                        'command' => [self::PHP_BINARY, 'artisan', 'tg:dispatch-token-scan-items', '--done-action=delete', '--port=8001'],
                    ],
                ],
                'command_preview' => implode("\n", [
                    'cd ' . self::WORKDIR,
                    '[8000 PORT]',
                    'C:\\php\\php.exe artisan tg:dispatch-token-scan-items --done-action=delete --port=8000',
                    '',
                    '[8001 PORT]',
                    'C:\\php\\php.exe artisan tg:dispatch-token-scan-items --done-action=delete --port=8001',
                ]),
                'button_variants' => [
                    [
                        'preset' => 'dispatch_remaining_tokens_port_8000',
                        'label' => '8000 PORT跑',
                        'title' => '補跑剩餘 token：跑 8000',
                    ],
                    [
                        'preset' => 'dispatch_remaining_tokens_port_8001',
                        'label' => '8001 PORT跑',
                        'title' => '補跑剩餘 token：跑 8001',
                    ],
                ],
            ],
            'dispatch_remaining_tokens_port_8000' => [
                'hidden' => true,
                'eyebrow' => 'Backlog Flush',
                'title' => '補跑剩餘 token：跑 8000',
                'summary' => '把 token_scan_items 裡還沒處理完的項目送到 8000。',
                'steps' => [
                    [
                        'display' => 'C:\\php\\php.exe artisan tg:dispatch-token-scan-items --done-action=delete --port=8000',
                        'command' => [self::PHP_BINARY, 'artisan', 'tg:dispatch-token-scan-items', '--done-action=delete', '--port=8000'],
                    ],
                ],
            ],
            'dispatch_remaining_tokens_port_8001' => [
                'hidden' => true,
                'eyebrow' => 'Backlog Flush',
                'title' => '補跑剩餘 token：跑 8001',
                'summary' => '把 token_scan_items 裡還沒處理完的項目送到 8001。',
                'steps' => [
                    [
                        'display' => 'C:\\php\\php.exe artisan tg:dispatch-token-scan-items --done-action=delete --port=8001',
                        'command' => [self::PHP_BINARY, 'artisan', 'tg:dispatch-token-scan-items', '--done-action=delete', '--port=8001'],
                    ],
                ],
            ],
        ];
    }
}
