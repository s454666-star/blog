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

    public function test_extract_tokens_includes_mtfxq_family_tokens(): void
    {
        $service = new TelegramCodeTokenService();

        $tokens = $service->extractTokens(<<<'TEXT'
mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2
mtfxqbot_12V_n1I7l7r4v7e890S029L4
mtfxq2bot_9V_A1R7u7F592Q4o6c6c1r5
mtfxq2bot_17V_T1b7Z7C4T506M868H5K8
TEXT);

        $this->assertSame([
            'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
            'mtfxqbot_12V_n1I7l7r4v7e890S029L4',
            'mtfxq2bot_9V_A1R7u7F592Q4o6c6c1r5',
            'mtfxq2bot_17V_T1b7Z7C4T506M868H5K8',
        ], $tokens);
    }

    public function test_mtfxq_family_tokens_are_not_dialogues_only(): void
    {
        $service = new TelegramCodeTokenService();

        $this->assertFalse($service->shouldStoreOnlyInDialogues('mtfxqbot_12V_n1I7l7r4v7e890S029L4'));
        $this->assertFalse($service->shouldStoreOnlyInDialogues('mtfxq2bot_9V_A1R7u7F592Q4o6c6c1r5'));
    }

    public function test_should_mark_dialogue_as_synced_for_filestore_tokens(): void
    {
        $service = new TelegramCodeTokenService();

        $this->assertTrue($service->shouldMarkDialogueAsSynced('filestoebot_abc123'));
        $this->assertTrue($service->shouldMarkDialogueAsSynced('new_files_star_bot_abc123'));
        $this->assertFalse($service->shouldMarkDialogueAsSynced('mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2'));
        $this->assertFalse($service->shouldMarkDialogueAsSynced('mtfxq2bot_9V_A1R7u7F592Q4o6c6c1r5'));
    }

    public function test_extract_tokens_includes_new_files_star_bot_tokens(): void
    {
        $service = new TelegramCodeTokenService();

        $tokens = $service->extractTokens(<<<'TEXT'
new_files_star_bot_1V_demo12345
new_files_star_bot_2P_demo67890
TEXT);

        $this->assertSame([
            'new_files_star_bot_1V_demo12345',
            'new_files_star_bot_2P_demo67890',
        ], $tokens);
    }
}
