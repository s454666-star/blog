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

    public function test_extract_tokens_includes_mtfxqbot_tokens(): void
    {
        $service = new TelegramCodeTokenService();

        $tokens = $service->extractTokens(<<<'TEXT'
mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2
mtfxqbot_9V_P1e7Y7B4r5B6N7b6k4G7
mtfxqbot_17V_T1b7Z7C4T506M868H5K8
TEXT);

        $this->assertSame([
            'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
            'mtfxqbot_9V_P1e7Y7B4r5B6N7b6k4G7',
            'mtfxqbot_17V_T1b7Z7C4T506M868H5K8',
        ], $tokens);
    }
}
