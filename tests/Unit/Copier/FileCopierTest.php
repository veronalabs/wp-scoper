<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Tests\Unit\Copier;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpScoper\Config\Package;
use VeronaLabs\WpScoper\Copier\FileCopier;

class FileCopierTest extends TestCase
{
    private string $fixtureVendor;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->fixtureVendor = dirname(__DIR__, 2) . '/fixtures/simple-project/vendor';
        $this->tempDir = sys_get_temp_dir() . '/wp-scoper-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    public function testCopiesPackageFiles(): void
    {
        $package = new Package(
            'geoip2/geoip2',
            $this->fixtureVendor . '/geoip2/geoip2',
            ['GeoIp2\\' => 'src/']
        );

        $copier = new FileCopier();
        $result = $copier->copyPackage($package, $this->tempDir);

        $this->assertNotEmpty($result['php_files']);
        $this->assertFileExists($this->tempDir . '/geoip2/geoip2/src/Database/Reader.php');
        $this->assertFileExists($this->tempDir . '/geoip2/geoip2/src/Model/City.php');
    }

    public function testExcludesPatterns(): void
    {
        $package = new Package(
            'geoip2/geoip2',
            $this->fixtureVendor . '/geoip2/geoip2',
            ['GeoIp2\\' => 'src/']
        );

        $copier = new FileCopier(['/\\.md$/']);
        $copier->copyPackage($package, $this->tempDir);

        $this->assertFileDoesNotExist($this->tempDir . '/geoip2/geoip2/README.md');
    }

    public function testDetectsTemplateDirectories(): void
    {
        $copier = new FileCopier([], ['views', 'templates']);

        // A simple PHP file (no namespace/class) in a template directory is a template
        $templateFile = $this->tempDir . '/template.php';
        file_put_contents($templateFile, '<div><?php echo $name; ?></div>');

        $this->assertTrue($copier->isTemplateFile($templateFile, 'views/template.php'));
        $this->assertTrue($copier->isTemplateFile($templateFile, 'some/views/page.php'));
    }

    public function testPhpClassInTemplateDirectoryNotDetectedAsTemplate(): void
    {
        $copier = new FileCopier([], ['views', 'templates']);

        // A PHP class file in a directory named "templates" is NOT a template
        $classFile = $this->tempDir . '/ServiceProvider.php';
        file_put_contents($classFile, "<?php\n\nnamespace Rabbit\\Templates;\n\nclass ServiceProvider {}\n");

        $this->assertFalse($copier->isTemplateFile($classFile, 'templates/ServiceProvider.php'));
    }

    public function testDetectsTemplateByContent(): void
    {
        $copier = new FileCopier();

        // File starting with HTML
        $templateFile = dirname(__DIR__, 2) . '/fixtures/simple-project/views/template.php';
        $this->assertTrue($copier->isTemplateFile($templateFile, 'other/template.php'));
    }

    public function testPhpFileNotDetectedAsTemplate(): void
    {
        $copier = new FileCopier();

        $phpFile = $this->fixtureVendor . '/geoip2/geoip2/src/Database/Reader.php';
        $this->assertFalse($copier->isTemplateFile($phpFile, 'src/Database/Reader.php'));
    }

    public function testCleanTarget(): void
    {
        $testDir = $this->tempDir . '/clean-test';
        mkdir($testDir, 0777, true);
        file_put_contents($testDir . '/old-file.php', '<?php // old');

        $copier = new FileCopier();
        $copier->cleanTarget($testDir);

        $this->assertDirectoryExists($testDir);
        $this->assertFileDoesNotExist($testDir . '/old-file.php');
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
