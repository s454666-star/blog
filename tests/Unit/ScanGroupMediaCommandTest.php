<?php

namespace Tests\Unit;

use App\Console\Commands\ScanGroupMediaCommand;
use App\Services\TelegramCodeTokenService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ScanGroupMediaCommandTest extends TestCase
{
    public function test_showfiles_tokens_resolve_to_expected_bots(): void
    {
        $command = new ScanGroupMediaCommand(new TelegramCodeTokenService());
        $method = new ReflectionMethod($command, 'resolveBotByToken');
        $method->setAccessible(true);

        $showfilesBot = $method->invoke($command, 'showfilesbot_123P_abcdef');
        $showfiles3Bot = $method->invoke($command, 'showfiles3bot_123P_abcdef');

        $this->assertSame('showfiles12bot', $showfilesBot['api']);
        $this->assertSame('@showfiles12bot', $showfilesBot['display']);
        $this->assertSame('vipfiles2bot', $showfiles3Bot['api']);
        $this->assertSame('@vipfiles2bot', $showfiles3Bot['display']);
    }

    public function test_mtfxq_family_tokens_resolve_to_mtfxq2_bot(): void
    {
        $command = new ScanGroupMediaCommand(new TelegramCodeTokenService());
        $method = new ReflectionMethod($command, 'resolveBotByToken');
        $method->setAccessible(true);

        $legacyBot = $method->invoke($command, 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2');
        $newBot = $method->invoke($command, 'mtfxq2bot_9V_A1R7u7F592Q4o6c6c1r5');

        $this->assertSame('mtfxq2bot', $legacyBot['api']);
        $this->assertSame('@mtfxq2bot', $legacyBot['display']);
        $this->assertSame('mtfxq2bot', $newBot['api']);
        $this->assertSame('@mtfxq2bot', $newBot['display']);
    }
}
