<?php
/**
 * Null Event Dispatcher
 *
 * No-op implementation of EventDispatcherInterface for testing and standalone usage.
 * This dispatcher does nothing - it's used when no event system is needed.
 *
 * @package DualNative\HTTP\Events
 */

namespace DualNative\HTTP\Events;

/**
 * Class NullEventDispatcher
 *
 * Null object pattern implementation for EventDispatcherInterface.
 * All methods are no-ops, making it safe to use in tests or standalone environments.
 */
class NullEventDispatcher implements EventDispatcherInterface
{
    /**
     * Dispatch an action/event (no-op).
     *
     * @param string $eventName The name of the event/action
     * @param mixed  ...$args   Arguments to pass to event listeners
     * @return void
     */
    public function dispatch(string $eventName, ...$args): void
    {
        // No-op: Do nothing
    }

    /**
     * Apply filters to a value (no-op - returns value unchanged).
     *
     * @param string $filterName The name of the filter
     * @param mixed  $value      The value to filter
     * @param mixed  ...$args    Additional arguments to pass to filter callbacks
     * @return mixed The unmodified value
     */
    public function filter(string $filterName, $value, ...$args)
    {
        // No-op: Return value unchanged
        return $value;
    }

    /**
     * Check if event has listeners (always returns false).
     *
     * @param string $eventName The name of the event/action
     * @return bool Always returns false
     */
    public function hasListeners(string $eventName): bool
    {
        // No-op: No listeners in null dispatcher
        return false;
    }
}
