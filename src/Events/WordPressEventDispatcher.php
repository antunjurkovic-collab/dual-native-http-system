<?php
/**
 * WordPress Event Dispatcher
 *
 * Adapter that bridges the EventDispatcherInterface to WordPress's hook system.
 * This allows the dual-native-http-system to use WordPress actions and filters.
 *
 * @package DualNative\HTTP\Events
 */

namespace DualNative\HTTP\Events;

/**
 * Class WordPressEventDispatcher
 *
 * WordPress-specific implementation of EventDispatcherInterface.
 */
class WordPressEventDispatcher implements EventDispatcherInterface
{
    /**
     * Dispatch an action/event using WordPress's do_action().
     *
     * @param string $eventName The name of the event/action
     * @param mixed  ...$args   Arguments to pass to event listeners
     * @return void
     */
    public function dispatch(string $eventName, ...$args): void
    {
        if (function_exists('do_action')) {
            do_action($eventName, ...$args);
        }
    }

    /**
     * Apply filters to a value using WordPress's apply_filters().
     *
     * @param string $filterName The name of the filter
     * @param mixed  $value      The value to filter
     * @param mixed  ...$args    Additional arguments to pass to filter callbacks
     * @return mixed The filtered value
     */
    public function filter(string $filterName, $value, ...$args)
    {
        if (function_exists('apply_filters')) {
            return apply_filters($filterName, $value, ...$args);
        }

        return $value;
    }

    /**
     * Check if event has listeners using WordPress's has_action().
     *
     * @param string $eventName The name of the event/action
     * @return bool True if event has listeners, false otherwise
     */
    public function hasListeners(string $eventName): bool
    {
        if (function_exists('has_action')) {
            return has_action($eventName) !== false;
        }

        return false;
    }
}
