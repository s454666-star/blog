<?php

    namespace App\Console\Commands;

    use Illuminate\Console\Command;
    use GuzzleHttp\Client;

    class SetMyCommands extends Command
    {
        /**
         * The name and signature of the console command.
         *
         * @var string
         */
        protected $signature = 'telegram:set-commands';

        /**
         * The console command description.
         *
         * @var string
         */
        protected $description = 'Register bot commands (/start) with Telegram via setMyCommands API';

        /**
         * Execute the console command.
         *
         * @return int
         */
        public function handle()
        {
            $token = config('telegram.bot_token');
            if (empty($token)) {
                $this->error('Telegram bot token not set in configuration. Please set TELEGRAM_BOT_TOKEN in your .env.');
                return self::FAILURE;
            }

            $client = new Client(['base_uri' => "https://api.telegram.org/bot{$token}/"]);

            try {
                $response = $client->post('setMyCommands', [
                    'json' => [
                        'commands' => [
                            [
                                'command' => 'start',
                                'description' => '列出本聊天的歷史對話',
                            ],
                        ],
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                if (!empty($data['ok'])) {
                    $this->info('Successfully set bot commands: ' . json_encode($data));
                    return self::SUCCESS;
                }

                $this->error('Failed to set commands: ' . json_encode($data));
                return self::FAILURE;

            } catch (\Exception $e) {
                $this->error('Error calling Telegram API: ' . $e->getMessage());
                return self::FAILURE;
            }
        }
    }
