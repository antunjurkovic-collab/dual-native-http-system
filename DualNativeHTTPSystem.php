<?php

/**
 * Dual-Native HTTP System
 * 
 * This file serves as the main entry point for the Dual-Native HTTP System library.
 * It provides a simple way to initialize and use the system.
 */

// If used as a standalone library (not as WordPress plugin)
if (!defined('ABSPATH')) {
    // Define any necessary constants
    if (!defined('DUAL_NATIVE_HTTP_LOADED')) {
        define('DUAL_NATIVE_HTTP_LOADED', true);
        
        // Include the system components
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once __DIR__ . '/vendor/autoload.php';
        } else {
            // Manual inclusion for non-composer environments
            require_once __DIR__ . '/src/DualNativeSystem.php';
            require_once __DIR__ . '/src/Config/Config.php';
            require_once __DIR__ . '/src/Core/CIDManager.php';
            require_once __DIR__ . '/src/Core/LinkManager.php';
            require_once __DIR__ . '/src/Core/CatalogManager.php';
            require_once __DIR__ . '/src/Validation/ValidationEngine.php';
            require_once __DIR__ . '/src/HTTP/HTTPRequestHandler.php';
        }
    }
}

/**
 * Initialize the Dual-Native HTTP System
 * 
 * @param array $config Configuration options
 * @return \DualNative\HTTP\DualNativeSystem
 */
function initialize_dual_native_system(array $config = []): \DualNative\HTTP\DualNativeSystem {
    return new \DualNative\HTTP\DualNativeSystem($config);
}