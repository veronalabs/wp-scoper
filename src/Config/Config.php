<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Config;

use InvalidArgumentException;

class Config
{
    /** @var string */
    private $namespacePrefix;

    /** @var array<string> */
    private $packages;

    /** @var string */
    private $targetDirectory;

    /** @var string */
    private $classPrefix;

    /** @var string */
    private $constantPrefix;

    /** @var array<string> */
    private $excludePackages;

    /** @var array<string> */
    private $excludePatterns;

    /** @var array<string> */
    private $excludeDirectories;

    /** @var bool */
    private $deleteVendorPackages;

    /** @var bool */
    private $updateCallSites;

    /** @var DevConfig|null */
    private $devPackages;

    /** @var string */
    private $workingDirectory;

    private function __construct(array $config, string $workingDirectory)
    {
        $this->workingDirectory = $workingDirectory;

        if (empty($config['namespace_prefix'])) {
            throw new InvalidArgumentException('wp-scoper: "namespace_prefix" is required in extra.wp-scoper config.');
        }

        if (empty($config['packages'])) {
            throw new InvalidArgumentException('wp-scoper: "packages" is required in extra.wp-scoper config.');
        }

        $this->namespacePrefix = rtrim($config['namespace_prefix'], '\\');
        $this->packages = $config['packages'];
        $this->targetDirectory = $config['target_directory'] ?? 'vendor-prefixed';
        $this->classPrefix = $config['class_prefix'] ?? self::deriveClassPrefix($this->namespacePrefix);
        $this->constantPrefix = $config['constant_prefix'] ?? self::deriveConstantPrefix($this->namespacePrefix);
        $this->excludePackages = $config['exclude_packages'] ?? [];
        $this->excludePatterns = $config['exclude_patterns'] ?? ['/\\.md$/'];
        $this->excludeDirectories = $config['exclude_directories'] ?? ['views', 'templates', 'resources'];
        $this->deleteVendorPackages = $config['delete_vendor_packages'] ?? false;
        $this->updateCallSites = $config['update_call_sites'] ?? true;
        $this->devPackages = isset($config['dev_packages'])
            ? DevConfig::fromArray($config['dev_packages'])
            : null;
    }

    public static function fromComposerJson(string $composerJsonPath): self
    {
        if (!file_exists($composerJsonPath)) {
            throw new InvalidArgumentException("composer.json not found at: {$composerJsonPath}");
        }

        $json = json_decode(file_get_contents($composerJsonPath), true);
        if ($json === null) {
            throw new InvalidArgumentException('Invalid JSON in composer.json');
        }

        $config = $json['extra']['wp-scoper'] ?? null;
        if ($config === null) {
            throw new InvalidArgumentException('No "extra.wp-scoper" configuration found in composer.json');
        }

        return new self($config, dirname(realpath($composerJsonPath)));
    }

    public static function fromArray(array $config, string $workingDirectory = '.'): self
    {
        return new self($config, $workingDirectory);
    }

    public static function deriveClassPrefix(string $namespacePrefix): string
    {
        return str_replace('\\', '', $namespacePrefix) . '_';
    }

    public static function deriveConstantPrefix(string $namespacePrefix): string
    {
        $parts = explode('\\', $namespacePrefix);
        $result = [];
        foreach ($parts as $part) {
            // Convert CamelCase to UPPER_SNAKE_CASE
            $snake = preg_replace('/([a-z])([A-Z])/', '$1_$2', $part);
            $result[] = strtoupper($snake);
        }

        return implode('_', $result) . '_';
    }

    public function getNamespacePrefix(): string
    {
        return $this->namespacePrefix;
    }

    /** @return array<string> */
    public function getPackages(): array
    {
        return $this->packages;
    }

    public function getTargetDirectory(): string
    {
        return $this->targetDirectory;
    }

    public function getAbsoluteTargetDirectory(): string
    {
        if (strpos($this->targetDirectory, '/') === 0 || strpos($this->targetDirectory, '\\') === 0) {
            return $this->targetDirectory;
        }

        return $this->workingDirectory . DIRECTORY_SEPARATOR . $this->targetDirectory;
    }

    public function getClassPrefix(): string
    {
        return $this->classPrefix;
    }

    public function getConstantPrefix(): string
    {
        return $this->constantPrefix;
    }

    /** @return array<string> */
    public function getExcludePackages(): array
    {
        return $this->excludePackages;
    }

    /** @return array<string> */
    public function getExcludePatterns(): array
    {
        return $this->excludePatterns;
    }

    /** @return array<string> */
    public function getExcludeDirectories(): array
    {
        return $this->excludeDirectories;
    }

    public function shouldDeleteVendorPackages(): bool
    {
        return $this->deleteVendorPackages;
    }

    public function shouldUpdateCallSites(): bool
    {
        return $this->updateCallSites;
    }

    public function getDevPackages(): ?DevConfig
    {
        return $this->devPackages;
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function getVendorDirectory(): string
    {
        return $this->workingDirectory . DIRECTORY_SEPARATOR . 'vendor';
    }
}
