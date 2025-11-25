# Dual-Native HTTP System

[![Packagist Version](https://img.shields.io/packagist/v/dual-native/http-system)](https://packagist.org/packages/dual-native/http-system)
[![PHP Version](https://img.shields.io/packagist/php-v/dual-native/http-system)](https://packagist.org/packages/dual-native/http-system)
[![License](https://img.shields.io/packagist/l/dual-native/http-system)](https://packagist.org/packages/dual-native/http-system)

A **framework-agnostic PHP library** implementing the Dual-Native Pattern for synchronized Human Representation (HR) and Machine Representation (MR) content delivery with Content Identity (CID) validation, bidirectional linking, and zero-fetch optimization.

## Features

- 🔄 **Framework-Agnostic**: Works with WordPress, Laravel, Symfony, or standalone
- 🆔 **Content Identity (CID)**: SHA-256 based version validators for zero-fetch optimization
- 🔗 **Bidirectional Linking**: Automatic navigation between HR and MR
- 📦 **Resource Catalog**: Registry of dual-native resources with efficient discovery
- 🔒 **Safe Write Operations**: Optimistic concurrency control with CID validation
- ✅ **Semantic Equivalence**: Validation that HR and MR represent the same content
- 🏗️ **Dependency Injection**: Clean architecture with pluggable adapters
- 🧪 **Well-Tested**: 14 PHPUnit tests (32 assertions) with 100% core coverage

## Installation

Install via Composer:

```bash
composer require dual-native/http-system
```

## Requirements

- PHP 8.0 or higher
- PSR-4 autoloading support

## Quick Start

### Basic Usage (Standalone)

```php
<?php
require 'vendor/autoload.php';

use DualNative\HTTP\DualNativeSystem;
use DualNative\HTTP\Events\NullEventDispatcher;
use DualNative\HTTP\Storage\InMemoryStorage;

// Initialize with null implementations (no external dependencies)
$system = new DualNativeSystem(
    ['version' => '1.0.0'],
    new NullEventDispatcher(),
    new InMemoryStorage()
);

// Compute Content Identity (CID)
$content = ['title' => 'Hello World', 'body' => 'Content here'];
$cid = $system->computeCID($content);
echo "CID: $cid\n"; // sha256-<hex>

// Validate CID
$isValid = $system->validateCID($content, $cid);
echo "Valid: " . ($isValid ? 'Yes' : 'No') . "\n";

// Generate bidirectional links
$links = $system->generateLinks('resource-123', 'https://example.com/page', 'https://api.example.com/resource/123');
print_r($links);
```

### WordPress Integration

```php
<?php
use DualNative\HTTP\DualNativeSystem;
use DualNative\HTTP\Events\WordPressEventDispatcher;
use DualNative\HTTP\Storage\WordPressStorage;

// Initialize with WordPress adapters
$system = new DualNativeSystem(
    [
        'version' => '1.0.0',
        'profile' => 'full',
        'cache_ttl' => 3600
    ],
    new WordPressEventDispatcher(),  // Bridges to do_action/apply_filters
    new WordPressStorage()            // Bridges to get_option/update_option
);

// Now WordPress hooks work through the library
add_action('dual_native_system_initialized', function($system) {
    // System is ready
});
```

See [MIGRATION-GUIDE.md](MIGRATION-GUIDE.md) for complete WordPress plugin integration instructions.

### Laravel Integration

```php
<?php
use DualNative\HTTP\DualNativeSystem;
use DualNative\HTTP\Events\EventDispatcherInterface;
use DualNative\HTTP\Storage\StorageInterface;

// Create Laravel adapters
class LaravelEventDispatcher implements EventDispatcherInterface {
    public function dispatch(string $eventName, ...$args): void {
        event($eventName, $args);
    }

    public function filter(string $filterName, $value, ...$args) {
        return $value; // Or implement Laravel's equivalent
    }

    public function hasListeners(string $eventName): bool {
        return Event::hasListeners($eventName);
    }
}

class LaravelStorage implements StorageInterface {
    public function get(string $key, $default = null) {
        return cache()->get($key, $default);
    }

    public function set(string $key, $value): bool {
        return cache()->put($key, $value, 3600);
    }

    public function delete(string $key): bool {
        return cache()->forget($key);
    }

    public function has(string $key): bool {
        return cache()->has($key);
    }
}

// Initialize system
$system = new DualNativeSystem(
    config('dualnative'),
    new LaravelEventDispatcher(),
    new LaravelStorage()
);
```

## Core Concepts

### Content Identity (CID)

CIDs are SHA-256 hashes of canonical JSON representations, used as ETags for efficient caching:

```php
$content = ['title' => 'My Post', 'body' => 'Content...'];

// Compute CID
$cid = $system->computeCID($content);
// Result: sha256-abc123...

// Validate CID (for safe writes)
if ($system->validateCID($content, $expectedCid)) {
    // Content hasn't changed, safe to update
}
```

### Safe Write Operations

Prevent conflicting updates using optimistic concurrency control:

```php
// Get current resource
$resource = $system->getResource('post-123');
$currentCid = $resource['cid'];

// Update with CID validation
$result = $system->updateResource(
    'post-123',
    $newData,
    $currentCid  // Must match current state
);

if ($result['success']) {
    $newCid = $result['new_cid'];
} else {
    // 412 Precondition Failed - resource was modified by another process
    $actualCid = $result['actual_cid'];
}
```

### Resource Catalog

Maintain a registry of dual-native resources:

```php
// Create a resource
$result = $system->createResource(
    'post-123',                          // Resource ID
    ['url' => 'https://example.com/post'], // Human Representation
    ['api_url' => 'https://api.example.com/post/123', 'title' => '...'], // Machine Representation
    ['author' => 'John Doe']             // Metadata
);

// Get catalog
$catalog = $system->getCatalog(
    $since = '2024-01-01T00:00:00Z',  // Only resources updated since
    $filters = ['status' => 'publish'],
    $limit = 100,
    $offset = 0
);
```

### Bidirectional Links

Generate navigation links between HR and MR:

```php
$links = $system->generateLinks(
    'post-123',
    'https://example.com/post-123',    // Human Representation URL
    'https://api.example.com/posts/123' // Machine Representation URL
);

// Result:
// [
//   'hr' => ['url' => '...', 'rel' => 'self', 'type' => 'text/html'],
//   'mr' => ['url' => '...', 'rel' => 'alternate', 'type' => 'application/json']
// ]
```

## Architecture

The library follows a clean architecture with dependency injection:

```
┌─────────────────────────────────┐
│     Your Application            │
│  (WordPress/Laravel/Symfony)    │
└────────────┬────────────────────┘
             │ uses
             ▼
┌─────────────────────────────────┐
│   DualNativeSystem              │
│   (Main Orchestrator)           │
├─────────────────────────────────┤
│  ├─ CIDManager                  │
│  ├─ LinkManager                 │
│  ├─ CatalogManager              │
│  ├─ ValidationEngine            │
│  └─ HTTPRequestHandler          │
└─────────────────────────────────┘
             │
      ┌──────┴──────┐
      ▼             ▼
┌─────────┐   ┌─────────┐
│  Event  │   │ Storage │
│Interface│   │Interface│
└─────────┘   └─────────┘
      │             │
      ▼             ▼
┌─────────┐   ┌─────────┐
│Framework│   │Framework│
│ Adapter │   │ Adapter │
└─────────┘   └─────────┘
```

### Interfaces

**EventDispatcherInterface**: Framework-agnostic event dispatching
- `dispatch(string $eventName, ...$args): void`
- `filter(string $filterName, $value, ...$args)`
- `hasListeners(string $eventName): bool`

**StorageInterface**: Framework-agnostic data persistence
- `get(string $key, $default = null)`
- `set(string $key, $value): bool`
- `delete(string $key): bool`
- `has(string $key): bool`

### Adapters

**Included:**
- `WordPressEventDispatcher` - Bridges to WordPress `do_action()`/`apply_filters()`
- `WordPressStorage` - Bridges to WordPress `get_option()`/`update_option()`
- `NullEventDispatcher` - No-op implementation for testing
- `InMemoryStorage` - Array-based storage for testing

**Create your own:** Implement the interfaces for your framework (Laravel, Symfony, etc.)

## Testing

The library includes a comprehensive test suite:

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Expected output:
# PHPUnit 9.6.29 by Sebastian Bergmann and contributors.
# ..............                                                    14 / 14 (100%)
# OK (14 tests, 32 assertions)
```

## API Reference

### DualNativeSystem

```php
// Constructor
new DualNativeSystem(
    array $config = [],
    ?EventDispatcherInterface $eventDispatcher = null,
    ?StorageInterface $storage = null
)

// Content Identity
$system->computeCID($content, ?array $excludeKeys = null): string
$system->validateCID($content, string $expectedCID, ?array $excludeKeys = null): bool

// Resource Management
$system->createResource(string $rid, $hr, $mr, array $metadata = []): array
$system->getResource(string $rid): ?array
$system->updateResource(string $rid, $newData, string $expectedCid): array

// Catalog
$system->getCatalog(?string $since, array $filters, int $limit, int $offset): array

// Links
$system->generateLinks(string $rid, string $hrUrl, string $mrUrl): array

// Validation
$system->validateSemanticEquivalence($hrContent, $mrContent, ?array $scope): array
$system->validateConformance(array $systemInfo): array

// Health
$system->healthCheck(): array
```

## Configuration

Default configuration:

```php
[
    'version' => '1.0.0',
    'profile' => 'full',
    'exclude_fields' => ['cid', 'etag', '_links', 'modified', 'modified_gmt'],
    'cache_ttl' => 3600
]
```

## Events

When using an event dispatcher, the library dispatches these events:

- `dual_native_system_initialized` - System initialized
- `dual_native_created_resource` - Resource created
- `dual_native_bidirectional_links` - Links generated
- `dual_native_link_header` - Link header generated
- `dual_native_computed_cid` - CID computed
- `dual_native_catalog_updated` - Catalog updated

## Performance

- **Zero-Fetch Optimization**: 90%+ reduction in unchanged content transfers
- **CID-based Caching**: Efficient conditional requests with ETags
- **Bandwidth Savings**: 83%+ reduction compared to full content fetching

## Use Cases

- **WordPress Sites**: Add machine-readable APIs to existing WordPress content
- **Headless CMS**: Provide synchronized HR/MR for decoupled architectures
- **API Versioning**: Use CIDs for efficient cache validation
- **Content Distribution**: Efficient content synchronization across systems
- **Real-time Systems**: Detect content changes without fetching full resources

## Documentation

- [MIGRATION-GUIDE.md](MIGRATION-GUIDE.md) - Integrating with WordPress plugin
- [Packagist](https://packagist.org/packages/dual-native/http-system) - Package information
- [GitHub](https://github.com/antunjurkovic-collab/dual-native-http-system) - Source code

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## License

GPL-2.0-or-later - GNU General Public License v2.0 or later

## Credits

Developed by the Dual-Native Team

## Support

- **Issues**: [GitHub Issues](https://github.com/antunjurkovic-collab/dual-native-http-system/issues)
- **Email**: info@dual-native.org
