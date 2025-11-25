<?php
/**
 * Storage Interface
 *
 * Provides a framework-agnostic interface for persistent storage.
 * This allows the dual-native-http-system to work with WordPress options,
 * Laravel cache, Symfony parameters, or any other storage mechanism.
 *
 * @package DualNative\HTTP\Storage
 */

namespace DualNative\HTTP\Storage;

/**
 * Interface StorageInterface
 *
 * Framework-agnostic persistent storage interface.
 */
interface StorageInterface
{
    /**
     * Get a value from storage.
     *
     * Equivalent to WordPress's get_option() but framework-agnostic.
     *
     * @param string $key     The storage key
     * @param mixed  $default Default value if key doesn't exist
     * @return mixed The stored value or default
     */
    public function get(string $key, $default = null);

    /**
     * Set a value in storage.
     *
     * Equivalent to WordPress's update_option() but framework-agnostic.
     *
     * @param string $key   The storage key
     * @param mixed  $value The value to store
     * @return bool True on success, false on failure
     */
    public function set(string $key, $value): bool;

    /**
     * Delete a value from storage.
     *
     * Equivalent to WordPress's delete_option() but framework-agnostic.
     *
     * @param string $key The storage key
     * @return bool True on success, false on failure
     */
    public function delete(string $key): bool;

    /**
     * Check if a key exists in storage.
     *
     * @param string $key The storage key
     * @return bool True if key exists, false otherwise
     */
    public function has(string $key): bool;
}
