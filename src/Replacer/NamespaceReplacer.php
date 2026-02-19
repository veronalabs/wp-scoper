<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Replacer;

class NamespaceReplacer implements ReplacerInterface
{
    /** @var string */
    private $prefix;

    /** @var array<string> Namespaces to replace, sorted longest first */
    private $namespaces;

    public function __construct(string $prefix, array $namespaces)
    {
        $this->prefix = $prefix;

        // Sort namespaces longest first to prevent partial replacements
        usort($namespaces, function (string $a, string $b): int {
            return strlen($b) - strlen($a);
        });

        $this->namespaces = $namespaces;
    }

    public function replace(string $contents): string
    {
        foreach ($this->namespaces as $namespace) {
            $contents = $this->replaceNamespace($contents, $namespace);
        }

        return $contents;
    }

    private function replaceNamespace(string $contents, string $namespace): string
    {
        $prefixed = $this->prefix . '\\' . $namespace;
        $quotedNs = preg_quote($namespace, '/');
        $quotedPrefix = preg_quote($this->prefix, '/');

        // Skip if already prefixed - check before processing
        // Pattern 1: namespace declarations
        // namespace GeoIp2; → namespace WP_Statistics\Deps\GeoIp2;
        // namespace GeoIp2\Database; → namespace WP_Statistics\Deps\GeoIp2\Database;
        $contents = preg_replace(
            '/^(\s*namespace\s+)(?!' . $quotedPrefix . '\\\\)(' . $quotedNs . ')\s*(;|{|\()/m',
            '$1' . addcslashes($prefixed, '\\$') . '$3',
            $contents
        );

        // Pattern 2: use statements (including use function, use const, grouped)
        // use GeoIp2\Database\Reader; → use WP_Statistics\Deps\GeoIp2\Database\Reader;
        // use function GeoIp2\func; → use function WP_Statistics\Deps\GeoIp2\func;
        $contents = preg_replace(
            '/^(\s*use\s+(?:function\s+|const\s+)?)(?!' . $quotedPrefix . '\\\\)(' . $quotedNs . '\\\\)/m',
            '$1' . addcslashes($prefixed, '\\$') . '\\',
            $contents
        );

        // Also handle "use Namespace;" without sub-namespace
        $contents = preg_replace(
            '/^(\s*use\s+(?:function\s+|const\s+)?)(?!' . $quotedPrefix . '\\\\)(' . $quotedNs . ')\s*;/m',
            '$1' . addcslashes($prefixed, '\\$') . ';',
            $contents
        );

        // Pattern 3: Fully qualified names (leading backslash)
        // \GeoIp2\Database\Reader → \WP_Statistics\Deps\GeoIp2\Database\Reader
        // Guard: not already prefixed
        $contents = preg_replace(
            '/(?<=\\\\)(?<!' . $quotedPrefix . '\\\\)(' . $quotedNs . ')(\\\\[A-Za-z])/',
            addcslashes($prefixed, '\\$') . '$2',
            $contents
        );

        // FQN for the namespace itself when used as type: \GeoIp2 (standalone)
        $contents = preg_replace(
            '/(?<=\\\\)(?<!' . $quotedPrefix . '\\\\)(' . $quotedNs . ')(?=[^\\\\A-Za-z0-9_])/',
            addcslashes($prefixed, '\\$'),
            $contents
        );

        // Pattern 4: Unqualified references in code context
        // Guards: not after ->, $, ::, not part of a longer identifier
        // GeoIp2\Database\Reader → WP_Statistics\Deps\GeoIp2\Database\Reader
        $contents = preg_replace(
            '/(?<![\\\\A-Za-z0-9_$>])(?<!\->)(?<!' . $quotedPrefix . '\\\\)(' . $quotedNs . ')(\\\\[A-Za-z])/',
            addcslashes($prefixed, '\\$') . '$2',
            $contents
        );

        // Pattern 5: String literals (single and double quoted)
        // 'GeoIp2\Database\Reader' → 'WP_Statistics\Deps\GeoIp2\Database\Reader'
        // "GeoIp2\\Database\\Reader" → "WP_Statistics\\Deps\\GeoIp2\\Database\\Reader"

        // Single-quoted strings: namespace separators are single backslash
        $singleSlashNs = $namespace; // e.g. GeoIp2
        $singleSlashPrefix = $prefixed; // e.g. WP_Statistics\Deps\GeoIp2
        $quotedSingleNs = preg_quote($singleSlashNs, '/');
        $quotedSinglePrefix = preg_quote($singleSlashPrefix, '/');

        $contents = preg_replace(
            "/(?<=['\"])(?!" . $quotedSinglePrefix . "\\\\)(" . $quotedSingleNs . ")(\\\\[A-Za-z][^'\"]*?)(?=['\"])/",
            addcslashes($singleSlashPrefix, '\\$') . '$2',
            $contents
        );

        // Double-quoted strings: namespace separators are double backslash
        $doubleSlashNs = str_replace('\\', '\\\\', $namespace); // e.g. GeoIp2 (no change if single-part)
        $doubleSlashPrefix = str_replace('\\', '\\\\', $prefixed);
        $quotedDoubleNs = preg_quote($doubleSlashNs, '/');
        $quotedDoublePrefix = preg_quote($doubleSlashPrefix, '/');

        if (strpos($namespace, '\\') !== false || true) {
            // Match double-backslash separated namespaces in strings
            $contents = preg_replace(
                '/(?<=["\'])(?!' . $quotedDoublePrefix . '\\\\\\\\)(' . $quotedDoubleNs . ')(\\\\\\\\[A-Za-z][^"\']*?)(?=["\'])/',
                addcslashes($doubleSlashPrefix, '\\$') . '$2',
                $contents
            );
        }

        // Pattern 6: PHPDoc annotations
        // @param GeoIp2\Database\Reader → @param WP_Statistics\Deps\GeoIp2\Database\Reader
        // @return \GeoIp2\Database\Reader → @return \WP_Statistics\Deps\GeoIp2\Database\Reader
        $contents = preg_replace(
            '/(@(?:param|return|var|throws|see|property|method|mixin)\s+\\\\?)(?!' . $quotedPrefix . '\\\\)(' . $quotedNs . ')(\\\\[A-Za-z])/m',
            '$1' . addcslashes($prefixed, '\\$') . '$3',
            $contents
        );

        // PHPDoc with pipe types: Type|GeoIp2\... or GeoIp2\...|Type
        $contents = preg_replace(
            '/(\\|\\\\?)(?!' . $quotedPrefix . '\\\\)(' . $quotedNs . ')(\\\\[A-Za-z])/',
            '$1' . addcslashes($prefixed, '\\$') . '$3',
            $contents
        );

        // Fix double-prefixing: when a class name matches its namespace name
        // (e.g. DeviceDetector\DeviceDetector), the class part can get prefixed again.
        // Prefix\NS\Prefix\NS → Prefix\NS (keeping whatever follows)
        $doublePrefix = $prefixed . '\\' . $this->prefix . '\\';
        if (strpos($contents, $doublePrefix) !== false) {
            $contents = str_replace($doublePrefix, $prefixed . '\\', $contents);
        }

        // Same fix for double-backslash string literals
        $doublePrefixStr = str_replace('\\', '\\\\', $doublePrefix);
        if (strpos($contents, $doublePrefixStr) !== false) {
            $fixedStr = str_replace('\\', '\\\\', $prefixed . '\\');
            $contents = str_replace($doublePrefixStr, $fixedStr, $contents);
        }

        // Fix cross-namespace double-prefixing: when a namespace name (e.g. "Carbon") also
        // appears as the last segment of an already-prefixed path (e.g. "Prefix\Illuminate\
        // Support\Carbon"), the FQN standalone pattern can incorrectly prefix it again,
        // producing "Prefix\Illuminate\Support\Prefix\Carbon". Fix by removing the
        // spurious mid-path prefix insertion.
        $midPathDoubleSearch = '\\' . $prefixed;
        if (strpos($contents, $midPathDoubleSearch) !== false) {
            $contents = preg_replace(
                '/([A-Za-z0-9_])' . preg_quote($midPathDoubleSearch, '/') . '(?=[^A-Za-z0-9_\\\\])/',
                '$1\\' . addcslashes($namespace, '\\$'),
                $contents
            );
        }

        return $contents;
    }
}
