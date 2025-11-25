<?php

namespace DualNative\HTTP\Core;

use DualNative\HTTP\Events\EventDispatcherInterface;
use DualNative\HTTP\Events\NullEventDispatcher;

/**
 * Link Manager for Dual-Native HTTP System
 * 
 * Handles bidirectional linking between Human Representation (HR) and Machine Representation (MR)
 */
class LinkManager
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
     * Generate bidirectional links for a resource
     * 
     * @param string $rid Resource Identity
     * @param string $hrUrl Human Representation URL
     * @param string $mrUrl Machine Representation URL
     * @return array Array containing HR and MR links
     */
    public function generateBidirectionalLinks(string $rid, string $hrUrl, string $mrUrl): array
    {
        $links = [
            'hr' => [
                'url' => $hrUrl,
                'rel' => 'self',
                'type' => 'text/html'
            ],
            'mr' => [
                'url' => $mrUrl,
                'rel' => 'alternate',
                'type' => 'application/json'
            ]
        ];
        
        return $this->eventDispatcher->filter('dual_native_bidirectional_links', $links, $rid, $hrUrl, $mrUrl);
    }
    
    /**
     * Generate HTTP Link header for bidirectional discovery
     * 
     * @param string $hrUrl Human Representation URL
     * @param string $mrUrl Machine Representation URL
     * @return string HTTP Link header value
     */
    public function generateLinkHeader(string $hrUrl, string $mrUrl): string
    {
        $links = [];
        
        // Add link from HR to MR
        $links[] = '<' . $mrUrl . '>; rel="alternate"; type="application/json"';
        
        // Add link from MR to HR
        $links[] = '<' . $hrUrl . '>; rel="canonical"; type="text/html"';
        
        $headerValue = implode(', ', $links);
        
        return $this->eventDispatcher->filter('dual_native_link_header', $headerValue, $hrUrl, $mrUrl);
    }
    
    /**
     * Parse HTTP Link header to extract related URLs
     * 
     * @param string $linkHeader The HTTP Link header value
     * @return array Parsed links with rel type as key
     */
    public function parseLinkHeader(string $linkHeader): array
    {
        $links = [];
        
        // Parse the link header according to RFC 8288
        $pattern = '/<([^>]+)>;\s*rel="([^"]+)";\s*type="([^"]+)"/';
        
        if (preg_match_all($pattern, $linkHeader, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = $match[1];
                $rel = $match[2];
                $type = $match[3];
                
                $links[$rel] = [
                    'url' => $url,
                    'type' => $type
                ];
            }
        }
        
        return $this->eventDispatcher->filter('dual_native_parsed_links', $links, $linkHeader);
    }
    
    /**
     * Validate bidirectional link consistency
     * 
     * @param string $hrUrl Human Representation URL
     * @param string $mrUrl Machine Representation URL
     * @return bool True if links are consistent, false otherwise
     */
    public function validateLinkConsistency(string $hrUrl, string $mrUrl): bool
    {
        // In a real implementation, this would fetch both URLs and verify that:
        // 1. HR contains a link to MR
        // 2. MR contains a link to HR
        // For now, we'll just return true as a placeholder
        
        $isValid = true;
        
        return $this->eventDispatcher->filter('dual_native_link_consistency', $isValid, $hrUrl, $mrUrl);
    }
    
    /**
     * Create a link structure for inclusion in MR
     * 
     * @param string $humanUrl URL to the human representation
     * @param string|null $apiUrl URL to the API endpoint (if different from MR URL)
     * @param string|null $publicUrl URL to the public MR endpoint (for public content)
     * @return array Structured links for MR
     */
    public function createMRLinks(string $humanUrl, ?string $apiUrl = null, ?string $publicUrl = null): array
    {
        $links = [
            'human_url' => $humanUrl
        ];
        
        if ($apiUrl) {
            $links['api_url'] = $apiUrl;
        }
        
        if ($publicUrl) {
            $links['public_url'] = $publicUrl;
        }
        
        return $this->eventDispatcher->filter('dual_native_mr_links', $links, $humanUrl, $apiUrl, $publicUrl);
    }
    
    /**
     * Create a link structure for inclusion in HR (HTML meta tags)
     * 
     * @param string $mrUrl URL to the machine representation
     * @param string|null $publicMrUrl URL to the public machine representation
     * @return array HTML meta/link tags
     */
    public function createHRLinks(string $mrUrl, ?string $publicMrUrl = null): array
    {
        $links = [
            '<link rel="alternate" type="application/json" href="' . htmlspecialchars($mrUrl, ENT_QUOTES, 'UTF-8') . '">'
        ];

        if ($publicMrUrl) {
            $links[] = '<link rel="alternate" type="application/json" href="' . htmlspecialchars($publicMrUrl, ENT_QUOTES, 'UTF-8') . '">';
        }

        return $this->eventDispatcher->filter('dual_native_hr_links', $links, $mrUrl, $publicMrUrl);
    }
}