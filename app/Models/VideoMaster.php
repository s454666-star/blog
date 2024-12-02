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
            'created_at',
            'updated_at',
        ];

        public $timestamps = true;

        /**
         * Get the screenshots for the video.
         */
        public function screenshots()
        {
            return $this->hasMany(VideoScreenshot::class, 'video_master_id', 'id');
        }
    }
