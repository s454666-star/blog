<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class FileScreenshot extends Model
    {
        protected $table = 'file_screenshots';

        protected $fillable = [
            'file_name',
            'file_path',
            'screenshot_paths',
            'rating',
            'notes',
            'type',  // 新增的 type 欄位
        ];
    }
