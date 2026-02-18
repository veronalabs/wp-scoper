<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpScoper\Generator\AutoloadGenerator;

class AutoloadGeneratorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/wp-scoper-autoload-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/vendor/package/src', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    public function testGeneratesAutoloadFile(): void
    {
        file_put_contents($this->tempDir . '/vendor/package/src/MyClass.php', <<<'PHP'
<?php
namespace Vendor\Package;

class MyClass {
}
PHP
        );

        $generator = new AutoloadGenerator();
        $generator->generate($this->tempDir);

        $this->assertFileExists($this->tempDir . '/autoload.php');
        $this->assertFileExists($this->tempDir . '/autoload-classmap.php');
    }

    public function testClassmapContainsClasses(): void
    {
        file_put_contents($this->tempDir . '/vendor/package/src/MyClass.php', <<<'PHP'
<?php
namespace Vendor\Package;

class MyClass {
}
PHP
        );

        $generator = new AutoloadGenerator();
        $classmap = $generator->buildClassmap($this->tempDir);

        $this->assertArrayHasKey('Vendor\\Package\\MyClass', $classmap);
    }

    public function testClassmapHandlesGlobalClass(): void
    {
        file_put_contents($this->tempDir . '/vendor/package/src/GlobalHelper.php', <<<'PHP'
<?php

class GlobalHelper {
}
PHP
        );

        $generator = new AutoloadGenerator();
        $classmap = $generator->buildClassmap($this->tempDir);

        $this->assertArrayHasKey('GlobalHelper', $classmap);
    }

    public function testClassmapHandlesMultipleClassesInFile(): void
    {
        file_put_contents($this->tempDir . '/vendor/package/src/Multiple.php', <<<'PHP'
<?php
namespace Vendor\Package;

class First {
}

class Second {
}
PHP
        );

        $generator = new AutoloadGenerator();
        $classmap = $generator->buildClassmap($this->tempDir);

        $this->assertArrayHasKey('Vendor\\Package\\First', $classmap);
        $this->assertArrayHasKey('Vendor\\Package\\Second', $classmap);
    }

    public function testAutoloaderIncludesFilesAutoload(): void
    {
        file_put_contents($this->tempDir . '/vendor/package/src/MyClass.php', <<<'PHP'
<?php
namespace Vendor\Package;
class MyClass {}
PHP
        );

        $generator = new AutoloadGenerator();
        $generator->generate($this->tempDir, ['vendor/package/functions.php']);

        $autoloadContent = file_get_contents($this->tempDir . '/autoload.php');
        $this->assertStringContainsString("require_once __DIR__ . '/vendor/package/functions.php'", $autoloadContent);
    }

    public function testAutoloaderRegistersAutoloadFunction(): void
    {
        file_put_contents($this->tempDir . '/vendor/package/src/MyClass.php', <<<'PHP'
<?php
namespace Vendor\Package;
class MyClass {}
PHP
        );

        $generator = new AutoloadGenerator();
        $generator->generate($this->tempDir);

        $autoloadContent = file_get_contents($this->tempDir . '/autoload.php');
        $this->assertStringContainsString('spl_autoload_register', $autoloadContent);
        $this->assertStringContainsString('autoload-classmap.php', $autoloadContent);
    }

    public function testHandlesEmptyDirectory(): void
    {
        $generator = new AutoloadGenerator();
        $classmap = $generator->buildClassmap($this->tempDir);

        $this->assertEmpty($classmap);
    }

    private function recursiveDelete(string $dir): void
    {
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
