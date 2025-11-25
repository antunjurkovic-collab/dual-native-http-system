# Pre-Packagist Testing Report
**Date**: November 25, 2025
**Package**: dual-native/http-system
**Status**: ⚠️ Needs Fixes Before Publishing

---

## Executive Summary

The **dual-native-http-system** package is a PHP library that provides the core Dual-Native Pattern implementation. It is designed to work alongside the **wp-dual-native WordPress plugin** as the underlying kernel/library.

### Architecture

```
┌──────────────────────────────────────────┐
│   wp-dual-native WordPress Plugin         │  ← WordPress Adapter
│   (REST API endpoints, WP integration)    │
└──────────────────┬───────────────────────┘
                   │ requires (Composer)
                   ▼
┌──────────────────────────────────────────┐
│  dual-native/http-system Library          │  ← Core Kernel
│  (CIDManager, CatalogManager, etc.)       │
└──────────────────────────────────────────┘
```

---

## Test Results

### ✅ PASSED: Composer Validation
```bash
composer validate
```
**Result**: composer.json is valid and ready for Packagist

**Notes**:
- Package name: `dual-native/http-system`
- Version detection warning is expected (will be resolved with git tags)
- Dependencies installed successfully (PHPUnit 9.6.29)

### ❌ FAILED: PHPUnit Tests (14 errors)

**Issue 1: WordPress Function Dependencies**
```
Error: Call to undefined function DualNative\HTTP\do_action()
```

The library currently has hard dependencies on WordPress functions:
- `do_action()` (used in DualNativeSystem.php:67)
- Likely others: `apply_filters()`, `get_option()`, etc.

**Problem**: These functions don't exist when running tests outside WordPress.

**Impact**: Tests cannot run standalone, which is required for:
- Packagist CI/CD integration
- Independent testing
- Non-WordPress PHP applications using this library

### ⚠️ WARNING: PHP 8.0+ Deprecations

Multiple deprecation warnings for implicit nullable types:
```
PHP Deprecated: Implicitly marking parameter $equivalenceScope as nullable is deprecated
```

**Files Affected**:
- `src/DualNativeSystem.php` (lines 246, 271, 283, 296)
- `src/Core/CIDManager.php` (lines 21, 60)
- `src/Core/LinkManager.php` (line 116, 140)
- `src/Core/CatalogManager.php` (multiple)
- `src/Validation/ValidationEngine.php` (line 20)
- `src/HTTP/HTTPRequestHandler.php` (line 51)
- `src/HTTP/ValidatorGuard.php` (line 141)

**Fix Required**: Change `function foo($param = null)` to `function foo(?$param = null)`

---

## Critical Issues Before Packagist

### 1. **WordPress Coupling** (BLOCKER)

**Current State**: Library is tightly coupled to WordPress

**Options**:

#### Option A: Mock WordPress Functions for Tests (Quick Fix)
Create a `tests/bootstrap.php` file with WordPress function stubs:

```php
<?php
if (!function_exists('do_action')) {
    function do_action($hook_name, ...$args) {
        // Mock implementation for testing
        return;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook_name, $value, ...$args) {
        // Mock implementation for testing
        return $value;
    }
}
// ... other WordPress functions
```

**Pros**: Quick, tests can run
**Cons**: Doesn't solve the core architectural issue

#### Option B: Decouple from WordPress (Recommended)
Make WordPress integration optional through dependency injection:

```php
// Before (tightly coupled)
do_action('dual_native_cid_computed', $cid);

// After (decoupled)
if ($this->eventDispatcher) {
    $this->eventDispatcher->dispatch('dual_native_cid_computed', $cid);
}
```

Create an interface:
```php
interface EventDispatcherInterface {
    public function dispatch(string $eventName, ...$args);
}
```

Then create a WordPress adapter:
```php
class WordPressEventDispatcher implements EventDispatcherInterface {
    public function dispatch(string $eventName, ...$args) {
        do_action($eventName, ...$args);
    }
}
```

**Pros**: Clean architecture, library truly standalone, reusable for Laravel/Symfony/etc.
**Cons**: More work, breaking changes for wp-dual-native plugin

### 2. **PHP Type Hints** (MINOR)

Fix implicit nullable deprecations (PHP 8.0+):

```php
// Before
function computeCID(array $content, array $excludeKeys = null)

// After
function computeCID(array $content, ?array $excludeKeys = null)
```

**Impact**: Low (just warnings, not errors)
**Effort**: ~30 minutes

---

## Recommended Actions Before Publishing

### Priority 1: Fix WordPress Coupling (BLOCKER)

**Choose** Option A (quick fix) or Option B (proper fix):

**Option A Steps**:
1. Create `tests/bootstrap.php` with WordPress function mocks
2. Update `phpunit.xml` to include bootstrap file
3. Re-run tests

**Option B Steps** (Recommended):
1. Create `src/Events/EventDispatcherInterface.php`
2. Create `src/Events/WordPressEventDispatcher.php`
3. Update `DualNativeSystem` to accept optional `EventDispatcherInterface` in constructor
4. Replace all `do_action()` / `apply_filters()` with interface calls
5. Update wp-dual-native plugin to pass `WordPressEventDispatcher` when instantiating
6. Re-run tests

### Priority 2: Fix PHP Type Hints

Run this sed command (or manual find/replace):
```bash
# Find all implicit nullable parameters
grep -r "= null)" src/
```

