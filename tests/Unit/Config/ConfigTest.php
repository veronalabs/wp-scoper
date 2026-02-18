<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Tests\Unit\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VeronaLabs\WpScoper\Config\Config;

class ConfigTest extends TestCase
{
    public function testFromArrayWithValidConfig(): void
    {
        $config = Config::fromArray([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
        ], '/tmp');

        $this->assertSame('WP_Statistics\\Deps', $config->getNamespacePrefix());
        $this->assertSame(['geoip2/geoip2'], $config->getPackages());
        $this->assertSame('vendor-prefixed', $config->getTargetDirectory());
    }

    public function testFromArrayThrowsWithoutNamespacePrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('namespace_prefix');

        Config::fromArray([
            'packages' => ['geoip2/geoip2'],
        ], '/tmp');
    }

    public function testFromArrayThrowsWithoutPackages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('packages');

        Config::fromArray([
            'namespace_prefix' => 'WP_Statistics\\Deps',
        ], '/tmp');
    }

    public function testDefaultValues(): void
    {
        $config = Config::fromArray([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
        ], '/tmp');

        $this->assertSame('vendor-prefixed', $config->getTargetDirectory());
        $this->assertSame('WP_StatisticsDeps_', $config->getClassPrefix());
        $this->assertSame('WP_STATISTICS_DEPS_', $config->getConstantPrefix());
        $this->assertSame([], $config->getExcludePackages());
        $this->assertSame(['/\\.md$/'], $config->getExcludePatterns());
        $this->assertSame(['views', 'templates', 'resources'], $config->getExcludeDirectories());
        $this->assertFalse($config->shouldDeleteVendorPackages());
        $this->assertTrue($config->shouldUpdateCallSites());
        $this->assertNull($config->getDevPackages());
    }

    public function testCustomValues(): void
    {
        $config = Config::fromArray([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
            'target_directory' => 'src/Dependencies',
            'class_prefix' => 'WP_Stats_',
            'constant_prefix' => 'WPS_',
            'exclude_packages' => ['psr/log'],
            'exclude_patterns' => ['/tests/'],
            'exclude_directories' => ['views'],
            'delete_vendor_packages' => true,
            'update_call_sites' => false,
        ], '/tmp');

        $this->assertSame('src/Dependencies', $config->getTargetDirectory());
        $this->assertSame('WP_Stats_', $config->getClassPrefix());
        $this->assertSame('WPS_', $config->getConstantPrefix());
        $this->assertSame(['psr/log'], $config->getExcludePackages());
        $this->assertSame(['/tests/'], $config->getExcludePatterns());
        $this->assertSame(['views'], $config->getExcludeDirectories());
        $this->assertTrue($config->shouldDeleteVendorPackages());
        $this->assertFalse($config->shouldUpdateCallSites());
    }

    public function testDevPackagesConfig(): void
    {
        $config = Config::fromArray([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
            'dev_packages' => [
                'enabled' => true,
                'target_directory' => 'tests/vendor-prefixed',
                'packages' => ['fakerphp/faker'],
            ],
        ], '/tmp');

        $dev = $config->getDevPackages();
        $this->assertNotNull($dev);
        $this->assertTrue($dev->isEnabled());
        $this->assertSame('tests/vendor-prefixed', $dev->getTargetDirectory());
        $this->assertSame(['fakerphp/faker'], $dev->getPackages());
    }

    public function testDeriveClassPrefix(): void
    {
        $this->assertSame('WP_StatisticsDeps_', Config::deriveClassPrefix('WP_Statistics\\Deps'));
        $this->assertSame('MyPlugin_', Config::deriveClassPrefix('MyPlugin'));
        $this->assertSame('ABCDef_', Config::deriveClassPrefix('ABC\\Def'));
    }

    public function testDeriveConstantPrefix(): void
    {
        $this->assertSame('WP_STATISTICS_DEPS_', Config::deriveConstantPrefix('WP_Statistics\\Deps'));
        $this->assertSame('MY_PLUGIN_', Config::deriveConstantPrefix('MyPlugin'));
    }

    public function testTrailingBackslashStripped(): void
    {
        $config = Config::fromArray([
            'namespace_prefix' => 'WP_Statistics\\Deps\\',
            'packages' => ['geoip2/geoip2'],
        ], '/tmp');

        $this->assertSame('WP_Statistics\\Deps', $config->getNamespacePrefix());
    }

    public function testAbsoluteTargetDirectory(): void
    {
        $config = Config::fromArray([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
            'target_directory' => 'vendor-prefixed',
        ], '/home/user/project');

        $this->assertSame('/home/user/project' . DIRECTORY_SEPARATOR . 'vendor-prefixed', $config->getAbsoluteTargetDirectory());
    }

    public function testFromComposerJson(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/fixtures/simple-project/composer.json';
        $config = Config::fromComposerJson($fixturePath);

        $this->assertSame('TestProject\\Deps', $config->getNamespacePrefix());
        $this->assertSame(['geoip2/geoip2'], $config->getPackages());
        $this->assertSame(['psr/log'], $config->getExcludePackages());
    }

    public function testFromComposerJsonThrowsForMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Config::fromComposerJson('/nonexistent/composer.json');
    }
}
