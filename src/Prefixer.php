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

    public function __construct(Config $config, ?callable $output = null)
    {
        $this->config = $config;
        $this->output = $output;
    }

    public function run(): void
    {
        $this->log('Starting wp-scoper...');

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

        foreach ($packages as $package) {
            $this->log("Copying {$package->getName()}...");
            $result = $copier->copyPackage($package, $targetDir);
            $allPhpFiles = array_merge($allPhpFiles, $result['php_files']);
            $allTemplateFiles = array_merge($allTemplateFiles, $result['template_files']);

            // Collect files autoload entries
            foreach ($package->getAutoloadFiles() as $file) {
                $allFilesAutoload[] = $package->getName() . '/' . $file;
            }
        }

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

        $this->log(sprintf('Found %d namespace(s) to prefix', count($namespaces)));

        // Step 4: Discover global classes and constants
        $globalClasses = ClassmapReplacer::findGlobalClasses($allPhpFiles);
        $constants = ConstantReplacer::findConstants($allPhpFiles);

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
        $generator->generate($targetDir, $allFilesAutoload);

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

        $srcDir = $this->config->getWorkingDirectory() . '/src';
        if (!is_dir($srcDir)) {
            $this->log('No src/ directory found, skipping call site updates.');
            return;
        }

        $finder = new Finder();
        $finder->files()->name('*.php')->in($srcDir);

        // Exclude the target directory if it's inside src/
        $targetDir = $this->config->getAbsoluteTargetDirectory();
        if (strpos($targetDir, $srcDir) === 0) {
            $relativeTarget = substr($targetDir, strlen($srcDir) + 1);
            $finder->exclude($relativeTarget);
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

    private function log(string $message): void
    {
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }
}
