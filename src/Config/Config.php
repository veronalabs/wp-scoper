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
        '/vendor\\/autoload\\.php$/',
        '/package\\.xml$/i',
        '/phpcs\\.xml/i',
        '/phpstan\\.neon/i',
        '/psalm\\.xml/i',
        '/\\.phpunit/i',
        '/\\.editorconfig$/',
        '/\\.gitignore$/',
        '/(?:^|\\/)\\.github\\//i',
        '/(?:^|\\/)\\.gitlab\\//i',
        '/(?:^|\\/)examples?\\//i',
        '/(?:^|\\/)ext\\//i',
        '/(?:^|\\/)php4\\//i',
        '/(?:^|\\/)tests?\\//i',
        '/\\bbin\\//i',
        '/(?:^|\\/)dev-bin\\//i',
        '/Makefile$/',
        '/phpunit\\.xml(\\.dist)?$/i',
        '/\\.travis\\.yml$/',
        '/Dockerfile/i',
        '/docker-compose/i',
        '/COPYING$/i',
        '/\\.rst$/i',
        '/sonar-project\\.properties$/i',
        '/phpdox\\.xml$/i',
        '/\\bdocs?\\//i',
        '/renovate\\.json$/i',
        '/psalm-baseline\\.xml$/i',
        '/\\.neon$/i',
        '/\\.xsd$/i',
        '/\\.legacy\\.php$/i',
        '/\\.pem$/i',
        '/\\.crt$/i',
        '/\\.cer$/i',
        '/\\.key$/i',
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

    /** @var array<string> Directories to scan for call site updates, empty = disabled */
    private $callSiteDirectories;

    /** @var DevConfig|null */
    private $devPackages;

    /** @var bool Whether to apply PHP cross-version compatibility fixers to scoped code */
    private $phpCompat;

    /** @var string|null Raw PHP version constraint of the host project (platform.php or require.php) */
    private $phpConstraint;

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
        $updateCallSites = $config['update_call_sites'] ?? true;
        if (is_array($updateCallSites)) {
            $this->callSiteDirectories = $updateCallSites;
        } elseif ($updateCallSites) {
            $this->callSiteDirectories = ['src'];
        } else {
            $this->callSiteDirectories = [];
        }
        $this->devPackages = isset($config['dev_packages'])
            ? DevConfig::fromArray($config['dev_packages'])
            : null;
        $this->phpCompat = $config['php_compat'] ?? false;
        $this->phpConstraint = null;
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

        $profile = getenv('SCOPER_PROFILE');
        $config = self::applyProfile($config, $profile === false || $profile === '' ? null : $profile);

        $instance = new self($config, dirname(realpath($composerJsonPath)));

        // Read host project's PSR-4 autoload mappings
        if (isset($json['autoload']['psr-4'])) {
            $instance->hostAutoloadPsr4 = $json['autoload']['psr-4'];
        }

        // Detect the project's PHP floor for php_compat fixers. Prefer the
        // pinned platform version, fall back to the declared require constraint.
        $instance->phpConstraint = $json['config']['platform']['php']
            ?? $json['require']['php']
            ?? null;

        return $instance;
    }

    public static function fromArray(array $config, string $workingDirectory = '.', array $hostAutoloadPsr4 = [], ?string $profile = null, ?string $phpConstraint = null): self
    {
        if ($profile !== null) {
            $config = self::applyProfile($config, $profile);
        }
        $instance = new self($config, $workingDirectory);
        $instance->hostAutoloadPsr4 = $hostAutoloadPsr4;
        $instance->phpConstraint = $phpConstraint;
        return $instance;
    }

    /**
     * Merge a named profile from `extra.wp-scoper.profiles.{name}` into the
     * base config. Used to derive different scope outputs (e.g. free vs
     * premium) from a single composer.json — the build script selects the
     * profile via the SCOPER_PROFILE environment variable.
     *
     * Merge semantics:
     * - `packages` (and `dev_packages.packages`) are APPENDED to the base
     *   list and de-duplicated. This is the additive case: a premium profile
     *   adds the SDK on top of free's shared deps without restating them.
     * - All other keys (namespace_prefix, target_directory, class_prefix,
     *   exclude_patterns, etc.) REPLACE the base value when present in the
     *   profile.
     *
     * @param array<string, mixed> $config Raw extra.wp-scoper config
     * @param string|null $profileName Profile name to apply, or null for base
     * @return array<string, mixed>
     */
    public static function applyProfile(array $config, ?string $profileName): array
    {
        $profiles = $config['profiles'] ?? [];
        unset($config['profiles']);

        if ($profileName === null) {
            return $config;
        }

        if (!isset($profiles[$profileName])) {
            throw new InvalidArgumentException(sprintf(
                'wp-scoper: SCOPER_PROFILE="%s" but no matching entry in extra.wp-scoper.profiles. Defined profiles: %s',
                $profileName,
                empty($profiles) ? '(none)' : implode(', ', array_keys($profiles))
            ));
        }

        $profile = $profiles[$profileName];

        if (isset($profile['packages']) && is_array($profile['packages'])) {
            $config['packages'] = array_values(array_unique(array_merge(
                $config['packages'] ?? [],
                $profile['packages']
            )));
        }

        if (isset($profile['dev_packages']) && is_array($profile['dev_packages'])) {
            $base = $config['dev_packages'] ?? [];
            if (isset($profile['dev_packages']['packages']) && is_array($profile['dev_packages']['packages'])) {
                $base['packages'] = array_values(array_unique(array_merge(
                    $base['packages'] ?? [],
                    $profile['dev_packages']['packages']
                )));
            }
            foreach ($profile['dev_packages'] as $devKey => $devValue) {
                if ($devKey !== 'packages') {
                    $base[$devKey] = $devValue;
                }
            }
            $config['dev_packages'] = $base;
        }

        foreach ($profile as $key => $value) {
            if ($key === 'packages' || $key === 'dev_packages') {
                continue;
            }
            $config[$key] = $value;
        }

        return $config;
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
        return !empty($this->callSiteDirectories);
    }

    /** @return array<string> */
    public function getCallSiteDirectories(): array
    {
        return $this->callSiteDirectories;
    }

    public function getDevPackages(): ?DevConfig
    {
        return $this->devPackages;
    }

    /**
     * Whether PHP cross-version compatibility fixers (e.g. implicit-nullable
     * parameter normalization) should be applied to the scoped dependency
     * code. Opt-in via `extra.wp-scoper.php_compat: true`.
     */
    public function isPhpCompatEnabled(): bool
    {
        return $this->phpCompat;
    }

    /**
     * The host project's minimum supported PHP version as "X.Y", derived from
     * its composer.json (platform.php preferred, require.php fallback), or null
     * when no constraint is available. Used to gate php_compat fixers so that
     * rewritten syntax stays valid down to the declared floor.
     */
    public function getTargetPhpFloor(): ?string
    {
        return self::parsePhpFloor($this->phpConstraint);
    }

    /**
     * True when the detected PHP floor is >= $version ("X.Y"). False when the
     * floor is unknown — callers decide how to treat the unknown case.
     */
    public function targetPhpAtLeast(string $version): bool
    {
        $floor = $this->getTargetPhpFloor();
        if ($floor === null) {
            return false;
        }

        return self::versionKey($floor) >= self::versionKey($version);
    }

    /**
     * Extract the lowest "X.Y" PHP version satisfying a Composer constraint
     * string, without depending on composer/semver (the standalone CLI path
     * may not have it autoloaded). Handles forms like "8.0", ">=8.0", "^8.0",
     * "~8.1", "8.0.*" and unions such as "^7.2|^8.0" (whose floor is the lowest
     * branch). Returns null for empty/unparseable input.
     */
    public static function parsePhpFloor(?string $constraint): ?string
    {
        if ($constraint === null || trim($constraint) === '') {
            return null;
        }

        if (!preg_match_all('/(\d+)(?:\.(\d+))?(?:\.\d+)?/', $constraint, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $min = null;
        foreach ($matches as $set) {
            $major = (int) $set[1];
            $minor = isset($set[2]) && $set[2] !== '' ? (int) $set[2] : 0;
            $key = $major * 1000 + $minor;
            if ($min === null || $key < $min['key']) {
                $min = ['key' => $key, 'str' => $major . '.' . $minor];
            }
        }

        return $min['str'] ?? null;
    }

    private static function versionKey(string $version): int
    {
        $parts = explode('.', $version);
        $major = (int) ($parts[0] ?? 0);
        $minor = (int) ($parts[1] ?? 0);

        return $major * 1000 + $minor;
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
