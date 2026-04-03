<?php

namespace App\Services;

use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

class PresetCommandRunnerService
{
    private const WORKDIR = 'C:\\www\\blog';
    private const PHP_BINARY = 'C:\\php\\php.exe';

    public function presets(): array
    {
        $presets = [];
        $order = [
            'scan_group_tokens' => 0,
            'dispatch_remaining_tokens' => 1,
            'move_video_duplicates' => 2,
            'move_folder_duplicates' => 3,
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

    public function stream(string $id, array $input = [], callable $onEvent = null): void
    {
        $preset = $this->getPreset($id, $input);

        $this->executePreset($preset, $onEvent);
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
        $preset['command_preview'] = $preset['command_preview'] ?? $this->buildCommandPreview($preset['steps'] ?? []);

        return $preset;
    }

    private function resolvePreset(string $id, array $preset, array $input): array
    {
        if (!in_array($id, ['move_video_duplicates', 'move_folder_duplicates'], true)) {
            return $preset;
        }

        $defaultPath = (string) ($preset['path_input']['default'] ?? '');
        $path = $this->normalizePathInput($input['path'] ?? $defaultPath, $defaultPath);

        $preset['path_input']['value'] = $path;
        $preset['command_preview'] = str_replace('__PATH__', $path, (string) ($preset['command_preview_template'] ?? ''));
        $preset['steps'] = [
            [
                'display' => 'php artisan ' . $preset['command_name'] . ' "' . $path . '"',
                'command' => [self::PHP_BINARY, 'artisan', $preset['command_name'], $path],
            ],
        ];

        return $preset;
    }

    private function normalizePathInput(mixed $value, string $fallback = ''): string
    {
        $path = trim((string) $value);
        $path = trim($path, " \t\n\r\0\x0B\"");

        if ($path === '') {
            $path = trim($fallback);
        }

        if ($path === '') {
            throw new InvalidArgumentException('Path is required.');
        }

        if (mb_strlen($path) > 1000) {
            throw new InvalidArgumentException('Path is too long.');
        }

        return $path;
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

    private function executePreset(array $preset, callable $onEvent = null): array
    {
        $startedAt = microtime(true);
        $timestamp = now();
        $output = '';
        $emit = function (string $event, array $payload = []) use ($onEvent): void {
            if ($onEvent !== null) {
                $onEvent($event, $payload);
            }
        };

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
        ]);
        $emit('chunk', ['text' => $header]);

        $success = true;
        $exitCode = 0;
        $totalSteps = count($preset['steps']);

        foreach ($preset['steps'] as $index => $step) {
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

                $process->run(function (string $type, string $buffer) use (&$output, $emit): void {
                    $chunk = $this->normalizeOutput($buffer);
                    if ($chunk === '') {
                        return;
                    }

                    $output .= $chunk;
                    $emit('chunk', [
                        'text' => $chunk,
                        'stream' => $type === Process::ERR ? 'stderr' : 'stdout',
                    ]);
                });

                $exitCode = (int) $process->getExitCode();
            } catch (Throwable $e) {
                $success = false;
                $exitCode = 1;
                $errorChunk = $this->normalizeOutput('Process exception: ' . $e->getMessage() . "\n");
                $output .= $errorChunk;
                $emit('chunk', [
                    'text' => $errorChunk,
                    'stream' => 'stderr',
                ]);
            }

            $stepDurationMs = (int) round((microtime(true) - $stepStartedAt) * 1000);
            $stepFooter = $this->normalizeOutput(sprintf(
                "\n[step %d finished] exit=%d duration=%sms\n\n",
                $index + 1,
                $exitCode,
                $stepDurationMs
            ));

            $output .= $stepFooter;
            $emit('chunk', ['text' => $stepFooter]);

            if ($exitCode !== 0) {
                $success = false;
                break;
            }
        }

        if (!$success && $exitCode === 0) {
            $exitCode = 1;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $finishedAt = now()->format('Y-m-d H:i:s');
        $footer = $this->normalizeOutput(sprintf(
            "[finished] success=%s exit=%d total_duration=%sms\n",
            $success ? 'yes' : 'no',
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
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'finished_at' => $finishedAt,
            'output' => $output,
        ];

        $emit('complete', [
            'preset' => $result['preset'],
            'success' => $result['success'],
            'exit_code' => $result['exit_code'],
            'duration_ms' => $result['duration_ms'],
            'finished_at' => $result['finished_at'],
        ]);

        return $result;
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
                'accent_from' => '#2f8bff',
                'accent_to' => '#35d4c6',
                'accent_soft' => 'rgba(47, 139, 255, 0.16)',
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
                'accent_from' => '#ff8a3d',
                'accent_to' => '#ffcb57',
                'accent_soft' => 'rgba(255, 138, 61, 0.16)',
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
                'accent_from' => '#26b872',
                'accent_to' => '#8bdc88',
                'accent_soft' => 'rgba(38, 184, 114, 0.16)',
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
                'accent_from' => '#f05d82',
                'accent_to' => '#f5a35c',
                'accent_soft' => 'rgba(240, 93, 130, 0.16)',
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
