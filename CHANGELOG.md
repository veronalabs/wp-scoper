# Changelog

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
