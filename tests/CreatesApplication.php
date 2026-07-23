<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $isMemorySqlite = $connection === 'sqlite' && $database === ':memory:';
        $isNamedTestDatabase = app()->environment('testing')
            && preg_match('/(?:^|[_-])(test|testing)(?:$|[_-])/i', $database) === 1;

        if (!$isMemorySqlite && !$isNamedTestDatabase) {
            throw new RuntimeException(
                "Refusing to run tests against non-test database [{$connection}:{$database}]."
            );
        }

        return $app;
    }
}
