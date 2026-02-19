<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper;

use Symfony\Component\Finder\Finder;
use VeronaLabs\WpScoper\Config\Config;
use VeronaLabs\WpScoper\Config\Package;
use VeronaLabs\WpScoper\Copier\FileCopier;
use VeronaLabs\WpScoper\Finder\PackageFinder;
use VeronaLabs\WpScoper\Generator\AutoloadGenerator;
use VeronaLabs\WpScoper\Replacer\ClassmapReplacer;
use VeronaLabs\WpScoper\Replacer\ConstantReplacer;
use VeronaLabs\WpScoper\Replacer\NamespaceReplacer;

class Prefixer
{
    /** @var Config */
    private $config;

    /** @var callable|null Output callback for logging */
    private $output;

    /** @var array<string, mixed> Stats collected during prefixing */
    private $stats = [];

    public function __construct(Config $config, ?callable $output = null)
    {
        $this->config = $config;
        $this->output = $output;
    }

    /** @return array<string, mixed> */
    public function getStats(): array
    {
        return $this->stats;
    }

    public function run(): void
    {
        $this->log('Starting wp-scoper...');

        $this->stats = [
            'packages' => 0,
            'php_files' => 0,
            'template_files' => 0,
            'excluded_files' => 0,
            'original_size' => 0,
            'total_size' => 0,
            'namespaces' => 0,
            'global_classes' => 0,
            'constants' => 0,
            'call_sites_updated' => 0,
            'target_directory' => $this->config->getTargetDirectory(),
        ];

        if (empty($this->config->getPackages())) {
            $this->log('No packages configured.');

            // Still generate autoloader if host project has PSR-4 mappings
            if (!empty($this->config->getHostAutoloadPsr4())) {
                $targetDir = $this->config->getAbsoluteTargetDirectory();
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }
                $this->log('Generating autoloader for host project...');
                $generator = new AutoloadGenerator();
                $generator->generate(
                    $targetDir,
                    [],
                    $this->config->getHostAutoloadPsr4(),
                    $this->config->getWorkingDirectory()
                );
            }

            return;
        }

        // Step 1: Find packages (including transitive deps)
        $this->log('Finding packages...');
        $packageFinder = new PackageFinder($this->config->getVendorDirectory());
        $packages = $packageFinder->findPackages(
            $this->config->getPackages(),
            $this->config->getExcludePackages()
        );

        if (empty($packages)) {
            $this->log('No packages found. Make sure you have run "composer install" first.');
            return;
        }

        $this->stats['packages'] = count($packages);

        $this->log(sprintf('Found %d package(s): %s', count($packages), implode(', ', array_map(
            function (Package $p): string { return $p->getName(); },
            $packages
        ))));

        // Step 2: Clean and copy files to target directory
        $targetDir = $this->config->getAbsoluteTargetDirectory();
        $copier = new FileCopier(
            $this->config->getExcludePatterns(),
            $this->config->getExcludeDirectories()
        );

        $this->log("Cleaning target directory: {$targetDir}");
        $copier->cleanTarget($targetDir);

        $allPhpFiles = [];
        $allTemplateFiles = [];
        $allFilesAutoload = [];
        $totalExcluded = 0;
        $originalSize = 0;
        $totalSize = 0;

        foreach ($packages as $package) {
            $this->log("Copying {$package->getName()}...");
            $result = $copier->copyPackage($package, $targetDir);
            $allPhpFiles = array_merge($allPhpFiles, $result['php_files']);
            $allTemplateFiles = array_merge($allTemplateFiles, $result['template_files']);
            $totalExcluded += $result['excluded_files'];
            $originalSize += $result['original_size'];
            $totalSize += $result['total_size'];

            // Collect files autoload entries (only for files that were actually copied)
            foreach ($package->getAutoloadFiles() as $file) {
                $filePath = $package->getName() . '/' . $file;
                if (file_exists($targetDir . '/' . $filePath)) {
                    $allFilesAutoload[] = $filePath;
                }
            }
        }

        $this->stats['php_files'] = count($allPhpFiles);
        $this->stats['template_files'] = count($allTemplateFiles);
        $this->stats['excluded_files'] = $totalExcluded;
        $this->stats['original_size'] = $originalSize;
        $this->stats['total_size'] = $totalSize;

        $this->log(sprintf(
            'Copied %d PHP file(s), %d template file(s) (skipped for prefixing)',
            count($allPhpFiles),
            count($allTemplateFiles)
        ));

        // Step 3: Collect namespaces from all packages
        $namespaces = [];
        foreach ($packages as $package) {
            foreach ($package->getNamespaces() as $ns) {
                $ns = rtrim($ns, '\\');
                if ($ns !== '' && !in_array($ns, $namespaces, true)) {
                    $namespaces[] = $ns;
                }
            }
        }

        $this->stats['namespaces'] = count($namespaces);

        $this->log(sprintf('Found %d namespace(s) to prefix', count($namespaces)));

