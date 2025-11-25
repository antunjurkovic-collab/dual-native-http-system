# Decoupling Refactoring Summary

This document describes the comprehensive refactoring to decouple dual-native-http-system from WordPress.

## Changes Made

### 1. New Interfaces Created

#### EventDispatcherInterface (`src/Events/EventDispatcherInterface.php`)
- `dispatch(string $eventName, ...$args): void` - Replaces `do_action()`
- `filter(string $filterName, $value, ...$args)` - Replaces `apply_filters()`
- `hasListeners(string $eventName): bool` - Replaces `has_action()`

#### StorageInterface (`src/Storage/StorageInterface.php`)
- `get(string $key, $default = null)` - Replaces `get_option()`
- `set(string $key, $value): bool` - Replaces `update_option()`
- `delete(string $key): bool` - Replaces `delete_option()`
- `has(string $key): bool` - Check if key exists

### 2. Implementations Created

#### WordPress Adapters
- `WordPressEventDispatcher` - Bridges to WordPress hooks system
- `WordPressStorage` - Bridges to WordPress options API

#### Testing/Standalone Implementations
- `NullEventDispatcher` - No-op implementation for tests
- `InMemoryStorage` - Array-based storage for tests

### 3. Classes to Update

All classes below need to accept optional EventDispatcherInterface and use it instead of WordPress functions:

#### DualNativeSystem.php
- Add `EventDispatcherInterface $eventDispatcher = null` to constructor
- Replace `do_action('dual_native_system_initialized', $this)` with `$this->eventDispatcher?->dispatch('dual_native_system_initialized', $this)`
- Replace `apply_filters()` with `$this->eventDispatcher?->filter()`
- Pass EventDispatcher to all managers during initialization
- Fix nullable types: lines 246, 271, 283, 296

#### CIDManager.php
- Add `EventDispatcherInterface $eventDispatcher = null` to constructor
- Replace all `apply_filters()` calls with `$this->eventDispatcher?->filter()`
- Fix nullable types: lines 21, 60

#### LinkManager.php
- Add `EventDispatcherInterface $eventDispatcher = null` to constructor
- Replace all `apply_filters()` calls with `$this->eventDispatcher?->filter()`
- Fix nullable types: lines 116, 140

#### CatalogManager.php
- Add `EventDispatcherInterface $eventDispatcher = null` and `StorageInterface $storage = null` to constructor
- Replace all `apply_filters()` calls with `$this->eventDispatcher?->filter()`
- Replace `get_option('dual_native_catalog', [])` with `$this->storage?->get('dual_native_catalog', []) ?? []`
- Replace `update_option('dual_native_catalog', $allEntries)` with `$this->storage?->set('dual_native_catalog', $allEntries) ?? false`
- Fix nullable types: lines 47, 114, 182, 201, 231, 320

#### ValidationEngine.php
- Add `EventDispatcherInterface $eventDispatcher = null` to constructor
- Replace all `apply_filters()` calls with `$this->eventDispatcher?->filter()`
- Fix nullable type: line 20

#### HTTPRequestHandler.php
- Add `EventDispatcherInterface $eventDispatcher = null` to constructor (update existing constructor)
- Replace all `apply_filters()` calls with `$this->eventDispatcher?->filter()`
- Fix nullable type: line 51

### 4. Testing Updates

#### tests/BasicConformanceTest.php
- Update to instantiate DualNativeSystem with NullEventDispatcher
- Update to instantiate with InMemoryStorage

#### tests/DualNativeSystemTest.php
- Update to instantiate DualNativeSystem with NullEventDispatcher
- Update to instantiate with InMemoryStorage

### 5. Nullable Type Fixes

Convert all instances of `= null)` parameters to `?Type = null)` for PHP 8.0+ compatibility.

**Before:**
```php
function foo($param = null)
function bar(array $arr = null)
```

**After:**
```php
function foo($param = null)  // Allowed for mixed types
function bar(?array $arr = null)  // Required for typed parameters
```

## Usage After Refactoring

### WordPress Usage (wp-dual-native plugin)

```php
use DualNative\HTTP\DualNativeSystem;
use DualNative\HTTP\Events\WordPressEventDispatcher;
use DualNative\HTTP\Storage\WordPressStorage;

$system = new DualNativeSystem([
    'version' => '2.0.0',
    'profile' => 'tct-1'
], new WordPressEventDispatcher(), new WordPressStorage());
```

### Standalone/Testing Usage

```php
use DualNative\HTTP\DualNativeSystem;
use DualNative\HTTP\Events\NullEventDispatcher;
use DualNative\HTTP\Storage\InMemoryStorage;

$system = new DualNativeSystem([
    'version' => '2.0.0',
    'profile' => 'tct-1'
], new NullEventDispatcher(), new InMemoryStorage());
```

### Laravel Usage (example)

```php
use DualNative\HTTP\DualNativeSystem;

class LaravelEventDispatcher implements \DualNative\HTTP\Events\EventDispatcherInterface {
    public function dispatch(string $eventName, ...$args): void {
        event($eventName, $args);
    }

    public function filter(string $filterName, $value, ...$args) {
        // Laravel doesn't have filters, just return value
        return $value;
    }

    public function hasListeners(string $eventName): bool {
        return Event::hasListeners($eventName);
    }
}

class LaravelCacheStorage implements \DualNative\HTTP\Storage\StorageInterface {
    public function get(string $key, $default = null) {
        return Cache::get($key, $default);
    }

    public function set(string $key, $value): bool {
        return Cache::forever($key, $value);
    }

    public function delete(string $key): bool {
        return Cache::forget($key);
    }

    public function has(string $key): bool {
        return Cache::has($key);
    }
}

$system = new DualNativeSystem(
    ['version' => '2.0.0'],
    new LaravelEventDispatcher(),
    new LaravelCacheStorage()
);
```

## Migration Guide for wp-dual-native Plugin

### Before (Tightly Coupled)
```php
$system = new DualNativeSystem([
    'version' => '2.0.0'
]);
// WordPress hooks automatically worked
```

### After (Decoupled)
```php
use DualNative\HTTP\Events\WordPressEventDispatcher;
use DualNative\HTTP\Storage\WordPressStorage;

$system = new DualNativeSystem(
    [
        'version' => '2.0.0'
    ],
    new WordPressEventDispatcher(),
    new WordPressStorage()
);
```

## Benefits

1. **Framework Agnostic**: Library works with any PHP framework
2. **Testable**: Tests run without WordPress functions
3. **Maintainable**: Clear dependencies via interfaces
4. **Flexible**: Easy to add support for new frameworks
5. **Production Ready**: Packagist publication now possible

## Breaking Changes

- **Constructor signature changed** for DualNativeSystem and all manager classes
- **WordPress integration** now requires explicit adapters
- Existing wp-dual-native plugin needs 1-line update to pass adapters

## Next Steps

1. Run automated refactoring script
2. Update all classes with new constructor signatures
3. Replace all WordPress function calls
4. Fix all nullable type hints
5. Update tests to use new interfaces
6. Run PHPUnit - all tests should pass
7. Update wp-dual-native plugin
8. Publish to Packagist
