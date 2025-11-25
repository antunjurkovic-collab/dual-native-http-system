<?php

namespace DualNative\HTTP\Validation;

use DualNative\HTTP\Events\EventDispatcherInterface;
use DualNative\HTTP\Events\NullEventDispatcher;

/**
 * Validation Engine for Dual-Native HTTP System
 * 
 * Handles semantic equivalence validation and system conformance checks
 */
class ValidationEngine
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
     * Validate semantic equivalence between HR and MR
     * 
     * @param mixed $hrContent Human Representation content
     * @param mixed $mrContent Machine Representation content
     * @param array $equivalenceScope Fields that must match between HR and MR
     * @return array Validation results
     */
    public function validateSemanticEquivalence($hrContent, $mrContent, ?array $equivalenceScope = null): array
    {
        if ($equivalenceScope === null) {
            // Default equivalence scope - these are the fields that must match
            $equivalenceScope = ['title', 'content', 'status', 'modified'];
        }
        
        $results = [
            'isValid' => true,
            'fieldsChecked' => $equivalenceScope,
            'differences' => [],
            'details' => []
        ];
        
        foreach ($equivalenceScope as $field) {
            $hrValue = $this->extractFieldValue($hrContent, $field);
            $mrValue = $this->extractFieldValue($mrContent, $field);
            
            // Normalize values for comparison (remove whitespace, etc.)
            $normalizedHrValue = $this->normalizeValue($hrValue);
            $normalizedMrValue = $this->normalizeValue($mrValue);
            
            if ($normalizedHrValue !== $normalizedMrValue) {
                $results['isValid'] = false;
                $results['differences'][] = [
                    'field' => $field,
                    'hrValue' => $hrValue,
                    'mrValue' => $mrValue,
                    'normalizedHrValue' => $normalizedHrValue,
                    'normalizedMrValue' => $normalizedMrValue
                ];
            } else {
                $results['details'][] = [
                    'field' => $field,
                    'status' => 'matched',
                    'value' => $normalizedHrValue
                ];
            }
        }
        
        return $this->eventDispatcher->filter('dual_native_semantic_equivalence_validation', $results, $hrContent, $mrContent, $equivalenceScope);
    }
    
    /**
     * Validate system conformance to dual-native standards
     * 
     * @param array $systemInfo Information about the system to validate
     * @return array Conformance validation results
     */
    public function validateConformance(array $systemInfo): array
    {
        $results = [
            'level' => 0, // Start at Level 0 (HR-only)
            'passedRequirements' => [],
            'failedRequirements' => [],
            'details' => []
        ];
        
        // Check Level 1: HR + MR with one-way link
        if ($this->hasHR($systemInfo) && $this->hasMR($systemInfo) && $this->hasLink($systemInfo, 'hr_to_mr')) {
            $results['level'] = 1;
            $results['passedRequirements'][] = 'hr_mr_with_link';
            $results['details'][] = 'Level 1: HR and MR with HR→MR link exists';
        } else {
            $results['failedRequirements'][] = 'hr_mr_with_link';
            $results['details'][] = 'Level 1 requirement failed: HR and MR with HR→MR link needed';
        }
        
        // Check Level 2: Bidirectional linking
        if ($results['level'] >= 1 && $this->hasLink($systemInfo, 'mr_to_hr')) {
            $results['level'] = 2;
            $results['passedRequirements'][] = 'bidirectional_linking';
            $results['details'][] = 'Level 2: Bidirectional linking achieved';
        } else {
            $results['failedRequirements'][] = 'bidirectional_linking';
            if ($results['level'] >= 1) {
                $results['details'][] = 'Level 2 requirement failed: MR→HR link needed';
            }
        }
        
        // Check Level 3: CID and zero-fetch
        if ($results['level'] >= 2 && $this->hasCID($systemInfo) && $this->supportsConditionalRequests($systemInfo)) {
            $results['level'] = 3;
            $results['passedRequirements'][] = 'cid_and_zero_fetch';
            $results['details'][] = 'Level 3: CID and zero-fetch optimization achieved';
        } else {
            $results['failedRequirements'][] = 'cid_and_zero_fetch';
            if ($results['level'] >= 2) {
                $results['details'][] = 'Level 3 requirement failed: CID and conditional requests needed';
            }
        }
        
        // Check Level 4: Catalog and safe writes
        if ($results['level'] >= 3 && $this->hasCatalog($systemInfo) && $this->supportsSafeWrites($systemInfo)) {
            $results['level'] = 4;
            $results['passedRequirements'][] = 'catalog_and_safe_writes';
            $results['details'][] = 'Level 4: Full dual-native with catalog and safe writes achieved';
        } else {
            $results['failedRequirements'][] = 'catalog_and_safe_writes';
            if ($results['level'] >= 3) {
                $results['details'][] = 'Level 4 requirement failed: Catalog and safe write operations needed';
            }
        }
        
        return $this->eventDispatcher->filter('dual_native_conformance_validation', $results, $systemInfo);
    }
    
    /**
     * Validate CID parity between catalog and live endpoints
     * 
     * @param string $catalogCid CID from catalog
     * @param string $liveCid CID from live endpoint
     * @return bool True if CIDs match, false otherwise
     */
    public function validateCIDParity(string $catalogCid, string $liveCid): bool
    {
        $isValid = hash_equals($catalogCid, $liveCid);
        return $this->eventDispatcher->filter('dual_native_cid_parity_validation', $isValid, $catalogCid, $liveCid);
    }
    
    /**
     * Extract a field value from content using various strategies
     * 
     * @param mixed $content The content to extract from
     * @param string $field The field name to extract
     * @return mixed The extracted field value
     */
    private function extractFieldValue($content, string $field)
    {
        // If content is an array/object, extract directly
        if (is_array($content) || is_object($content)) {
            if (is_array($content)) {
                return $content[$field] ?? null;
            } else {
                return $content->$field ?? null;
            }
        }
        
        // If content is HTML, extract using appropriate method
        if (is_string($content) && $this->isHtml($content)) {
            return $this->extractFieldFromHtml($content, $field);
        }
        
        // Default: return the content itself if it's the field we're looking for
        return $content;
    }
    
    /**
     * Check if content is HTML
     * 
     * @param string $content Content to check
     * @return bool True if content appears to be HTML
     */
    private function isHtml(string $content): bool
    {
        return stripos($content, '<html') !== false || 
               stripos($content, '<body') !== false || 
               stripos($content, '<head') !== false ||
               (substr_count($content, '<') > 0 && substr_count($content, '>') > 0);
    }
    
    /**
     * Extract field from HTML content
     * 
     * @param string $html HTML content to extract from
     * @param string $field Field to extract
     * @return string|null Extracted field value
     */
    private function extractFieldFromHtml(string $html, string $field): ?string
    {
        switch ($field) {
            case 'title':
                if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $matches)) {
                    return trim($matches[1]);
                }
                if (preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $html, $matches)) {
                    return trim($matches[1]);
                }
                break;
                
            case 'content':
                if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $matches)) {
                    $content = $matches[1];
                    // Strip HTML tags to get plain text
                    return trim(wp_strip_all_tags($content));
                }
                break;
                
            case 'modified':
            case 'status':
                // For dates and status, look for meta tags or time elements
                if (preg_match('/<meta[^>]+property=["\']article:modified_time["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches) ||
                    preg_match('/<meta[^>]+name=["\']last-modified["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches) ||
                    preg_match('/<time[^>]+datetime=["\']([^"\']+)["\']/i', $html, $matches)) {
                    return $matches[1];
                }
                break;
        }
        
        return null;
    }
    
    /**
     * Normalize a value for comparison
     * 
     * @param mixed $value Value to normalize
     * @return mixed Normalized value
     */
    private function normalizeValue($value)
    {
        if (is_string($value)) {
            // Remove extra whitespace and normalize
            return trim(preg_replace('/\s+/', ' ', $value));
        }
        
        return $value;
    }
    
    /**
     * Check if system has Human Representation
     * 
     * @param array $systemInfo System information
     * @return bool
     */
    private function hasHR(array $systemInfo): bool
    {
        return !empty($systemInfo['hr_endpoint']) || !empty($systemInfo['has_human_representation']);
    }
    
    /**
     * Check if system has Machine Representation
     * 
     * @param array $systemInfo System information
     * @return bool
     */
    private function hasMR(array $systemInfo): bool
    {
        return !empty($systemInfo['mr_endpoint']) || !empty($systemInfo['has_machine_representation']);
    }
    
    /**
     * Check if system has a specific type of link
     * 
     * @param array $systemInfo System information
     * @param string $linkType Type of link ('hr_to_mr' or 'mr_to_hr')
     * @return bool
     */
    private function hasLink(array $systemInfo, string $linkType): bool
    {
        switch ($linkType) {
            case 'hr_to_mr':
                return !empty($systemInfo['hr_links_to_mr']) || !empty($systemInfo['hr_has_mr_link']);
            case 'mr_to_hr':
                return !empty($systemInfo['mr_links_to_hr']) || !empty($systemInfo['mr_has_hr_link']);
            default:
                return false;
        }
    }
    
    /**
     * Check if system has Content Identity
     * 
     * @param array $systemInfo System information
     * @return bool
     */
    private function hasCID(array $systemInfo): bool
    {
        return !empty($systemInfo['has_cid']) || !empty($systemInfo['supports_etag']);
    }
    
    /**
     * Check if system supports conditional requests
     * 
     * @param array $systemInfo System information
     * @return bool
     */
    private function supportsConditionalRequests(array $systemInfo): bool
    {
        return !empty($systemInfo['supports_304']) || !empty($systemInfo['supports_if_none_match']);
    }
    
    /**
     * Check if system has catalog
     * 
     * @param array $systemInfo System information
     * @return bool
     */
    private function hasCatalog(array $systemInfo): bool
    {
        return !empty($systemInfo['has_catalog']) || !empty($systemInfo['catalog_endpoint']);
    }
    
    /**
     * Check if system supports safe write operations
     * 
     * @param array $systemInfo System information
     * @return bool
     */
    private function supportsSafeWrites(array $systemInfo): bool
    {
        return !empty($systemInfo['supports_safe_writes']) || !empty($systemInfo['supports_if_match']);
    }
    
    /**
     * Run a comprehensive validation test
     * 
     * @param array $validationData Data to validate
     * @return array Comprehensive validation results
     */
    public function runComprehensiveValidation(array $validationData): array
    {
        $results = [
            'overallStatus' => 'pass',
            'tests' => [],
            'summary' => [
                'passed' => 0,
                'failed' => 0,
                'skipped' => 0
            ]
        ];
        
        // Run semantic equivalence test if HR and MR provided
        if (isset($validationData['hr_content']) && isset($validationData['mr_content'])) {
            $semEqResult = $this->validateSemanticEquivalence(
                $validationData['hr_content'], 
                $validationData['mr_content'],
                $validationData['equivalence_scope'] ?? null
            );
            
            $results['tests']['semantic_equivalence'] = $semEqResult;
            
            if (!$semEqResult['isValid']) {
                $results['overallStatus'] = 'fail';
                $results['summary']['failed']++;
            } else {
                $results['summary']['passed']++;
            }
        } else {
            $results['summary']['skipped']++;
            $results['tests']['semantic_equivalence'] = ['status' => 'skipped', 'reason' => 'HR or MR content not provided'];
        }
        
        // Run conformance validation if system info provided
        if (isset($validationData['system_info'])) {
            $conformanceResult = $this->validateConformance($validationData['system_info']);
            
            $results['tests']['conformance'] = $conformanceResult;
            
            if ($conformanceResult['level'] < ($validationData['required_level'] ?? 2)) {
                $results['overallStatus'] = 'fail';
                $results['summary']['failed']++;
            } else {
                $results['summary']['passed']++;
            }
        } else {
            $results['summary']['skipped']++;
            $results['tests']['conformance'] = ['status' => 'skipped', 'reason' => 'System info not provided'];
        }
        
        return $this->eventDispatcher->filter('dual_native_comprehensive_validation', $results, $validationData);
    }
}