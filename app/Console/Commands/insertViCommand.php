<?php

namespace App\Console\Commands;

use App\Models\TestVi;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class insertViCommand extends Command
{
    protected $signature   = 'command:ins-vi';
    protected $description = 'Command description';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $filePath = '111.txt';
        if (!Storage::exists($filePath)) {
            $this->error("檔案未找到。");
            return;
        }

        $data   = [];
        $pkData = [];
        $lines  = Storage::get($filePath);

        foreach (explode("\n", $lines) as $line) {
            $line = trim($line);
            preg_match_all('/(v_|p_|d_|pk_)[\w-]+/', $line, $matches);

            foreach ($matches[0] as $match) {
                $length = strlen($match);
                if (str_starts_with($match, 'v_') || str_starts_with($match, 'd_')) {
                    if ($length > 70) {
                        $data[] = $match;
                    }
                } elseif (str_starts_with($match, 'p_')) {
                    if ($length > 80) {
                        $data[] = $match;
                    }
                } elseif (str_starts_with($match, 'pk_')) {
                    if ($length > 25) {
                        $pkData[] = $match;
                    }
                }
            }
        }

        $data = array_merge($data, $pkData);

        if (empty($data)) {
            $this->error("未找到符合條件的數據。");
            return;
        }

        try {
            $counts = 0;
            foreach ($data as $name) {
                $record = TestVi::firstOrCreate([
                    'name' => $name,
                ], [
                    'date' => now(),
                ]);

                if ($record->wasRecentlyCreated) {
                    $counts++;
                }
            }

            $this->info("總共插入了 " . $counts . " 條記錄。");
        }
        catch (Exception $e) {
            $this->error("插入數據時發生錯誤。");
            dd($data, $e);
        }
    }
}
