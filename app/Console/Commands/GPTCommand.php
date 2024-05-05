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
女子高中。制服リモバイ散歩。透明感抜群で乳首も尻穴もピンク色。おまけに未処理マンコの最強コントラスト！嫌よ嫌よも好きのうち。約束破りの強制中出し
4月　桜が咲く様に業界にも花咲く季節
つい先日ま女子高中.だった卵を孵化させる時です
隠してきた甲斐があります！！
秘蔵っこの『みきちゃん』です
肌を見ればわかりますよ。ピチピチ
少し前までこれがリアルだった制服
高い焼肉を食べさせろとうるさいです。
これも愛嬌という事で少しムカつくけど良しとしよう
桜も咲いて最高のロケーション
『ちょっとだけなら』という事で
リモバイデート
バイブを仕込もうとお股を広げてもらうと
ちゃんとマン毛が生えております！！
しかも結構モジャモジャっぽいぞ？笑
まだ脱毛するお金もありません。
正真正銘1....〇代のまん毛ちゃん
『ちゃんときてる？』
『うん。。きてる』
やっぱりモジモジしてる笑
大学に進学したから今頃は噂になってるかもしれないね笑
人気のない草むらに行くと座り込んでいきなりヒイヒイし始めた
我慢してたのかな？笑
もっと人気のない所でフェラしてもらう事に
『大丈夫？ここ？怒』
とか言いながらもちゃんと咥えてくれました。
人が来たから中断ww
車内へ移動
ホテルに着くまでの道中
ずっとチンポを咥えておりました笑
ホテルへ到着
まじまじ見ると綺麗な体だなあ〜
ピンク色のかわいい乳首
透き通って見える
そして必見！！密林剛毛ジャングルまんこ
見た目とのギャップが、、、GOOD
ふっさふさです
自身の剛毛マンコをいじりながらフェラ
こないだまで女...◯高〇だったとは思えない泣
たっぷりと互いの陰部を刺激しあった所で密林を開拓
彼氏もいないし
SEXには多少飢えてそう
まんざらでも無さそうだから一応外に出す約束で生挿入
まだ1....〇代
このクラスの巨根には出会った事がないのか激しく乱れる
フサフサ密林マンコが毛束になっているのはマンコから愛汁が溢れ出ている証
そうとうに気持ちようです
巨尻だね〜
なかなかの巨尻がとてつもなくエロい！！
そして、けつ穴もピンク
色素の薄い女って最高にエロいね
『中に出していい？』
『ダメ！絶対だめ！！
お願い外に出して！ダメダメ！！
お願いお願い』
出しちゃったよ〜。。。
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
