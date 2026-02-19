# Changelog

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
