<?php
/**
 * In-Memory Storage
 *
 * Simple in-memory implementation of StorageInterface for testing and standalone usage.
 *
 * @package DualNative\HTTP\Storage
 */

namespace DualNative\HTTP\Storage;

/**
 * Class InMemoryStorage
 *
 * In-memory array-based implementation of StorageInterface.
 * Data is lost when the object is destroyed (no persistence).
 */
class InMemoryStorage implements StorageInterface
{
    /**
     * @var array Storage array
     */
    private $storage = [];

    /**
     * Get a value from storage.
     *
     * @param string $key     The storage key
     * @param mixed  $default Default value if key doesn't exist
     * @return mixed The stored value or default
     */
    public function get(string $key, $default = null)
    {
        return $this->storage[$key] ?? $default;
    }

    /**
     * Set a value in storage.
     *
     * @param string $key   The storage key
     * @param mixed  $value The value to store
     * @return bool Always returns true
     */
    public function set(string $key, $value): bool
    {
        $this->storage[$key] = $value;
        return true;
    }

    /**
     * Delete a value from storage.
     *
     * @param string $key The storage key
     * @return bool True if key existed and was deleted, false otherwise
     */
    public function delete(string $key): bool
    {
        if (isset($this->storage[$key])) {
            unset($this->storage[$key]);
            return true;
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
        return isset($this->storage[$key]);
    }

    /**
     * Clear all data from storage (useful for testing).
     *
     * @return void
     */
    public function clear(): void
    {
        $this->storage = [];
    }

    /**
     * Get all stored data (useful for debugging).
     *
     * @return array All stored data
     */
    public function all(): array
    {
        return $this->storage;
    }
}
