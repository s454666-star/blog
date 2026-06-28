<?php

namespace Database\Seeders;

use App\Models\CrawlerProfileCandidate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CrawlerProfileCandidateSeeder extends Seeder
{
    private const SOURCE = 'synthetic_85sugarbaby_active_flow';
    private const PROFILE_BASE_URL = 'https://85sugarbaby.com.tw/view?user_id=';
    private const IMAGE_BASE_URL = 'https://85sugarbaby.com.tw/home/ubuntu/85SugarDatabaseBackup/uploads/headpic';

    public function run(): void
    {
        $capturedAt = now();
        $source = self::SOURCE;
        $filter = [
            'areas' => ['台北', '新北'],
            'age_min' => 18,
            'age_max' => 22,
            'source_note' => 'synthetic seed data only',
        ];

        DB::transaction(function () use ($capturedAt, $filter): void {
            CrawlerProfileCandidate::query()
                ->where('source', self::SOURCE)
                ->delete();

            foreach ($this->profiles() as $index => $profile) {
                $candidate = CrawlerProfileCandidate::query()->updateOrCreate(
                    [
                        'source' => self::SOURCE,
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
                'external_user_id' => '114224',
                'nickname' => '測試小晴',
                'age' => 18,
                'area' => '台北',
                'profile_url' => self::PROFILE_BASE_URL . '114224',
                'images' => [
                    self::IMAGE_BASE_URL . '/0/114/114224/2768616154v.jpg',
                    self::IMAGE_BASE_URL . '/0/114/114224/2768616155v.jpg',
                ],
            ],
            [
                'external_user_id' => '114225',
                'nickname' => '測試米娜',
                'age' => 19,
                'area' => '新北',
                'profile_url' => self::PROFILE_BASE_URL . '114225',
                'images' => [self::IMAGE_BASE_URL . '/0/114/114225/2768616171v.jpg'],
            ],
            [
                'external_user_id' => '114226',
                'nickname' => '測試安安',
                'age' => 20,
                'area' => '台北',
                'profile_url' => self::PROFILE_BASE_URL . '114226',
                'images' => [self::IMAGE_BASE_URL . '/0/114/114226/2768616189v.jpg'],
            ],
            [
                'external_user_id' => '114227',
                'nickname' => '測試露西',
                'age' => 21,
                'area' => '新北',
                'profile_url' => self::PROFILE_BASE_URL . '114227',
                'images' => [
                    self::IMAGE_BASE_URL . '/0/114/114227/2768616198v.jpg',
                    self::IMAGE_BASE_URL . '/0/114/114227/2768616203v.jpg',
                ],
            ],
            [
                'external_user_id' => '114228',
                'nickname' => '測試可可',
                'age' => 22,
                'area' => '台北',
                'profile_url' => self::PROFILE_BASE_URL . '114228',
                'images' => [self::IMAGE_BASE_URL . '/0/114/114228/2768616217v.jpg'],
            ],
            [
                'external_user_id' => '114229',
                'nickname' => '測試艾琳',
                'age' => 18,
                'area' => '新北',
                'profile_url' => self::PROFILE_BASE_URL . '114229',
                'images' => [self::IMAGE_BASE_URL . '/0/114/114229/2768616228v.jpg'],
            ],
            [
                'external_user_id' => '114230',
                'nickname' => '測試娜娜',
                'age' => 19,
                'area' => '台北',
                'profile_url' => self::PROFILE_BASE_URL . '114230',
                'images' => [self::IMAGE_BASE_URL . '/0/114/114230/2768616232v.jpg'],
            ],
            [
                'external_user_id' => '114231',
                'nickname' => '測試星星',
                'age' => 20,
                'area' => '新北',
                'profile_url' => self::PROFILE_BASE_URL . '114231',
                'images' => [
                    self::IMAGE_BASE_URL . '/0/114/114231/2768616247v.jpg',
                    self::IMAGE_BASE_URL . '/0/114/114231/2768616248v.jpg',
                ],
            ],
            [
                'external_user_id' => '114232',
                'nickname' => '測試花花',
                'age' => 21,
                'area' => '台北',
                'profile_url' => self::PROFILE_BASE_URL . '114232',
                'images' => [self::IMAGE_BASE_URL . '/0/114/114232/2768616259v.jpg'],
            ],
            [
                'external_user_id' => '114233',
                'nickname' => '測試妮可',
                'age' => 22,
                'area' => '新北',
                'profile_url' => self::PROFILE_BASE_URL . '114233',
                'images' => [self::IMAGE_BASE_URL . '/0/114/114233/2768616271v.jpg'],
            ],
        ];
    }
}
