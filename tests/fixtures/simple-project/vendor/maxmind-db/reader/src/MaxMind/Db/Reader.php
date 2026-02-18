<?php

namespace MaxMind\Db;

use MaxMind\Db\Reader\Decoder;

class Reader
{
    private $database;

    public function __construct(string $database)
    {
        $this->database = $database;
    }

    public function get(string $ipAddress): ?array
    {
        return ['city' => ['names' => ['en' => 'Test City']]];
    }

    public function close(): void
    {
    }
}
