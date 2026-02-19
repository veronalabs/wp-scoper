<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Config;

use InvalidArgumentException;

class Config
{
    /** @var array<string> Built-in exclude patterns always applied */
    private const BUILT_IN_EXCLUDE_PATTERNS = [
        '/\\.md$/i',
        '/LICENSE(\\.txt)?$/i',
        '/CHANGELOG/i',
        '/UPGRADING/i',
        '/composer\\.json$/',
        '/composer\\.lock$/',
        '/autoload\\.php$/',
        '/package\\.xml$/i',
        '/phpcs\\.xml/i',
        '/phpstan\\.neon/i',
        '/psalm\\.xml/i',
        '/\\.phpunit/i',
        '/\\.editorconfig$/',
        '/\\.gitignore$/',
        '/\\.github\\//i',
        '/\\.gitlab\\//i',
        '/examples?\\//i',
        '/ext\\//i',
        '/php4\\//i',
        '/tests?\\//i',
        '/\\bbin\\//i',
        '/dev-bin\\//i',
        '/Makefile$/',
        '/phpunit\\.xml(\\.dist)?$/i',
        '/\\.travis\\.yml$/',
        '/Dockerfile$/i',
        '/docker-compose/i',
        '/COPYING$/i',
    ];

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

    /** @var array<string, string> PSR-4 mappings from the host project's autoload config */
    private $hostAutoloadPsr4;

    /** @var string */
    private $workingDirectory;

    private function __construct(array $config, string $workingDirectory)
    {
        $this->workingDirectory = $workingDirectory;

        if (empty($config['namespace_prefix'])) {
            throw new InvalidArgumentException('wp-scoper: "namespace_prefix" is required in extra.wp-scoper config.');
        }

        if (!isset($config['packages'])) {
            throw new InvalidArgumentException('wp-scoper: "packages" is required in extra.wp-scoper config.');
        }

        $this->namespacePrefix = rtrim($config['namespace_prefix'], '\\');
        $this->packages = $config['packages'];
        $this->targetDirectory = $config['target_directory'] ?? 'vendor-prefixed';
        $this->classPrefix = $config['class_prefix'] ?? self::deriveClassPrefix($this->namespacePrefix);
        $this->constantPrefix = $config['constant_prefix'] ?? self::deriveConstantPrefix($this->namespacePrefix);
        $this->excludePackages = $config['exclude_packages'] ?? [];
        $this->excludePatterns = $config['exclude_patterns'] ?? [];
        $this->excludeDirectories = $config['exclude_directories'] ?? ['views', 'templates', 'resources'];
        $this->deleteVendorPackages = $config['delete_vendor_packages'] ?? false;
        $this->updateCallSites = $config['update_call_sites'] ?? true;
        $this->devPackages = isset($config['dev_packages'])
            ? DevConfig::fromArray($config['dev_packages'])
            : null;
        $this->hostAutoloadPsr4 = [];
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

        $instance = new self($config, dirname(realpath($composerJsonPath)));

        // Read host project's PSR-4 autoload mappings
        if (isset($json['autoload']['psr-4'])) {
            $instance->hostAutoloadPsr4 = $json['autoload']['psr-4'];
        }

        return $instance;
    }

    public static function fromArray(array $config, string $workingDirectory = '.', array $hostAutoloadPsr4 = []): self
    {
        $instance = new self($config, $workingDirectory);
        $instance->hostAutoloadPsr4 = $hostAutoloadPsr4;
        return $instance;
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
        return array_unique(array_merge(self::BUILT_IN_EXCLUDE_PATTERNS, $this->excludePatterns));
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

    /** @return array<string, string> */
    public function getHostAutoloadPsr4(): array
    {
        return $this->hostAutoloadPsr4;
    }

    public function getVendorDirectory(): string
    {
        return $this->workingDirectory . DIRECTORY_SEPARATOR . 'vendor';
    }
}
