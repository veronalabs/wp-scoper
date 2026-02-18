<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Replacer;

class ClassmapReplacer implements ReplacerInterface
{
    /** @var string */
    private $prefix;

    /** @var array<string> Global class names to prefix */
    private $classes;

    /** @var array<string> PHP built-in classes to never prefix */
    private static $phpBuiltinClasses = [
        'stdClass', 'Exception', 'ErrorException', 'Error', 'TypeError', 'ValueError',
        'ArithmeticError', 'DivisionByZeroError', 'ParseError', 'Throwable',
        'RuntimeException', 'LogicException', 'InvalidArgumentException',
        'BadMethodCallException', 'BadFunctionCallException', 'DomainException',
        'LengthException', 'OutOfBoundsException', 'OutOfRangeException',
        'OverflowException', 'RangeException', 'UnderflowException',
        'UnexpectedValueException',
        'Iterator', 'IteratorAggregate', 'ArrayAccess', 'Serializable',
        'Countable', 'Traversable', 'JsonSerializable', 'Stringable',
        'Generator', 'Closure', 'Fiber',
        'DateTime', 'DateTimeImmutable', 'DateTimeInterface', 'DateTimeZone',
        'DateInterval', 'DatePeriod',
        'SplFileInfo', 'SplFileObject', 'SplTempFileObject',
        'SplDoublyLinkedList', 'SplStack', 'SplQueue', 'SplHeap',
        'SplMaxHeap', 'SplMinHeap', 'SplPriorityQueue', 'SplFixedArray',
        'SplObjectStorage',
        'ArrayObject', 'ArrayIterator', 'RecursiveArrayIterator',
        'DirectoryIterator', 'FilesystemIterator', 'GlobIterator',
        'RecursiveDirectoryIterator', 'RecursiveIteratorIterator',
        'RegexIterator', 'RecursiveRegexIterator',
        'PDO', 'PDOStatement', 'PDOException',
        'SimpleXMLElement', 'DOMDocument', 'DOMElement', 'DOMNode',
        'ReflectionClass', 'ReflectionMethod', 'ReflectionFunction',
        'ReflectionProperty', 'ReflectionParameter', 'ReflectionType',
        'WeakReference', 'WeakMap',
        'CurlHandle', 'CurlMultiHandle',
    ];

    public function __construct(string $prefix, array $classes)
    {
        $this->prefix = $prefix;

        // Filter out PHP built-in classes and already-prefixed classes
        $this->classes = array_filter($classes, function (string $class) use ($prefix): bool {
            return !in_array($class, self::$phpBuiltinClasses, true)
                && strpos($class, $prefix) !== 0;
        });

        // Sort longest first to prevent partial replacements
        usort($this->classes, function (string $a, string $b): int {
            return strlen($b) - strlen($a);
        });
    }

    public function replace(string $contents): string
    {
        foreach ($this->classes as $class) {
            $contents = $this->replaceClass($contents, $class);
        }

        return $contents;
    }

    private function replaceClass(string $contents, string $class): string
    {
        $prefixed = $this->prefix . $class;
        $quotedClass = preg_quote($class, '/');
        $quotedPrefix = preg_quote($this->prefix, '/');

        // Class declarations: class ClassName, interface ClassName, trait ClassName
        $contents = preg_replace(
            '/^(\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+)(?!' . $quotedPrefix . ')(' . $quotedClass . ')(?=\s|{|$)/m',
            '$1' . $prefixed,
            $contents
        );

        // extends/implements: extends ClassName, implements ClassName
        $contents = preg_replace(
            '/((?:extends|implements)\s+(?:[A-Za-z0-9_]+\s*,\s*)*)(?!' . $quotedPrefix . ')(' . $quotedClass . ')(?=\s|,|{|$)/m',
            '$1' . $prefixed,
            $contents
        );

        // new ClassName
        $contents = preg_replace(
            '/(new\s+)(?!' . $quotedPrefix . ')(' . $quotedClass . ')(?=\s*\(|\s*;|\s*$)/m',
            '$1' . $prefixed,
            $contents
        );

        // instanceof ClassName
        $contents = preg_replace(
            '/(instanceof\s+)(?!' . $quotedPrefix . ')(' . $quotedClass . ')(?=\s|;|\))/m',
            '$1' . $prefixed,
            $contents
        );

        // ClassName::  (static calls)
        $contents = preg_replace(
            '/(?<![A-Za-z0-9_$\\\\>])(?!' . $quotedPrefix . ')(' . $quotedClass . ')(::)/',
            $prefixed . '$2',
            $contents
        );

        // Type hints: function foo(ClassName $x)
        $contents = preg_replace(
            '/([\(,]\s*(?:\?\s*)?)(?!' . $quotedPrefix . ')(' . $quotedClass . ')(\s+\$)/',
            '$1' . $prefixed . '$3',
            $contents
        );

        // Return types: ): ClassName
        $contents = preg_replace(
            '/(:\s*(?:\?\s*)?)(?!' . $quotedPrefix . ')(' . $quotedClass . ')(?=\s*[{;])/',
            '$1' . $prefixed,
            $contents
        );

        // String references: 'ClassName' and "ClassName"
        $contents = preg_replace(
            '/([\'"])(?!' . $quotedPrefix . ')(' . $quotedClass . ')([\'"])/',
            '$1' . $prefixed . '$3',
            $contents
        );

        return $contents;
    }

    /**
     * Scan PHP files for global (non-namespaced) class definitions.
     *
     * @param array<string> $files
     * @return array<string>
     */
    public static function findGlobalClasses(array $files): array
    {
        $classes = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            // Skip files with namespace declarations
            if (preg_match('/^\s*namespace\s+[A-Za-z]/m', $contents)) {
                continue;
            }

            // Find class/interface/trait declarations
            if (preg_match_all(
                '/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m',
                $contents,
                $matches
            )) {
                foreach ($matches[1] as $className) {
                    $classes[] = $className;
                }
            }
        }

        return array_unique($classes);
    }
}
