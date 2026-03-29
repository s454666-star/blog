<?php

namespace Tests\Unit;

use App\Console\Commands\DispatchTokenScanItemsCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DispatchTokenScanItemsCommandTest extends TestCase
{
    public function test_showfiles12_prefixes_resolve_to_showfiles12_bot(): void
    {
        $command = new DispatchTokenScanItemsCommand();
        $method = new ReflectionMethod($command, 'resolveBotByToken');
        $method->setAccessible(true);

        $qqBot = $method->invoke($command, 'QQfile_bot:14106_76302_385-140P_15V');
        $viBot = $method->invoke($command, 'vi_BAACAgEAAxkBFfAqOmUJtMF-kwAB-hQFXznApJqihypLQQACdwEAAkRp0EW8apA6GKpUJTAE');
        $ntmBot = $method->invoke($command, 'ntmjmqbot_0p_40v_0d_QLnxQFByQp2Kq');

        $this->assertSame('showfiles12bot', $qqBot['api']);
        $this->assertSame('@showfiles12bot', $qqBot['display']);
        $this->assertSame('showfiles12bot', $viBot['api']);
        $this->assertSame('@showfiles12bot', $viBot['display']);
        $this->assertSame('showfiles12bot', $ntmBot['api']);
        $this->assertSame('@showfiles12bot', $ntmBot['display']);
    }

    public function test_delay_is_applied_only_when_qq_or_yz_is_followed_by_another_qq_family_token(): void
    {
        $command = new DispatchTokenScanItemsCommand();
        $method = new ReflectionMethod($command, 'determineDelayBeforeNextJob');
        $method->setAccessible(true);

        $delay = $method->invoke($command, [
            'bot_api' => 'QQfile_bot',
        ], [
            'token' => 'yzfile_bot:14120_108172_755-39P_10V',
            'item' => null,
        ]);

        $this->assertSame(8000000, $delay);

        $delayAfterYz = $method->invoke($command, [
            'bot_api' => 'yzfile_bot',
        ], [
            'token' => 'yzfile_bot:14191_108172_777-22P',
            'item' => null,
        ]);

        $this->assertSame(8000000, $delayAfterYz);
    }

    public function test_delay_is_skipped_when_next_token_is_vip_or_messenger(): void
    {
        $command = new DispatchTokenScanItemsCommand();
        $method = new ReflectionMethod($command, 'determineDelayBeforeNextJob');
        $method->setAccessible(true);

        $vipDelay = $method->invoke($command, [
            'bot_api' => 'QQfile_bot',
        ], [
            'token' => 'showfilesbot_abc123',
            'item' => null,
        ]);

        $messengerDelay = $method->invoke($command, [
            'bot_api' => 'yzfile_bot',
        ], [
            'token' => 'Messengercode_abc123',
            'item' => null,
        ]);

        $this->assertSame(0, $vipDelay);
        $this->assertSame(0, $messengerDelay);
    }
}
