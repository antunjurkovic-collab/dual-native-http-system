<?php

namespace DualNative\HTTP\Core;

use DualNative\HTTP\Events\EventDispatcherInterface;
use DualNative\HTTP\Events\NullEventDispatcher;

use DualNative\HTTP\Config\Config;

/**
 * Content Identity Manager for Dual-Native HTTP System
 * 
 * Handles CID computation, validation, and management
 */
class CIDManager
{
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * Constructor
     *
     * @param EventDispatcherInterface|null $eventDispatcher
     */
    public function __construct(?EventDispatcherInterface $eventDispatcher = null)
    {
        $this->eventDispatcher = $eventDispatcher ?? new NullEventDispatcher();
    }
    /**
     * @var EventDispatcherInterface
     */
    /**
     * Compute a Content Identity (CID) for a given content structure
     * 
     * @param mixed $content The content to compute CID for
     * @param array $excludeKeys Fields to exclude from CID computation
     * @return string The computed CID in format 'sha256-<hex>'
     */
    public function computeCID($content, ?array $excludeKeys = null): string
    {
        if ($excludeKeys === null) {
            $excludeKeys = Config::CID_EXCLUDE_FIELDS;
        }
        
        $excludeKeys = $this->eventDispatcher->filter('dual_native_cid_exclude_keys', $excludeKeys, $content);
        
        $cleanContent = $this->deepExclude($content, $excludeKeys);
        $canonicalContent = $this->canonicalize($cleanContent);
        
        // Convert to JSON with consistent formatting for deterministic hashing
        $jsonOptions = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
        $canonicalJson = json_encode($canonicalContent, $jsonOptions);
        
        if ($canonicalJson === false) {
            // Fallback to standard JSON encoding
            $canonicalJson = json_encode($canonicalContent, $jsonOptions);
        }
        
        if ($canonicalJson === false || $canonicalJson === null) {
            // Last resort: use string representation
            $canonicalJson = (string) $content;
        }
        
        $hash = hash('sha256', $canonicalJson);
        $cid = 'sha256-' . $hash;
        
        return $this->eventDispatcher->filter('dual_native_computed_cid', $cid, $content);
    }
    
    /**
     * Validate if a provided CID matches the computed CID for content
     * 
     * @param mixed $content The content to validate against
     * @param string $expectedCID The CID to validate
     * @param array $excludeKeys Fields to exclude from CID computation
     * @return bool True if CIDs match, false otherwise
     */
    public function validateCID($content, string $expectedCID, ?array $excludeKeys = null): bool
    {
        $computedCID = $this->computeCID($content, $excludeKeys);
        $isValid = hash_equals($expectedCID, $computedCID);
        
        return $this->eventDispatcher->filter('dual_native_cid_validation', $isValid, $content, $expectedCID);
    }
    
    /**
     * Deeply exclude specified keys from nested arrays/objects
     * 
     * @param mixed $data The data structure to clean
     * @param array $excludeKeys Keys to exclude
     * @return mixed The cleaned data structure
     */
    private function deepExclude($data, array $excludeKeys)
    {
        if (is_array($data)) {
            $isAssoc = array_keys($data) !== range(0, count($data) - 1);
            
            if ($isAssoc) {
                $result = [];
                foreach ($data as $key => $value) {
                    if (!in_array($key, $excludeKeys, true)) {
                        $result[$key] = $this->deepExclude($value, $excludeKeys);
                    }
                }
                return $result;
            } else {
                $result = [];
                foreach ($data as $value) {
                    $result[] = $this->deepExclude($value, $excludeKeys);
                }
                return $result;
            }
        } elseif (is_object($data)) {
            $result = new \stdClass();
            foreach (get_object_vars($data) as $key => $value) {
                if (!in_array($key, $excludeKeys, true)) {
                    $result->$key = $this->deepExclude($value, $excludeKeys);
                }
            }
            return $result;
        }
        
        return $data;
    }
    
    /**
     * Canonicalize data structure by sorting keys consistently
     * 
     * @param mixed $data The data to canonicalize
     * @return mixed The canonicalized data
     */
    private function canonicalize($data)
    {
        if (is_array($data)) {
            $isAssoc = array_keys($data) !== range(0, count($data) - 1);
            
            if ($isAssoc) {
                ksort($data);
                $result = [];
                foreach ($data as $key => $value) {
                    $result[$key] = $this->canonicalize($value);
                }
                return $result;
            } else {
                $result = [];
                foreach ($data as $value) {
                    $result[] = $this->canonicalize($value);
                }
                return $result;
            }
        } elseif (is_object($data)) {
            $vars = get_object_vars($data);
            ksort($vars);
            $result = new \stdClass();
            foreach ($vars as $key => $value) {
                $result->$key = $this->canonicalize($value);
            }
            return $result;
        }
        
        return $data;
    }
    
    /**
     * Parse a CID string to extract the algorithm and hash
     * 
     * @param string $cid The CID string
     * @return array Array with 'algorithm' and 'hash' keys
     */
    public function parseCID(string $cid): array
    {
        $parts = explode('-', $cid, 2);
        
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Invalid CID format');
        }
        
        return [
            'algorithm' => $parts[0],
            'hash' => $parts[1]
        ];
    }
}