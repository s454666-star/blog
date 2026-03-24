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
}
