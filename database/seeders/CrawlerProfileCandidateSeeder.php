<?php

namespace Database\Seeders;

use App\Models\CrawlerProfileCandidate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CrawlerProfileCandidateSeeder extends Seeder
{
    public function run(): void
    {
        $capturedAt = now();
        $filter = [
            'areas' => ['台北', '新北'],
            'age_min' => 18,
            'age_max' => 22,
            'source_note' => 'synthetic seed data only',
        ];

        DB::transaction(function () use ($capturedAt, $filter): void {
            foreach ($this->profiles() as $index => $profile) {
                $candidate = CrawlerProfileCandidate::query()->updateOrCreate(
                    [
                        'source' => 'synthetic_85sugarbaby_active_flow',
                        'external_user_id' => $profile['external_user_id'],
                    ],
                    [
                        'nickname' => $profile['nickname'],
                        'age' => $profile['age'],
                        'area' => $profile['area'],
                        'profile_url' => $profile['profile_url'],
                        'matched_filter_json' => $filter,
                        'raw_payload' => [
                            'synthetic' => true,
                            'seed_index' => $index + 1,
                        ],
                        'captured_at' => $capturedAt,
                    ]
                );

                $seenImageHashes = [];

                foreach ($profile['images'] as $sortOrder => $imageUrl) {
                    $hash = hash('sha256', $imageUrl);
                    $seenImageHashes[] = $hash;

                    $candidate->images()->updateOrCreate(
                        ['image_url_hash' => $hash],
                        [
                            'image_url' => $imageUrl,
                            'sort_order' => $sortOrder + 1,
                            'captured_at' => $capturedAt,
                        ]
                    );
                }

                $candidate->images()
                    ->whereNotIn('image_url_hash', $seenImageHashes)
                    ->delete();
            }
        });
    }

    private function profiles(): array
    {
        return [
            [
                'external_user_id' => 'SYN-0001',
                'nickname' => '測試小晴',
                'age' => 18,
                'area' => '台北',
                'profile_url' => 'https://example.test/85sugarbaby/view?user_id=SYN-0001',
                'images' => [
                    'https://example.test/85sugarbaby/images/syn-0001-1.jpg',
                    'https://example.test/85sugarbaby/images/syn-0001-2.jpg',
                ],
            ],
            [
                'external_user_id' => 'SYN-0002',
                'nickname' => '測試米娜',
                'age' => 19,
                'area' => '新北',
                'profile_url' => 'https://example.test/85sugarbaby/view?user_id=SYN-0002',
                'images' => ['https://example.test/85sugarbaby/images/syn-0002-1.jpg'],
            ],
            [
                'external_user_id' => 'SYN-0003',
                'nickname' => '測試安安',
                'age' => 20,
                'area' => '台北',
                'profile_url' => 'https://example.test/85sugarbaby/view?user_id=SYN-0003',
                'images' => ['https://example.test/85sugarbaby/images/syn-0003-1.jpg'],
            ],
            [
                'external_user_id' => 'SYN-0004',
                'nickname' => '測試露西',
                'age' => 21,
                'area' => '新北',
                'profile_url' => 'https://example.test/85sugarbaby/view?user_id=SYN-0004',
                'images' => [
                    'https://example.test/85sugarbaby/images/syn-0004-1.jpg',
                    'https://example.test/85sugarbaby/images/syn-0004-2.jpg',
                ],
            ],
            [
                'external_user_id' => 'SYN-0005',
                'nickname' => '測試可可',
                'age' => 22,
                'area' => '台北',
                'profile_url' => 'https://example.test/85sugarbaby/view?user_id=SYN-0005',
                'images' => ['https://example.test/85sugarbaby/images/syn-0005-1.jpg'],
            ],
            [
                'external_user_id' => 'SYN-0006',
                'nickname' => '測試艾琳',
                'age' => 18,
                'area' => '新北',
                'profile_url' => 'https://example.test/85sugarbaby/view?user_id=SYN-0006',
                'images' => ['https://example.test/85sugarbaby/images/syn-0006-1.jpg'],
            ],
            [
                'external_user_id' => 'SYN-0007',
                'nickname' => '測試娜娜',
                'age' => 19,
                'area' => '台北',
                'profile_url' => 'https://example.test/85sugarbaby/view?user_id=SYN-0007',
                'images' => ['https://example.test/85sugarbaby/images/syn-0007-1.jpg'],
            ],
            [
                'external_user_id' => 'SYN-0008',
                'nickname' => '測試星星',
                'age' => 20,
                'area' => '新北',
                'profile_url' => 'https://example.test/85sugarbaby/view?user_id=SYN-0008',
                'images' => [
                    'https://example.test/85sugarbaby/images/syn-0008-1.jpg',
                    'https://example.test/85sugarbaby/images/syn-0008-2.jpg',
                ],
            ],
            [
                'external_user_id' => 'SYN-0009',
                'nickname' => '測試花花',
                'age' => 21,
                'area' => '台北',
                'profile_url' => 'https://example.test/85sugarbaby/view?user_id=SYN-0009',
                'images' => ['https://example.test/85sugarbaby/images/syn-0009-1.jpg'],
            ],
            [
                'external_user_id' => 'SYN-0010',
                'nickname' => '測試妮可',
                'age' => 22,
                'area' => '新北',
                'profile_url' => 'https://example.test/85sugarbaby/view?user_id=SYN-0010',
                'images' => ['https://example.test/85sugarbaby/images/syn-0010-1.jpg'],
            ],
        ];
    }
}
