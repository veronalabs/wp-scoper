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

    public function testDoesNotDoublePrefixClass(): void
    {
        $replacer = new ClassmapReplacer('WPS_', ['MyGlobalClass']);
        $input = 'class WPS_MyGlobalClass {';
        $result = $replacer->replace($input);

        $this->assertStringNotContainsString('WPS_WPS_', $result);
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
