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
携帯ショップで懸命に働く新卒の〇〇ちゃん！素朴で庶民的な彼女を誑かし2度も大量種付け
こんにちは。
危険なふたりのあとがない男羽賀けんです！
ついに新垢の２作目が出ました！
執行猶予付きの僕らの時代の到来です。
個人的にはあの伝説のキングとのコラボに感動してます。。。
今回はキングさんから女の仔を紹介してもらってデートをしてきました
女性の心に漬け込むのは僕の得意技です。
生憎の雨でカフェデートとなりました。
現在は某N〇Tの携帯ショップで販売員をしているとのこと。
新卒で働いているとのことですが、１年目にして社会の厳しさを痛感したそうです…
初任給が低すぎて生活するのがやっとのこと。
そんな女性を救いたいと心から思い僕はハメ撮りさせてくれるならお金あげると誑かしました。
難色を示していましたが、なんとか了承を得て無事ホテルへ…
いざ入ると男性経験はたったの2人…
入った瞬間からかなり緊張しており、身体は硬直していました。
この初心な感じって最高に萌えますよね…！
服をゆっくり脱がせていくと色白の潔白の身体が露わに。。。
不慣れながらも懸命に舐める彼女。
相当真面目な人生を送ってきたのでしょう。。
そんな彼女も社会の荒波に揉まれてしまいました。
その真面目さをもってこの社会を生きたら悪い大人に利用されちゃうよ。
イキまくりで喘ぐ彼女に堪らず中に2度も大量注入してしまいました（笑）
これから地獄の日々が待ち受けてるだろうけど僕には関係ないです…
搾取してやる…！
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
