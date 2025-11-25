<?php

namespace DualNative\HTTP\HTTP;

use DualNative\HTTP\Core\CIDManager;

/**
 * Validator Guard Middleware
 * 
 * Provides reusable validation for conditional requests and safe writes
 */
class ValidatorGuard
{
    /**
     * @var CIDManager
     */
    private $cidManager;
    
    /**
     * Constructor
     * 
     * @param CIDManager $cidManager
     */
    public function __construct(CIDManager $cidManager)
    {
        $this->cidManager = $cidManager;
    }
    
    /**
     * Validate If-None-Match header for conditional GET requests
     * 
     * @param array $headers Request headers
     * @param mixed $currentResource The current resource to compare against
     * @param array|null $excludeKeys Fields to exclude from CID computation
     * @return bool True if resource matches (should return 304), false otherwise
     */
    public function validateIfNoneMatch(array $headers, $currentResource, ?array $excludeKeys = null): bool
    {
        $ifNoneMatch = $this->extractHeader($headers, 'if-none-match');
        if (!$ifNoneMatch) {
            return false;
        }
        
        $currentCid = $this->cidManager->computeCID($currentResource, $excludeKeys);
        return $this->normalizeAndCompareValidators($ifNoneMatch, $currentCid);
    }
    
    /**
     * Validate If-Match header for safe write operations
     * 
     * @param array $headers Request headers
     * @param mixed $currentResource The current resource to compare against
     * @param array|null $excludeKeys Fields to exclude from CID computation
     * @return array Result with 'valid' boolean and 'currentCid' if invalid
     */
    public function validateIfMatch(array $headers, $currentResource, ?array $excludeKeys = null): array
    {
        $ifMatch = $this->extractHeader($headers, 'if-match');
        if (!$ifMatch) {
            return [
                'valid' => false,
                'error' => 'If-Match header required for safe write',
                'currentCid' => $this->cidManager->computeCID($currentResource, $excludeKeys)
            ];
        }
        
        $currentCid = $this->cidManager->computeCID($currentResource, $excludeKeys);
        
        if (!$this->normalizeAndCompareValidators($ifMatch, $currentCid)) {
            return [
                'valid' => false,
                'error' => 'Precondition Failed - Resource has been modified by another process',
                'currentCid' => $currentCid
            ];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Extract a header value from request headers (case-insensitive)
     * 
     * @param array $headers Request headers
     * @param string $headerName Header name to extract
     * @return string|null Header value or null if not found
     */
    private function extractHeader(array $headers, string $headerName): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === strtolower($headerName)) {
                return $value;
            }
        }
        
        // Check if header was passed as server var
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        if (isset($_SERVER[$serverKey])) {
            return $_SERVER[$serverKey];
        }
        
        return null;
    }
    
    /**
     * Normalize and compare HTTP validator (ETag) values
     * 
     * @param string $validatorHeader The validator header value (If-None-Match, If-Match)
     * @param string $expectedCid The expected CID
     * @return bool Whether any of the provided validators match the expected CID
     */
    private function normalizeAndCompareValidators(string $validatorHeader, string $expectedCid): bool
    {
        // Split on commas to handle multiple validators
        $validators = array_map('trim', explode(',', $validatorHeader));
        
        foreach ($validators as $validator) {
            // Remove W/ prefix for weak validators
            if (stripos($validator, 'W/"') === 0) {
                $validator = substr($validator, 3); // Remove "W/"" prefix
            }
            
            // Remove surrounding quotes if present
            $validator = trim($validator, '"');
            
            // Compare the normalized validator to the expected CID
            if (hash_equals($expectedCid, $validator)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate standardized response headers for a resource
     * 
     * @param mixed $resource The resource to generate headers for
     * @param array|null $excludeKeys Fields to exclude from CID computation
     * @return array Standardized headers
     */
    public function generateStandardHeaders($resource, ?array $excludeKeys = null): array
    {
        $cid = $this->cidManager->computeCID($resource, $excludeKeys);
        
        $headers = [
            'ETag' => '"' . $cid . '"',
            'Cache-Control' => 'no-cache'
        ];
        
        // Add Last-Modified if the resource has a modified field
        if (is_array($resource) && isset($resource['modified'])) {
            $modified_time = $resource['modified'];
            if (is_string($modified_time)) {
                $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', strtotime($modified_time)) . ' GMT';
            }
        } elseif (is_object($resource) && isset($resource->modified)) {
            $modified_time = $resource->modified;
            if (is_string($modified_time)) {
                $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', strtotime($modified_time)) . ' GMT';
            }
        }
        
        // Add Content-Digest header (RFC 9530)
        $jsonContent = json_encode($resource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers['Content-Digest'] = 'sha-256=:' . base64_encode(hash('sha256', $jsonContent, true)) . ':';
        
        // Calculate Content-Length if possible
        if ($jsonContent !== false) {
            $headers['Content-Length'] = strlen($jsonContent);
        }
        
        return $headers;
    }
}