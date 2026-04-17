<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpScoper\Config\Config;

class BuiltInExcludePatternsTest extends TestCase
{
    private const P_GITHUB   = '/(?:^|\\/)\\.github\\//i';
    private const P_GITLAB   = '/(?:^|\\/)\\.gitlab\\//i';
    private const P_EXAMPLES = '/(?:^|\\/)examples?\\//i';
    private const P_EXT      = '/(?:^|\\/)ext\\//i';
    private const P_PHP4     = '/(?:^|\\/)php4\\//i';
    private const P_TESTS    = '/(?:^|\\/)tests?\\//i';
    private const P_DEV_BIN  = '/(?:^|\\/)dev-bin\\//i';

    public function testAnchoredPatternsAreDeclaredAsBuiltIn(): void
    {
        $patterns = Config::fromArray([
            'namespace_prefix' => 'Test\\Deps',
            'packages' => [],
        ], '/tmp')->getExcludePatterns();

        foreach (
            [
                self::P_GITHUB,
                self::P_GITLAB,
                self::P_EXAMPLES,
                self::P_EXT,
                self::P_PHP4,
                self::P_TESTS,
                self::P_DEV_BIN,
            ] as $pattern
        ) {
            $this->assertContains($pattern, $patterns);
        }
    }

    /**
     * @dataProvider directoryPatternMatchProvider
     */
    public function testBuiltInDirectoryPatternMatchesExpectedPaths(
        string $pattern,
        string $relativePath,
        bool $shouldMatch
    ): void {
        $this->assertSame($shouldMatch, preg_match($pattern, $relativePath) === 1);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function directoryPatternMatchProvider(): array
    {
        return [
            '.github at root matches'             => [self::P_GITHUB,   '.github/workflows/ci.yml', true],
            '.github nested matches'              => [self::P_GITHUB,   'src/.github/foo.php',      true],
            'notgithub/ does not match'           => [self::P_GITHUB,   'notgithub/foo.php',        false],

            '.gitlab at root matches'             => [self::P_GITLAB,   '.gitlab/ci.yml',           true],
            '.gitlab nested matches'              => [self::P_GITLAB,   'src/.gitlab/foo.php',      true],
            'notgitlab/ does not match'           => [self::P_GITLAB,   'notgitlab/foo.php',        false],

            'example/ at root matches'            => [self::P_EXAMPLES, 'example/foo.php',          true],
            'examples/ at root matches'           => [self::P_EXAMPLES, 'examples/foo.php',         true],
            'examples/ nested matches'            => [self::P_EXAMPLES, 'src/examples/foo.php',     true],
            'MyExamples/ does not match'          => [self::P_EXAMPLES, 'MyExamples/foo.php',       false],
            'bad-examples/ does not match'        => [self::P_EXAMPLES, 'bad-examples/foo.php',     false],
            'nested MyExamples/ does not match'   => [self::P_EXAMPLES, 'src/MyExamples/foo.php',   false],

            'ext/ at root matches'                => [self::P_EXT,      'ext/Foo.php',              true],
            'ext/ nested matches'                 => [self::P_EXT,      'src/ext/Foo.php',          true],
            'Text/ does not match'                => [self::P_EXT,      'Text/Foo.php',             false],
            'Context/ does not match'             => [self::P_EXT,      'Context/Foo.php',          false],
            'nested Text/ does not match'         => [self::P_EXT,      'src/Text/Foo.php',         false],

            'php4/ at root matches'               => [self::P_PHP4,     'php4/foo.php',             true],
            'php4/ nested matches'                => [self::P_PHP4,     'src/php4/foo.php',         true],
            'graphql4/ does not match'            => [self::P_PHP4,     'graphql4/foo.php',         false],

            'test/ at root matches'               => [self::P_TESTS,    'test/foo.php',             true],
            'tests/ at root matches'              => [self::P_TESTS,    'tests/foo.php',            true],
            'tests/ nested matches'               => [self::P_TESTS,    'src/tests/foo.php',        true],
            'UnitTests/ does not match'           => [self::P_TESTS,    'UnitTests/foo.php',        false],
            'apitest/ does not match'             => [self::P_TESTS,    'apitest/foo.php',          false],

            'dev-bin/ at root matches'            => [self::P_DEV_BIN,  'dev-bin/foo.php',          true],
            'dev-bin/ nested matches'             => [self::P_DEV_BIN,  'src/dev-bin/foo.php',      true],
            'my-dev-bin/ does not match'          => [self::P_DEV_BIN,  'my-dev-bin/foo.php',       false],
        ];
    }
}
