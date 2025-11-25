# Dual-Native HTTP System Examples

## Conformance Endpoint

Check system conformance to dual-native pattern:

```bash
curl -i https://yoursite.com/wp-json/dual-native/v2/conformance
```

## Conditional Requests (304 Not Modified)

First request to get the resource and ETag:

```bash
curl -i https://yoursite.com/wp-json/dual-native/v2/posts/123
# Response includes: ETag: "sha256-abc123..."
```

Subsequent request with ETag to potentially get 304:

```bash
curl -i -H 'If-None-Match: "sha256-abc123..."' https://yoursite.com/wp-json/dual-native/v2/posts/123
# Returns 304 Not Modified if content unchanged
```

## Safe Write Operations (412 Precondition Failed)

Perform a safe write with If-Match header:

```bash
curl -i -X POST \
  -H 'Content-Type: application/json' \
  -H 'X-WP-Nonce: YOUR_NONCE' \
  -H 'If-Match: "sha256-current_cid..."' \
  -d '{
    "insert": "index",
    "index": 2,
    "block": {
      "type": "core/paragraph",
      "content": "New paragraph"
    }
  }' \
  https://yoursite.com/wp-json/dual-native/v2/posts/123/blocks
```

## Block Insertion Operations

Append a block to the end:

```bash
curl -i -X POST \
  -H 'Content-Type: application/json' \
  -H 'X-WP-Nonce: YOUR_NONCE' \
  -H 'If-Match: "sha256-existing_cid..."' \
  -d '{
    "insert": "append",
    "block": {
      "type": "core/heading",
      "level": 3,
      "content": "New Heading"
    }
  }' \
  https://yoursite.com/wp-json/dual-native/v2/posts/123/blocks
```

Insert a block at a specific index:

```bash
curl -i -X POST \
  -H 'Content-Type: application/json' \
  -H 'X-WP-Nonce: YOUR_NONCE' \
  -H 'If-Match: "sha256-existing_cid..."' \
  -d '{
    "insert": "index",
    "index": 1,
    "block": {
      "type": "core/list",
      "ordered": false,
      "items": ["First item", "Second item"]
    }
  }' \
  https://yoursite.com/wp-json/dual-native/v2/posts/123/blocks
```

## Using the Validator Guard (PHP)

For implementing safe operations in your own code:

```php
use DualNative\HTTP\HTTP\ValidatorGuard;
use DualNative\HTTP\Core\CIDManager;

$cidManager = new CIDManager();
$validatorGuard = new ValidatorGuard($cidManager);

// Validate a write operation
$headers = $_SERVER; // or from your request object
$currentResource = get_resource_data();

$result = $validatorGuard->validateIfMatch($headers, $currentResource);

if (!$result['valid']) {
    // Return 412 error to client
    http_response_code(412);
    header('ETag: "' . $result['currentCid'] . '"');
    echo json_encode([
        'error' => 'Precondition Failed',
        'message' => $result['error']
    ]);
    return;
}

// Safe to proceed with the write operation
update_resource($newData);
```

## Catalog Endpoints

Get the internal catalog (requires edit_posts capability):

```bash
curl -i https://yoursite.com/wp-json/dual-native/v2/catalog
```

Get the public catalog (published content only):

```bash
curl -i https://yoursite.com/wp-json/dual-native/v2/public-catalog
```

Filter catalog by date:

```bash
curl -i "https://yoursite.com/wp-json/dual-native/v2/catalog?since=2025-01-01T00:00:00Z"
```

Filter catalog by resource type (posts, pages, attachments, etc.):

```bash
curl -i "https://yoursite.com/wp-json/dual-native/v2/catalog?type=page"
curl -i "https://yoursite.com/wp-json/dual-native/v2/catalog?type=attachment"
curl -i "https://yoursite.com/wp-json/dual-native/v2/catalog?type=post"
```

The catalog system supports all WordPress content types:
- **Posts**: Standard blog posts (post_type='post')
- **Pages**: Static pages (post_type='page')
- **Attachments**: Media files (post_type='attachment')
- **Custom post types**: Any registered custom post types

The `save_post` hook automatically captures changes to any post type (posts, pages, etc.) in the catalog with the appropriate resource type.

## Conformance Endpoint

Check the system's conformance to the dual-native pattern:

```bash
curl -i https://yoursite.com/wp-json/dual-native/v2/conformance
```

The conformance endpoint returns information about:
- Current conformance level
- Available features (semantic equivalence, content digest, etc.)
- Validation results
- System health information

Example response:
```json
{
  "level": 2,
  "passedRequirements": ["hr_mr_with_link", "bidirectional_linking", "cid_and_zero_fetch"],
  "failedRequirements": [],
  "details": [
    "Level 1: HR and MR with HR→MR link exists",
    "Level 2: Bidirectional linking achieved",
    "Level 3: CID and zero-fetch optimization achieved"
  ],
  "checks": {
    "semantic_equivalence": {
      "description": "Semantic equivalence validation is available",
      "status": "available"
    },
    "content_digest": {
      "description": "RFC 9530 Content-Digest header support",
      "status": "implemented"
    }
  }
}
```

The endpoint also supports conditional requests using If-None-Match headers, returning 304 if the conformance status hasn't changed.