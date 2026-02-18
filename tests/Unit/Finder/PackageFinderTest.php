<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Tests\Unit\Finder;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VeronaLabs\WpScoper\Finder\PackageFinder;

class PackageFinderTest extends TestCase
{
    private string $fixtureVendor;

    protected function setUp(): void
    {
        $this->fixtureVendor = dirname(__DIR__, 2) . '/fixtures/simple-project/vendor';
    }

    public function testFindsDirectPackage(): void
    {
        $finder = new PackageFinder($this->fixtureVendor);
        $packages = $finder->findPackages(['geoip2/geoip2']);

        $names = array_map(fn($p) => $p->getName(), $packages);
        $this->assertContains('geoip2/geoip2', $names);
    }

    public function testFindsTransitiveDependencies(): void
    {
        $finder = new PackageFinder($this->fixtureVendor);
        $packages = $finder->findPackages(['geoip2/geoip2']);

        $names = array_map(fn($p) => $p->getName(), $packages);
        $this->assertContains('geoip2/geoip2', $names);
        $this->assertContains('maxmind-db/reader', $names);
    }

    public function testExcludesPackages(): void
    {
        $finder = new PackageFinder($this->fixtureVendor);
        $packages = $finder->findPackages(['geoip2/geoip2'], ['maxmind-db/reader']);

        $names = array_map(fn($p) => $p->getName(), $packages);
        $this->assertContains('geoip2/geoip2', $names);
        $this->assertNotContains('maxmind-db/reader', $names);
    }

    public function testExtractsAutoloadInfo(): void
    {
        $finder = new PackageFinder($this->fixtureVendor);
        $packages = $finder->findPackages(['geoip2/geoip2']);

        $geoip2 = null;
        foreach ($packages as $p) {
            if ($p->getName() === 'geoip2/geoip2') {
                $geoip2 = $p;
                break;
            }
        }

        $this->assertNotNull($geoip2);
        $this->assertArrayHasKey('GeoIp2\\', $geoip2->getAutoloadPsr4());
        $this->assertContains('GeoIp2', $geoip2->getNamespaces());
    }

    public function testSkipsUnknownPackages(): void
    {
        $finder = new PackageFinder($this->fixtureVendor);
        $packages = $finder->findPackages(['nonexistent/package']);

        $this->assertEmpty($packages);
    }

    public function testThrowsForMissingInstalledJson(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $finder = new PackageFinder('/nonexistent/vendor');
        $finder->findPackages(['anything/here']);
    }

    public function testExtractsDependencies(): void
    {
        $finder = new PackageFinder($this->fixtureVendor);
        $packages = $finder->findPackages(['geoip2/geoip2'], ['maxmind-db/reader']);

        $geoip2 = null;
        foreach ($packages as $p) {
            if ($p->getName() === 'geoip2/geoip2') {
                $geoip2 = $p;
                break;
            }
        }

        $this->assertNotNull($geoip2);
        $this->assertContains('maxmind-db/reader', $geoip2->getDependencies());
    }
}
