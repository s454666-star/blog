<?php

namespace App\Http\Controllers;

use App\Models\Article;
use DOMDocument;
use DOMXPath;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GetBtDataDetailController
{
    private const SOURCE_TYPE_BT = 2;

    protected Client $client;

    private GetRealImageController $getRealImageController;

    public function __construct(GetRealImageController $getRealImageController)
    {
        $this->getRealImageController = $getRealImageController;
        $this->client = new Client([
            'allow_redirects' => [
                'max' => 20,
                'strict' => true,
                'referer' => true,
                'protocols' => ['http', 'https'],
                'track_redirects' => true,
            ],
        ]);
    }

    public function fetchDetail(string $detailPageUrl): void
    {
        $detailPageUrl = trim($detailPageUrl);

        if ($detailPageUrl === '') {
            return;
        }

        $lock = $this->acquireDetailLock($detailPageUrl);

        if ($lock === false) {
            Log::info('BT detail skipped because another crawler is already processing the same URL', [
                'url' => $detailPageUrl,
            ]);

            return;
        }

        if ($lock === null) {
            Log::warning('BT detail skipped because the Redis lock store is unavailable', [
                'url' => $detailPageUrl,
            ]);

            return;
        }

        try {
            if ($this->articleExists($detailPageUrl)) {
                echo "文章已存在，跳過儲存。\r\n";

                return;
            }

            $response = $this->client->request('GET', $detailPageUrl, ['verify' => false]);
            $htmlContent = $response->getBody()->getContents();

            $dom = new DOMDocument();
            @$dom->loadHTML($htmlContent);
            $xpath = new DOMXPath($dom);

            $titleNode = $xpath->query('//h3[@class="panel-title"]')->item(0);
            $title = $titleNode ? trim($titleNode->nodeValue) : '';

            $timeNode = $xpath->query('//div[@class="row"]/div[@class="col-md-1" and text()="Date:"]/following-sibling::div[@class="col-md-5"]')->item(0);
            $articleTime = $timeNode ? trim($timeNode->nodeValue) : '';
            $articleTime = str_replace(' UTC', '', $articleTime);
            $articleTimeWithSeconds = Carbon::createFromFormat('Y-m-d H:i', $articleTime, 'Asia/Taipei')
                ->format('Y-m-d H:i:s');

            $magnetLinkNode = $xpath->query('//a[contains(@href,"magnet:?xt=")]')->item(0);
            $magnetLink = $magnetLinkNode ? trim($magnetLinkNode->getAttribute('href')) : '';

            $downloadLinkNode = $xpath->query('//div[@class="panel-footer clearfix"]/a[contains(@href,"/download/")]')->item(0);
            $baseUrl = parse_url($detailPageUrl, PHP_URL_SCHEME) . '://' . parse_url($detailPageUrl, PHP_URL_HOST);
            $downloadLink = $downloadLinkNode ? $baseUrl . trim($downloadLinkNode->getAttribute('href')) : '';

            $attempt = 0;
            $imageUrls = [];

            do {
                $dom = new DOMDocument();
                @$dom->loadHTML($htmlContent);
                $xpath = new DOMXPath($dom);

                $descriptionNode = $xpath->query('//div[contains(@id,"torrent-description")]')->item(0);
                $imageUrls = [];

                if ($descriptionNode) {
                    $lines = explode("\n", $descriptionNode->textContent);
                    foreach ($lines as $line) {
                        if (preg_match('/https:\/\/.*?\.jpg/', $line, $matches)) {
                            $imageUrls[] = $matches[0];
                        }
                    }
                }

                if ($imageUrls !== []) {
                    break;
                }

                sleep(30);
                $attempt++;
                $response = $this->client->request('GET', $detailPageUrl, ['verify' => false]);
                $htmlContent = $response->getBody()->getContents();
            } while ($attempt < 5);

            DB::transaction(function () use (
                $detailPageUrl,
                $title,
                $magnetLink,
                $downloadLink,
                $articleTimeWithSeconds,
                $imageUrls
            ): void {
                if ($this->articleExists($detailPageUrl)) {
                    return;
                }

                $article = Article::create([
                    'title' => $title,
                    'password' => $magnetLink,
                    'https_link' => $downloadLink,
                    'detail_url' => $detailPageUrl,
                    'article_time' => $articleTimeWithSeconds,
                    'source_type' => self::SOURCE_TYPE_BT,
                    'is_disabled' => 0,
                ]);

                if ($imageUrls === []) {
                    Log::error('沒有找到圖片，儲存文章但無圖片', ['url' => $detailPageUrl]);

                    return;
                }

                foreach (array_values(array_unique($imageUrls)) as $imageUrl) {
                    try {
                        $realUrl = $this->getRealImageController->processImage($imageUrl);

                        if (!$realUrl) {
                            continue;
                        }

                        $existingImage = $article->images()
                            ->where('image_path', $realUrl)
                            ->exists();

                        if (!$existingImage) {
                            $article->images()->create([
                                'image_name' => basename($realUrl),
                                'image_path' => $realUrl,
                            ]);
                        }
                    } catch (Exception $e) {
                        Log::error('圖片處理失敗', [
                            'url' => $imageUrl,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        } catch (GuzzleException $e) {
            Log::error('請求失敗', [
                'url' => $detailPageUrl,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->releaseDetailLock($lock);
        }
    }

    private function articleExists(string $detailPageUrl): bool
    {
        return Article::query()
            ->where('source_type', self::SOURCE_TYPE_BT)
            ->where('detail_url', $detailPageUrl)
            ->exists();
    }

    /**
     * @return Lock|false|null false means the URL is already being processed, null means Redis locking is unavailable.
     */
    private function acquireDetailLock(string $detailPageUrl): Lock|false|null
    {
        try {
            $lock = Cache::lock(
                $this->detailLockKey($detailPageUrl),
                (int) config('bt.detail_lock_seconds', 900)
            );

            return $lock->get() ? $lock : false;
        } catch (Throwable $e) {
            Log::warning('BT detail lock unavailable', [
                'url' => $detailPageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function releaseDetailLock(?Lock $lock): void
    {
        if ($lock instanceof Lock) {
            $lock->release();
        }
    }

    private function detailLockKey(string $detailPageUrl): string
    {
        return 'bt-crawler:detail:' . sha1($detailPageUrl);
    }
}
