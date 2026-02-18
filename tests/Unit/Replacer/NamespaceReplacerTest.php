<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Tests\Unit\Replacer;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpScoper\Replacer\NamespaceReplacer;

class NamespaceReplacerTest extends TestCase
{
    private function makeReplacer(array $namespaces = ['GeoIp2']): NamespaceReplacer
    {
        return new NamespaceReplacer('WP_Statistics\\Deps', $namespaces);
    }

    // ========================================================================
    // Pattern 1: Namespace declarations
    // ========================================================================

    public function testPrefixesNamespaceDeclaration(): void
    {
        $replacer = $this->makeReplacer();
        $input = '<?php' . "\n" . 'namespace GeoIp2;';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('namespace WP_Statistics\\Deps\\GeoIp2;', $result);
    }

    public function testPrefixesNamespaceDeclarationWithSubNamespace(): void
    {
        $replacer = $this->makeReplacer();
        $input = '<?php' . "\n" . 'namespace GeoIp2\\Database;';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('namespace WP_Statistics\\Deps\\GeoIp2\\Database;', $result);
    }

    public function testPrefixesNamespaceDeclarationWithBraces(): void
    {
        $replacer = $this->makeReplacer();
        $input = '<?php' . "\n" . 'namespace GeoIp2\\Database {';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('namespace WP_Statistics\\Deps\\GeoIp2\\Database {', $result);
    }

    public function testDoesNotDoublePrefixNamespace(): void
    {
        $replacer = $this->makeReplacer();
        $input = '<?php' . "\n" . 'namespace WP_Statistics\\Deps\\GeoIp2;';
        $result = $replacer->replace($input);

        $this->assertStringNotContainsString('WP_Statistics\\Deps\\WP_Statistics\\Deps', $result);
    }

    // ========================================================================
    // Pattern 2: Use statements
    // ========================================================================

    public function testPrefixesUseStatement(): void
    {
        $replacer = $this->makeReplacer();
        $input = 'use GeoIp2\\Database\\Reader;';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('use WP_Statistics\\Deps\\GeoIp2\\Database\\Reader;', $result);
    }

    public function testPrefixesUseFunctionStatement(): void
    {
        $replacer = $this->makeReplacer();
        $input = 'use function GeoIp2\\someFunction;';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('use function WP_Statistics\\Deps\\GeoIp2\\someFunction;', $result);
    }

    public function testPrefixesUseConstStatement(): void
    {
        $replacer = $this->makeReplacer();
        $input = 'use const GeoIp2\\SOME_CONST;';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('use const WP_Statistics\\Deps\\GeoIp2\\SOME_CONST;', $result);
    }

    public function testDoesNotDoublePrefixUseStatement(): void
    {
        $replacer = $this->makeReplacer();
        $input = 'use WP_Statistics\\Deps\\GeoIp2\\Database\\Reader;';
        $result = $replacer->replace($input);

        $this->assertStringNotContainsString('WP_Statistics\\Deps\\WP_Statistics\\Deps', $result);
    }

    // ========================================================================
    // Pattern 3: Fully qualified names
    // ========================================================================

    public function testPrefixesFqnInNew(): void
    {
        $replacer = $this->makeReplacer();
        $input = '$x = new \\GeoIp2\\Database\\Reader();';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('\\WP_Statistics\\Deps\\GeoIp2\\Database\\Reader()', $result);
    }

    public function testPrefixesFqnInInstanceof(): void
    {
        $replacer = $this->makeReplacer();
        $input = 'if ($x instanceof \\GeoIp2\\Model\\City)';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('\\WP_Statistics\\Deps\\GeoIp2\\Model\\City)', $result);
    }

    public function testPrefixesFqnInCatch(): void
    {
        $replacer = $this->makeReplacer();
        $input = 'catch (\\GeoIp2\\Exception\\AddressNotFoundException $e)';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('\\WP_Statistics\\Deps\\GeoIp2\\Exception\\AddressNotFoundException', $result);
    }

    // ========================================================================
    // Pattern 4: Unqualified references (guards)
    // ========================================================================

    public function testPrefixesUnqualifiedReference(): void
    {
        $replacer = $this->makeReplacer();
        $input = '$x = new GeoIp2\\Database\\Reader();';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('WP_Statistics\\Deps\\GeoIp2\\Database\\Reader()', $result);
    }

    public function testDoesNotPrefixPropertyAccess(): void
    {
        $replacer = $this->makeReplacer(['GeoIp2', 'MaxMind\\Db']);
        $input = '$this->dbReader->get($ip);';
        $result = $replacer->replace($input);

        // Property access should NOT be touched
        $this->assertSame($input, $result);
    }

    public function testDoesNotPrefixVariables(): void
    {
        $replacer = $this->makeReplacer();
        $input = '$GeoIp2Reader = "test";';
        $result = $replacer->replace($input);

        // Variable names should NOT be touched
        $this->assertStringNotContainsString('$WP_Statistics', $result);
    }

