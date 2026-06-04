# Changelog

## 1.4.1 - 2026-06-04

### Changed
- Internal refactor only, no behaviour change: deduplicated the replacer-apply sequence into `Prefixer::applyReplacers()` (shared by the dependency loop, dev-package loop, and call-site updates — the latter still skips the nullable fixer), shared the PHP-constraint detection as `Plugin::detectPhpConstraint()` across the plugin hook and `wp-scope` command, and removed an unreachable guard in `NullableParamReplacer`. Scoped output is identical to 1.4.0.

---

## 1.4.0 - 2026-06-04

### Added
- **PHP cross-version compatibility fixing via `extra.wp-scoper.php_compat: true`** (default `false`): opt-in pass that rewrites implicitly-nullable parameters (`Type $x = null`) in scoped dependency code to the explicit form (`?Type $x = null`, and `A|B $x = null` → `A|B|null $x = null`). PHP 8.4 deprecates the implicit form, so dependencies pinned for an older PHP floor (e.g. `thecodingmachine/safe` kept for PHP 8.0 support) otherwise flood PHP 8.4/8.5 runtimes with `E_DEPRECATED` notices. The rewrite is behaviour-identical and valid on any PHP ≥ 7.1. The target PHP floor is **auto-detected** from the host `composer.json` (`config.platform.php` preferred, `require.php` fallback) — no hardcoded version — and gates the fixer so rewritten syntax stays valid for the declared minimum. Skips already-nullable types, `mixed`, unions already containing `null`, intersection types, untyped params, and non-`null` defaults. Applied to copied dependency files only — the host project's own source is never rewritten. New `NullableParamReplacer`; new `Config` API: `isPhpCompatEnabled()`, `getTargetPhpFloor()`, `targetPhpAtLeast()`, `parsePhpFloor()`, and a new optional `$phpConstraint` parameter on `Config::fromArray()`. Existing configs without `php_compat` are unaffected.

---

## 1.3.0 - 2026-05-04

### Added
- **Profile-aware scoping via `extra.wp-scoper.profiles.{name}`**: a single composer.json can now express a base set of scoped packages plus zero-or-more named profiles that contribute additional packages (and override scalar keys). Build scripts pick the profile via the `SCOPER_PROFILE` environment variable (e.g., `SCOPER_PROFILE=premium composer install`). `packages` arrays are appended + de-duplicated; all other keys (namespace_prefix, target_directory, etc.) replace base values when present in the profile. `dev_packages.packages` follow the same append-and-dedupe rule. Throws `InvalidArgumentException` if `SCOPER_PROFILE` names a profile that isn't defined — fail-loud over silent-wrong-output. Public API: `Config::applyProfile(array, ?string): array` and a new optional `$profile` parameter on `Config::fromArray()`. Existing `composer.json` configs without `profiles` are unaffected.

---

## 1.2.8 - 2026-05-02

### Fixed
- **Stale `autoload_files.php` / `autoload_static.php` references after `delete_vendor_packages: true`**: when a scoped package declared an `autoload.files` entry (Symfony polyfills, `league/csv` function file, etc.), wp-scoper deleted the original package directory but left Composer's eager-load tables pointing at the now-missing path, triggering `require` failures on autoloader boot. `FileCopier::deleteVendorPackages()` now strips entries for the removed packages from both files automatically, so consumers no longer need a custom `bin/fix-autoload.php` post-script.

---

## 1.2.7 - 2026-04-22

### Fixed
- **Polyfill stub classes incorrectly prefixed**: Symfony polyfill packages (`polyfill-intl-normalizer`, `polyfill-php73`, `polyfill-php80`) ship stub files that declare classes in the global namespace so they act as fallbacks when the corresponding PHP extension or version is missing. `ClassmapReplacer` prefixed these stubs alongside other global classes, which broke the fallbacks and caused fatal `Class "X" not found` errors at runtime on servers that lacked the native implementation — most visibly `Normalizer` on hosts without the `intl` extension, but also `Attribute`, `JsonException`, `PhpToken`, and `UnhandledMatchError` on older PHP versions. The built-in allowlist now covers these five classes, plus the related `CompileError`, `UnitEnum`, `BackedEnum`, `SensitiveParameter`, and `Override` globals.

---

## 1.2.6 - 2026-04-18

### Fixed
- **Unanchored built-in directory exclude patterns dropped legitimate directories**: `ext/`, `examples?/`, `tests?/`, `php4/`, `dev-bin/`, `.github/`, `.gitlab/` behaved as substring matches, so e.g. `Text/` and `Context/` were treated as `ext/` and silently omitted from the scoped output. The seven patterns now anchor to path-start or immediately after `/`.

---

## 1.2.5 - 2026-04-07

### Fixed
- **`use Namespace as Alias;` form not prefixed**: When a `use` statement imported a namespace itself (no trailing class) with an alias — e.g. `use Symfony\Polyfill\Mbstring as p;` in Symfony polyfill bootstrap files — `NamespaceReplacer` left it unchanged. After scoping, the aliased reference resolved to a non-existent class and produced fatal `Class not found` errors at runtime on hosts where the polyfill code path actually executed (e.g. servers without the native `mbstring` extension)

---

## 1.2.4 - 2026-03-05

### Added
- ABSPATH guard (`if (!defined('ABSPATH')) exit;`) to generated `autoload.php` and `autoload-classmap.php` for WordPress.org plugin compliance

---

## 1.2.3 - 2026-02-21

### Added
- Built-in exclude patterns for certificate/key files (`.pem`, `.crt`, `.cer`, `.key`)

---

## 1.2.2 - 2026-02-20

