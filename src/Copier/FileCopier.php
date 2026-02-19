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
     * Delete original packages from vendor directory.
     *
     * @param array<Package> $packages
     */
    public function deleteVendorPackages(array $packages): void
    {
        foreach ($packages as $package) {
            if (is_dir($package->getPath())) {
                $this->filesystem->remove($package->getPath());
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
        // Check if the file is in a template directory
        $parts = explode('/', str_replace('\\', '/', $relativePath));
        foreach ($parts as $part) {
            if (in_array(strtolower($part), array_map('strtolower', $this->templateDirectories), true)) {
                return true;
            }
        }

        $content = file_get_contents($filePath);
        if ($content === false || $content === '') {
            return false;
        }

        $trimmed = ltrim($content);

        // Files starting with <?php that have a namespace, class, interface, trait,
        // or enum declaration are never templates. This prevents false positives
        // from HTML-like tags inside PHPDoc comments and strings.
        if (str_starts_with($trimmed, '<?php') &&
            preg_match('/^\s*(?:namespace|(?:abstract\s+|final\s+)?class|interface|trait|enum)\s+[A-Za-z]/m', $content)
        ) {
            return false;
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
