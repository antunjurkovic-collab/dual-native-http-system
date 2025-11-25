<?php

namespace DualNative\HTTP;

use DualNative\HTTP\Core\CIDManager;
use DualNative\HTTP\Core\LinkManager;
use DualNative\HTTP\Core\CatalogManager;
use DualNative\HTTP\Validation\ValidationEngine;
use DualNative\HTTP\HTTP\HTTPRequestHandler;
use DualNative\HTTP\Config\Config;
use DualNative\HTTP\Events\EventDispatcherInterface;
use DualNative\HTTP\Events\NullEventDispatcher;
use DualNative\HTTP\Storage\StorageInterface;
use DualNative\HTTP\Storage\InMemoryStorage;

/**
 * Main Dual-Native HTTP System Orchestrator
 * 
 * The core system that coordinates all dual-native functionality
 */
class DualNativeSystem
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
     * @var CatalogManager
     */
    private $catalogManager;
    
    /**
     * @var ValidationEngine
     */
    private $validationEngine;
    
    /**
     * @var HTTPRequestHandler
     */
    private $requestHandler;
    
    /**
     * @var array System configuration
     */
    private $config;
    
    /**
     * @var EventDispatcherInterface|null
     */
    private $eventDispatcher;
    
    /**
     * @var StorageInterface|null
     */
    private $storage;
    
    /**
     * Constructor
     * 
     * @param array $config System configuration
     */
    public function __construct(
        array $config = [],
        ?EventDispatcherInterface $eventDispatcher = null,
        ?StorageInterface $storage = null
    ) {
        $this->config = $config + [
            'version' => Config::VERSION,
            'profile' => Config::DEFAULT_PROFILE,
            'exclude_fields' => Config::CID_EXCLUDE_FIELDS,
            'cache_ttl' => Config::CACHE_TTL
        ];

        // Initialize dependencies BEFORE components that use them
        $this->eventDispatcher = $eventDispatcher ?? new NullEventDispatcher();
        $this->storage = $storage ?? new InMemoryStorage();

        // Initialize core components (now dependencies are available)
        $this->initializeComponents();

        // Dispatch initialization event
        $this->eventDispatcher->dispatch('dual_native_system_initialized', $this);
    }
    
    /**
     * Initialize all system components
     */
    private function initializeComponents(): void
    {
        // Create core managers with dependency injection
        $this->cidManager = new CIDManager($this->eventDispatcher);
        $this->linkManager = new LinkManager($this->eventDispatcher);
        $this->catalogManager = new CatalogManager($this->cidManager, $this->linkManager, $this->eventDispatcher, $this->storage);
        $this->validationEngine = new ValidationEngine($this->eventDispatcher);
        $this->requestHandler = new HTTPRequestHandler($this->cidManager, $this->linkManager, $this->catalogManager, $this->validationEngine, $this->eventDispatcher);
    }
    
    /**
     * Process an HTTP request through the dual-native system
     * 
     * @param array $request The HTTP request data
     * @return array The HTTP response data
     */
    public function processRequest(array $request): array
    {
        return $this->requestHandler->processRequest($request);
    }
    
    /**
     * Create a dual-native resource with both HR and MR representations
     * 
     * @param string $rid Resource Identity
     * @param mixed $humanRepresentation Human Representation data
     * @param mixed $machineRepresentation Machine Representation data
     * @param array $metadata Additional metadata
     * @return array Result containing resource information
     */
    public function createResource(string $rid, $humanRepresentation, $machineRepresentation, array $metadata = []): array
    {
        // Compute CID for the machine representation
        $mrCid = $this->cidManager->computeCID($machineRepresentation);
        
        // Generate bidirectional links
        $links = $this->linkManager->generateBidirectionalLinks(
            $rid,
            $humanRepresentation['url'] ?? '',
            $machineRepresentation['api_url'] ?? ''
        );
        
        // Add links to the machine representation
        $machineRepresentation['links'] = $links['mr'];
        
        // Update the catalog with this resource
        $catalogResult = $this->catalogManager->updateCatalogEntry(
            $rid,
            $humanRepresentation['url'] ?? '',
            $machineRepresentation['api_url'] ?? '',
            $mrCid,
            null,
            $metadata
        );
        
        $result = [
            'rid' => $rid,
            'cid' => $mrCid,
            'hr' => $humanRepresentation,
            'mr' => $machineRepresentation,
            'links' => $links,
            'catalog_updated' => $catalogResult
        ];
        
        return $this->eventDispatcher->filter('dual_native_created_resource', $result, $rid, $humanRepresentation, $machineRepresentation);
    }
    
    /**
     * Update a resource with safe write operations using CID validation
     * 
     * @param string $rid Resource Identity
     * @param mixed $newData New data for the resource
     * @param string $expectedCid Expected CID to validate against
     * @param bool $updateCatalog Whether to update the catalog
     * @return array Result of the update operation
     */
    public function updateResource(string $rid, $newData, string $expectedCid, bool $updateCatalog = true): array
    {
        // Validate the CID to ensure no concurrent modifications
        $currentResource = $this->getResource($rid);
        
        if (!$currentResource) {
            return [
                'success' => false,
                'error' => 'Resource not found',
                'rid' => $rid
            ];
        }
        
        $currentCid = $currentResource['cid'] ?? null;
        
        if (!$currentCid || !$this->cidManager->validateCID($currentResource['mr'], $expectedCid)) {
            return [
                'success' => false,
                'error' => 'CID mismatch - resource has been modified by another process',
                'rid' => $rid,
                'expected_cid' => $expectedCid,
                'actual_cid' => $currentCid
            ];
        }
        
        // Perform the update
        $updatedResource = $this->applyResourceUpdate($rid, $newData);
        
        if (!$updatedResource) {
            return [
                'success' => false,
                'error' => 'Failed to update resource',
                'rid' => $rid
            ];
        }
        
        // Compute new CID
        $newCid = $this->cidManager->computeCID($updatedResource['mr']);
        
        // Update catalog if requested
        if ($updateCatalog) {
            $this->catalogManager->updateCatalogEntry(
                $rid,
                $updatedResource['hr']['url'] ?? '',
                $updatedResource['mr']['api_url'] ?? '',
                $newCid
            );
        }
        
        return [
            'success' => true,
            'rid' => $rid,
            'new_cid' => $newCid,
            'resource' => $updatedResource
        ];
    }
    
    /**
     * Get a resource by its RID
     * 
     * @param string $rid Resource Identity
     * @return array|null Resource data or null if not found
     */
    public function getResource(string $rid): ?array
    {
        // In a real implementation, this would fetch from the data source
        // For now, we'll return null as a placeholder
        $catalogEntry = $this->catalogManager->getCatalogEntry($rid);
        
        if (!$catalogEntry) {
            return null;
        }
        
        // Fetch the actual resource data from the HR or MR URL
        // This is a simplified version
        return [
            'rid' => $rid,
            'hr' => [
                'url' => $catalogEntry['hr'] ?? '',
            ],
            'mr' => [
                'api_url' => $catalogEntry['mr'] ?? '',
                'content_id' => $catalogEntry['content_id'] ?? '',
            ],
            'cid' => $catalogEntry['content_id'] ?? '',
            'updatedAt' => $catalogEntry['updatedAt'] ?? null
        ];
    }
    
    /**
     * Validate semantic equivalence between HR and MR of a resource
     * 
     * @param mixed $hrContent Human Representation content
     * @param mixed $mrContent Machine Representation content
     * @param array $equivalenceScope Fields that must match between HR and MR
     * @return array Validation results
     */
    public function validateSemanticEquivalence($hrContent, $mrContent, ?array $equivalenceScope = null): array
    {
        return $this->validationEngine->validateSemanticEquivalence($hrContent, $mrContent, $equivalenceScope);
    }
    
    /**
     * Validate system conformance to dual-native standards
     * 
     * @param array $systemInfo Information about the system to validate
     * @return array Conformance validation results
     */
    public function validateConformance(array $systemInfo): array
    {
        return $this->validationEngine->validateConformance($systemInfo);
    }
    
    /**
     * Get the catalog of dual-native resources
     * 
     * @param string|null $since ISO 8601 timestamp to get entries updated since
     * @param array $filters Additional filters (status, types, etc.)
     * @param int $limit Maximum number of entries to return
     * @param int $offset Offset for pagination
     * @return array Catalog data
     */
    public function getCatalog(?string $since = null, array $filters = [], int $limit = 0, int $offset = 0): array
    {
        return $this->catalogManager->getCatalog($since, $filters, $limit, $offset);
    }
    
    /**
     * Compute a Content Identity for provided content
     * 
     * @param mixed $content The content to compute CID for
     * @param array $excludeKeys Fields to exclude from CID computation
     * @return string The computed CID
     */
    public function computeCID($content, ?array $excludeKeys = null): string
    {
        return $this->cidManager->computeCID($content, $excludeKeys ?: $this->config['exclude_fields']);
    }
    
    /**
     * Validate a Content Identity against content
     * 
     * @param mixed $content The content to validate against
     * @param string $expectedCID The CID to validate
     * @param array $excludeKeys Fields to exclude from CID computation
     * @return bool True if CIDs match, false otherwise
     */
    public function validateCID($content, string $expectedCID, ?array $excludeKeys = null): bool
    {
        return $this->cidManager->validateCID($content, $expectedCID, $excludeKeys ?: $this->config['exclude_fields']);
    }
    
    /**
     * Generate bidirectional links for a resource
     * 
     * @param string $rid Resource Identity
     * @param string $hrUrl Human Representation URL
     * @param string $mrUrl Machine Representation URL
     * @return array Array containing HR and MR links
     */
    public function generateLinks(string $rid, string $hrUrl, string $mrUrl): array
    {
        return $this->linkManager->generateBidirectionalLinks($rid, $hrUrl, $mrUrl);
    }
    
    /**
     * Run a comprehensive validation across the system
     * 
     * @param array $validationData Data to validate
     * @return array Comprehensive validation results
     */
    public function runComprehensiveValidation(array $validationData): array
    {
        return $this->validationEngine->runComprehensiveValidation($validationData);
    }
    
    /**
     * Get the CID manager instance
     * 
     * @return CIDManager
     */
    public function getCIDManager(): CIDManager
    {
        return $this->cidManager;
    }
    
    /**
     * Get the Link manager instance
     * 
     * @return LinkManager
     */
    public function getLinkManager(): LinkManager
    {
        return $this->linkManager;
    }
    
    /**
     * Get the Catalog manager instance
     * 
     * @return CatalogManager
     */
    public function getCatalogManager(): CatalogManager
    {
        return $this->catalogManager;
    }
    
    /**
     * Get the Validation engine instance
     * 
     * @return ValidationEngine
     */
    public function getValidationEngine(): ValidationEngine
    {
        return $this->validationEngine;
    }
    
    /**
     * Get the HTTP request handler instance
     * 
     * @return HTTPRequestHandler
     */
    public function getHTTPRequestHandler(): HTTPRequestHandler
    {
        return $this->requestHandler;
    }
    
    /**
     * Get system configuration
     * 
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Apply resource update (implementation-specific)
     * 
     * @param string $rid Resource Identity
     * @param mixed $newData New data for the resource
     * @return array|null Updated resource data or null on failure
     */
    private function applyResourceUpdate(string $rid, $newData): ?array
    {
        // This would interact with the data source to update the resource
        // For now, returning a placeholder response
        $currentResource = $this->getResource($rid);
        
        if (!$currentResource) {
            return null;
        }
        
        // Apply the update logic based on the specific implementation
        // For WordPress, this would update the post content
        // For a database system, this would update the record
        
        return [
            'rid' => $rid,
            'hr' => $currentResource['hr'],
            'mr' => $newData,
            'cid' => $this->computeCID($newData)
        ];
    }
    
    /**
     * Perform system health check
     * 
     * @return array Health check results
     */
    public function healthCheck(): array
    {
        $components = [
            'cid_manager' => method_exists($this->cidManager, 'computeCID'),
            'link_manager' => method_exists($this->linkManager, 'generateBidirectionalLinks'),
            'catalog_manager' => method_exists($this->catalogManager, 'getCatalogEntry'),
            'validation_engine' => method_exists($this->validationEngine, 'validateSemanticEquivalence'),
            'http_handler' => method_exists($this->requestHandler, 'processRequest')
        ];
        
        $allOk = !in_array(false, $components, true);
        
        return [
            'status' => $allOk ? 'healthy' : 'degraded',
            'timestamp' => gmdate('c'),
            'version' => $this->config['version'],
            'components' => $components,
            'profile' => $this->config['profile']
        ];
    }
}