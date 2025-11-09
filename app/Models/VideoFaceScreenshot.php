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
         * 所屬的截圖
         */
        public function videoScreenshot()
        {
            return $this->belongsTo(VideoScreenshot::class, 'video_screenshot_id', 'id');
        }

        /**
         * 透過截圖反查 VideoMaster
         * 正確的一對一穿越關聯（hasOneThrough）鍵位設定如下：
         * firstKey  = VideoScreenshot.video_master_id   （through 表上，指向 related 的鍵）
         * secondKey = VideoMaster.id                    （related 表的主鍵）
         * localKey  = VideoFaceScreenshot.video_screenshot_id（當前模型到 through 的鍵）
         * secondLocalKey = VideoScreenshot.id           （through 表的主鍵）
         */
        public function videoMaster()
        {
            return $this->hasOneThrough(
                VideoMaster::class,
                VideoScreenshot::class,
                'video_master_id',
                'id',
                'video_screenshot_id',
                'id'
            );
        }
    }
