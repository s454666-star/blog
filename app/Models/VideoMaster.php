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
            'm3u8_path',
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
         * 影片的截圖列表
         */
        public function screenshots()
        {
            return $this->hasMany(VideoScreenshot::class, 'video_master_id', 'id');
        }

        /**
         * 影片的特徵摘要（供重複比對使用）
         */
        public function feature()
        {
            return $this->hasOne(VideoFeature::class, 'video_master_id', 'id');
        }

        public function externalDuplicateMatches()
        {
            return $this->hasMany(ExternalVideoDuplicateMatch::class, 'video_master_id', 'id');
        }

        /**
         * 影片的「主面」人臉列表（透過 screenshots）
         * 🚩 不在這裡排序，避免 hasManyThrough 對父表排序的 SQL 問題；排序放查詢端（Controller）做。
         */
        public function masterFaces()
        {
            return $this->hasManyThrough(
                VideoFaceScreenshot::class,
                VideoScreenshot::class,
                'video_master_id',
                'video_screenshot_id',
                'id',
                'id'
            )->where('is_master', 1);
        }
    }
