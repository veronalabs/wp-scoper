# WP Scoper

A Composer plugin that prefixes namespaces in WordPress plugin dependencies to prevent fatal PHP conflicts.

## The Problem

WordPress plugins sharing the same Composer dependencies (e.g., Guzzle, GeoIP2) cause fatal errors because PHP cannot load two versions of the same class. When Plugin A requires `geoip2/geoip2 ^2.0` and Plugin B requires `geoip2/geoip2 ^3.0`, one will crash.

WP Scoper solves this by adding a unique prefix to all namespaces in your vendored dependencies:

```
GeoIp2\Database\Reader → WP_Statistics\Deps\GeoIp2\Database\Reader
```

Each plugin gets its own isolated copy of dependencies. No conflicts.

## Comparison

| Feature | WP Scoper | Mozart | PHP-Scoper |
|---|---|---|---|
| Installation | `composer require --dev` | Global / PHAR | Global / PHAR |
| Works on any machine | ✅ Yes | ❌ No | ❌ No |
| Composer plugin | ✅ Yes (auto-runs) | ❌ No | ❌ No |
| Namespace prefixing | ✅ Yes | ✅ Yes | ✅ Yes |
| Global class prefixing | ✅ Yes | ✅ Yes | ✅ Yes |
| Constant prefixing | ✅ Yes | ❌ No | ✅ Yes |
| Template/view safety | ✅ Auto-detect + config | ❌ No | ⚠️ Manual exclude |
| WordPress function safety | ✅ Safe | ✅ Safe | ❌ Breaks WP functions |
| Property name bug | ✅ Fixed | ❌ Had bugs | ➖ N/A (AST) |
| Autoloader generation | ✅ Yes (classmap) | ❌ No | ⚠️ Partial |
| Dev-dependency support | ✅ Yes | ❌ No | ❌ No |
| Update host source files | ✅ Yes | ❌ No | ➖ N/A |
| PHP version support | 7.4+ | 8.1+ | 7.2+ |

## Installation

```bash
composer require --dev veronalabs/wp-scoper
```

Add configuration to your `composer.json`:

```json
{
    "require": {
        "geoip2/geoip2": "^2.0"
    },
    "require-dev": {
        "veronalabs/wp-scoper": "^1.0"
    },
    "extra": {
        "wp-scoper": {
            "namespace_prefix": "WP_Statistics\\Deps",
            "packages": ["geoip2/geoip2"]
        }
    }
}
```

Run `composer install` or `composer wp-scope`. That's it.

## Output

After running, WP Scoper displays a summary of what was done:

```
+------------------------------------------------+
| WP Scoper - Your dependencies, your namespace! |
+---------------------+--------------------------+
| Packages            | 7                        |
| PHP Files Prefixed  | 90                       |
| Files Excluded      | 60                       |
| Namespaces Prefixed | 6                        |
| Global Classes      | 3                        |
| Constants           | 0                        |
| Call Sites Updated  | 0                        |
| Output Size         | 2.3 MB / 2.7 MB (-14%)   |
| Target Directory    | packages                 |
+------------------------------------------------+
```

| Stat | Description |
|---|---|
| **Packages** | Number of vendor packages processed (including transitive dependencies) |
| **PHP Files Prefixed** | PHP files copied and namespace-prefixed |
| **Files Excluded** | Files skipped by built-in and custom exclude patterns (tests, docs, configs) |
| **Namespaces Prefixed** | Unique vendor namespaces that were rewritten |
| **Global Classes** | Non-namespaced classes that were prefixed (e.g. `Spyc` -> `WP_Statistics_Spyc`) |
| **Constants** | `define()` constants that were prefixed |
| **Call Sites Updated** | Files in your `src/` whose `use` statements were auto-updated |
| **Output Size** | Output size vs original size with reduction percentage |
| **Target Directory** | Where prefixed files were written |

## How It Works

1. Reads your `extra.wp-scoper` config from `composer.json`
2. Discovers listed packages and their transitive dependencies
3. Copies them to a target directory (default: `vendor-prefixed/`)
4. Prefixes all namespaces, global classes, and constants
5. Generates a classmap-based autoloader
6. Optionally updates `use` statements in your own source files

## Configuration Reference

