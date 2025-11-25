<?php

namespace DualNative\HTTP\Config;

/**
 * Configuration class for Dual-Native HTTP System
 */
class Config
{
    /**
     * System version
     */
    public const VERSION = '2.0';
    
    /**
     * Default profile identifier
     */
    public const DEFAULT_PROFILE = 'dual-native-core-1.0';
    
    /**
     * Default profile for HTTP (TCT)
     */
    public const HTTP_PROFILE = 'tct-1';
    
    /**
     * Fields to exclude from CID computation by default
     */
    public const CID_EXCLUDE_FIELDS = ['modified', 'links', 'cid', 'etag'];
    
    /**
     * Default catalog batch size
     */
    public const CATALOG_BATCH_SIZE = 1000;
    
    /**
     * Default zero-fetch cache TTL
     */
    public const CACHE_TTL = 300; // 5 minutes
    
    /**
     * Default timeout for validation operations
     */
    public const VALIDATION_TIMEOUT = 30; // seconds
}