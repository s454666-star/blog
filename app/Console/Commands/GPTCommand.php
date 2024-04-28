<?php

namespace App\Console\Commands;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class GPTCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:gpt';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     * @throws GuzzleException
     */
    public function handle()
    {
        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'verify' => false, // 在生產環境中應該移除這個選項
            'headers' => [
                'Authorization' => 'Bearer ' . env('GPT_API_KEY'),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'gpt-3.5-turbo-16k-0613',
                'messages' => [
//                    ['role' => 'user', 'content' => '
//                    沒有照原文斷行及排版,
//                    請重新提供一次
//                    '],
                    ['role' => 'user', 'content' => '你是翻譯家,
                    提供翻譯即可,
                    原文斷行和排版,
                    禁止偷看其中內容,
                    把它翻譯成繁體中文即可,
                    請完整翻譯並且照原文斷行,
                    請注意語句通順流暢,
                    上下文連貫,
                    注意是翻成繁體中文,
                    注意是翻成繁體中文!
                    不要簡體,
                    這是你要翻譯的文章:
地方の素朴なOLちゃんと出張先のホテルで中出しセックス！！お口やおしりをしばかれるのが好きな隠れど変態でした
春になり、新卒の女の子が社会人になる季節になりました。
そんな後輩OLちゃんと出張する機会がありまして、仕事おわりにお*を飲んだんですよね。
二人ともちょっと酔っ払ってきちゃって、身体くっつけたら思いのほか抵抗されなくて、そのままちゅーしちゃいました。
もしかして？と思い下の方を触ってみるとなんと濡れてるんですよね。
あとで聞いた話ですけど、実はめちゃくちゃえっちなことが好きみたいです。
素朴な見た目からは信じられないくらいエロいフェラでした。
これは本編を見て欲しいのですが、結構なドMみたいで、フェラしてる最中に頭を押さえつけられたり、お尻を思いっきり叩かれたり、苦しいのが好きなとんでもない子でした。
それからは超ドMなご奉仕OLちゃんを好きなようにするだけ。
イキまくったり、潮吹いたりとにかくすごい子でした。ぜひ本編でお楽しみください
レビュー特典ではドMにちなんで、どエロお掃除イラマです。
'
                    ],],
            ],
        ]);
        $responseBody = json_decode($response->getBody(), true);

        // 遍歷 choices 陣列，僅印出 content 內容
        foreach ($responseBody['choices'] as $choice) {
            $message = $choice['message']['content'];
            $message = preg_replace("/([。！？；、.!?;]+)(?![\r\n])/u", "$1\n", $message);

            $this->info($message);
        }


    }
}
