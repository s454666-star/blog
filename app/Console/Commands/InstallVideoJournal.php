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
        if ($path !== ':memory:' && !is_file($path) && !touch($path)) {
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
        foreach (['tags'] as $column) {
            if (!$schema->hasColumn('video_journal_entries', $column)) {
                $schema->table('video_journal_entries', function (Blueprint $table) use ($column) {
                    $table->longText($column)->default('[]');
                });
            }
        }
        if (!$schema->hasTable('video_journal_people')) {
            $schema->create('video_journal_people', function (Blueprint $table) {
                $table->id();
                $table->string('label')->nullable();
                $table->string('status', 24)->default('unreviewed')->index();
                $table->longText('centroid_embedding')->nullable();
                $table->string('embedding_model')->nullable();
                $table->string('embedding_version')->nullable();
                $table->unsignedInteger('embedding_dimensions')->nullable();
                $table->timestamps();
            });
        }
        if (!$schema->hasTable('video_journal_faces')) {
            $schema->create('video_journal_faces', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entry_id')->constrained('video_journal_entries')->cascadeOnDelete();
                $table->unsignedSmallInteger('position');
                $table->longText('image');
                $table->char('image_sha256', 64)->index();
                $table->foreignId('person_id')->nullable()->constrained('video_journal_people')->nullOnDelete();
                $table->string('feature_status', 24)->default('pending')->index();
                $table->longText('embedding')->nullable();
                $table->string('embedding_model')->nullable();
                $table->string('embedding_version')->nullable();
                $table->unsignedInteger('embedding_dimensions')->nullable();
                $table->string('preprocessing_version')->nullable();
                $table->text('face_box')->nullable();
                $table->text('landmarks')->nullable();
                $table->float('detection_confidence')->nullable();
                $table->float('quality_score')->nullable();
                $table->float('match_distance')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->index(['entry_id', 'position']);
                $table->index(['person_id', 'entry_id']);
                $table->index(['embedding_model', 'embedding_version']);
            });
        }
        if (!$schema->hasTable('video_journal_person_entries')) {
            $schema->create('video_journal_person_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('person_id')->constrained('video_journal_people')->cascadeOnDelete();
                $table->foreignId('entry_id')->constrained('video_journal_entries')->cascadeOnDelete();
                $table->string('assignment_method', 24)->nullable();
                $table->float('confidence')->nullable();
                $table->string('review_status', 24)->default('pending');
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->unique(['person_id', 'entry_id']);
                $table->index(['entry_id', 'person_id']);
            });
        }
        $this->info('Local video journal is ready.');
        return self::SUCCESS;
    }
}
