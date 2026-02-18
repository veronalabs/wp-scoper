<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Finder;

use InvalidArgumentException;
use VeronaLabs\WpScoper\Config\Package;

class PackageFinder
{
    /** @var string */
    private $vendorDirectory;

    /** @var array<string, array> Cached installed.json data keyed by package name */
    private $installedPackages;

    public function __construct(string $vendorDirectory)
    {
        $this->vendorDirectory = $vendorDirectory;
    }

    /**
     * Find packages and their transitive dependencies.
     *
     * @param array<string> $packageNames
     * @param array<string> $excludePackages
     * @return array<Package>
     */
    public function findPackages(array $packageNames, array $excludePackages = []): array
    {
        $this->loadInstalledJson();

        $resolved = [];
        $queue = $packageNames;

        while (!empty($queue)) {
            $name = array_shift($queue);

            if (isset($resolved[$name])) {
                continue;
            }

            if (in_array($name, $excludePackages, true)) {
                continue;
            }

            $packageData = $this->installedPackages[$name] ?? null;
            if ($packageData === null) {
                continue;
            }

            $package = $this->buildPackage($name, $packageData);
            $resolved[$name] = $package;

            // Add transitive dependencies to queue
            foreach ($package->getDependencies() as $dep) {
                if (!isset($resolved[$dep]) && !in_array($dep, $excludePackages, true)) {
                    $queue[] = $dep;
                }
            }
        }

        return array_values($resolved);
    }

    private function loadInstalledJson(): void
    {
        if ($this->installedPackages !== null) {
            return;
        }

        $installedJsonPath = $this->vendorDirectory . '/composer/installed.json';
        if (!file_exists($installedJsonPath)) {
            throw new InvalidArgumentException(
                "Could not find installed.json at: {$installedJsonPath}. Run 'composer install' first."
            );
        }

        $json = json_decode(file_get_contents($installedJsonPath), true);
        if ($json === null) {
            throw new InvalidArgumentException('Invalid JSON in installed.json');
        }

        // Composer v2 wraps packages in a "packages" key
        $packages = $json['packages'] ?? $json;

        $this->installedPackages = [];
        foreach ($packages as $packageData) {
            $name = $packageData['name'] ?? null;
            if ($name !== null) {
                $this->installedPackages[$name] = $packageData;
            }
        }
    }

    private function buildPackage(string $name, array $data): Package
    {
        $installPath = $data['install-path'] ?? null;
        if ($installPath !== null) {
            // install-path is relative to vendor/composer/
            $path = realpath($this->vendorDirectory . '/composer/' . $installPath);
        } else {
            $path = $this->vendorDirectory . '/' . $name;
        }

        if (!$path || !is_dir($path)) {
            $path = $this->vendorDirectory . '/' . $name;
        }

        $autoload = $data['autoload'] ?? [];
        $psr4 = [];
        $classmap = [];
        $files = [];

        if (isset($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $namespace => $dirs) {
                $dirs = (array)$dirs;
                foreach ($dirs as $dir) {
                    $psr4[$namespace] = $dir;
                }
            }
        }

        if (isset($autoload['psr-0'])) {
            // Convert PSR-0 to rough PSR-4 equivalent for namespace detection
            foreach ($autoload['psr-0'] as $namespace => $dirs) {
                $dirs = (array)$dirs;
                foreach ($dirs as $dir) {
                    $psr4[$namespace] = $dir;
                }
            }
        }

        if (isset($autoload['classmap'])) {
            $classmap = (array)$autoload['classmap'];
        }

        if (isset($autoload['files'])) {
            $files = (array)$autoload['files'];
        }

        // Extract dependencies (only non-platform packages)
        $dependencies = [];
        $require = $data['require'] ?? [];
        foreach (array_keys($require) as $dep) {
            if (!$this->isPlatformPackage($dep)) {
                $dependencies[] = $dep;
            }
        }

        return new Package($name, $path, $psr4, $classmap, $files, $dependencies);
    }

    private function isPlatformPackage(string $name): bool
    {
        return $name === 'php'
            || strpos($name, 'ext-') === 0
            || strpos($name, 'lib-') === 0
            || $name === 'composer-plugin-api';
    }
}
