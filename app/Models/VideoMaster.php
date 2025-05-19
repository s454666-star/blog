<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;

    class VideoMaster extends Model
    {
        use HasFactory;

        protected $table = 'video_master';

        protected $primaryKey = 'id';

        protected $fillable = [
            'video_name',
            'video_path',
            'duration',
            'video_type',
            'created_at',
            'updated_at',
        ];

        protected $casts = [
            'duration' => 'decimal:2',
        ];

        public $timestamps = true;

        /**
         * Get the screenshots for the video.
         */
        public function screenshots()
        {
            return $this->hasMany(VideoScreenshot::class, 'video_master_id', 'id');
        }

        /**
         * Get the master face screenshots for the video.
         */
        public function masterFaces()
        {
            return $this->hasManyThrough(VideoFaceScreenshot::class, VideoScreenshot::class, 'video_master_id', 'video_screenshot_id', 'id', 'id')
                ->where('is_master', 1)
                ->orderBy('video_master.duration', 'asc');
        }
    }
