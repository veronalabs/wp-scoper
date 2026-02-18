<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Tests\Unit\Replacer;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpScoper\Replacer\ConstantReplacer;

class ConstantReplacerTest extends TestCase
{
    public function testPrefixesDefine(): void
    {
        $replacer = new ConstantReplacer('WPS_', ['MY_CONSTANT']);
        $input = "define('MY_CONSTANT', 'value');";
        $result = $replacer->replace($input);

        $this->assertStringContainsString("define('WPS_MY_CONSTANT', 'value')", $result);
    }

    public function testPrefixesDefineWithDoubleQuotes(): void
    {
        $replacer = new ConstantReplacer('WPS_', ['MY_CONSTANT']);
        $input = 'define("MY_CONSTANT", "value");';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('define("WPS_MY_CONSTANT", "value")', $result);
    }

    public function testPrefixesDefined(): void
    {
        $replacer = new ConstantReplacer('WPS_', ['MY_CONSTANT']);
        $input = "if (defined('MY_CONSTANT'))";
        $result = $replacer->replace($input);

        $this->assertStringContainsString("defined('WPS_MY_CONSTANT')", $result);
    }

    public function testPrefixesConstantFunction(): void
    {
        $replacer = new ConstantReplacer('WPS_', ['MY_CONSTANT']);
        $input = "\$val = constant('MY_CONSTANT');";
        $result = $replacer->replace($input);

        $this->assertStringContainsString("constant('WPS_MY_CONSTANT')", $result);
    }

    public function testPrefixesBareUsage(): void
    {
        $replacer = new ConstantReplacer('WPS_', ['MY_CONSTANT']);
        $input = 'echo MY_CONSTANT;';
        $result = $replacer->replace($input);

        $this->assertStringContainsString('echo WPS_MY_CONSTANT;', $result);
    }

    public function testDoesNotPrefixPhpBuiltins(): void
    {
        $replacer = new ConstantReplacer('WPS_', ['PHP_EOL', 'TRUE', 'DIRECTORY_SEPARATOR']);
        $input = 'echo PHP_EOL;';
        $result = $replacer->replace($input);

        $this->assertStringNotContainsString('WPS_PHP_EOL', $result);
    }

    public function testDoesNotDoublePrefixConstant(): void
    {
        $replacer = new ConstantReplacer('WPS_', ['MY_CONSTANT']);
        $input = "define('WPS_MY_CONSTANT', 'value');";
        $result = $replacer->replace($input);

        $this->assertStringNotContainsString('WPS_WPS_', $result);
    }

    public function testFindConstants(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'wp-scoper-test');
        file_put_contents($tempFile, <<<'PHP'
<?php
define('GEOIP_VERSION', '2.0');
define('GEOIP_DB_PATH', '/path');
PHP
        );

        $constants = ConstantReplacer::findConstants([$tempFile]);
        unlink($tempFile);

        $this->assertContains('GEOIP_VERSION', $constants);
        $this->assertContains('GEOIP_DB_PATH', $constants);
    }

    public function testFindConstantsSkipsBuiltins(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'wp-scoper-test');
        file_put_contents($tempFile, <<<'PHP'
<?php
// This shouldn't happen in real code, but test the filter
define('PHP_EOL', 'fake');
PHP
        );

        $constants = ConstantReplacer::findConstants([$tempFile]);
        unlink($tempFile);

        $this->assertNotContains('PHP_EOL', $constants);
    }
}
