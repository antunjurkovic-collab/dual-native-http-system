# Dual-Native HTTP Profile: tct-1

This document defines the tct-1 HTTP profile for the Dual-Native pattern implementation in the Dual-Native HTTP System.

## Profile Overview

The tct-1 profile specifies how the dual-native pattern is implemented over HTTP, building on the standard TCT (Collaboration Tunnel Protocol) specifications.

## Required MR Fields

All Machine Representations (MR) in the tct-1 profile MUST include:

### Core Fields
- `rid`: Resource Identity (string, stable identifier)
- `cid`: Content Identity (string, format: `sha256-<hex>`)
- `profile`: Profile identifier (string, fixed value: `tct-1`)
- `mr_schema`: MR schema version (string, fixed value: `tct-1`)
- `links`: Object containing bidirectional links

### Content Fields
- `title`: Resource title (string)
- `content`: Resource content (string) or `blocks` array
- `status`: Publication status (string)
- `author`: Author object with `id` and `name`
- `published`: Publication datetime (ISO 8601 string)
- `modified`: Last modification datetime (ISO 8601 string)

### Block-Specific Fields (when applicable)
- `blocks`: Array of structured blocks
- `core_content_text`: Plain text content (string)
- `word_count`: Number of words in content (integer)

## CID Exclude Rules

The following fields are excluded from Content Identity (CID) computation by default:

- `cid` - Content Identity field itself
- `links` - Links to other resources
- `modified` - Timestamp field (would cause false changes)
- `updatedAt` - Catalog timestamp

Custom implementations MAY exclude additional fields that don't affect semantic content.

## Determinism Rules

To ensure consistent CIDs across systems:

1. **Key Ordering**: Object keys MUST be sorted lexicographically
2. **Array Handling**: Arrays are processed in order, objects by sorted keys
3. **Timestamps**: Use consistent formatting (ISO 8601 with 'T' separator)
4. **Whitespace**: Excess whitespace is normalized (consecutive spaces collapsed to single space)

## Transport Mapping

### HTTP Headers

The profile defines specific HTTP header mappings:

#### Response Headers
- `ETag`: Contains the Resource's CID in quoted format: `"sha256-<hex>"`
- `Last-Modified`: RFC 2822 formatted modification time
- `Content-Type`: `application/json; profile="tct-1"` or variant
- `Cache-Control`: `no-cache` for dynamic content
- `Content-Digest`: RFC 9530 header with Base64-encoded SHA-256 digest

#### Request Headers for Conditional Operations
- `If-None-Match`: Used for conditional GET requests (304 responses)
- `If-Match`: Used for safe write operations (precondition validation)

### HTTP Status Codes

The profile maps dual-native concepts to HTTP status codes:

- **200 OK**: Successful resource retrieval
- **200 OK**: Successful safe write operation with updated resource
- **304 Not Modified**: Content unchanged (conditional GET)
- **412 Precondition Failed**: If-Match CID mismatch (safe write conflict)
- **428 Precondition Required**: Missing If-Match header for safe write
- **405 Method Not Allowed**: Unsupported HTTP method

## Semantic Equivalence Requirements

For HR ↔ MR semantic equivalence validation, the following fields MUST match:

- `title` between HR and MR
- Core content (either `content`, `blocks`, or `core_content_text`)
- `modified` timestamps (accounting for formatting differences)
- `status` field

## Content Formats

### Block Format
```json
{
  "type": "core/paragraph|core/heading|core/list|core/image|core/code|core/quote",
  "content": "text content",
  "level": 2,  // for headings
  "ordered": true,  // for lists
  "items": [],      // for lists
  "imageId": 123,   // for images
  "altText": "",    // for images
  "url": ""         // for images
}
```

### Link Format
```json
{
  "links": {
    "human_url": "https://example.com/resource",
    "api_url": "https://api.example.com/v2/resource"
  }
}
```

## Validation Endpoints

The profile provides:
- `/conformance` - System conformance information
- `/catalog` - Catalog of resources (authenticated)
- `/public-catalog` - Public catalog of published resources
- `/posts/{id}` - Individual resource MR
- `/posts/{id}/blocks` - Safe write operations

## Security Considerations

1. **Authentication**: Write operations require authentication
2. **Catalog Access**: Internal catalog requires edit permissions
3. **Public Access**: Public catalog only shows published content
4. **CID Validation**: Always validate CIDs before safe operations

## Dynamic State Separation (SID)

For resources with volatile dynamic state (like view counts, last accessed times, etc.), the tct-1 profile supports an optional State ID (SID) to separate dynamic elements from the Content Identity (CID).

### CID vs SID Scope
- **CID** covers stable content that affects semantic meaning
- **SID** covers dynamic state that changes frequently but doesn't affect core content meaning
- By default, all fields are part of CID unless explicitly excluded

### SID Implementation
While not required, implementations may track dynamic state separately:

```json
{
  "rid": "resource-123",
  "cid": "sha256-abc123...",  // Stable content hash
  "sid": "sha256-xyz789...", // Dynamic state hash (optional)
  "title": "Article Title",
  "content": "Stable content here...",
  "dynamic": {
    "viewCount": 1234,
    "lastAccessed": "2025-01-15T10:30:00Z"
  }
}
```

Dynamic fields should be explicitly excluded from CID computation using the CID exclude rules.

## Versioning

This is the tct-1 profile specification. Future versions will be tct-1.x with backward compatibility maintained for basic operations.