<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class MediaDurationProbeService
{
    public function probeDurationSeconds(
        string $absolutePath,
        ?string $ffprobeBin = null,
        ?string $ffmpegBin = null,
        int $timeoutSeconds = 60,
        int $ffprobeAttempts = 3
    ): float {
        $ffprobeBin = $this->resolveFfprobeBinary($ffprobeBin);
        $ffmpegBin = $this->resolveFfmpegBinary($ffmpegBin, $ffprobeBin);
        $timeoutSeconds = max(5, $timeoutSeconds);
        $ffprobeAttempts = max(1, $ffprobeAttempts);

        $probeFailures = [];

        for ($attempt = 1; $attempt <= $ffprobeAttempts; $attempt++) {
            $result = $this->runProcess(
                $this->buildFfprobeCommand($ffprobeBin, $absolutePath),
                $timeoutSeconds
            );

            $durationSeconds = $this->parseNumericDurationSeconds((string) ($result['output'] ?? ''));
            if (($result['successful'] ?? false) && $durationSeconds !== null && $durationSeconds > 0) {
                return $durationSeconds;
            }

            $probeFailures[] = $this->describeProbeFailure($result, 'ffprobe');
        }

        $fallbackResult = $this->runProcess(
            $this->buildFfmpegFallbackCommand($ffmpegBin, $absolutePath),
            min(30, $timeoutSeconds)
        );
        $fallbackOutput = trim(
            (string) ($fallbackResult['error_output'] ?? '') . "\n" . (string) ($fallbackResult['output'] ?? '')
        );
        $fallbackDuration = $this->parseFfmpegDurationSeconds($fallbackOutput);

        if ($fallbackDuration !== null && $fallbackDuration > 0) {
            return $fallbackDuration;
        }

        $message = 'ffprobe 失敗：' . (string) end($probeFailures);
        $fallbackFailure = $this->describeProbeFailure($fallbackResult, 'ffmpeg fallback');

        if ($fallbackFailure !== '') {
            $message .= '；ffmpeg fallback 失敗：' . $fallbackFailure;
        }

        throw new RuntimeException($message);
    }

    protected function runProcess(array $command, int $timeoutSeconds): array
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        return [
            'successful' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error_output' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
        ];
    }

    private function buildFfprobeCommand(string $ffprobeBin, string $absolutePath): array
    {
        return [
            $ffprobeBin,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=nokey=1:noprint_wrappers=1',
            $absolutePath,
        ];
    }

    private function buildFfmpegFallbackCommand(string $ffmpegBin, string $absolutePath): array
    {
        return [
            $ffmpegBin,
            '-hide_banner',
            '-i',
            $absolutePath,
        ];
    }

    private function parseNumericDurationSeconds(string $output): ?float
    {
        $output = trim($output);
        if ($output === '' || !is_numeric($output)) {
            return null;
        }

        return max(0.0, (float) $output);
    }

    private function parseFfmpegDurationSeconds(string $output): ?float
    {
        if (!preg_match('/Duration:\s*(\d{2}):(\d{2}):(\d{2}(?:\.\d+)?)/i', $output, $matches)) {
            return null;
        }

        $hours = (int) ($matches[1] ?? 0);
        $minutes = (int) ($matches[2] ?? 0);
        $seconds = (float) ($matches[3] ?? 0);

        return max(0.0, ($hours * 3600) + ($minutes * 60) + $seconds);
    }

    private function describeProbeFailure(array $result, string $toolName): string
    {
        $output = trim((string) ($result['error_output'] ?? '') ?: (string) ($result['output'] ?? ''));
        if ($output !== '') {
            return $output;
        }

        if (($result['successful'] ?? false) && $toolName === 'ffprobe') {
            return '未回傳有效的 duration';
        }

        $exitCode = $result['exit_code'] ?? null;
        if ($exitCode === null) {
            return '未回傳錯誤訊息';
        }

        return '未回傳錯誤訊息 (exit_code=' . $exitCode . ')';
    }

    private function resolveFfprobeBinary(?string $ffprobeBin): string
    {
        $ffprobeBin = trim((string) $ffprobeBin);
        if ($ffprobeBin !== '') {
            return $ffprobeBin;
        }

        $envBin = trim((string) env('FFPROBE_BIN', 'ffprobe'));
        return $envBin !== '' ? $envBin : 'ffprobe';
    }

    private function resolveFfmpegBinary(?string $ffmpegBin, string $ffprobeBin): string
    {
        $ffmpegBin = trim((string) $ffmpegBin);
        if ($ffmpegBin !== '') {
            return $ffmpegBin;
        }

        $envBin = trim((string) env('FFMPEG_BIN', ''));
        if ($envBin !== '') {
            return $envBin;
        }

        $probeBasename = strtolower((string) pathinfo($ffprobeBin, PATHINFO_BASENAME));
        if (in_array($probeBasename, ['ffprobe', 'ffprobe.exe'], true)) {
            $extension = pathinfo($ffprobeBin, PATHINFO_EXTENSION);
            $candidate = rtrim((string) dirname($ffprobeBin), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'ffmpeg'
                . ($extension !== '' ? '.' . $extension : '');

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'ffmpeg';
    }
}