| Option | Required | Default | Description |
|---|---|---|---|
| `namespace_prefix` | **Yes** | - | Prefix for all namespaces (e.g., `WP_Statistics\\Deps`) |
| `packages` | **Yes** | - | Which vendor packages to prefix (transitive deps auto-included) |
| `target_directory` | No | `vendor-prefixed` | Where prefixed files are copied |
| `class_prefix` | No | Auto-derived | Prefix for global (non-namespaced) classes |
| `constant_prefix` | No | Auto-derived | Prefix for `define()` constants |
| `exclude_packages` | No | `[]` | Transitive deps to skip |
| `exclude_patterns` | No | `[]` | Additional regex patterns for files to skip (merged with built-in patterns) |
| `exclude_directories` | No | `["views", "templates", "resources"]` | Directories with template files (copied but not prefixed) |
| `delete_vendor_packages` | No | `false` | Remove originals from `vendor/` after copy |
| `update_call_sites` | No | `true` | Update `use` statements in host project files. `true` scans `src/`, or pass an array of directories (see below) |
| `dev_packages` | No | `null` | Config for prefixing `require-dev` packages separately |

### Full Configuration Example

```json
{
    "extra": {
        "wp-scoper": {
            "target_directory": "packages",
            "namespace_prefix": "WP_Statistics\\Deps",
            "class_prefix": "WP_Statistics_",
            "constant_prefix": "WP_STATISTICS_",
            "packages": ["geoip2/geoip2", "matomo/device-detector"],
            "exclude_packages": ["psr/log"],
            "exclude_patterns": ["/\\.md$/", "/tests?\\//i"],
            "exclude_directories": ["views", "templates", "resources"],
            "delete_vendor_packages": false,
            "update_call_sites": true,
            "dev_packages": {
                "enabled": true,
                "target_directory": "tests/vendor-prefixed",
                "packages": ["fakerphp/faker"]
            }
        }
    }
}
```

## Project Layout

Recommended layout with `vendor/` gitignored:

```
my-plugin/
├── .gitignore              # /vendor/ is gitignored
├── composer.json
├── vendor/                 # NOT committed
│   └── autoload.php
├── packages/               # COMMITTED - prefixed vendor packages
│   ├── autoload.php        # Generated by wp-scoper
│   ├── geoip2/
│   └── maxmind/
├── src/
│   └── Plugin.php          # Your code
└── tests/
```

In your plugin's main file:

```php
require_once __DIR__ . '/packages/autoload.php';

use WP_Statistics\Deps\GeoIp2\Database\Reader;

$reader = new Reader('/path/to/GeoLite2-City.mmdb');
```

## Usage

### As a Composer Plugin (automatic)

WP Scoper runs automatically after `composer install` and `composer update`.

### Manual Command

```bash
composer wp-scope
composer wp-scope --dry-run
```

### Standalone CLI

```bash
vendor/bin/wp-scoper
vendor/bin/wp-scoper /path/to/project
vendor/bin/wp-scoper --dry-run
```

## Automatic Call Site Updates (`update_call_sites`)

When dependencies get prefixed, your own source code still references the original namespaces. Normally you'd have to manually find and replace every `use` statement, every `new` call, and every type hint across your entire project. With `update_call_sites` enabled (the default), wp-scoper does this for you automatically.

By default it scans all PHP files in your `src/` directory. You can also pass an array of directories to scan additional locations:

```json
{
    "update_call_sites": ["src", "includes"]
}
```

You write your code using the original package namespaces, and wp-scoper handles the rest.

### Example: Before and After

Say you have this file in your project:

**`src/GeoIP/GeoIPService.php` - before running wp-scoper:**

```php
<?php

namespace WP_Statistics\GeoIP;

use GeoIp2\Database\Reader;
use GeoIp2\Model\City;
use GeoIp2\Exception\AddressNotFoundException;

class GeoIPService
{
    /** @var Reader */
    private $reader;

    public function __construct(string $dbPath)
    {
        $this->reader = new Reader($dbPath);
    }

    public function lookup(string $ip): ?City
    {
        try {
            return $this->reader->city($ip);
        } catch (AddressNotFoundException $e) {
            return null;
        }
    }
}
```

**After running `composer install` (or `composer wp-scope`), wp-scoper automatically changes it to:**

```php
<?php

namespace WP_Statistics\GeoIP;

use WP_Statistics\Deps\GeoIp2\Database\Reader;
use WP_Statistics\Deps\GeoIp2\Model\City;
use WP_Statistics\Deps\GeoIp2\Exception\AddressNotFoundException;

class GeoIPService
{
    /** @var Reader */
    private $reader;

    public function __construct(string $dbPath)
    {
        $this->reader = new Reader($dbPath);
    }

    public function lookup(string $ip): ?City
    {
        try {
            return $this->reader->city($ip);
        } catch (AddressNotFoundException $e) {
            return null;
        }
    }
}
```