Then add `?` prefix to type hints.

### Priority 3: Git Setup for Packagist

```bash
cd C:\Users\Antun\Desktop\claude\Partners\dual-native-http-system

# Initialize git if not already done
git init

# Add all files
git add .

# Commit
git commit -m "feat: Initial release of dual-native/http-system library"

# Tag with semantic version
git tag -a v2.0.0 -m "Version 2.0.0 - Level 2 Dual-Native HTTP System"

# Create GitHub repository (if not exists)
# Then push
git remote add origin https://github.com/YOUR_USERNAME/dual-native-http-system.git
git push -u origin main
git push --tags
```

### Priority 4: Publish to Packagist

Once tests pass:

1. Make repository public on GitHub
2. Go to https://packagist.org/packages/submit
3. Enter repository URL: `https://github.com/YOUR_USERNAME/dual-native-http-system`
4. Submit
5. Set up GitHub webhook for auto-updates

---

## Testing Strategy

### Unit Tests (Currently Broken)
```bash
composer install
vendor/bin/phpunit
```
**Status**: ❌ 14 errors (WordPress functions missing)

### Integration Tests with WordPress

The library is meant to be used WITH WordPress, so integration testing is critical:

#### Test with wp-dual-native Plugin

1. **Install library in wp-dual-native**:
```bash
cd C:\Users\Antun\Desktop\claude\Partners\wp-dual-native
composer require dual-native/http-system:dev-main
```

2. **Activate wp-dual-native plugin in WordPress**

3. **Run CI Smoke Tests** (from dual-native-http-system):
```bash
# Set environment variables
export DNH_BASE="http://localhost:8080"
export DNH_USER="admin"
export DNH_PASS="your-app-password"
export DNH_POST_ID=1

# Run smoke tests
bash scripts/ci-smoke.sh
```

This tests:
- ETag equals CID
- 304 Not Modified on conditional GET
- 412 Precondition Failed on safe writes
- Content-Digest parity
- Required HTTP headers

### Manual HTTP Testing

Test endpoints directly:

```bash
# Get a post with MR
curl -i http://localhost:8080/wp-json/dual-native/v2/posts/1

# Conditional GET (should return 304)
curl -i -H 'If-None-Match: "sha256-..."' \
  http://localhost:8080/wp-json/dual-native/v2/posts/1

# Safe write (should return 412 if ETag mismatched)
curl -i -X POST \
  -H 'Content-Type: application/json' \
  -H 'If-Match: "sha256-wrong"' \
  -d '{"insert":"append","block":{"type":"core/paragraph","content":"Test"}}' \
  http://localhost:8080/wp-json/dual-native/v2/posts/1/blocks
```

---

## Documentation Completeness

### ✅ GOOD
- README.md is comprehensive
- examples.md covers all major use cases
- ROADMAP.md tracks Level 2 progress
- profiles/tct-1.md documents HTTP profile

### ⚠️ NEEDS IMPROVEMENT
- **Missing**: Installation instructions for standalone (non-WordPress) use
- **Missing**: Examples of using library outside WordPress (Laravel, Symfony, etc.)
- **Incomplete**: API documentation (no PHPDoc on public methods)

### Recommended Additions

1. **API-REFERENCE.md** - Document all public classes/methods
2. **STANDALONE-USAGE.md** - Show how to use without WordPress
3. **INTEGRATIONS.md** - Show how to integrate with Laravel, Symfony, etc.

---

## Summary Checklist

Before publishing to Packagist:

- [ ] **Fix WordPress coupling** (Option A or B above)
- [ ] **Fix PHP 8.0 type hints** (add `?` to nullable parameters)
- [ ] **Unit tests pass** (`vendor/bin/phpunit`)
- [ ] **Integration tests pass** (CI smoke tests with wp-dual-native)
- [ ] **Git repository initialized** with semantic version tag
- [ ] **Repository is public** on GitHub
- [ ] **Documentation complete** (API reference, standalone usage)
- [ ] **License file present** (GPL-2.0-or-later)
- [ ] **Composer validate passes** ✅ (Already passing)

---

## Next Steps

### Immediate (Before Publishing)

1. **Decision**: Choose Option A (quick mock) or Option B (proper decoupling)
2. Implement chosen solution
3. Re-run PHPUnit tests until all pass
4. Test integration with wp-dual-native plugin
5. Fix PHP type hints

### Short-term (After Publishing)

1. Add GitHub Actions CI/CD
2. Set up Packagist auto-update webhook
3. Write API documentation
4. Create standalone usage examples

### Long-term

1. Add support for other frameworks (Laravel, Symfony)
2. Reach Level 3/4 conformance (see ROADMAP.md)
3. Build tooling ecosystem (schema validators, conformance dashboards)

---

## Conclusion

The **dual-native-http-system** library is architecturally sound and well-documented, but has a **critical blocker** before Packagist publication:

**WordPress function dependencies prevent standalone testing.**

**Recommendation**: Implement **Option B** (decouple from WordPress) for long-term maintainability, OR **Option A** (mock WordPress functions) for quick publication.

After fixing this issue and the PHP type hints, the library will be ready for Packagist publication and can serve as a standalone kernel for dual-native implementations across PHP ecosystems.

---

**Generated**: November 25, 2025
**Maintainer**: Review and implement recommendations above
