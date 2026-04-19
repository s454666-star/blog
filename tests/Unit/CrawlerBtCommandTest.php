<?php

namespace Tests\Unit;

use App\Console\Commands\CrawlerBtCommand;
use App\Http\Controllers\GetBtDataController;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class CrawlerBtCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'bt.crawler_enabled' => true,
            'bt.run_lock_seconds' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_skips_when_bt_crawler_is_disabled(): void
    {
        config(['bt.crawler_enabled' => false]);

        $controller = Mockery::mock(GetBtDataController::class);
        $controller->shouldNotReceive('fetchData');

        $command = new CrawlerBtCommand();

        $this->assertSame(CrawlerBtCommand::SUCCESS, $command->handle($controller));
    }

    public function test_it_skips_when_another_run_holds_the_redis_lock(): void
    {
        $controller = Mockery::mock(GetBtDataController::class);
        $controller->shouldNotReceive('fetchData');

        $lock = Cache::lock($this->runLockKey(), 60);
        $this->assertTrue($lock->get());

        try {
            $command = new CrawlerBtCommand();

            $this->assertSame(CrawlerBtCommand::SUCCESS, $command->handle($controller));
        } finally {
            $lock->release();
        }
    }

    public function test_it_fetches_bt_data_when_the_lock_is_available(): void
    {
        $controller = Mockery::mock(GetBtDataController::class);
        $controller->shouldReceive('fetchData')->once();

        $command = new CrawlerBtCommand();

        $this->assertSame(CrawlerBtCommand::SUCCESS, $command->handle($controller));
    }

    private function runLockKey(): string
    {
        $reflection = new ReflectionClass(CrawlerBtCommand::class);

        return (string) $reflection->getConstant('RUN_LOCK_KEY');
    }
}