### Fixed
- `deleteVendorPackages` now cleans up empty parent org directories (e.g., `vendor/geoip2/` after removing `vendor/geoip2/geoip2/`)

### Docs
- Updated README examples to use `packages` as the recommended `target_directory` instead of `src/Dependencies`

---

## 1.2.0 - 2026-02-19

### Added
- `update_call_sites` now accepts an array of directories (e.g., `["src", "includes"]`) in addition to `true`/`false`
- Built-in exclude patterns for `.rst`, `.legacy.php`, `.neon`, `.xsd`, `renovate.json`, `psalm-baseline.xml`, `sonar-project.properties`, `phpdox.xml`, and `docs/` directories

### Changed
- `Dockerfile` exclude pattern now matches variants like `Dockerfile-dev` (was `Dockerfile$`)

### Fixed
- `composer wp-scope` command now produces identical output to the automatic post-install/post-update hook (was missing host PSR-4 autoload mappings)
- Classmap generator now handles PHP 8.2 `readonly` class modifier (e.g., `final readonly class`)
- `update_call_sites` with `"."` (root directory) no longer rewrites files inside `vendor/`
- Excluded files (e.g., `.legacy.php`) are no longer referenced in the generated `autoload.php` `require_once` list
- PHP class files in directories named `templates/`, `views/`, or `resources/` are no longer misidentified as template files and skipped during prefixing

---

## 1.1.1 - 2026-02-19

### Fixed
- `composer wp-scope` command was not passing host project PSR-4 autoload config to the generated autoloader

### Docs
- Updated README output table to show size reduction format

---

## 1.1.0 - 2026-02-19

### Added
- **Files Excluded** and **Output Size** stats in summary table
- Output size shows original vs output with reduction percentage (e.g., `2.3 MB / 2.7 MB (-14%)`)
- Built-in exclude patterns for `tests/`, `bin/`, `dev-bin/`, `Makefile`, `phpunit.xml`, `.travis.yml`, `Dockerfile`, `COPYING`
- Output stats table documented in README

### Fixed
- **Global class resolution in namespaced files**: Classes like `WP_Statistics_Spyc` now use fully qualified names (`\WP_Statistics_Spyc`) in usage contexts so they resolve correctly inside namespaced files
- **Class declaration in namespaced files**: Namespaced classes sharing a name with a global class (e.g., `DeviceDetector\Yaml\Spyc` vs global `Spyc`) are no longer incorrectly renamed
- **Use-import for global classes**: Added `use ClassName` and `use ClassName as Alias` pattern to ClassmapReplacer
- **Namespace-imported class collision**: When a namespaced file has `use Something\ClassName`, bare `ClassName` usage (new, instanceof, etc.) is no longer replaced since it resolves to the import, not the global class

---

## 1.0.0 - 2026-02-19

### Added
- Composer plugin with automatic post-install/post-update hooks
- `composer wp-scope` command and `bin/wp-scoper` standalone CLI
- Namespace prefixing with 6 regex patterns and guards against property/variable/array-key replacement
- Global class prefixing (skips PHP built-ins)
- Constant prefixing (`define`, `defined`, `constant`, bare usage)
- Transitive dependency resolution from `installed.json`
- Template/view file auto-detection (directory name, content analysis, HTML-to-PHP ratio)
- Classmap-based autoloader generation
- PSR-4 host project autoloading in generated autoloader (no `vendor/` needed in production)
- Automatic call site updates in host project `src/` files
- Dev-dependency support with separate target directory
- `--dry-run` flag for previewing changes
- Configurable exclude patterns, exclude packages, and exclude directories
- Built-in default exclude patterns for common junk files (examples/, ext/, php4/, composer.json, autoload.php, package.xml, phpcs.xml, etc.)
- Summary table displayed after prefixing with package stats
- Empty `packages` array allowed (generates autoloader only when host PSR-4 config exists)

---

## 0.2.0-beta - 2026-02-19

### Added
- Summary table displayed after prefixing with package stats and slogan
- Built-in default exclude patterns for common junk files (examples/, ext/, php4/, composer.json, autoload.php, package.xml, phpcs.xml, etc.)
- User-configured `exclude_patterns` are now merged with built-in defaults (instead of replacing them)

### Fixed
- **Double-prefixing bug**: When a class name matches its namespace (e.g., `DeviceDetector\DeviceDetector`), the class part was incorrectly prefixed again, producing `Prefix\NS\Prefix\NS` instead of `Prefix\NS\NS`
- **Template detection false positives**: PHP files with HTML tags in PHPDoc comments (e.g., `<ul>`, `<li>`, `<p>` in doc blocks) were incorrectly detected as templates and skipped during prefixing. Files starting with `<?php` that contain a class/interface/trait/enum declaration are now never treated as templates

## 0.1.0-beta - 2026-02-18

### Added
- Composer plugin with automatic post-install/post-update hooks
- `composer wp-scope` command and `bin/wp-scoper` standalone CLI
- Namespace prefixing with 6 regex patterns and guards against property/variable/array-key replacement
- Global class prefixing (skips PHP built-ins)
- Constant prefixing (`define`, `defined`, `constant`, bare usage)
- Transitive dependency resolution from `installed.json`
- Template/view file auto-detection (directory name, content analysis, HTML-to-PHP ratio)
- Classmap-based autoloader generation
- PSR-4 host project autoloading in generated autoloader (no `vendor/` needed in production)
- Automatic call site updates in host project `src/` files
- Dev-dependency support with separate target directory
- `--dry-run` flag for previewing changes
- Configurable exclude patterns, exclude packages, and exclude directories
- Empty `packages` array allowed (generates autoloader only when host PSR-4 config exists)
