# Manual Updates Still Needed

The automated refactoring script has completed the following:
1. ✅ Fixed all nullable type hints
2. ✅ Updated DualNativeSystem to use dependency injection
3. ✅ Created EventDispatcher and Storage interfaces and implementations

## Remaining Manual Updates

The following files need manual constructor updates to accept EventDispatcher:

### 1. src/Core/CIDManager.php
**Update constructor from:**
```php
public function __construct()
{
    // existing code
}
```

**To:**
```php
use DualNative\HTTP\Events\EventDispatcherInterface;
use DualNative\HTTP\Events\NullEventDispatcher;

private $eventDispatcher;

public function __construct(?EventDispatcherInterface $eventDispatcher = null)
{
    $this->eventDispatcher = $eventDispatcher ?? new NullEventDispatcher();
}
```

**Replace all apply_filters() calls:**
- Line 27: `$excludeKeys = $this->eventDispatcher->filter('dual_native_cid_exclude_keys', $excludeKeys, $content);`
- Line 49: `return $this->eventDispatcher->filter('dual_native_computed_cid', $cid, $content);`
- Line 65: `return $this->eventDispatcher->filter('dual_native_cid_validation', $isValid, $content, $expectedCID);`

### 2. src/Core/LinkManager.php
Similar updates as CIDManager - add EventDispatcher property and constructor parameter.

**Replace all apply_filters() calls** (lines 35, 57, 86, 105, 130, 150)

### 3. src/Core/CatalogManager.php
Add both EventDispatcher AND StorageInterface:

```php
use DualNative\HTTP\Events\EventDispatcherInterface;
use DualNative\HTTP\Events\NullEventDispatcher;
use DualNative\HTTP\Storage\StorageInterface;
use DualNative\HTTP\Storage\InMemoryStorage;

private $eventDispatcher;
private $storage;

public function __construct(
    CIDManager $cidManager,
    LinkManager $linkManager,
    ?EventDispatcherInterface $eventDispatcher = null,
    ?StorageInterface $storage = null
) {
    $this->cidManager = $cidManager;
    $this->linkManager = $linkManager;
    $this->eventDispatcher = $eventDispatcher ?? new NullEventDispatcher();
    $this->storage = $storage ?? new InMemoryStorage();
}
```

**Replace get_option/update_option:**
- Line 282, 296: `$this->storage->set('dual_native_catalog', $allEntries)`
- Line 403: `$this->storage->get('dual_native_catalog', [])`

**Replace apply_filters()** (lines 68, 73, 85, 99, 149, 219, 265)

### 4. src/Validation/ValidationEngine.php
Add EventDispatcher property and constructor.

**Replace apply_filters()** (lines 60, 124, 137, 379)

### 5. src/HTTP/HTTPRequestHandler.php
Update existing constructor to add EventDispatcher parameter:

```php
public function __construct(
    CIDManager $cidManager,
    LinkManager $linkManager,
    CatalogManager $catalogManager,
    ?ValidationEngine $validationEngine = null,
    ?EventDispatcherInterface $eventDispatcher = null
) {
    $this->cidManager = $cidManager;
    $this->linkManager = $linkManager;
    $this->catalogManager = $catalogManager;
    $this->validationEngine = $validationEngine;
    $this->eventDispatcher = $eventDispatcher ?? new NullEventDispatcher();
}
```

**Replace apply_filters()** (lines 95, 584, 682)

### 6. tests/BasicConformanceTest.php & tests/DualNativeSystemTest.php
Update test setup to use null implementations:

```php
use DualNative\HTTP\Events\NullEventDispatcher;
use DualNative\HTTP\Storage\InMemoryStorage;

public function setUp(): void
{
    $this->system = new DualNativeSystem(
        [],
        new NullEventDispatcher(),
        new InMemoryStorage()
    );
}
```

## Quick Test Command

After manual updates, run:
```bash
cd C:\Users\Antun\Desktop\claude\Partners\dual-native-http-system
vendor/bin/phpunit
```

All 14 tests should now pass!