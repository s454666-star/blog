<?php

namespace App\Services;

class TelegramFilestoreBotProfileResolver
{
    public const FILESTORE = 'filestore';
    public const BACKUP_RESTORE = 'backup_restore';

    /**
     * @return array{key:string,username:string,token:string,prefix:string}
     */
    public function resolve(?string $profileKey = null): array
    {
        $normalized = $this->normalize($profileKey);

        if ($normalized === self::BACKUP_RESTORE) {
            $username = ltrim(trim((string) config('telegram.backup_restore_bot_username', 'new_files_star_bot')), '@');

            return [
                'key' => self::BACKUP_RESTORE,
                'username' => $username,
                'token' => trim((string) config('telegram.backup_restore_bot_token')),
                'prefix' => $this->buildPrefixFromUsername($username),
            ];
        }

        $username = ltrim(trim((string) config('telegram.filestore_bot_username', 'filestoebot')), '@');

        return [
            'key' => self::FILESTORE,
            'username' => $username,
            'token' => trim((string) config('telegram.filestore_bot_token')),
            'prefix' => $this->buildPrefixFromUsername($username),
        ];
    }

    public function normalize(?string $profileKey = null): string
    {
        $normalized = strtolower(str_replace('-', '_', trim((string) $profileKey)));

        return match ($normalized) {
            '',
            self::FILESTORE,
            'filestore_bot' => self::FILESTORE,
            self::BACKUP_RESTORE,
            'backup',
            'backup_bot',
            'new_files_star',
            'new_files_star_bot',
            'newfilesstar' => self::BACKUP_RESTORE,
            default => self::FILESTORE,
        };
    }

    /**
     * @return array<int, string>
     */
    public function supportedDecodePrefixes(): array
    {
        $prefixes = array_filter([
            $this->resolve(self::FILESTORE)['prefix'],
            $this->resolve(self::BACKUP_RESTORE)['prefix'],
        ]);

        return array_values(array_unique(array_map('strtolower', $prefixes)));
    }

    public function canonicalDecodePrefix(): string
    {
        return $this->resolve(self::FILESTORE)['prefix'];
    }

    private function buildPrefixFromUsername(string $username): string
    {
        $normalized = ltrim(trim($username), '@');

        return $normalized === '' ? '' : strtolower($normalized) . '_';
    }
}
