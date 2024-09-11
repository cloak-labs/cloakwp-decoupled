<?php

declare(strict_types=1);

namespace Snicco\Component\BetterWPAPI;

use InvalidArgumentException;
use WP_User;

use function add_action;
use function add_filter;
use function apply_filters_ref_array;
use function array_unshift;
use function current_user_can;
use function do_action;
use function get_class;
use function get_current_user_id;
use function gettype;
use function is_user_logged_in;
use function remove_filter;
use function wp_cache_delete;
use function wp_cache_get;
use function wp_cache_set;
use function wp_create_nonce;
use function wp_get_current_user;
use function wp_verify_nonce;

/**
 * @note No new (public|protected) methods will be added to this class until a next major version.
 */
class BetterWPAPI
{
    /**
     * @return true
     */
    public function addFilter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return add_filter($hook_name, $callback, $priority, $accepted_args);
    }

    /**
     * @return true
     */
    public function addAction(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return add_action($hook_name, $callback, $priority, $accepted_args);
    }

    /**
     * @param mixed ...$args
     */
    public function doAction(string $hook_name, ...$args): void
    {
        do_action($hook_name, ...$args);
    }

    /**
     * @template T
     *
     * @param T     $filterable_value
     * @param mixed ...$args
     *
     * @psalm-return T
     */
    public function applyFiltersStrict(string $hook_name, $filterable_value, ...$args)
    {
        /** @psalm-suppress MixedAssignment */
        $return_value = $this->applyFilters($hook_name, $filterable_value, ...$args);

        $expected = gettype($filterable_value);
        $actual = gettype($return_value);
        if ($actual !== $expected) {
            throw new InvalidArgumentException(
                sprintf('Initial value for filter [%s] is %s. Returned [%s].', $hook_name, $expected, $actual)
            );
        }

        if ('object' === $expected) {
            /**
             * @var object $filterable_value
             * @var object $return_value
             */
            $value_class = get_class($filterable_value);
            $returned_class = get_class($return_value);

            if ($value_class !== $returned_class) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Initial value for filter [%s] is an instance of [%s]. Returned [%s].',
                        $hook_name,
                        $value_class,
                        $returned_class
                    )
                );
            }
        }

        /**
         * @var T $return_value
         */
        return $return_value;
    }

    /**
     * @param mixed $value
     * @param mixed ...$args
     *
     * @return mixed
     */
    public function applyFilters(string $hook_name, $value, ...$args)
    {
        array_unshift($args, $value);

        return apply_filters_ref_array($hook_name, $args);
    }

    public function removeFilter(string $hook_name, callable $callback, int $priority = 10): bool
    {
        return remove_filter($hook_name, $callback, $priority);
    }

    public function isUserLoggedIn(): bool
    {
        return is_user_logged_in();
    }

    public function currentUser(): WP_User
    {
        return wp_get_current_user();
    }

    public function currentUserId(): int
    {
        return get_current_user_id();
    }

    /**
     * @param mixed $args
     */
    public function currentUserCan(string $capability, ...$args): bool
    {
        return current_user_can($capability, ...$args);
    }

    /**
     * @return false|mixed
     */
    public function cacheGet(string $key, string $group = '', bool $force = false, bool &$found = null)
    {
        return wp_cache_get($key, $group, $force, $found);
    }

    /**
     * @param mixed $data
     */
    public function cacheSet(string $key, $data, string $group = '', int $expire = 0): bool
    {
        return wp_cache_set($key, $data, $group, $expire);
    }

    public function cacheDelete(string $key, string $group = ''): bool
    {
        return wp_cache_delete($key, $group);
    }

    public function verifyNonce(string $nonce, string $action): bool
    {
        return false !== wp_verify_nonce($nonce, $action);
    }

    public function createNonce(string $form_action): string
    {
        return wp_create_nonce($form_action);
    }
}
