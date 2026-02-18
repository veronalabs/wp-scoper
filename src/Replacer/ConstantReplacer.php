<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Replacer;

class ConstantReplacer implements ReplacerInterface
{
    /** @var string */
    private $prefix;

    /** @var array<string> Constant names to prefix */
    private $constants;

    /** @var array<string> PHP built-in constants to never prefix */
    private static $phpBuiltinConstants = [
        'PHP_EOL', 'PHP_INT_MAX', 'PHP_INT_MIN', 'PHP_INT_SIZE',
        'PHP_FLOAT_MAX', 'PHP_FLOAT_MIN', 'PHP_FLOAT_DIG', 'PHP_FLOAT_EPSILON',
        'PHP_VERSION', 'PHP_MAJOR_VERSION', 'PHP_MINOR_VERSION', 'PHP_RELEASE_VERSION',
        'PHP_OS', 'PHP_OS_FAMILY', 'PHP_SAPI', 'PHP_MAXPATHLEN',
        'PHP_PREFIX', 'PHP_BINDIR', 'PHP_LIBDIR', 'PHP_DATADIR',
        'PHP_EXTENSION_DIR', 'PHP_CONFIG_FILE_PATH',
        'DIRECTORY_SEPARATOR', 'PATH_SEPARATOR',
        'TRUE', 'FALSE', 'NULL',
        'STDIN', 'STDOUT', 'STDERR',
        'E_ALL', 'E_ERROR', 'E_WARNING', 'E_NOTICE', 'E_STRICT',
        'E_DEPRECATED', 'E_USER_ERROR', 'E_USER_WARNING', 'E_USER_NOTICE',
        'FILE_APPEND', 'FILE_IGNORE_NEW_LINES', 'FILE_SKIP_EMPTY_LINES',
        'FILE_USE_INCLUDE_PATH', 'LOCK_EX', 'LOCK_SH', 'LOCK_UN',
        'SORT_REGULAR', 'SORT_NUMERIC', 'SORT_STRING', 'SORT_ASC', 'SORT_DESC',
        'ARRAY_FILTER_USE_BOTH', 'ARRAY_FILTER_USE_KEY',
        'JSON_THROW_ON_ERROR', 'JSON_PRETTY_PRINT', 'JSON_UNESCAPED_SLASHES',
        'JSON_UNESCAPED_UNICODE', 'JSON_FORCE_OBJECT',
        'PREG_SPLIT_NO_EMPTY', 'PREG_SET_ORDER', 'PREG_OFFSET_CAPTURE',
        'GLOB_BRACE', 'GLOB_MARK', 'GLOB_NOSORT', 'GLOB_NOCHECK',
        'SEEK_SET', 'SEEK_CUR', 'SEEK_END',
    ];

    public function __construct(string $prefix, array $constants)
    {
        $this->prefix = $prefix;

        $this->constants = array_filter($constants, function (string $constant) use ($prefix): bool {
            return !in_array($constant, self::$phpBuiltinConstants, true)
                && strpos($constant, $prefix) !== 0;
        });

        // Sort longest first
        usort($this->constants, function (string $a, string $b): int {
            return strlen($b) - strlen($a);
        });
    }

    public function replace(string $contents): string
    {
        foreach ($this->constants as $constant) {
            $contents = $this->replaceConstant($contents, $constant);
        }

        return $contents;
    }

    private function replaceConstant(string $contents, string $constant): string
    {
        $prefixed = $this->prefix . $constant;
        $quotedConst = preg_quote($constant, '/');
        $quotedPrefix = preg_quote($this->prefix, '/');

        // define('CONSTANT_NAME', value)
        $contents = preg_replace(
            '/(define\s*\(\s*[\'"])(?!' . $quotedPrefix . ')(' . $quotedConst . ')([\'"])/',
            '$1' . $prefixed . '$3',
            $contents
        );

        // defined('CONSTANT_NAME')
        $contents = preg_replace(
            '/(defined\s*\(\s*[\'"])(?!' . $quotedPrefix . ')(' . $quotedConst . ')([\'"])/',
            '$1' . $prefixed . '$3',
            $contents
        );

        // constant('CONSTANT_NAME')
        $contents = preg_replace(
            '/(constant\s*\(\s*[\'"])(?!' . $quotedPrefix . ')(' . $quotedConst . ')([\'"])/',
            '$1' . $prefixed . '$3',
            $contents
        );

        // Bare constant usage in code (not in strings, not as part of identifiers)
        // Guard against: $CONSTANT, ->CONSTANT, ::CONSTANT (which are handled elsewhere or shouldn't be touched)
        $contents = preg_replace(
            '/(?<![A-Za-z0-9_$>\'":])(?!' . $quotedPrefix . ')(' . $quotedConst . ')(?![A-Za-z0-9_\\\\(])/',
            $prefixed,
            $contents
        );

        return $contents;
    }

    /**
     * Scan PHP files for constant definitions.
     *
     * @param array<string> $files
     * @return array<string>
     */
    public static function findConstants(array $files): array
    {
        $constants = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            // Find define() calls
            if (preg_match_all(
                '/define\s*\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/',
                $contents,
                $matches
            )) {
                foreach ($matches[1] as $constant) {
                    if (!in_array($constant, self::$phpBuiltinConstants, true)) {
                        $constants[] = $constant;
                    }
                }
            }
        }

        return array_unique($constants);
    }
}
