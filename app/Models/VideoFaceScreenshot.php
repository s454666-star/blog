<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;

    class VideoFaceScreenshot extends Model
    {
        use HasFactory;

        protected $table = 'video_face_screenshots';
        protected $primaryKey = 'id';

        protected $fillable = [
            'video_screenshot_id',
            'face_image_path',
            'is_master',
            'created_at',
            'updated_at',
        ];

        public $timestamps = true;

        /**
         * Get the screenshot that owns the face screenshot.
         */
        public function videoScreenshot()
        {
            return $this->belongsTo(VideoScreenshot::class, 'video_screenshot_id', 'id');
        }

        /**
         * Get the video master through the screenshot.
         */
        public function videoMaster()
        {
            return $this->hasOneThrough(VideoMaster::class, VideoScreenshot::class, 'id', 'id', 'video_screenshot_id', 'video_master_id');
        }
    }