Notice:
- All three `use` statements were updated automatically
- Your own namespace (`WP_Statistics\GeoIP`) was **not** touched
- `$this->reader` was **not** touched
- Local aliases (`Reader`, `City`, `AddressNotFoundException`) still work as before
- You never had to manually search/replace anything

### What gets updated in your source files

- `use GeoIp2\...` → `use WP_Statistics\Deps\GeoIp2\...`
- `new \GeoIp2\Database\Reader()` → `new \WP_Statistics\Deps\GeoIp2\Database\Reader()`
- `@param GeoIp2\Model\City` → `@param WP_Statistics\Deps\GeoIp2\Model\City`
- Fully qualified class references, type hints, catch blocks, etc.

### When to disable it

Set `"update_call_sites": false` if:
- You prefer to manage namespace references manually
- Your source files are outside `src/` and you handle updates yourself
- You're running wp-scoper in a CI pipeline where source files shouldn't be modified

## Template Safety

WP Scoper auto-detects template/view files (HTML mixed with PHP) and skips them during prefixing. Detection uses:

1. **Directory names** - Files in `views/`, `templates/`, `resources/` (configurable)
2. **Content analysis** - Files not starting with `<?php` that contain HTML tags
3. **HTML-to-PHP ratio** - Files with significantly more HTML than PHP

Template files are still copied to the target directory; they just aren't modified.

## What Gets Prefixed

- **Namespace declarations**: `namespace GeoIp2;` → `namespace WP_Statistics\Deps\GeoIp2;`
- **Use statements**: `use GeoIp2\Database\Reader;` → `use WP_Statistics\Deps\GeoIp2\Database\Reader;`
- **Fully qualified names**: `new \GeoIp2\Database\Reader()` → `new \WP_Statistics\Deps\GeoIp2\Database\Reader()`
- **Type hints and return types**
- **String class references**: `'GeoIp2\Database\Reader'`
- **PHPDoc annotations**: `@param GeoIp2\Model\City`
- **Global classes**: `class MyHelper` → `class WPS_MyHelper`
- **Constants**: `define('MY_CONST', ...)` → `define('WPS_MY_CONST', ...)`

## What Does NOT Get Prefixed

- Property access: `$this->reader` is never touched
- Variable names: `$reader` is never touched
- Array keys: `$data['reader']` is never touched
- WordPress functions: `add_action`, `get_option`, etc. are safe
- PHP built-in classes: `stdClass`, `DateTime`, `Exception`, etc.
- PHP built-in constants: `PHP_EOL`, `DIRECTORY_SEPARATOR`, etc.
- Files matching `exclude_patterns`
- Template/view files (auto-detected)

## Dev-Dependencies Support

Prefix `require-dev` packages into a separate directory for test isolation:

```json
{
    "dev_packages": {
        "enabled": true,
        "target_directory": "tests/vendor-prefixed",
        "packages": ["fakerphp/faker"]
    }
}
```

This generates a separate autoloader at `tests/vendor-prefixed/autoload.php` loaded only during testing.

## Standalone Autoloader (No `vendor/` in Production)

WordPress plugins submitted to wordpress.org can't ship a `vendor/` directory. WP Scoper solves this by generating a standalone autoloader that handles **both** your own classes and prefixed dependencies — no `vendor/autoload.php` needed.

WP Scoper automatically reads your project's `autoload.psr-4` from `composer.json` and includes it in the generated autoloader. For example, with this config:

```json
{
    "autoload": {
        "psr-4": {
            "WP_SMS\\": "src/"
        }
    },
    "extra": {
        "wp-scoper": {
            "namespace_prefix": "WP_SMS\\Deps",
            "packages": ["geoip2/geoip2"],
            "target_directory": "packages"
        }
    }
}
```

The generated `packages/autoload.php` will autoload:
1. **Prefixed dependencies** via classmap (`WP_SMS\Deps\GeoIp2\...`)
2. **Your own classes** via PSR-4 (`WP_SMS\...` → `src/`)

In your plugin, you only need one require:

```php
require_once __DIR__ . '/packages/autoload.php';
```

This single file replaces `vendor/autoload.php` entirely. Ship `packages/` in your plugin zip, keep `vendor/` in `.gitignore`.

## Requirements

- PHP 7.4+
- Composer 2.0+

## License

MIT
