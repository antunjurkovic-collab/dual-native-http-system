<?php
/**
 * Event Dispatcher Interface
 *
 * Provides a framework-agnostic interface for event dispatching and filtering.
 * This allows the dual-native-http-system to work with WordPress, Laravel, Symfony,
 * or any other framework without tight coupling.
 *
 * @package DualNative\HTTP\Events
 */

namespace DualNative\HTTP\Events;

/**
 * Interface EventDispatcherInterface
 *
 * Framework-agnostic event dispatching interface.
 */
interface EventDispatcherInterface
{
    /**
     * Dispatch an action/event.
     *
     * Equivalent to WordPress's do_action() but framework-agnostic.
     *
     * @param string $eventName The name of the event/action
     * @param mixed  ...$args   Arguments to pass to event listeners
     * @return void
     */
    public function dispatch(string $eventName, ...$args): void;

    /**
     * Apply filters to a value.
     *
     * Equivalent to WordPress's apply_filters() but framework-agnostic.
     *
     * @param string $filterName The name of the filter
     * @param mixed  $value      The value to filter
     * @param mixed  ...$args    Additional arguments to pass to filter callbacks
     * @return mixed The filtered value
     */
    public function filter(string $filterName, $value, ...$args);

    /**
     * Check if event has listeners.
     *
     * Equivalent to WordPress's has_action() but framework-agnostic.
     *
     * @param string $eventName The name of the event/action
     * @return bool True if event has listeners, false otherwise
     */
    public function hasListeners(string $eventName): bool;
}
