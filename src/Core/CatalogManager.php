<?php

namespace DualNative\HTTP\Core;

use DualNative\HTTP\Events\EventDispatcherInterface;
use DualNative\HTTP\Events\NullEventDispatcher;
use DualNative\HTTP\Storage\StorageInterface;
use DualNative\HTTP\Storage\InMemoryStorage;

use DualNative\HTTP\Config\Config;

/**
 * Catalog Manager for Dual-Native HTTP System
 * 
 * Handles the Dual-Native Catalog (DNC) - registry of dual-native resources
 */
class CatalogManager
{
    /**
     * @var CIDManager
     */
    private $cidManager;
    
    /**
     * @var LinkManager
     */
    private $linkManager;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var StorageInterface
     */
    private $storage;
    
    /**
     * Constructor
     * 
     * @param CIDManager $cidManager
     * @param LinkManager $linkManager
     */
    public function __construct(
        CIDManager $cidManager,
        LinkManager $linkManager,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?StorageInterface $storage = null
    )
    {
        $this->cidManager = $cidManager;
        $this->linkManager = $linkManager;
        $this->eventDispatcher = $eventDispatcher ?? new NullEventDispatcher();
        $this->storage = $storage ?? new InMemoryStorage();
    }
    
    /**
     * Add or update a resource in the catalog
     * 
     * @param string $rid Resource Identity
     * @param string $hrUrl Human Representation URL
     * @param string $mrUrl Machine Representation URL
     * @param string $cid Content Identity
     * @param string|null $updatedAt Last update timestamp (ISO 8601)
     * @param array $metadata Additional metadata
     * @return bool True on success, false on failure
     */
    public function updateCatalogEntry(string $rid, string $hrUrl, string $mrUrl, string $cid, ?string $updatedAt = null, array $metadata = []): bool
    {
        if ($updatedAt === null) {
            $updatedAt = gmdate('c'); // ISO 8601 format
        }
        
        $catalogEntry = [
            'rid' => $rid,
            'hr' => $hrUrl,
            'mr' => $mrUrl,
            'content_id' => $cid,
            'updatedAt' => $updatedAt,
            'profile' => Config::HTTP_PROFILE
        ];
        
        // Add any additional metadata
        foreach ($metadata as $key => $value) {
            $catalogEntry[$key] = $value;
        }
        
        // Apply filters for customization
        $catalogEntry = $this->eventDispatcher->filter('dual_native_catalog_entry', $catalogEntry, $rid);
        
        // Store the catalog entry (in WordPress meta, DB, etc.)
        $result = $this->storeCatalogEntry($rid, $catalogEntry);
        
        return $this->eventDispatcher->filter('dual_native_catalog_update_result', $result, $catalogEntry);
    }
    
    /**
     * Remove a resource from the catalog
     * 
     * @param string $rid Resource Identity
     * @return bool True on success, false on failure
     */
    public function removeCatalogEntry(string $rid): bool
    {
        $result = $this->deleteCatalogEntry($rid);
        return $this->eventDispatcher->filter('dual_native_catalog_remove_result', $result, $rid);
    }
    
    /**
     * Get a catalog entry by RID
     * 
     * @param string $rid Resource Identity
     * @return array|null Catalog entry or null if not found
     */
    public function getCatalogEntry(string $rid): ?array
    {
        $entry = $this->fetchCatalogEntry($rid);
        
        if ($entry) {
            return $this->eventDispatcher->filter('dual_native_catalog_get_entry', $entry, $rid);
        }
        
        return null;
    }
    
    /**
     * Get the complete catalog with optional filtering
     *
     * @param string|null $since ISO 8601 timestamp to get entries updated since
     * @param array $filters Additional filters (status, types, etc.)
     * @param int $limit Maximum number of entries to return
     * @param int $offset Offset for pagination
     * @return array Catalog data
     */
    public function getCatalog(?string $since = null, array $filters = [], int $limit = 0, int $offset = 0): array
    {
        $entries = $this->fetchCatalogEntries($since, $filters, $limit, $offset);

        // Determine the catalog's "last updated" timestamp as the most recent entry
        $maxUpdatedAt = null;
        foreach ($entries as $entry) {
            if (isset($entry['updatedAt']) && (!$maxUpdatedAt || $entry['updatedAt'] > $maxUpdatedAt)) {
                $maxUpdatedAt = $entry['updatedAt'];
            }
        }

        // Convert to ISO 8601 if it's a timestamp string
        if ($maxUpdatedAt) {
            $maxUpdatedAt = $this->convertToISO8601($maxUpdatedAt);
        } else {
            $maxUpdatedAt = gmdate('c'); // fallback to current time
        }

        $catalog = [
            'version' => 1,
            'profile' => Config::HTTP_PROFILE,
            'updatedAt' => $maxUpdatedAt,
            'items' => $entries,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => count($entries)
            ]
        ];

