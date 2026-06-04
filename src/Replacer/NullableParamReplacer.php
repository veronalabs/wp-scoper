<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Replacer;

/**
 * PHP 8.4+ compatibility fixer: rewrite implicitly-nullable parameters
 * (`Type $x = null`) to the explicit nullable form (`?Type $x = null`).
 *
 * PHP 8.4 deprecates the implicit form (E_DEPRECATED: "Implicitly marking
 * parameter $x as nullable is deprecated"). Dependencies pinned for an older
 * PHP floor (e.g. thecodingmachine/safe 2.5.0, kept for PHP 8.0 support) still
 * ship the implicit form and flood PHP 8.4/8.5 runtimes with notices. The
 * explicit form is behaviour-identical and valid on every PHP >= 7.1, so this
 * pass keeps scoped output clean across the whole supported range without
 * changing semantics.
 *
 * Enabled per-project via `extra.wp-scoper.php_compat: true`; the Prefixer
 * gates it on the detected PHP floor. Applied to copied dependency files only,
 * never to the host project's own source.
 */
class NullableParamReplacer implements ReplacerInterface
{
    /**
     * Matches a single typed parameter whose default is the literal `null`.
     *
     * - `pre`     the parameter boundary: an opening `(` or a separating `,`.
     * - `mods`    optional constructor-promotion modifiers (`public`,
     *             `private`, `protected`, `readonly`). Captured separately and
     *             preserved so they are never mistaken for the type — e.g.
     *             `public $data = null` (a promoted, *untyped* param) does not
     *             match at all (no type token follows), and `public int $x =
     *             null` becomes `public ?int $x = null`.
     * - `type`    a single class/scalar name (optionally namespaced) or a `|`
     *             union. A leading `?` is intentionally NOT matched, so
     *             already-nullable params never reach the callback. Intersection
     *             types (`A&B`) never match because `&` is not part of the type
     *             group and cannot be followed by the required whitespace.
     * - `mid`     whitespace plus an optional by-reference `&`.
     * - `post`    a zero-width lookahead at the next `,`/`)` so the separating
     *             comma is NOT consumed and can serve as the `pre` of the next
     *             parameter — letting a single pass fix chained params on one
     *             line (`string $a = null, string $b = null`).
     */
    private const PARAM_RE = '/'
        . '(?P<pre>[(,]\s*)'
        . '(?P<mods>(?:(?:public|private|protected|readonly)\s+)*+)'
        . '(?P<type>\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*'
        . '(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*'
        . '(?:\s*\|\s*\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*'
        . '(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*)*)'
        . '(?P<mid>\s+&?\s*)'
        . '(?P<var>\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)'
        . '(?P<assign>\s*=\s*)'
        . '(?P<null>null)'
        . '(?P<post>(?=\s*[,)]))'
        . '/i';

    public function replace(string $contents): string
    {
        $result = preg_replace_callback(self::PARAM_RE, [self::class, 'fixParam'], $contents);

        // preg_replace_callback returns null only on PCRE error; never rewrite
        // to a broken/empty file in that case.
        return $result ?? $contents;
    }

    /**
     * @param array<string, string> $m
     */
    private static function fixParam(array $m): string
    {
        $type = $m['type'];
        $compact = preg_replace('/\s+/', '', $type);

        $members = explode('|', $compact);
        foreach ($members as $member) {
            $bare = strtolower(ltrim($member, '\\'));
            // Union already nullable, or `mixed` (which implicitly includes
            // null and would make `?mixed` a fatal error) — leave untouched.
            if ($bare === 'null' || $bare === 'mixed') {
                return $m[0];
            }
        }

        // Union types cannot take a leading "?" (`?int|string` is a parse
        // error); append "|null" instead. Single types get the "?" prefix.
        $newType = count($members) > 1 ? $type . '|null' : '?' . $type;

        return $m['pre'] . $m['mods'] . $newType . $m['mid'] . $m['var'] . $m['assign'] . $m['null'] . $m['post'];
    }
}
