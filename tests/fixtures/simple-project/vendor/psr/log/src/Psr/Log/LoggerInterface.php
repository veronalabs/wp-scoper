<?php

namespace Psr\Log;

interface LoggerInterface
{
    public function info(string $message, array $context = []): void;
}