        // Step 4: Discover global classes and constants
        $globalClasses = ClassmapReplacer::findGlobalClasses($allPhpFiles);
        $constants = ConstantReplacer::findConstants($allPhpFiles);

        $this->stats['global_classes'] = count($globalClasses);
        $this->stats['constants'] = count($constants);

        $this->log(sprintf(
            'Found %d global class(es) and %d constant(s)',
            count($globalClasses),
            count($constants)
        ));

        // Step 5: Apply replacements to copied files
        $namespaceReplacer = new NamespaceReplacer($this->config->getNamespacePrefix(), $namespaces);
        $classmapReplacer = !empty($globalClasses)
            ? new ClassmapReplacer($this->config->getClassPrefix(), $globalClasses)
            : null;
        $constantReplacer = !empty($constants)
            ? new ConstantReplacer($this->config->getConstantPrefix(), $constants)
            : null;

        $this->log('Applying namespace prefixes...');
        foreach ($allPhpFiles as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            $original = $contents;

            // Apply replacements in order: namespaces first, then classes, then constants
            $contents = $namespaceReplacer->replace($contents);

            if ($classmapReplacer !== null) {
                $contents = $classmapReplacer->replace($contents);
            }

            if ($constantReplacer !== null) {
                $contents = $constantReplacer->replace($contents);
            }

            if ($contents !== $original) {
                file_put_contents($file, $contents);
            }
        }

        // Step 6: Update host project source files (call sites)
        if ($this->config->shouldUpdateCallSites()) {
            $this->updateCallSites($namespaceReplacer, $classmapReplacer, $constantReplacer);
        }

        // Step 7: Generate autoloader
        $this->log('Generating autoloader...');
        $generator = new AutoloadGenerator();
        $generator->generate(
            $targetDir,
            $allFilesAutoload,
            $this->config->getHostAutoloadPsr4(),
            $this->config->getWorkingDirectory()
        );

        // Step 8: Handle dev packages if configured
        $devConfig = $this->config->getDevPackages();
        if ($devConfig !== null && $devConfig->isEnabled() && !empty($devConfig->getPackages())) {
            $this->runDevPackages($packageFinder, $devConfig, $namespaces);
        }

        // Step 9: Optionally delete original vendor packages
        if ($this->config->shouldDeleteVendorPackages()) {
            $this->log('Deleting original vendor packages...');
            $copier->deleteVendorPackages($packages);
        }