        if ($since) {
            $catalog['since'] = $since;
        }

        return $this->eventDispatcher->filter('dual_native_catalog', $catalog, $since, $filters, $limit, $offset);
    }

    /**
     * Convert various timestamp formats to ISO 8601
     *
     * @param string $timestamp Timestamp in various formats
     * @return string ISO 8601 formatted timestamp
     */
    private function convertToISO8601(string $timestamp): string
    {
        // Handle common MySQL timestamp formats
        $timestamp = trim($timestamp);

        // Try to parse the timestamp
        if ($timestampObj = date_create($timestamp)) {
            return $timestampObj->format('c');
        }

        // Fallback to current time if parsing fails
        return gmdate('c');
    }

    /**
     * Get catalog entries for a specific resource type (posts, pages, media, etc.)
     *
     * @param string $resourceType Type of resource (post, page, attachment, etc.)
     * @param string|null $since ISO 8601 timestamp
     * @param array $filters Additional filters
     * @param int $limit Maximum number of entries
     * @param int $offset Offset for pagination
     * @return array Catalog entries for the specified resource type
     */
    public function getCatalogForResourceType(string $resourceType, ?string $since = null, array $filters = [], int $limit = 0, int $offset = 0): array
    {
        // Add resource type to filters
        $filters['type'] = $resourceType;
        return $this->getCatalog($since, $filters, $limit, $offset);
    }

    /**
     * Add catalog entry for different resource types
     *
     * @param string $resourceType Type of resource (post, page, attachment, etc.)
     * @param string $rid Resource Identity
     * @param string $hrUrl Human Representation URL
     * @param string $mrUrl Machine Representation URL
     * @param string $cid Content Identity
     * @param string|null $updatedAt Last update timestamp
     * @param array $metadata Additional metadata
     * @return bool True on success, false on failure
     */
    public function addResourceToCatalog(string $resourceType, string $rid, string $hrUrl, string $mrUrl, string $cid, ?string $updatedAt = null, array $metadata = []): bool
    {
        $catalogEntry = [
            'rid' => $rid,
            'type' => $resourceType,
            'hr' => $hrUrl,
            'mr' => $mrUrl,
            'content_id' => $cid,
            'updatedAt' => $updatedAt ?: gmdate('c'),
            'profile' => Config::HTTP_PROFILE
        ];

        // Add any additional metadata
        foreach ($metadata as $key => $value) {
            $catalogEntry[$key] = $value;
        }

        // Apply filters for customization
        $catalogEntry = $this->eventDispatcher->filter('dual_native_catalog_entry', $catalogEntry, $rid, $resourceType);

        // Store the catalog entry
        return $this->storeCatalogEntry($rid, $catalogEntry);
    }
    
    /**
     * Validate catalog consistency by checking if CIDs match live content
     * 
     * @param array $catalogEntries Optional specific entries to validate
     * @return array Validation results with any inconsistencies
     */
    public function validateCatalog(?array $catalogEntries = null): array
    {
        if ($catalogEntries === null) {
            $catalogEntries = $this->fetchCatalogEntries();
        }
        
        $inconsistencies = [];
        
        foreach ($catalogEntries as $entry) {
            $rid = $entry['rid'] ?? '';
            $catalogCid = $entry['content_id'] ?? '';
            
            // Get the live MR to compare CID
            $liveMr = $this->fetchLiveMR($entry['mr'] ?? '');
            
            if ($liveMr && isset($liveMr['cid'])) {
                $liveCid = $liveMr['cid'];
                
                if ($catalogCid !== $liveCid) {
                    $inconsistencies[] = [
                        'rid' => $rid,
                        'catalog_cid' => $catalogCid,
                        'live_cid' => $liveCid,
                        'status' => 'cid_mismatch'
                    ];
                }
            } else {
                $inconsistencies[] = [
                    'rid' => $rid,
                    'status' => 'mr_not_accessible'
                ];
            }
        }
        
        return $this->eventDispatcher->filter('dual_native_catalog_validation', $inconsistencies, $catalogEntries);
    }
    
    /**
     * Store catalog entry (implementation specific)
     * 
     * @param string $rid Resource Identity
     * @param array $entry Catalog entry
     * @return bool True on success, false on failure
     */
    private function storeCatalogEntry(string $rid, array $entry): bool
    {
        // In WordPress context, this could be stored as an option
        // or in a custom database table
        $allEntries = $this->fetchAllCatalogEntries();
        $allEntries[$rid] = $entry;
        
        return $this->storage->set('dual_native_catalog', $allEntries);
    }
    
    /**
     * Delete catalog entry (implementation specific)
     * 
     * @param string $rid Resource Identity
     * @return bool True on success, false on failure
     */
    private function deleteCatalogEntry(string $rid): bool
    {
        $allEntries = $this->fetchAllCatalogEntries();
        unset($allEntries[$rid]);
        
        return $this->storage->set('dual_native_catalog', $allEntries);
    }
    
    /**
     * Fetch catalog entry by RID (implementation specific)
     * 
     * @param string $rid Resource Identity
     * @return array|null Catalog entry or null if not found
     */
    private function fetchCatalogEntry(string $rid): ?array
    {
        $allEntries = $this->fetchAllCatalogEntries();
        return $allEntries[$rid] ?? null;
    }
    
    /**
     * Fetch multiple catalog entries with optional filtering
     * 
     * @param string|null $since ISO 8601 timestamp
     * @param array $filters Additional filters
     * @param int $limit Maximum number of entries
     * @param int $offset Offset for pagination
     * @return array Array of catalog entries
     */
    private function fetchCatalogEntries(?string $since = null, array $filters = [], int $limit = 0, int $offset = 0): array
    {
        $allEntries = $this->fetchAllCatalogEntries();
        
        // Apply "since" filter if specified
        if ($since) {
            $filteredEntries = [];
            foreach ($allEntries as $rid => $entry) {
                if (isset($entry['updatedAt']) && $entry['updatedAt'] > $since) {
                    $filteredEntries[$rid] = $entry;
                }
            }
            $allEntries = $filteredEntries;
        }
        
        // Apply additional filters
        $allEntries = $this->applyFilters($allEntries, $filters);
        
        // Apply pagination
        $entries = array_values($allEntries); // Re-index
        
        if ($limit > 0) {
            $entries = array_slice($entries, $offset, $limit);
        }
        
        return $entries;
    }
    
    /**
     * Apply filters to catalog entries
     * 
     * @param array $entries Catalog entries
     * @param array $filters Filters to apply
     * @return array Filtered entries
     */
    private function applyFilters(array $entries, array $filters): array
    {
        if (empty($filters)) {
            return $entries;
        }
        
        $filteredEntries = [];
        
        foreach ($entries as $rid => $entry) {
            $matches = true;
            
            foreach ($filters as $filterKey => $filterValue) {
                if (!isset($entry[$filterKey])) {
                    $matches = false;
                    break;
                }
                
                // Handle special cases and different filter types
                if (is_array($filterValue)) {
                    // Check if the value is in the array
                    if (!in_array($entry[$filterKey], $filterValue)) {
                        $matches = false;
                        break;
                    }
                } else {
                    // Simple equality check
                    if ($entry[$filterKey] !== $filterValue) {
                        $matches = false;
                        break;
                    }
                }
            }
            
            if ($matches) {
                $filteredEntries[$rid] = $entry;
            }
        }
        
        return $filteredEntries;
    }
    
    /**
     * Fetch all catalog entries from storage
     * 
     * @return array All catalog entries
     */
    private function fetchAllCatalogEntries(): array
    {
        $entries = $this->storage->get('dual_native_catalog', []);
        return is_array($entries) ? $entries : [];
    }
    
    /**
     * Fetch live Machine Representation for validation
     * 
     * @param string $mrUrl URL to the MR
     * @return array|null Live MR data or null on failure
     */
    private function fetchLiveMR(string $mrUrl): ?array
    {
        // This would typically make an HTTP request to the MR URL
        // For WordPress, it might call the internal REST API
        // This is a simplified version that assumes we can access the data internally
        
        // Extract resource ID from URL if possible
        if (preg_match('/\/(\d+)(?:\/|$)/', $mrUrl, $matches)) {
            $resourceId = $matches[1];
            
            // This would be specific to WordPress context
            // For now, returning null as a placeholder
            return null;
        }
        
        return null;
    }
    
    /**
     * Purge the catalog (remove all entries)
     * 
     * @return bool True on success, false on failure
     */
    public function purgeCatalog(): bool
    {
        return delete_option('dual_native_catalog');
    }
}