# Dual-Native HTTP System Roadmap

## Level 2 Goals Status

Target: Level 2 — Dual-Native-aware systems (Implementation in Progress)

### Implemented
- [x] RID/MR/CID/DNC core functionality
- [x] ETag==CID for conditional reads
- [x] If-Match for safe writes (results in 412 errors on conflict)
- [x] Content-Digest header (RFC 9530)
- [x] Insert at index/prepend with telemetry
- [x] Block-aware MR mapping for Gutenberg content
- [x] Validator guard middleware for reusable validation
- [x] Conformance endpoint for system validation
- [x] Public vs. internal catalog endpoints
- [x] All-standard response headers (ETag, Last-Modified, Cache-Control, Content-Length)

### Current Focus
- [x] MR-first defaults with canonical block→MR mapping
- [x] CID-guard middleware for validation
- [x] DNC coverage extended to all resource classes
- [x] Conformance endpoint providing validation checks

### Planned Next
- [ ] MR-first defaults fully implemented across all operations
- [ ] Complete catalog coverage for all WordPress resource types (media, taxonomies, users, etc.)
- [ ] Enhanced conformance endpoint with detailed validation results
- [ ] Performance monitoring endpoint with SLO tracking
- [ ] Complete profile documentation for tct-1
- [ ] CID guard middleware applied to all mutation endpoints
- [ ] Client libraries and examples for easy integration
- [ ] Advanced catalog filtering options
- [ ] Webhook support for real-time updates
- [ ] Bulk operations support

## Implementation Steps

### Phase 1: Core Stability (Current)
- Complete Level 2 requirements
- Test conformance against dual-native pattern
- Document all available endpoints
- Create comprehensive examples

### Phase 2: Expansion
- Extend to all WordPress content types
- Add advanced filtering and pagination
- Implement performance monitoring
- Add support for additional content formats

### Phase 3: Optimization
- Advanced caching strategies
- Bulk operations support
- Enhanced error handling and reporting
- Additional profile definitions (tct-2, etc.)

## Conformance Targets

### Level 1: HR + MR with Links
- [x] Human Representation available
- [x] Machine Representation available
- [x] One-way linking implemented

### Level 2: Bidirectional Links
- [x] Bidirectional linking between HR ↔ MR
- [x] MR-first architecture principles
- [ ] Full resource class coverage

### Level 3: CID Validation
- [x] CID computation and validation
- [x] Conditional GET with 304 responses
- [x] Safe write operations with If-Match
- [x] Content-Digest headers (RFC 9530)

### Level 4: Full Dual-Native
- [x] Dual-Native Catalog (DNC)
- [x] Catalog filtering and pagination
- [ ] Complete resource class coverage
- [ ] Comprehensive conformance checking
- [ ] Advanced observability features

## Timeline
- **Level 2 Complete**: Target Q1 2025
- **Level 3 Complete**: Target Q2 2025
- **Level 4 Complete**: Target Q3 2025