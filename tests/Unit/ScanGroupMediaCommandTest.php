<?php

namespace Tests\Unit;

use App\Console\Commands\ScanGroupMediaCommand;
use App\Services\TelegramCodeTokenService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ScanGroupMediaCommandTest extends TestCase
{
    public function test_showfiles_tokens_resolve_to_vipfiles_bot(): void
    {
        $command = new ScanGroupMediaCommand(new TelegramCodeTokenService());
        $method = new ReflectionMethod($command, 'resolveBotByToken');
        $method->setAccessible(true);

        $showfilesBot = $method->invoke($command, 'showfilesbot_123P_abcdef');
        $showfiles3Bot = $method->invoke($command, 'showfiles3bot_123P_abcdef');

        $this->assertSame('vipfiles2bot', $showfilesBot['api']);
        $this->assertSame('@vipfiles2bot', $showfilesBot['display']);
        $this->assertSame('vipfiles2bot', $showfiles3Bot['api']);
        $this->assertSame('@vipfiles2bot', $showfiles3Bot['display']);
    }

    public function test_mtfxq_tokens_resolve_to_mtfxq_bot(): void
    {
        $command = new ScanGroupMediaCommand(new TelegramCodeTokenService());
        $method = new ReflectionMethod($command, 'resolveBotByToken');
        $method->setAccessible(true);

        $bot = $method->invoke($command, 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2');

        $this->assertSame('mtfxqbot', $bot['api']);
        $this->assertSame('@mtfxqbot', $bot['display']);
    }
}
