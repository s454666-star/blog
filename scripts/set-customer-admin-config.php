<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

$username = base64_decode((string) getenv('CRM_ADMIN_USER_B64'), true);
$passwordHash = base64_decode((string) getenv('CRM_ADMIN_HASH_B64'), true);
$envPath = dirname(__DIR__).'/.env';

if (! is_string($username) || $username === '' || str_contains($username, "\n")) {
    fwrite(STDERR, "CRM admin username is missing or invalid.\n");
    exit(1);
}
if (! is_string($passwordHash) || ! preg_match('/^\$2[ayb]\$.{56}$/', $passwordHash)) {
    fwrite(STDERR, "CRM admin password hash is missing or invalid.\n");
    exit(1);
}
if (! is_file($envPath)) {
    fwrite(STDERR, "Application .env was not found.\n");
    exit(1);
}

$lines = preg_split('/\R/', (string) file_get_contents($envPath));
$lines = array_values(array_filter($lines, static fn (string $line): bool =>
    ! str_starts_with($line, 'CUSTOMER_ADMIN_USERNAME=')
    && ! str_starts_with($line, 'CUSTOMER_ADMIN_PASSWORD_HASH=')
));
$lines[] = 'CUSTOMER_ADMIN_USERNAME='.$username;
$lines[] = 'CUSTOMER_ADMIN_PASSWORD_HASH='.$passwordHash;

$temporaryPath = $envPath.'.crm-'.bin2hex(random_bytes(4));
file_put_contents($temporaryPath, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX);
chmod($temporaryPath, fileperms($envPath) & 0777);
rename($temporaryPath, $envPath);

fwrite(STDOUT, "Customer admin configuration updated.\n");
