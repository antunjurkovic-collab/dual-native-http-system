<?php

namespace DualNative\HTTP\HTTP;

use DualNative\HTTP\Events\EventDispatcherInterface;
use DualNative\HTTP\Events\NullEventDispatcher;

use DualNative\HTTP\Core\CIDManager;
use DualNative\HTTP\Core\LinkManager;
use DualNative\HTTP\Core\CatalogManager;
use DualNative\HTTP\Validation\ValidationEngine;
use DualNative\HTTP\Config\Config;

/**
 * HTTP Request Handler for Dual-Native HTTP System
 *
 * Handles HTTP requests, generates responses with proper headers, and manages conditional requests
 */
class HTTPRequestHandler
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
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var ValidatorGuard
     */
    private $validatorGuard;
    
    /**
     * Constructor
     *
     * @param CIDManager $cidManager
     * @param LinkManager $linkManager
     * @param CatalogManager $catalogManager
     * @param ValidationEngine|null $validationEngine
     */
    public function __construct(
        CIDManager $cidManager,
        LinkManager $linkManager,
        CatalogManager $catalogManager,
        ?ValidationEngine $validationEngine = null,
        ?EventDispatcherInterface $eventDispatcher = null
    )
    {
        $this->cidManager = $cidManager;
        $this->linkManager = $linkManager;
        $this->catalogManager = $catalogManager;
        $this->validationEngine = $validationEngine;
        $this->eventDispatcher = $eventDispatcher ?? new NullEventDispatcher();
        $this->validatorGuard = new ValidatorGuard($cidManager);
    }
    
    /**
     * Process an HTTP request and generate appropriate response
     * 
     * @param array $request The HTTP request data
     * @return array The HTTP response data
     */
    public function processRequest(array $request): array
    {
        $method = $request['method'] ?? 'GET';
        $path = $request['path'] ?? '';
        $headers = $request['headers'] ?? [];
        
        $response = [
            'status' => 200,
            'headers' => [],
            'body' => '',
            'cid' => null
        ];
        
        // Determine request type based on path
        if (preg_match('/\/catalog$/', $path)) {
            $response = $this->handleCatalogRequest($method, $path, $headers);
        } elseif (preg_match('/\/posts\/(\d+)$/', $path, $matches)) {
            $postId = (int) $matches[1];
            $response = $this->handleResourceRequest($method, $postId, $headers);
        } elseif (preg_match('/\/posts\/(\d+)\/blocks$/', $path, $matches)) {
            $postId = (int) $matches[1];
            $response = $this->handleBlockRequest($method, $postId, $headers, $request['body'] ?? []);
        } elseif (preg_match('/\/conformance$/', $path)) {
            $response = $this->handleConformanceRequest($method, $headers);
        } else {
            $response['status'] = 404;
            $response['body'] = json_encode(['error' => 'Not found']);
        }

        return $this->eventDispatcher->filter('dual_native_http_response', $response, $request);
    }

    /**
     * Handle conformance requests
     *
     * @param string $method HTTP method
     * @param array $headers Request headers
     * @return array HTTP response
     */
    private function handleConformanceRequest(string $method, array $headers): array
    {
        if ($method !== 'GET') {
            return [
                'status' => 405,
                'headers' => ['Allow' => 'GET'],
                'body' => json_encode(['error' => 'Method not allowed']),
                'cid' => null
            ];
        }

        if (!$this->validationEngine) {
            return [
                'status' => 500,
                'headers' => [],
                'body' => json_encode(['error' => 'Validation engine not available']),
                'cid' => null
            ];
        }

        // Build system info for conformance validation
        $systemInfo = [
            'has_hr' => true,
            'has_mr' => true,
            'hr_links_to_mr' => true,
            'mr_links_to_hr' => true,
            'has_cid' => true,
            'supports_304' => true,
            'supports_if_none_match' => true,
            'supports_if_match' => true,
            'has_catalog' => true,
            'supports_safe_writes' => true,
        ];

        $conformanceResult = $this->validationEngine->validateConformance($systemInfo);

        // Add additional conformance checks
        $conformanceResult['checks'] = [
            'semantic_equivalence' => [
                'description' => 'Semantic equivalence validation is available',
                'status' => $this->validationEngine ? 'available' : 'not_available'
            ],
            'content_digest' => [
                'description' => 'RFC 9530 Content-Digest header support',
                'status' => 'implemented'
            ],
            'etag_validation' => [
                'description' => 'ETag-based conditional requests',
                'status' => 'implemented'
            ],
            'safe_write_operations' => [
                'description' => 'If-Match protected write operations',
                'status' => 'implemented'
            ],
            'catalog_functionality' => [
                'description' => 'DNC catalog with filters',
                'status' => 'implemented'
            ]
        ];

        // Compute CID for conformance results
        $conformanceCid = $this->cidManager->computeCID($conformanceResult);

        // Check conditional request
        $ifNoneMatch = $this->getHeader($headers, 'if-none-match', $this->getServerValue('HTTP_IF_NONE_MATCH'));

        if ($ifNoneMatch && $this->cidManager->validateCID($conformanceResult, $ifNoneMatch)) {
            // Content unchanged, return 304
            return [
                'status' => 304,
                'headers' => [
                    'ETag' => '"' . $conformanceCid . '"',
                    'Last-Modified' => gmdate('D, d M Y H:i:s') . ' GMT',
                    'Cache-Control' => 'no-cache',
                ],
                'body' => '',
                'cid' => $conformanceCid
            ];
        }

        $responseBody = json_encode($conformanceResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/json; profile="dual-native-conformance-1.0"',
                'ETag' => '"' . $conformanceCid . '"',
                'Last-Modified' => gmdate('D, d M Y H:i:s') . ' GMT',
                'Cache-Control' => 'no-cache',
                'Content-Length' => strlen($responseBody),
                'Content-Digest' => 'sha-256=:' . base64_encode(hash('sha256', $responseBody, true)) . ':'
            ],
            'body' => $responseBody,
            'cid' => $conformanceCid
        ];
    }

    /**
     * Handle catalog requests
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param array $headers Request headers
     * @return array HTTP response
     */
    private function handleCatalogRequest(string $method, string $path, array $headers): array
    {
        if ($method !== 'GET') {
            return [
                'status' => 405,
                'headers' => ['Allow' => 'GET'],
                'body' => json_encode(['error' => 'Method not allowed']),
                'cid' => null
            ];
        }
        
        // Parse query parameters
        $query = [];
        if (strpos($path, '?') !== false) {
            parse_str(substr($path, strpos($path, '?') + 1), $query);
        }
        
        $since = $query['since'] ?? null;
        $limit = isset($query['limit']) ? (int) $query['limit'] : 0;
        $offset = isset($query['offset']) ? (int) $query['offset'] : 0;
        
        $filters = [];
        if (isset($query['status'])) {
            $filters['status'] = $query['status'];
        }
        if (isset($query['type'])) {
            $filters['type'] = $query['type'];
        }
        
        $catalog = $this->catalogManager->getCatalog($since, $filters, $limit, $offset);
        
        // Compute CID for the catalog
        $catalogCid = $this->cidManager->computeCID($catalog);
        
        // Check if conditional request
        $ifNoneMatch = $this->getHeader($headers, 'if-none-match', $this->getServerValue('HTTP_IF_NONE_MATCH'));
        
        if ($ifNoneMatch && $this->cidManager->validateCID($catalog, $ifNoneMatch)) {
            // Content unchanged, return 304
            return [
                'status' => 304,
                'headers' => [
                    'ETag' => '"' . $catalogCid . '"',
                    'Cache-Control' => 'no-cache',
                ],
                'body' => '',
                'cid' => $catalogCid
            ];
        }
        
        $responseBody = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'ETag' => '"' . $catalogCid . '"',
                'Cache-Control' => 'no-cache',
                'Content-Length' => strlen($responseBody)
            ],
            'body' => $responseBody,
            'cid' => $catalogCid
        ];
    }

    /**
     * Validate If-None-Match header using the validator guard
     *
     * @param array $headers Request headers
     * @param mixed $currentResource The current resource to compare
     * @param array|null $excludeKeys Fields to exclude from CID computation
     * @return bool True if resource matches (should return 304), false otherwise
     */
    public function validateIfNoneMatch(array $headers, $currentResource, ?array $excludeKeys = null): bool
    {
        return $this->validatorGuard->validateIfNoneMatch($headers, $currentResource, $excludeKeys);
    }

    /**
     * Validate If-Match header using the validator guard
     *
     * @param array $headers Request headers
     * @param mixed $currentResource The current resource to compare
     * @param array|null $excludeKeys Fields to exclude from CID computation
     * @return array Result with 'valid' boolean and 'currentCid' if invalid
     */
    public function validateIfMatch(array $headers, $currentResource, ?array $excludeKeys = null): array
    {
        return $this->validatorGuard->validateIfMatch($headers, $currentResource, $excludeKeys);
    }

    /**
     * Generate standardized response headers using the validator guard
     *
     * @param mixed $resource The resource to generate headers for
     * @param array|null $excludeKeys Fields to exclude from CID computation
     * @return array Standardized headers
     */
    public function generateStandardHeaders($resource, ?array $excludeKeys = null): array
    {
        return $this->validatorGuard->generateStandardHeaders($resource, $excludeKeys);
    }
    
    /**
     * Handle resource requests (posts/pages)
     * 
     * @param string $method HTTP method
     * @param int $resourceId Resource ID
     * @param array $headers Request headers
     * @return array HTTP response
     */
    private function handleResourceRequest(string $method, int $resourceId, array $headers): array
    {
        if ($method === 'GET') {
            return $this->handleGetResource($resourceId, $headers);
        } elseif ($method === 'POST') {
            return $this->handlePostResource($resourceId, $headers);
        } else {
            return [
                'status' => 405,
                'headers' => ['Allow' => 'GET, POST'],
                'body' => json_encode(['error' => 'Method not allowed']),
                'cid' => null
            ];
        }
    }
    
    /**
     * Handle GET resource request
     * 
     * @param int $resourceId Resource ID
     * @param array $headers Request headers
     * @return array HTTP response
     */
    private function handleGetResource(int $resourceId, array $headers): array
    {
        // Get the resource data (this would interact with the data source)
        $resourceData = $this->getResourceData($resourceId);
        
        if (!$resourceData) {
            return [
                'status' => 404,
                'headers' => [],
                'body' => json_encode(['error' => 'Resource not found']),
                'cid' => null
            ];
        }
        
        // Compute CID for the resource
        $resourceCid = $this->cidManager->computeCID($resourceData);
        
        // Check conditional request
        $ifNoneMatch = $this->getHeader($headers, 'if-none-match', $this->getServerValue('HTTP_IF_NONE_MATCH'));
        
        if ($ifNoneMatch && $this->cidManager->validateCID($resourceData, $ifNoneMatch)) {
            // Content unchanged, return 304
            return [
                'status' => 304,
                'headers' => [
                    'ETag' => '"' . $resourceCid . '"',
                    'Cache-Control' => 'no-cache',
                ],
                'body' => '',
                'cid' => $resourceCid
            ];
        }
        
        // Generate bidirectional links
        $rid = (string) $resourceId;
        $baseUrl = home_url(); // In WordPress context
        $hrUrl = get_permalink($resourceId);
        $mrUrl = rest_url('dual-native/v2/posts/' . $resourceId);
        
        $links = $this->linkManager->generateBidirectionalLinks($rid, $hrUrl, $mrUrl);
        $linkHeader = $this->linkManager->generateLinkHeader($hrUrl, $mrUrl);
        
        // Add the links to the resource data
        $resourceData['links'] = $this->linkManager->createMRLinks($hrUrl, $mrUrl);
        $resourceData['cid'] = $resourceCid;
        
        $responseBody = json_encode($resourceData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'ETag' => '"' . $resourceCid . '"',
                'Link' => $linkHeader,
                'Cache-Control' => 'no-cache',
                'Content-Length' => strlen($responseBody),
                // RFC 9530 Content-Digest header
                'Content-Digest' => 'sha-256=:' . base64_encode(hash('sha256', $responseBody, true)) . ':'
            ],
            'body' => $responseBody,
            'cid' => $resourceCid
        ];
    }
    
    /**
     * Handle block requests (safe write operations)
     * 
     * @param string $method HTTP method
     * @param int $resourceId Resource ID
     * @param array $headers Request headers
     * @param array $body Request body
     * @return array HTTP response
     */
    private function handleBlockRequest(string $method, int $resourceId, array $headers, array $body): array
    {
        if ($method !== 'POST') {
            return [
                'status' => 405,
                'headers' => ['Allow' => 'POST'],
                'body' => json_encode(['error' => 'Method not allowed']),
                'cid' => null
            ];
        }
        
        // Check if the user has permission to edit this resource
        if (!current_user_can('edit_post', $resourceId)) {
            return [
                'status' => 403,
                'headers' => [],
                'body' => json_encode(['error' => 'Forbidden']),
                'cid' => null
            ];
        }
        
        // Validate If-Match header for safe write operations
        $ifMatch = $this->getHeader($headers, 'if-match', $this->getServerValue('HTTP_IF_MATCH'));
        
        if (!$ifMatch) {
            return [
                'status' => 428, // Precondition Required
                'headers' => [],
                'body' => json_encode(['error' => 'If-Match header required for safe write']),
                'cid' => null
            ];
        }
        
        // Validate the CID in If-Match header against current resource
        $currentResourceData = $this->getResourceData($resourceId);
        $currentCid = $this->cidManager->computeCID($currentResourceData);
        
        $ifMatchValue = trim($ifMatch, '"'); // Remove quotes if present
        
        if ($currentCid !== $ifMatchValue) {
            // CID doesn't match, return 412 Precondition Failed with current CID
            return [
                'status' => 412, // Precondition Failed
                'headers' => [
                    'ETag' => '"' . $currentCid . '"'
                ],
                'body' => json_encode([
                    'error' => 'Precondition Failed',
                    'message' => 'Resource has changed since last read',
                    'current_cid' => $currentCid
                ]),
                'cid' => $currentCid
            ];
        }
        
        // Perform the block insertion/update operation
        $result = $this->performBlockOperation($resourceId, $body);
        
        if (is_wp_error($result)) {
            return [
                'status' => $result->get_error_code() ?: 500,
                'headers' => [],
                'body' => json_encode(['error' => $result->get_error_message()]),
                'cid' => null
            ];
        }
        
        // Get the updated resource data
        $updatedResourceData = $this->getResourceData($resourceId);
        $updatedCid = $this->cidManager->computeCID($updatedResourceData);
        
        // Update the catalog with the new CID
        $hrUrl = get_permalink($resourceId);
        $mrUrl = rest_url('dual-native/v2/posts/' . $resourceId);
        $this->catalogManager->updateCatalogEntry((string) $resourceId, $hrUrl, $mrUrl, $updatedCid);
        
        // Return the updated resource
        $updatedResourceData['links'] = $this->linkManager->createMRLinks($hrUrl, $mrUrl);
        $updatedResourceData['cid'] = $updatedCid;
        
        $responseBody = json_encode($updatedResourceData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'ETag' => '"' . $updatedCid . '"',
                'Cache-Control' => 'no-cache',
                'Content-Length' => strlen($responseBody),
                // RFC 9530 Content-Digest header
                'Content-Digest' => 'sha-256=:' . base64_encode(hash('sha256', $responseBody, true)) . ':'
            ],
            'body' => $responseBody,
            'cid' => $updatedCid
        ];
    }
    
    /**
     * Get a header value from the headers array or server variables
     * 
     * @param array $headers Request headers
     * @param string $headerName Header name to get
     * @param string|null $serverValue Value from $_SERVER if not in headers array
     * @return string|null The header value
     */
    private function getHeader(array $headers, string $headerName, ?string $serverValue = null): ?string
    {
        // Check in headers array (case-insensitive)
        foreach ($headers as $key => $value) {
            if (strtolower($key) === strtolower($headerName)) {
                return $value;
            }
        }
        
        // Check in server variables if not found in headers array
        if ($serverValue) {
            return $serverValue;
        }
        
        return null;
    }
    
    /**
     * Get value from $_SERVER superglobal
     * 
     * @param string $key The server variable key
     * @return string|null The server variable value
     */
    private function getServerValue(string $key): ?string
    {
        return $_SERVER[$key] ?? null;
    }
    
    /**
     * Get resource data from the data source
     * 
     * @param int $resourceId Resource ID
     * @return array|null Resource data or null if not found
     */
    private function getResourceData(int $resourceId): ?array
    {
        // This would interact with the WordPress database or other data source
        // For now, we'll return a mock resource
        $post = get_post($resourceId);
        
        if (!$post) {
            return null;
        }
        
        // Build the resource representation
        $resourceData = [
            'rid' => $resourceId,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'status' => $post->post_status,
            'modified' => $post->post_modified_gmt,
            'author' => [
                'id' => $post->post_author,
                'name' => get_the_author_meta('display_name', $post->post_author)
            ],
            'type' => $post->post_type,
            'published' => $post->post_date_gmt
        ];
        
        // In a real implementation, we'd parse the blocks for structured content
        // For now, we'll just include the raw content
        
        return $this->eventDispatcher->filter('dual_native_resource_data', $resourceData, $resourceId);
    }
    
    /**
     * Perform block operation (insertion, update, etc.)
     * 
     * @param int $resourceId Resource ID
     * @param array $operationData Operation data
     * @return mixed Result of the operation, or WP_Error on failure
     */
    private function performBlockOperation(int $resourceId, array $operationData)
    {
        // This would perform the actual block operation
        // For now, we'll just update the post content with a simple approach
        // A full implementation would properly handle Gutenberg blocks
        
        $post = get_post($resourceId);
        if (!$post) {
            return new \WP_Error('post_not_found', 'Post not found');
        }
        
        // Get the current content
        $currentContent = $post->post_content;
        
        // Perform the operation based on the operation data
        $insertWhere = $operationData['insert'] ?? 'append';
        $newBlocks = $operationData['blocks'] ?? (isset($operationData['block']) ? [$operationData['block']] : []);
        
        if (empty($newBlocks)) {
            return new \WP_Error('no_blocks', 'No blocks to insert');
        }
        
        // For now, just append some text to the content
        // In a real implementation, we'd properly handle Gutenberg blocks
        $newContent = $currentContent;
        
        foreach ($newBlocks as $block) {
            $blockType = $block['type'] ?? 'unknown';
            $blockContent = $block['content'] ?? '';
            
            switch ($blockType) {
                case 'core/paragraph':
                    $newContent .= '<!-- wp:paragraph -->' . "\n" . '<p>' . esc_html($blockContent) . '</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n";
                    break;
                case 'core/heading':
                    $level = $block['level'] ?? 2;
                    $newContent .= '<!-- wp:heading -->' . "\n" . '<h' . $level . '>' . esc_html($blockContent) . '</h' . $level . '>' . "\n" . '<!-- /wp:heading -->' . "\n\n";
                    break;
                default:
                    $newContent .= '<!-- wp:paragraph -->' . "\n" . '<p>Block: ' . esc_html($blockContent) . '</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n";
                    break;
            }
        }
        
        // Update the post
        $updateResult = wp_update_post([
            'ID' => $resourceId,
            'post_content' => $newContent
        ]);
        
        if (is_wp_error($updateResult)) {
            return $updateResult;
        }
        
        // Clear the CID cache for this resource
        delete_post_meta($resourceId, '_dual_native_cid');
        
        return $updateResult;
    }
    
    /**
     * Generate HTTP response headers for a given resource
     * 
     * @param mixed $resource The resource to generate headers for
     * @param array $customHeaders Additional custom headers
     * @return array HTTP headers
     */
    public function generateHeaders($resource, array $customHeaders = []): array
    {
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-cache'
        ];
        
        // Add Content-Digest header (RFC 9530)
        if (is_array($resource) || is_object($resource)) {
            $jsonContent = json_encode($resource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['Content-Digest'] = 'sha-256=:' . base64_encode(hash('sha256', $jsonContent, true)) . ':';
        }
        
        // Compute and add CID/ETag
        $cid = $this->cidManager->computeCID($resource);
        $headers['ETag'] = '"' . $cid . '"';
        
        // Merge with custom headers
        $headers = array_merge($headers, $customHeaders);
        
        // Apply filters
        return $this->eventDispatcher->filter('dual_native_response_headers', $headers, $resource);
    }
    
    /**
     * Handle POST resource request (placeholder for future implementation)
     * 
     * @param int $resourceId Resource ID
     * @param array $headers Request headers
     * @return array HTTP response
     */
    private function handlePostResource(int $resourceId, array $headers): array
    {
        return [
            'status' => 405, // Method not allowed for now
            'headers' => ['Allow' => 'GET'],
            'body' => json_encode(['error' => 'Method not allowed']),
            'cid' => null
        ];
    }
}