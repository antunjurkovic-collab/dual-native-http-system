# Migration Guide: Updating wp-dual-native Plugin

## Overview

The `dual-native/http-system` package has been refactored to remove hard dependencies on WordPress functions. This makes the library framework-agnostic and allows it to be used in any PHP project (WordPress, Laravel, Symfony, standalone, etc.).

## What Changed

### 1. Dependency Injection Architecture

All core classes now accept dependencies via constructor injection instead of directly calling WordPress functions:

- **EventDispatcherInterface**: Replaces `do_action()` and `apply_filters()`
- **StorageInterface**: Replaces `get_option()` and `update_option()`

### 2. WordPress Functions Removed

The following WordPress functions have been replaced with PHP standard functions:
- `wp_json_encode()` → `json_encode()`
- `esc_url()` → `htmlspecialchars($url, ENT_QUOTES, 'UTF-8')`
- `do_action()` → `$eventDispatcher->dispatch()`
- `apply_filters()` → `$eventDispatcher->filter()`
- `get_option()` → `$storage->get()`
- `update_option()` → `$storage->set()`

### 3. New Interfaces and Implementations

**Event Dispatching:**
- `DualNative\HTTP\Events\EventDispatcherInterface`
- `DualNative\HTTP\Events\WordPressEventDispatcher` (for WordPress)
- `DualNative\HTTP\Events\NullEventDispatcher` (for testing/standalone)

**Storage:**
- `DualNative\HTTP\Storage\StorageInterface`
- `DualNative\HTTP\Storage\WordPressStorage` (for WordPress)
- `DualNative\HTTP\Storage\InMemoryStorage` (for testing/standalone)

## How to Update wp-dual-native Plugin

### Step 1: Update composer.json

Update the version constraint in your `wp-dual-native/composer.json`:

```json
{
  "require": {
    "dual-native/http-system": "^1.0"
  }
}
```

Then run:
```bash
composer update dual-native/http-system
```

### Step 2: Update DualNativeSystem Instantiation

**Before (Old Code):**
```php
use DualNative\HTTP\DualNativeSystem;

// Old way - no dependencies passed
$system = new DualNativeSystem($config);
```

**After (New Code):**
```php
use DualNative\HTTP\DualNativeSystem;
use DualNative\HTTP\Events\WordPressEventDispatcher;
use DualNative\HTTP\Storage\WordPressStorage;

// New way - pass WordPress adapters
$system = new DualNativeSystem(
    $config,
    new WordPressEventDispatcher(),
    new WordPressStorage()
);
```

### Step 3: Update Plugin Initialization

Locate where you initialize the `DualNativeSystem` in your plugin (typically in the main plugin file or a bootstrap class).

**Example: wp-dual-native/wp-dual-native.php**

```php
<?php
/**
 * Plugin Name: WP Dual Native
 * Description: WordPress integration for Dual-Native HTTP System
 * Version: 1.0.0
 */

use DualNative\HTTP\DualNativeSystem;
use DualNative\HTTP\Events\WordPressEventDispatcher;
use DualNative\HTTP\Storage\WordPressStorage;

// Initialize the system with WordPress adapters
function wp_dual_native_init() {
    $config = [
        'version' => '1.0.0',
        'profile' => 'full',
        'cache_ttl' => 3600
    ];

    // Create WordPress-specific implementations
    $eventDispatcher = new WordPressEventDispatcher();
    $storage = new WordPressStorage();

    // Instantiate system with dependencies
    global $wp_dual_native_system;
    $wp_dual_native_system = new DualNativeSystem($config, $eventDispatcher, $storage);
}
add_action('plugins_loaded', 'wp_dual_native_init');
```

### Step 4: Test Your WordPress Plugin

After making the changes:

1. **Deactivate and reactivate** the plugin
2. **Test basic functionality:**
   - Create a dual-native post
   - Verify HR and MR are generated correctly
   - Check that CID computation works
   - Verify catalog updates
3. **Check for WordPress hooks:**
   - Ensure `do_action()` and `apply_filters()` still work via `WordPressEventDispatcher`
   - Verify custom hooks are firing correctly
4. **Verify options storage:**
   - Check that catalog data is saved to wp_options
   - Confirm settings persist correctly

## Benefits of This Update

### 1. Framework Agnostic
The library now works in any PHP environment, not just WordPress.

