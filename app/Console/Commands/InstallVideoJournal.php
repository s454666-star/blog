<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class InstallVideoJournal extends Command
{
    protected $signature = 'video-journal:install';
    protected $description = 'Create the isolated local video journal database (preserves existing entries)';

    public function handle(): int
    {
        $path = config('database.connections.video_journal.database');
        if (!is_file($path) && !touch($path)) {
            $this->error('Unable to create local journal database.');
            return self::FAILURE;
        }
        $schema = Schema::connection('video_journal');
        if (!$schema->hasTable('video_journal_entries')) {
            $schema->create('video_journal_entries', function (Blueprint $table) {
                $table->id();
                $table->string('title', 200);
                $table->text('source');
                $table->longText('body')->default('');
                $table->timestamps();
            });
        }
        $this->info('Local video journal is ready.');
        return self::SUCCESS;
    }
}
