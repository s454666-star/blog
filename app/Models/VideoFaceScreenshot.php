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
    }
