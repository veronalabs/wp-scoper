<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use VeronaLabs\WpScoper\Command\PrefixCommand;

class CommandProvider implements CommandProviderCapability
{
    public function getCommands(): array
    {
        return [
            new PrefixCommand(),
        ];
    }
}
