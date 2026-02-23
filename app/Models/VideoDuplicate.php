<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Carbon;

    class VideoDuplicate extends Model
    {
        protected $table = 'video_duplicates';

        protected $fillable = [
            'filename',
            'full_path',
            'full_path_sha1',
            'file_size_bytes',
            'file_mtime',
            'duration_seconds',
            'snapshot1_b64',
            'snapshot2_b64',
            'snapshot3_b64',
            'snapshot4_b64',
            'hash1_hex',
            'hash2_hex',
            'hash3_hex',
            'hash4_hex',
            'similar_video_ids',
            'last_error',
        ];

        protected $casts = [
            'id' => 'integer',
            'file_size_bytes' => 'integer',
            'file_mtime' => 'integer',
            'duration_seconds' => 'integer',
        ];

        public function getSimilarVideoIdListAttribute(): array
        {
            $raw = (string)($this->similar_video_ids ?? '');
            if (trim($raw) === '') {
                return [];
            }

            $parts = array_map('trim', explode(',', $raw));
            $ids = [];
            foreach ($parts as $p) {
                if ($p === '') {
                    continue;
                }
                if (!ctype_digit($p)) {
                    continue;
                }
                $ids[] = (int)$p;
            }
            return $ids;
        }

        public function getSimilarVideoIdListUniqueAttribute(): array
        {
            $ids = $this->similar_video_id_list;
            $ids = array_values(array_unique($ids));
            sort($ids);
            return $ids;
        }

        public function getSnapshotsAttribute(): array
        {
            $keys = ['snapshot1_b64', 'snapshot2_b64', 'snapshot3_b64', 'snapshot4_b64'];
            $out = [];
            foreach ($keys as $k) {
                $v = $this->{$k};
                if (is_string($v) && trim($v) !== '') {
                    $out[] = $v;
                }
            }
            return $out;
        }

        public function getFileSizeHumanAttribute(): string
        {
            $bytes = (int)($this->file_size_bytes ?? 0);
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = 0;
            $size = (float)$bytes;

            while ($size >= 1024.0 && $i < count($units) - 1) {
                $size /= 1024.0;
                $i++;
            }

            if ($i === 0) {
                return (string)$bytes . ' ' . $units[$i];
            }

            return number_format($size, 2) . ' ' . $units[$i];
        }

        public function getDurationHmsAttribute(): string
        {
            $sec = (int)($this->duration_seconds ?? 0);
            if ($sec < 0) {
                $sec = 0;
            }

            $h = intdiv($sec, 3600);
            $m = intdiv($sec % 3600, 60);
            $s = $sec % 60;

            return sprintf('%02d:%02d:%02d', $h, $m, $s);
        }

        public function getMtimeHumanAttribute(): string
        {
            $ts = (int)($this->file_mtime ?? 0);
            if ($ts <= 0) {
                return '-';
            }

            return Carbon::createFromTimestamp($ts)->format('Y-m-d H:i:s');
        }

        public function getPathDirAttribute(): string
        {
            $p = (string)($this->full_path ?? '');
            if ($p === '') {
                return '';
            }

            $normalized = str_replace('\\', '/', $p);
            $dir = dirname($normalized);
            return str_replace('/', '\\', $dir);
        }
    }
