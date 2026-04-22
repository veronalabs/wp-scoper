<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Tests\Unit\Replacer;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpScoper\Replacer\ClassmapReplacer;

class ClassmapReplacerTest extends TestCase
{
    public function testPrefixesClassDeclaration(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['MyGlobalClass']);
        $input = 'class MyGlobalClass {';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('class WPS_MyGlobalClass {', $result);
    }

    public function testPrefixesAbstractClass(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['BaseClass']);
        $input = 'abstract class BaseClass {';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('abstract class WPS_BaseClass {', $result);
    }

    public function testPrefixesNewInstance(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['MyGlobalClass']);
        $input = '$x = new MyGlobalClass();';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('new \WPS_MyGlobalClass()', $result);
    }

    public function testPrefixesInstanceof(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['MyGlobalClass']);
        $input = 'if ($x instanceof MyGlobalClass)';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('instanceof \WPS_MyGlobalClass)', $result);
    }

    public function testPrefixesStaticCall(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['MyGlobalClass']);
        $input = 'MyGlobalClass::method();';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('\WPS_MyGlobalClass::method()', $result);
    }

    public function testPrefixesExtends(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['BaseClass']);
        $input = 'class Child extends BaseClass {';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('extends \WPS_BaseClass {', $result);
    }

    public function testPrefixesTypehint(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['MyGlobalClass']);
        $input = 'function foo(MyGlobalClass $obj)';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('(\WPS_MyGlobalClass $obj)', $result);
    }

    public function testPrefixesStringReference(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['MyGlobalClass']);
        $input = "\$class = 'MyGlobalClass';";
        $result = $replacer->replace($input);

        $this->assertStringContainsString("'WPS_MyGlobalClass'", $result);
    }

    public function testDoesNotPrefixPhpBuiltins(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['stdClass', 'Exception', 'DateTime']);
        $input = '$x = new stdClass();';
        $result = $replacer->replace($input);

        // stdClass is a built-in, should NOT be prefixed
        $this->assertStringNotContainsString('WPS_stdClass', $result);
    }

    /**
     * @dataProvider polyfillStubClassProvider
     */
    public function testDoesNotPrefixPolyfillStubClasses(string $className): void
    {
        // Symfony polyfill-* packages ship stubs that declare these classes in
        // the global namespace so they act as a fallback when the corresponding
        // PHP extension/version is missing. Prefixing the stub class breaks the
        // fallback and causes "Class X not found" fatals on servers that lack
        // the native implementation (e.g. PHP without the intl extension).
        $replacer = new ClassmapReplacer('WPS_', [$className]);
        $stub = "<?php\nclass {$className} {}\n";
        $usage = "<?php\n\$x = new {$className}();\n{$className}::FOO;";

        $this->assertStringNotContainsString(
            'WPS_' . $className,
            $replacer->replace($stub),
            "Polyfill stub declaration for {$className} must stay in the global namespace"
        );
        $this->assertStringNotContainsString(
            'WPS_' . $className,
            $replacer->replace($usage),
            "References to global polyfilled {$className} must not be prefixed"
        );
    }

    public function polyfillStubClassProvider(): array
    {
        return [
            'Attribute (PHP 8.0)'              => ['Attribute'],
            'JsonException (PHP 7.3)'          => ['JsonException'],
            'Normalizer (intl extension)'      => ['Normalizer'],
            'PhpToken (PHP 8.0)'               => ['PhpToken'],
            'UnhandledMatchError (PHP 8.0)'    => ['UnhandledMatchError'],
        ];
    }

    public function testDoesNotDoublePrefixClass(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['MyGlobalClass']);
        $input = 'class WPS_MyGlobalClass {';
        $result = $replacer->replace($input);

        $this->assertStringNotContainsString('WPS_WPS_', $result);
    }

    public function testDoesNotRenameClassDeclarationInNamespacedFile(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['Spyc']);
        // A namespaced file with class Spyc — this is DeviceDetector\Yaml\Spyc,
        // NOT the global Spyc class. Should not be renamed.
        $input = "<?php\nnamespace DeviceDetector\\Yaml;\n\nuse Spyc as SpycParser;\n\nclass Spyc implements ParserInterface\n{";
        $result = $replacer->replace($input);

        // Class declaration must stay as Spyc (it's a namespaced class)
        $this->assertStringContainsString('class Spyc implements ParserInterface', $result);
        $this->assertStringNotContainsString('class WPS_Spyc', $result);
        // But the use import should be renamed
        $this->assertStringContainsString('use WPS_Spyc as SpycParser;', $result);
    }

    public function testPrefixesUseImportOfGlobalClass(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['Spyc']);
        $input = "<?php\nnamespace App\\Yaml;\n\nuse Spyc;\n";
        $result = $replacer->replace($input);

        $this->assertStringContainsString('use WPS_Spyc;', $result);
    }

    public function testPrefixesUseImportWithAlias(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['Spyc']);
        $input = "<?php\nnamespace App\\Yaml;\n\nuse Spyc as SpycParser;\n";
        $result = $replacer->replace($input);

        $this->assertStringContainsString('use WPS_Spyc as SpycParser;', $result);
    }

    public function testSkipsUsagePatternsWhenNamespacedImportExists(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['Spyc']);
        // DeviceDetector.php has: use DeviceDetector\Yaml\Spyc; ... return new Spyc();
        // The `new Spyc()` resolves to the use-imported class, NOT the global Spyc.
        $input = "<?php\nnamespace App;\n\nuse App\\Yaml\\Spyc;\n\nreturn new Spyc();\nSpyc::load(\$f);\n\$x = 'Spyc';";
        $result = $replacer->replace($input);

        // Usage patterns should NOT be replaced (they resolve via the use import)
        $this->assertStringContainsString('return new Spyc();', $result);
        $this->assertStringContainsString('Spyc::load(', $result);
        // String references should still be replaced (strings don't use imports)
        $this->assertStringContainsString("'WPS_Spyc'", $result);
    }

    public function testFindGlobalClasses(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'wp-scoper-test');
        file_put_contents($tempFile, <<<'PHP'
<?php
class GlobalHelper {
    public function help() {}
}

abstract class AbstractWorker {
    abstract public function work(): void;
}
PHP
        );

        $classes = ClassmapReplacer::findGlobalClasses([$tempFile]);
        unlink($tempFile);

        $this->assertContains('GlobalHelper', $classes);
        $this->assertContains('AbstractWorker', $classes);
    }

    public function testFindGlobalClassesSkipsNamespacedFiles(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'wp-scoper-test');
        file_put_contents($tempFile, <<<'PHP'
<?php
namespace Some\Namespace;

class NamespacedClass {
}
PHP
        );

        $classes = ClassmapReplacer::findGlobalClasses([$tempFile]);
        unlink($tempFile);

        $this->assertNotContains('NamespacedClass', $classes);
    }
}
