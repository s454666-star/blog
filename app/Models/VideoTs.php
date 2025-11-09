<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class VideoTs extends Model
    {
        protected $table = 'videos_ts';

        protected $fillable = [
            'video_name',
            'path',
            'video_time',
            'tags',
            'rating',
        ];

        public $timestamps = false;
    }
