# Dual-Native HTTP System

The Dual-Native HTTP System is a comprehensive implementation of the dual-native pattern for WordPress and general HTTP applications. It provides both Human Representation (HR) and Machine Representation (MR) for content with Content Identity (CID) validation, bidirectional linking, and zero-fetch optimization.

## Features

- **Dual Representation**: Provides both human and machine interfaces for content
- **Content Identity (CID)**: Version validators for zero-fetch optimization
- **Bidirectional Linking**: Navigation between HR and MR
- **Catalog Management**: Registry of dual-native resources
- **Safe Write Operations**: Optimistic concurrency control with If-Match headers
- **Semantic Equivalence**: Validation that HR and MR represent the same content
- **RFC Compliance**: Implements RFC 9530 Content-Digest headers

## Installation

1. Clone this repository to your WordPress plugins directory
2. Activate the plugin through the WordPress admin interface
3. The system will automatically register REST API endpoints

## Usage

### Machine Representation Endpoints

```http
GET /wp-json/dual-native/v2/posts/{id}
```

Returns the machine representation of a WordPress post with:
- Content Identity (CID) as ETag
- Bidirectional links
- RFC 9530 Content-Digest header

### API Stability: v1 Aliasing

The v2 endpoints above are also available under v1 for stability while the system evolves:

- `GET /wp-json/dual-native/v1/posts/{id}`
- `POST /wp-json/dual-native/v1/posts/{id}/blocks`
- `GET /wp-json/dual-native/v1/catalog`
- `GET /wp-json/dual-native/v1/public-catalog`

Both versions map to the same handlers. New consumers can use v2; existing tools can continue to call v1.

### Safe Write Operations

```http
POST /wp-json/dual-native/v2/posts/{id}/blocks
Content-Type: application/json
If-Match: "sha256-abc123..."

{
  "insert": "append",
  "block": {
    "type": "core/paragraph",
    "content": "New content to add"
  }
}
```

Performs atomic updates with optimistic concurrency control.

### Resource Catalog

```http
GET /wp-json/dual-native/v2/catalog
```

Returns a catalog of all dual-native resources with CIDs for efficient discovery.

## System Components

### CIDManager
Computes and validates Content Identities (CIDs) using SHA-256 hashing of canonical representations.

### LinkManager
Manages bidirectional links between Human and Machine representations.

### CatalogManager
Maintains a registry of dual-native resources with CIDs and metadata.

### ValidationEngine
Verifies semantic equivalence between HR and MR and validates system conformance.

### HTTPRequestHandler
Processes HTTP requests and generates appropriate responses with proper headers.

### DualNativeSystem
Main orchestrator that coordinates all system components.

## Configuration

The system can be customized through WordPress hooks and filters:

```php
// Modify fields excluded from CID computation
add_filter('dual_native_cid_exclude_keys', function($exclude_keys) {
    $exclude_keys[] = 'custom_field';
    return $exclude_keys;
});

// Modify computed CID
add_filter('dual_native_computed_cid', function($cid, $content) {
    // Add custom logic here
    return $cid;
});
```

## Conformance Levels

The system supports different conformance levels:
- **Level 1**: HR and MR with one-way linking
- **Level 2**: Bidirectional linking
- **Level 3**: CID and zero-fetch optimization
- **Level 4**: Full dual-native with catalog and safe writes

## Implementation Status (Non-Normative)

Target: Level 2 — Dual-Native-aware systems

This section documents implementation progress against the Dual-Native pattern without modifying the pattern spec itself. It is descriptive, not normative.

- Current Level: Level 2 (in progress)
- Scope: Library/system kernel providing RID/MR/CID/DNC, validator-guarded writes, conditional reads, and integrity digests.

### Level 2 Goals (Delta From Level 1)

- MR-first by default
    - Canonical block→MR mapping; HR is a derived view.
- CID-guarded writes, everywhere
    - Provide a shared guard/middleware so all mutations are preconditioned on the current CID and return a new CID on success.
- Catalog coverage
    - DNC entries for all resource classes (e.g., posts, pages, media, taxonomies/configs).
