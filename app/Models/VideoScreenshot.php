<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;

    class VideoScreenshot extends Model
    {
        use HasFactory;

        protected $table = 'video_screenshots';
        protected $primaryKey = 'id';

        protected $fillable = [
            'video_master_id',
            'screenshot_path',
            'created_at',
            'updated_at',
        ];

        public $timestamps = true;

        /**
         * Get the video master that owns the screenshot.
         */
        public function videoMaster()
        {
            return $this->belongsTo(VideoMaster::class, 'video_master_id', 'id');
        }

        /**
         * Get the face screenshots for the screenshot.
         */
        public function faceScreenshots()
        {
            return $this->hasMany(VideoFaceScreenshot::class, 'video_screenshot_id', 'id');
        }
    }
