<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Tests\Integration;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpScoper\Config\Config;
use VeronaLabs\WpScoper\Prefixer;

class PrefixerTest extends TestCase
{
    private string $fixtureDir;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixtureDir = dirname(__DIR__) . '/fixtures/simple-project';
        $this->tempDir = sys_get_temp_dir() . '/wp-scoper-integration-' . uniqid();

        // Copy the entire fixture to temp so we don't modify fixtures
        $this->recursiveCopy($this->fixtureDir, $this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    public function testFullPrefixingWorkflow(): void
    {
        $config = Config::fromComposerJson($this->tempDir . '/composer.json');
        $messages = [];

        $prefixer = new Prefixer($config, function (string $msg) use (&$messages) {
            $messages[] = $msg;
        });

        $prefixer->run();

        // Verify target directory was created
        $targetDir = $this->tempDir . '/vendor-prefixed';
        $this->assertDirectoryExists($targetDir);

        // Verify autoloader was generated
        $this->assertFileExists($targetDir . '/autoload.php');
        $this->assertFileExists($targetDir . '/autoload-classmap.php');

        // Verify geoip2 files were copied and prefixed
        $readerFile = $targetDir . '/geoip2/geoip2/src/Database/Reader.php';
        $this->assertFileExists($readerFile);

        $readerContent = file_get_contents($readerFile);

        // Namespace should be prefixed
        $this->assertStringContainsString('namespace TestProject\\Deps\\GeoIp2\\Database;', $readerContent);

        // Use statements should be prefixed
        $this->assertStringContainsString('use TestProject\\Deps\\GeoIp2\\Exception\\AddressNotFoundException;', $readerContent);
        $this->assertStringContainsString('use TestProject\\Deps\\GeoIp2\\Model\\City;', $readerContent);

        // Cross-package reference should be prefixed (MaxMind\Db is a transitive dep)
        $this->assertStringContainsString('use TestProject\\Deps\\MaxMind\\Db\\Reader as DbReader;', $readerContent);

        // Property access should NOT be touched (Mozart bug fix!)
        $this->assertStringContainsString('$this->dbReader', $readerContent);
        $this->assertStringContainsString('$this->locales', $readerContent);

        // Variable names should NOT be touched
        $this->assertStringContainsString('private $dbReader;', $readerContent);
        $this->assertStringContainsString('private $locales;', $readerContent);
    }

    public function testTransitiveDependencyPrefixed(): void
    {
        $config = Config::fromComposerJson($this->tempDir . '/composer.json');
        $prefixer = new Prefixer($config);
        $prefixer->run();

        $targetDir = $this->tempDir . '/vendor-prefixed';

        // MaxMind\Db is a transitive dep of geoip2 and should be included
        $maxmindFile = $targetDir . '/maxmind-db/reader/src/MaxMind/Db/Reader.php';
        $this->assertFileExists($maxmindFile);

        $content = file_get_contents($maxmindFile);
        $this->assertStringContainsString('namespace TestProject\\Deps\\MaxMind\\Db;', $content);
    }

    public function testExcludedPackageNotCopied(): void
    {
        $config = Config::fromComposerJson($this->tempDir . '/composer.json');
        $prefixer = new Prefixer($config);
        $prefixer->run();

        $targetDir = $this->tempDir . '/vendor-prefixed';

        // psr/log is in exclude_packages
        $this->assertDirectoryDoesNotExist($targetDir . '/psr/log');
    }

    public function testExcludedPatternsSkipped(): void
    {
        $config = Config::fromComposerJson($this->tempDir . '/composer.json');
        $prefixer = new Prefixer($config);
        $prefixer->run();

        $targetDir = $this->tempDir . '/vendor-prefixed';

        // .md files should be excluded
        $this->assertFileDoesNotExist($targetDir . '/geoip2/geoip2/README.md');
    }

    public function testCallSitesUpdated(): void
    {
        $config = Config::fromComposerJson($this->tempDir . '/composer.json');
        $prefixer = new Prefixer($config);
        $prefixer->run();

        // src/MyPlugin.php should have updated use statements
        $pluginContent = file_get_contents($this->tempDir . '/src/MyPlugin.php');

        $this->assertStringContainsString('use TestProject\\Deps\\GeoIp2\\Database\\Reader;', $pluginContent);
        $this->assertStringContainsString('use TestProject\\Deps\\GeoIp2\\Model\\City;', $pluginContent);

        // Property access should still be untouched
        $this->assertStringContainsString('$this->reader', $pluginContent);
    }

    public function testClassmapAutoloaderContainsPrefixedClasses(): void
    {
        $config = Config::fromComposerJson($this->tempDir . '/composer.json');
        $prefixer = new Prefixer($config);
        $prefixer->run();

        $targetDir = $this->tempDir . '/vendor-prefixed';
        $classmap = require $targetDir . '/autoload-classmap.php';

        // Should contain prefixed class names
        $this->assertArrayHasKey('TestProject\\Deps\\GeoIp2\\Database\\Reader', $classmap);
        $this->assertArrayHasKey('TestProject\\Deps\\GeoIp2\\Model\\City', $classmap);
        $this->assertArrayHasKey('TestProject\\Deps\\MaxMind\\Db\\Reader', $classmap);
    }

    public function testOutputMessagesProduced(): void
    {
        $config = Config::fromComposerJson($this->tempDir . '/composer.json');
        $messages = [];

        $prefixer = new Prefixer($config, function (string $msg) use (&$messages) {
            $messages[] = $msg;
        });

        $prefixer->run();

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('Starting', $messages[0]);
        $this->assertStringContainsString('Done', end($messages));
    }

    private function recursiveCopy(string $source, string $dest): void
    {
        mkdir($dest, 0777, true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $dest . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0777, true);
                }
            } else {
                copy($item->getRealPath(), $target);
            }
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($dir);
    }
}
