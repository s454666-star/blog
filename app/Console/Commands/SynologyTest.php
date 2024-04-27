<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SynologyService;

class SynologyTest extends Command
{
    protected $signature = 'synology:test';
    protected $description = 'Test Synology API for retrieving shared folders';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $synologyService = new SynologyService();
        $sessionId = $synologyService->loginAndGetSid();
        if ($sessionId) {
            $this->info('Logged in successfully. Session ID: ' . $sessionId);
            $folders = $synologyService->getSharedFolders($sessionId);
            foreach ($folders as $folder) {
                $this->line('Folder: ' . $folder['name']);
            }
        } else {
            $this->error('Failed to log in.');
        }
    }
}
