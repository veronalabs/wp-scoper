<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Config;

class DevConfig
{
    /** @var bool */
    private $enabled;

    /** @var string */
    private $targetDirectory;

    /** @var array<string> */
    private $packages;

    public function __construct(bool $enabled, string $targetDirectory, array $packages)
    {
        $this->enabled = $enabled;
        $this->targetDirectory = $targetDirectory;
        $this->packages = $packages;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['enabled'] ?? false,
            $data['target_directory'] ?? 'tests/vendor-prefixed',
            $data['packages'] ?? []
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getTargetDirectory(): string
    {
        return $this->targetDirectory;
    }

    /** @return array<string> */
    public function getPackages(): array
    {
        return $this->packages;
    }
}