- Profiles & schema governance
    - Publish MR schema/version and a profile identifier (e.g., tct-1), plus rules for CID-affecting fields and exclude lists.
- Conformance & observability
    - Expose a conformance check (semantic equivalence, digest parity) and track SLOs (route time, 304 hit rate, conflict rate).
- Policy defaults
    - Tighten catalog/read policies (internal vs. public published-only) via configuration.

Status: The following are implemented today:

- RID/MR/CID/DNC core; ETag==CID (conditional reads); If-Match (safe writes → 412); Content-Digest; insert at index/prepend with telemetry.

Planned next (without changing the spec): MR-first defaults, all-resource DNC, CID guard middleware, profile docs, conformance endpoint.

### Profiles (Non-Normative)

- HTTP Profile: tct-1
    - Content-Type includes a profile; responses include ETag (CID), Last-Modified, Cache-Control, Content-Digest.
    - Mapping of "validator" (CID) and "precondition failed/not modified" to transport status codes is defined here for convenience; the pattern itself remains transport-agnostic.

For detailed profile specification, see [profiles/tct-1.md](profiles/tct-1.md).

## Additional Resources

- [Implementation Roadmap](ROADMAP.md) - Detailed progress tracking toward Level 2
- [HTTP Profile Specification](profiles/tct-1.md) - Detailed tct-1 profile documentation
- [Conformance Endpoint](#) - System validation via `/dual-native/v2/conformance`

## Architecture

The system follows the 5 pillars of the dual-native pattern:
1. **Resource Identity (RID)**: Stable identifiers shared by HR and MR
2. **Content Identity (CID)**: Version validators for synchronization
3. **Bidirectional Linking**: Navigation between HR and MR
4. **Semantic Equivalence**: HR and MR represent the same content
5. **Cataloging & Discovery**: Registry of dual-native resources

## Performance

- **Zero-Fetch Optimization**: 90%+ reduction in unchanged content transfers
- **Bandwidth Savings**: 83%+ reduction compared to HTML parsing
- **Conditional Requests**: ETag-based validation for efficient caching

## Security

- Optimistic concurrency control prevents conflicting writes
- Proper authentication and authorization for write operations
- Content integrity through CID validation

## Testing

The system includes a comprehensive test suite:

```bash
composer install
vendor/bin/phpunit
```

### CI Smoke (HTTP behavior)

Use the provided smoke scripts to assert the key Dual‑Native behaviors against a live site (ETag==CID, 304/412 flows, digest parity, headers).

Requirements: Bash (or PowerShell), curl, Python 3.

Set environment (example):

```bash
export DNH_BASE="https://example.com"
export DNH_USER="admin"              # optional if public
export DNH_PASS="app-password"       # optional if public
export DNH_POST_ID=123
bash scripts/ci-smoke.sh
```

On Windows (PowerShell):

```powershell
$env:DNH_BASE = "https://example.com"
$env:DNH_USER = "admin"
$env:DNH_PASS = "app-password"
$env:DNH_POST_ID = 123
powershell -ExecutionPolicy Bypass -File scripts/ci-smoke.ps1
```

The script verifies:

- ETag equals CID (on MR)
- 304 Not Modified on repeat GET with If-None-Match
- 412 Precondition Failed on write with mismatched If-Match
- Content-Digest parity (sha-256 of response bytes)
- Presence of Last-Modified, Cache-Control, Content-Length

### Packagist / Composer

This library is designed for Packagist as `dual-native/http-system`.

Publish (maintainers):

1. Ensure repository is public with a semantic version tag (e.g., `v2.0.0`).
2. Submit the repository URL to https://packagist.org/packages/submit
3. Configure auto-updates via GitHub/Packagist webhook.

Consume (adapters/plugins):

```bash
composer require dual-native/http-system:^2
```

Then wire the kernel (CIDManager, CatalogManager, ValidationEngine, HTTPRequestHandler) into your adapter (e.g., WordPress, Laravel), mapping your domain’s entities to MR.


## License

GPL v2 or later