### 2. Better Testing
Tests no longer require WordPress mock functions - they use `NullEventDispatcher` and `InMemoryStorage`.

### 3. Improved Maintainability
Clear separation between business logic (library) and framework integration (adapters).

### 4. Flexibility
You can implement custom event dispatchers or storage backends for specific needs.

## Example: Custom Event Dispatcher

If you need custom event handling logic:

```php
use DualNative\HTTP\Events\EventDispatcherInterface;

class CustomEventDispatcher implements EventDispatcherInterface
{
    public function dispatch(string $eventName, ...$args): void
    {
        // Custom event handling
        error_log("Event dispatched: $eventName");

        // Still trigger WordPress hooks if available
        if (function_exists('do_action')) {
            do_action($eventName, ...$args);
        }
    }

    public function filter(string $filterName, $value, ...$args)
    {
        // Custom filter logic
        if (function_exists('apply_filters')) {
            return apply_filters($filterName, $value, ...$args);
        }
        return $value;
    }

    public function hasListeners(string $eventName): bool
    {
        return function_exists('has_action') && has_action($eventName);
    }
}

// Use custom dispatcher
$system = new DualNativeSystem(
    $config,
    new CustomEventDispatcher(),
    new WordPressStorage()
);
```

## Example: Custom Storage Backend

If you want to store catalog data in a custom table instead of wp_options:

```php
use DualNative\HTTP\Storage\StorageInterface;

class CustomDatabaseStorage implements StorageInterface
{
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function get(string $key, $default = null)
    {
        $table = $this->wpdb->prefix . 'dual_native_catalog';
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT value FROM $table WHERE key = %s", $key)
        );
        return $result !== null ? maybe_unserialize($result) : $default;
    }

    public function set(string $key, $value): bool
    {
        $table = $this->wpdb->prefix . 'dual_native_catalog';
        return $this->wpdb->replace(
            $table,
            ['key' => $key, 'value' => maybe_serialize($value)],
            ['%s', '%s']
        );
    }

    public function delete(string $key): bool
    {
        $table = $this->wpdb->prefix . 'dual_native_catalog';
        return $this->wpdb->delete($table, ['key' => $key], ['%s']) !== false;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
}

// Use custom storage
global $wpdb;
$system = new DualNativeSystem(
    $config,
    new WordPressEventDispatcher(),
    new CustomDatabaseStorage($wpdb)
);
```

## Breaking Changes

### Constructor Signature Changed

**Before:**
```php
public function __construct(array $config = [])
```

**After:**
```php
public function __construct(
    array $config = [],
    ?EventDispatcherInterface $eventDispatcher = null,
    ?StorageInterface $storage = null
)
```

**Impact:** If you instantiate `DualNativeSystem` without passing dependencies, it will use `NullEventDispatcher` and `InMemoryStorage` by default. **This means WordPress hooks won't fire and data won't persist!**

**Solution:** Always pass `WordPressEventDispatcher` and `WordPressStorage` when using in WordPress.

### PHP Version Requirement

- **Minimum PHP version:** 8.0+ (for nullable type hints)
- **Recommended:** PHP 8.1+

## Troubleshooting

### Issue: WordPress hooks not firing

**Symptom:** `do_action()` and `apply_filters()` calls don't trigger

**Solution:** Ensure you're passing `WordPressEventDispatcher`:
```php
$system = new DualNativeSystem($config, new WordPressEventDispatcher(), new WordPressStorage());
```

### Issue: Catalog data not persisting

**Symptom:** Catalog entries disappear after page refresh

**Solution:** Ensure you're passing `WordPressStorage`:
```php
$system = new DualNativeSystem($config, new WordPressEventDispatcher(), new WordPressStorage());
```

### Issue: Tests failing with "Call to undefined function"

**Symptom:** PHPUnit tests fail with WordPress function errors

**Solution:** Use `NullEventDispatcher` and `InMemoryStorage` in tests:
```php
$system = new DualNativeSystem([], new NullEventDispatcher(), new InMemoryStorage());
```

## Support

If you encounter issues during migration:

1. Check that you're passing the correct adapters (WordPress vs Null/InMemory)
2. Verify PHP version is 8.0+
3. Ensure composer dependencies are up to date
4. Check error logs for specific issues

## Timeline

- **v0.x**: Old version with WordPress dependencies
- **v1.0+**: New version with dependency injection

If you're still on v0.x, follow this guide to upgrade to v1.0+.
