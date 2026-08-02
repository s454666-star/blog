<?php

namespace App\Contracts;

interface RecycleBin
{
    /** @param array<int, string> $paths */
    public function move(array $paths): void;
}
