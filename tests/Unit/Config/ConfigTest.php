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

    public function testEmptyPackagesArrayIsValid(): void
    {
        $config = Config::fromArray([
            'namespace_prefix' => 'WP_SMS\\Deps',
            'packages' => [],
        ], '/tmp');

        $this->assertSame([], $config->getPackages());
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
        // Built-in patterns are always included
        $this->assertContains('/\\.md$/i', $config->getExcludePatterns());
        $this->assertContains('/(?:^|\\/)examples?\\//i', $config->getExcludePatterns());
        $this->assertContains('/(?:^|\\/)ext\\//i', $config->getExcludePatterns());
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
        // User patterns are merged with built-in defaults
        $this->assertContains('/tests/', $config->getExcludePatterns());
        $this->assertContains('/\\.md$/i', $config->getExcludePatterns());
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

    public function testApplyProfileAppendsPackages(): void
    {
        $merged = Config::applyProfile([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2', 'matomo/device-detector'],
            'profiles' => [
                'premium' => ['packages' => ['veronalabs/wp-premium-sdk']],
            ],
        ], 'premium');

        $this->assertSame(
            ['geoip2/geoip2', 'matomo/device-detector', 'veronalabs/wp-premium-sdk'],
            $merged['packages']
        );
        $this->assertArrayNotHasKey('profiles', $merged, 'profiles key should be stripped after merge');
    }

    public function testApplyProfileDeduplicatesPackages(): void
    {
        $merged = Config::applyProfile([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2', 'matomo/device-detector'],
            'profiles' => [
                'premium' => ['packages' => ['matomo/device-detector', 'veronalabs/wp-premium-sdk']],
            ],
        ], 'premium');

        $this->assertSame(
            ['geoip2/geoip2', 'matomo/device-detector', 'veronalabs/wp-premium-sdk'],
            $merged['packages']
        );
    }

    public function testApplyProfileReplacesScalarKeys(): void
    {
        $merged = Config::applyProfile([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
            'target_directory' => 'packages',
            'profiles' => [
                'premium' => ['target_directory' => 'pro-packages'],
            ],
        ], 'premium');

        $this->assertSame('pro-packages', $merged['target_directory']);
    }

    public function testApplyProfileWithNullReturnsBaseConfigStrippingProfiles(): void
    {
        $merged = Config::applyProfile([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
            'profiles' => [
                'premium' => ['packages' => ['veronalabs/wp-premium-sdk']],
            ],
        ], null);

        $this->assertSame(['geoip2/geoip2'], $merged['packages']);
        $this->assertArrayNotHasKey('profiles', $merged);
    }

    public function testApplyProfileThrowsForUnknownProfile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SCOPER_PROFILE="agency"');

        Config::applyProfile([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
            'profiles' => [
                'premium' => ['packages' => ['veronalabs/wp-premium-sdk']],
            ],
        ], 'agency');
    }

    public function testApplyProfileThrowsWhenProfileRequestedButNoneDefined(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Defined profiles: (none)');

        Config::applyProfile([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
        ], 'premium');
    }

    public function testFromArrayWithProfileMergesPackages(): void
    {
        $config = Config::fromArray([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
            'profiles' => [
                'premium' => ['packages' => ['veronalabs/wp-premium-sdk']],
            ],
        ], '/tmp', [], 'premium');

        $this->assertSame(
            ['geoip2/geoip2', 'veronalabs/wp-premium-sdk'],
            $config->getPackages()
        );
    }

    public function testFromArrayWithoutProfileLeavesPackagesUntouched(): void
    {
        $config = Config::fromArray([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
            'profiles' => [
                'premium' => ['packages' => ['veronalabs/wp-premium-sdk']],
            ],
        ], '/tmp');

        $this->assertSame(['geoip2/geoip2'], $config->getPackages());
    }

    public function testApplyProfileMergesDevPackages(): void
    {
        $merged = Config::applyProfile([
            'namespace_prefix' => 'WP_Statistics\\Deps',
            'packages' => ['geoip2/geoip2'],
            'dev_packages' => [
                'enabled' => true,
                'packages' => ['phpunit/phpunit'],
            ],
            'profiles' => [
                'premium' => [
                    'dev_packages' => [
                        'packages' => ['mockery/mockery'],
                    ],
                ],
            ],
        ], 'premium');

        $this->assertTrue($merged['dev_packages']['enabled']);
        $this->assertSame(['phpunit/phpunit', 'mockery/mockery'], $merged['dev_packages']['packages']);
    }
}
