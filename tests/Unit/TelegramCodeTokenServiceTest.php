<?php

namespace Tests\Unit;

use App\Services\TelegramCodeTokenService;
use PHPUnit\Framework\TestCase;

class TelegramCodeTokenServiceTest extends TestCase
{
    public function test_extract_tokens_includes_qqfile_bot_tokens(): void
    {
        $service = new TelegramCodeTokenService();

        $tokens = $service->extractTokens(<<<'TEXT'
QQfile_bot:14120_108172_755-39P_10V
QQfile_bot:14191_108172_777-22P
TEXT);

        $this->assertSame([
            'QQfile_bot:14120_108172_755-39P_10V',
            'QQfile_bot:14191_108172_777-22P',
        ], $tokens);
    }

    public function test_extract_tokens_rejects_invalid_qqfile_bot_tokens(): void
    {
        $service = new TelegramCodeTokenService();

        $tokens = $service->extractTokens('QQfile_bot:bad');

        $this->assertSame([], $tokens);
    }
}
