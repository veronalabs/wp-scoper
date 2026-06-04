<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Tests\Unit\Replacer;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpScoper\Replacer\NullableParamReplacer;

class NullableParamReplacerTest extends TestCase
{
    private function replace(string $code): string
    {
        return (new NullableParamReplacer())->replace($code);
    }

    public function testPrefixesScalarParam(): void
    {
        $in = 'function gmdate(string $format, int $timestamp = null): string {}';
        $this->assertStringContainsString('?int $timestamp = null', $this->replace($in));
    }

    public function testFixesChainedParamsOnOneLine(): void
    {
        $in = 'function f(string $userid = null, string $passwd = null) {}';
        $out = $this->replace($in);
        $this->assertStringContainsString('?string $userid = null', $out);
        $this->assertStringContainsString('?string $passwd = null', $out);
    }

    public function testPrefixesFqcnParam(): void
    {
        $in = 'function f(\CurlHandle $handle = null) {}';
        $this->assertStringContainsString('?\CurlHandle $handle = null', $this->replace($in));
    }

    public function testPrefixesByReferenceParam(): void
    {
        $in = 'function f(int &$error_code = null) {}';
        $this->assertStringContainsString('?int &$error_code = null', $this->replace($in));
    }

    public function testPrefixesCallableAndSelf(): void
    {
        $this->assertStringContainsString('?callable $cb = null', $this->replace('function f(callable $cb = null) {}'));
        $this->assertStringContainsString('?self $s = null', $this->replace('function f(self $s = null) {}'));
    }

    public function testUnionTypeAppendsNull(): void
    {
        $in = 'function f(int|string $x = null) {}';
        $this->assertStringContainsString('int|string|null $x = null', $this->replace($in));
    }

    public function testSkipsAlreadyNullable(): void
    {
        $in = 'function f(?int $x = null) {}';
        $this->assertSame($in, $this->replace($in));
    }

    public function testSkipsMixed(): void
    {
        $in = 'function f(mixed $x = null) {}';
        $this->assertSame($in, $this->replace($in));
    }

    public function testSkipsUnionAlreadyContainingNull(): void
    {
        $in = 'function f(int|null $x = null) {}';
        $this->assertSame($in, $this->replace($in));
    }

    public function testSkipsIntersectionType(): void
    {
        $in = 'function f(Countable&Traversable $x = null) {}';
        $this->assertSame($in, $this->replace($in));
    }

    public function testSkipsUntypedParam(): void
    {
        $in = 'function f($value = null) {}';
        $this->assertSame($in, $this->replace($in));
    }

    public function testSkipsNonNullDefault(): void
    {
        $this->assertSame('function f(int $x = 0) {}', $this->replace('function f(int $x = 0) {}'));
        $this->assertSame('function f(int $x = SOME_CONST) {}', $this->replace('function f(int $x = SOME_CONST) {}'));
    }

    public function testSkipsPromotedUntypedParam(): void
    {
        // `public`/`private` here are promotion modifiers, not types — an
        // untyped promoted param has no nullable deprecation and must be left
        // alone (a `?public` rewrite would be a fatal syntax error).
        $in = 'public function __construct(public bool $success, public $data = null) {}';
        $this->assertSame($in, $this->replace($in));
    }

    public function testPrefixesPromotedTypedParam(): void
    {
        $in = 'public function __construct(private string $y = null) {}';
        $this->assertStringContainsString('private ?string $y = null', $this->replace($in));
    }

    public function testPrefixesPromotedReadonlyTypedParam(): void
    {
        $in = 'public function __construct(public readonly int $z = null) {}';
        $this->assertStringContainsString('public readonly ?int $z = null', $this->replace($in));
    }

    public function testIsIdempotent(): void
    {
        $in = 'function f(int $a = null, int|string $b = null, ?float $c = null) {}';
        $once = $this->replace($in);
        $twice = $this->replace($once);
        $this->assertSame($once, $twice);
    }

    public function testRealSafeStubSignatures(): void
    {
        // Verbatim signatures from thecodingmachine/safe generated/datetime.php.
        $in = <<<'PHP'
        function date(string $format, int $timestamp = null): string
        {
        }
        function gmmktime(int $hour, int $minute = null, int $second = null, int $month = null, int $day = null, int $year = null): int
        {
        }
        function strtotime(string $datetime, int $baseTimestamp = null): int
        {
        }
        PHP;

        $out = $this->replace($in);

        $this->assertStringContainsString('function date(string $format, ?int $timestamp = null)', $out);
        $this->assertStringContainsString('?int $minute = null, ?int $second = null, ?int $month = null, ?int $day = null, ?int $year = null', $out);
        $this->assertStringContainsString('function strtotime(string $datetime, ?int $baseTimestamp = null)', $out);
        // Non-nullable leading params must be untouched.
        $this->assertStringContainsString('function gmmktime(int $hour,', $out);
        $this->assertStringContainsString('function date(string $format,', $out);
    }
}