        $this->log('Done!');
    }

    private function updateCallSites(
        NamespaceReplacer $namespaceReplacer,
        ?ClassmapReplacer $classmapReplacer,
        ?ConstantReplacer $constantReplacer
    ): void {
        $this->log('Updating call sites in host project...');

        $workingDir = $this->config->getWorkingDirectory();
        $dirs = [];
        foreach ($this->config->getCallSiteDirectories() as $dir) {
            $absDir = $workingDir . '/' . $dir;
            if (is_dir($absDir)) {
                $dirs[] = $absDir;
            }
        }

        if (empty($dirs)) {
            $this->log('No call site directories found, skipping call site updates.');
            return;
        }

        $finder = new Finder();
        $finder->files()->name('*.php')->in($dirs);

        // Always exclude vendor directory from call site updates
        $finder->exclude('vendor');

        // Exclude the target directory if it's inside any scanned directory
        $targetDir = $this->config->getAbsoluteTargetDirectory();
        foreach ($dirs as $dir) {
            if (strpos($targetDir, $dir) === 0) {
                $relativeTarget = substr($targetDir, strlen($dir) + 1);
                $finder->exclude($relativeTarget);
            }
        }

        $count = 0;
        foreach ($finder as $file) {
            $contents = file_get_contents($file->getRealPath());
            if ($contents === false) {
                continue;
            }

            $original = $contents;

            $contents = $namespaceReplacer->replace($contents);

            if ($classmapReplacer !== null) {
                $contents = $classmapReplacer->replace($contents);
            }

            if ($constantReplacer !== null) {
                $contents = $constantReplacer->replace($contents);
            }

            if ($contents !== $original) {
                file_put_contents($file->getRealPath(), $contents);
                $count++;
            }
        }

        $this->stats['call_sites_updated'] = $count;

        $this->log(sprintf('Updated %d source file(s)', $count));
    }

    private function runDevPackages(
        PackageFinder $packageFinder,
        \VeronaLabs\WpScoper\Config\DevConfig $devConfig,
        array $existingNamespaces
    ): void {
        $this->log('Processing dev packages...');

        $devPackages = $packageFinder->findPackages(
            $devConfig->getPackages(),
            $this->config->getExcludePackages()
        );

        if (empty($devPackages)) {
            $this->log('No dev packages found.');
            return;
        }

        $targetDir = $this->config->getWorkingDirectory() . '/' . $devConfig->getTargetDirectory();
        $copier = new FileCopier(
            $this->config->getExcludePatterns(),
            $this->config->getExcludeDirectories()
        );

        $copier->cleanTarget($targetDir);

        $devPhpFiles = [];
        $devFilesAutoload = [];

        foreach ($devPackages as $package) {
            $this->log("Copying dev package: {$package->getName()}...");
            $result = $copier->copyPackage($package, $targetDir);
            $devPhpFiles = array_merge($devPhpFiles, $result['php_files']);

            foreach ($package->getAutoloadFiles() as $file) {
                $devFilesAutoload[] = $package->getName() . '/' . $file;
            }
        }

        // Collect dev namespaces
        $devNamespaces = [];
        foreach ($devPackages as $package) {
            foreach ($package->getNamespaces() as $ns) {
                $ns = rtrim($ns, '\\');
                if ($ns !== '' && !in_array($ns, $devNamespaces, true)) {
                    $devNamespaces[] = $ns;
                }
            }
        }

        $allNamespaces = array_unique(array_merge($existingNamespaces, $devNamespaces));

        $namespaceReplacer = new NamespaceReplacer($this->config->getNamespacePrefix(), $allNamespaces);
        $globalClasses = ClassmapReplacer::findGlobalClasses($devPhpFiles);
        $constants = ConstantReplacer::findConstants($devPhpFiles);

        $classmapReplacer = !empty($globalClasses)
            ? new ClassmapReplacer($this->config->getClassPrefix(), $globalClasses)
            : null;
        $constantReplacer = !empty($constants)
            ? new ConstantReplacer($this->config->getConstantPrefix(), $constants)
            : null;

        foreach ($devPhpFiles as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            $original = $contents;
            $contents = $namespaceReplacer->replace($contents);

            if ($classmapReplacer !== null) {
                $contents = $classmapReplacer->replace($contents);
            }

            if ($constantReplacer !== null) {
                $contents = $constantReplacer->replace($contents);
            }

            if ($contents !== $original) {
                file_put_contents($file, $contents);
            }
        }

        $generator = new AutoloadGenerator();
        $generator->generate($targetDir, $devFilesAutoload);

        $this->log(sprintf('Processed %d dev PHP file(s)', count($devPhpFiles)));
    }

    /**
     * Format stats as a summary table.
     *
     * @return array<string> Lines of the formatted table
     */
    public static function formatSummaryTable(array $stats): array
    {
        $rows = [
            ['Packages', (string) ($stats['packages'] ?? 0)],
            ['PHP Files Prefixed', (string) ($stats['php_files'] ?? 0)],
            ['Files Excluded', (string) ($stats['excluded_files'] ?? 0)],
            ['Namespaces Prefixed', (string) ($stats['namespaces'] ?? 0)],
            ['Global Classes', (string) ($stats['global_classes'] ?? 0)],
            ['Constants', (string) ($stats['constants'] ?? 0)],
            ['Call Sites Updated', (string) ($stats['call_sites_updated'] ?? 0)],
            ['Output Size', self::formatSizeWithReduction((int) ($stats['original_size'] ?? 0), (int) ($stats['total_size'] ?? 0))],
            ['Target Directory', $stats['target_directory'] ?? '-'],
        ];

        $slogan = 'WP Scoper - Your dependencies, your namespace!';
        $sloganLen = strlen($slogan);

        // Calculate column widths
        $labelWidth = 0;
        $valueWidth = 0;
        foreach ($rows as $row) {
            $labelWidth = max($labelWidth, strlen($row[0]));
            $valueWidth = max($valueWidth, strlen($row[1]));
        }

        // Ensure total width accommodates the slogan
        $innerWidth = $labelWidth + 3 + $valueWidth; // 3 = " | "
        if ($innerWidth < $sloganLen) {
            $valueWidth += $sloganLen - $innerWidth;
            $innerWidth = $sloganLen;
        }

        $border = '+' . str_repeat('-', $innerWidth + 2) . '+';
        $divider = '+' . str_repeat('-', $labelWidth + 2) . '+' . str_repeat('-', $innerWidth - $labelWidth - 1) . '+';

        $lines = [];
        $lines[] = $border;
        $lines[] = '| ' . str_pad($slogan, $innerWidth) . ' |';
        $lines[] = $divider;

        foreach ($rows as $row) {
            $lines[] = '| ' . str_pad($row[0], $labelWidth) . ' | ' . str_pad($row[1], $innerWidth - $labelWidth - 3) . ' |';
        }

        $lines[] = $border;

        return $lines;
    }

    private static function formatSizeWithReduction(int $original, int $output): string
    {
        if ($original === 0) {
            return self::formatBytes($output);
        }

        $reduction = (int) round((1 - $output / $original) * 100);

        return self::formatBytes($output) . ' / ' . self::formatBytes($original) . ' (-' . $reduction . '%)';
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1) . ' ' . $units[$i];
    }

    private function log(string $message): void
    {
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }
}
