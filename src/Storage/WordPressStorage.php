<?php
/**
 * WordPress Storage
 *
 * Adapter that bridges the StorageInterface to WordPress's options API.
 *
 * @package DualNative\HTTP\Storage
 */

namespace DualNative\HTTP\Storage;

/**
 * Class WordPressStorage
 *
 * WordPress-specific implementation of StorageInterface.
 */
class WordPressStorage implements StorageInterface
{
    /**
     * Get a value from storage using WordPress's get_option().
     *
     * @param string $key     The storage key
     * @param mixed  $default Default value if key doesn't exist
     * @return mixed The stored value or default
     */
    public function get(string $key, $default = null)
    {
        if (function_exists('get_option')) {
            $value = get_option($key, $default);
            return $value !== false ? $value : $default;
        }

        return $default;
    }

    /**
     * Set a value in storage using WordPress's update_option().
     *
     * @param string $key   The storage key
     * @param mixed  $value The value to store
     * @return bool True on success, false on failure
     */
    public function set(string $key, $value): bool
    {
        if (function_exists('update_option')) {
            return update_option($key, $value);
        }

        return false;
    }

    /**
     * Delete a value from storage using WordPress's delete_option().
     *
     * @param string $key The storage key
     * @return bool True on success, false on failure
     */
    public function delete(string $key): bool
    {
        if (function_exists('delete_option')) {
            return delete_option($key);
        }

        return false;
    }

    /**
     * Check if a key exists in storage.
     *
     * @param string $key The storage key
     * @return bool True if key exists, false otherwise
     */
    public function has(string $key): bool
    {
        if (function_exists('get_option')) {
            return get_option($key) !== false;
        }

        return false;
    }
}