    public function testDoesNotPrefixPartOfLongerIdentifier(): void
    {
        $replacer = $this->makeReplacer(['GeoIp2']);
        $input = 'class MyGeoIp2Helper {';
        $result = $replacer->replace($input);

        // Should NOT replace inside a longer identifier
        $this->assertStringContainsString('MyGeoIp2Helper', $result);
    }

    public function testDoesNotTouchPropertyNames(): void
    {
        $replacer = $this->makeReplacer(['MaxMind\\Db']);
        $input = <<<'PHP'
<?php
class Reader {
    private $database;

    public function __construct(string $database)
    {
        $this->database = $database;
    }
}
PHP;
        $result = $replacer->replace($input);

        // $this->database should NOT be changed
        $this->assertStringContainsString('$this->database = $database;', $result);
        $this->assertStringContainsString('private $database;', $result);
    }

    public function testDoesNotTouchArrayKeys(): void
    {
        $replacer = $this->makeReplacer(['GeoIp2']);
        $input = "\$data['GeoIp2'] = true;";
        $result = $replacer->replace($input);

        // Array key should be unchanged
        $this->assertSame($input, $result);
    }

    // ========================================================================
    // Pattern 5: String literals
    // ========================================================================

    public function testPrefixesSingleQuotedString(): void
    {
        $replacer = $this->makeReplacer();
        $input = "\$class = 'GeoIp2\\Database\\Reader';";
        $result = $replacer->replace($input);

        $this->assertStringContainsString("'WP_Statistics\\Deps\\GeoIp2\\Database\\Reader'", $result);
    }

    public function testPrefixesDoubleQuotedStringWithDoubleBackslashes(): void
    {
        $replacer = $this->makeReplacer();
        $input = '$class = "GeoIp2\\\\Database\\\\Reader";';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('"WP_Statistics\\\\Deps\\\\GeoIp2\\\\Database\\\\Reader"', $result);
    }

    // ========================================================================
    // Pattern 6: PHPDoc
    // ========================================================================

    public function testPrefixesPhpDocParam(): void
    {
        $replacer = $this->makeReplacer();
        $input = '/** @param GeoIp2\\Model\\City $city */';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('@param WP_Statistics\\Deps\\GeoIp2\\Model\\City', $result);
    }

    public function testPrefixesPhpDocReturn(): void
    {
        $replacer = $this->makeReplacer();
        $input = '/** @return \\GeoIp2\\Model\\City */';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('@return \\WP_Statistics\\Deps\\GeoIp2\\Model\\City', $result);
    }

    public function testPrefixesPhpDocVar(): void
    {
        $replacer = $this->makeReplacer();
        $input = '/** @var GeoIp2\\Database\\Reader $reader */';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('@var WP_Statistics\\Deps\\GeoIp2\\Database\\Reader', $result);
    }

    // ========================================================================
    // Multiple namespaces
    // ========================================================================

    public function testHandlesMultipleNamespaces(): void
    {
        $replacer = new NamespaceReplacer('WP_Statistics\\Deps', ['GeoIp2', 'MaxMind\\Db']);

        $input = <<<'PHP'
<?php
namespace GeoIp2\Database;

use MaxMind\Db\Reader as DbReader;

class Reader
{
    private $dbReader;

    public function __construct(string $filename)
    {
        $this->dbReader = new DbReader($filename);
    }
}
PHP;

        $result = $replacer->replace($input);

        $this->assertStringContainsString('namespace WP_Statistics\\Deps\\GeoIp2\\Database;', $result);
        $this->assertStringContainsString('use WP_Statistics\\Deps\\MaxMind\\Db\\Reader', $result);
        // $this->dbReader should NOT change
        $this->assertStringContainsString('$this->dbReader = new DbReader($filename);', $result);
    }

    public function testLongestNamespaceMatchedFirst(): void
    {
        // MaxMind\Db should be matched before MaxMind to avoid partial replacement
        $replacer = new NamespaceReplacer('Prefix', ['MaxMind', 'MaxMind\\Db']);

        $input = 'use MaxMind\\Db\\Reader;';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('use Prefix\\MaxMind\\Db\\Reader;', $result);
        $this->assertStringNotContainsString('Prefix\\Prefix\\', $result);
    }

    // ========================================================================
    // Edge cases
    // ========================================================================

    public function testDoesNotModifyNonPhpContent(): void
    {
        $replacer = $this->makeReplacer();
        $input = '<html><body>GeoIp2 is great</body></html>';
        $result = $replacer->replace($input);

        // No namespace-like patterns, should be unchanged
        $this->assertSame($input, $result);
    }

    public function testHandlesEmptyInput(): void
    {
        $replacer = $this->makeReplacer();
        $this->assertSame('', $replacer->replace(''));
    }

    public function testTypehintWithNullable(): void
    {
        $replacer = $this->makeReplacer();
        $input = 'function foo(?\\GeoIp2\\Model\\City $city): ?\\GeoIp2\\Model\\City';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('\\WP_Statistics\\Deps\\GeoIp2\\Model\\City', $result);
    }
}
