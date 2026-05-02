<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Copier;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use VeronaLabs\WpScoper\Config\Package;

class FileCopier
{
    /** @var Filesystem */
    private $filesystem;

    /** @var array<string> Regex patterns for files to skip entirely */
    private $excludePatterns;

    /** @var array<string> Directory names containing template files */
    private $templateDirectories;

    public function __construct(
        array $excludePatterns = [],
        array $templateDirectories = []
    ) {
        $this->filesystem = new Filesystem();
        $this->excludePatterns = $excludePatterns;
        $this->templateDirectories = $templateDirectories;
    }

    /**
     * Copy a package to the target directory.
     *
     * @return array{php_files: string[], template_files: string[], excluded_files: int, total_size: int, original_size: int}
     */
    public function copyPackage(Package $package, string $targetDirectory): array
    {
        $sourcePath = $package->getPath();
        $packageTarget = $targetDirectory . '/' . $package->getName();

        if (!is_dir($sourcePath)) {
            return ['php_files' => [], 'template_files' => [], 'excluded_files' => 0, 'total_size' => 0, 'original_size' => 0];
        }

        $phpFiles = [];
        $templateFiles = [];
        $excludedFiles = 0;
        $totalSize = 0;
        $originalSize = 0;

        $finder = new Finder();
        $finder->files()->in($sourcePath)->ignoreDotFiles(true)->ignoreVCS(true);

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $originalSize += $file->getSize();

            if ($this->shouldExclude($relativePath)) {
                $excludedFiles++;
                continue;
            }

            $targetPath = $packageTarget . '/' . $relativePath;
            $this->filesystem->mkdir(dirname($targetPath));
            $this->filesystem->copy($file->getRealPath(), $targetPath, true);
            $totalSize += $file->getSize();

            if ($file->getExtension() === 'php') {
                if ($this->isTemplateFile($file->getRealPath(), $relativePath)) {
                    $templateFiles[] = $targetPath;
                } else {
                    $phpFiles[] = $targetPath;
                }
            }
        }

        return ['php_files' => $phpFiles, 'template_files' => $templateFiles, 'excluded_files' => $excludedFiles, 'total_size' => $totalSize, 'original_size' => $originalSize];
    }

    /**
     * Clean the target directory before copying.
     */
    public function cleanTarget(string $targetDirectory): void
    {
        if (is_dir($targetDirectory)) {
            $this->filesystem->remove($targetDirectory);
        }
        $this->filesystem->mkdir($targetDirectory);
    }

    /**
     * Delete original packages from vendor directory and clean up the
     * generated Composer autoloader files so they no longer reference the
     * removed packages. Without this cleanup, eager `files` autoload entries
     * (typically Symfony polyfills, league/csv functions, etc.) would fatal
     * on autoloader boot with "file not found" errors after the vendor copy
     * is gone.
     *
     * @param array<Package> $packages
     */
    public function deleteVendorPackages(array $packages): void
    {
        $vendorDir = null;

        foreach ($packages as $package) {
            if (is_dir($package->getPath())) {
                if ($vendorDir === null) {
                    // Package paths look like {vendorDir}/{vendor}/{name}, so
                    // two levels up is always the vendor root.
                    $vendorDir = dirname($package->getPath(), 2);
                }

                $this->filesystem->remove($package->getPath());

                // Clean up empty parent org directory (e.g., vendor/geoip2/ after removing vendor/geoip2/geoip2/)
                $parentDir = dirname($package->getPath());
                if (is_dir($parentDir) && count(scandir($parentDir)) === 2) {
                    $this->filesystem->remove($parentDir);
                }
            }
        }

        if ($vendorDir !== null) {
            $this->cleanComposerAutoloadFiles($packages, $vendorDir);
        }
    }

    /**
     * Strip references to the given packages from Composer's generated
     * autoload_files.php and autoload_static.php so the autoloader doesn't
     * try to require files that have just been deleted.
     *
     * @param array<Package> $packages
     */
    private function cleanComposerAutoloadFiles(array $packages, string $vendorDir): void
    {
        $packageNames = array_map(static function (Package $p): string {
            return $p->getName();
        }, $packages);

        if (empty($packageNames)) {
            return;
        }

        $composerDir = $vendorDir . '/composer';

        foreach (['autoload_files.php', 'autoload_static.php'] as $filename) {
            $file = $composerDir . '/' . $filename;

            if (!is_file($file)) {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $original = $content;

            foreach ($packageNames as $packageName) {
                // Remove any line whose path segment is "/{vendor}/{package}/" or
                // "/{vendor}/{package}'" — covers both the array-syntax in
                // autoload_files.php (`$vendorDir . '/symfony/polyfill-mbstring/bootstrap.php'`)
                // and the static syntax in autoload_static.php
                // (`__DIR__ . '/..' . '/symfony/polyfill-mbstring/bootstrap.php'`).
                $pattern = '#^.*/' . preg_quote($packageName, '#') . "[/'].*\R?#m";
                $content = preg_replace($pattern, '', $content);
            }

            if ($content !== null && $content !== $original) {
                file_put_contents($file, $content);
            }
        }
    }

    private function shouldExclude(string $relativePath): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            if (preg_match($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect if a PHP file is a template (HTML mixed with PHP).
     */
    public function isTemplateFile(string $filePath, string $relativePath): bool
    {
        $content = file_get_contents($filePath);
        if ($content === false || $content === '') {
            return false;
        }

        $trimmed = ltrim($content);

        // Files starting with <?php that have a namespace, class, interface, trait,
        // or enum declaration are never templates, even if they live in a template
        // directory (e.g. Rabbit\Templates\Engine is a PHP class, not a view file).
        if (str_starts_with($trimmed, '<?php') &&
            preg_match('/^\s*(?:namespace|(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum))\s+[A-Za-z]/m', $content)
        ) {
            return false;
        }

        // Check if the file is in a template directory
        $parts = explode('/', str_replace('\\', '/', $relativePath));
        foreach ($parts as $part) {
            if (in_array(strtolower($part), array_map('strtolower', $this->templateDirectories), true)) {
                return true;
            }
        }

        // If file doesn't start with <?php, it's likely a template
        if (!str_starts_with($trimmed, '<?php')) {
            // Check if it contains HTML-like content
            if (preg_match('/<[a-zA-Z]/', $trimmed)) {
                return true;
            }
        }

        // Check HTML-to-PHP ratio: if there's significant HTML, it's a template
        $phpTagCount = substr_count($content, '<?php') + substr_count($content, '<?=');
        $htmlTagCount = preg_match_all('/<[a-zA-Z][^>]*>/', $content);

        if ($htmlTagCount > 5 && $htmlTagCount > $phpTagCount * 3) {
            return true;
        }

        return false;
    }
}
