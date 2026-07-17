<?php

namespace Tests\Unit;

use App\Models\TelegramResourceCode;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TelegramResourceCodeTest extends TestCase
{
    public function test_resume_checkpoint_fields_are_fillable_and_casted(): void
    {
        $model = new TelegramResourceCode([
            'last_completed_page' => '96',
            'resume_from_page' => '97',
            'decoder_total_pages' => '408',
            'resume_bot_username' => 'QQ7bet_bot',
            'paused_at' => '2026-07-17 12:39:43',
        ]);

        $this->assertSame(96, $model->last_completed_page);
        $this->assertSame(97, $model->resume_from_page);
        $this->assertSame(408, $model->decoder_total_pages);
        $this->assertSame('QQ7bet_bot', $model->resume_bot_username);
        $this->assertInstanceOf(Carbon::class, $model->paused_at);
    }
}
