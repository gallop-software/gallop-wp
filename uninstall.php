<?php
/**
 * Fires when the user deletes the Gallop plugin from the Plugins screen.
 *
 * Removes plugin-owned options and rate-limit transients. Posts created under
 * Gallop-registered custom post types are intentionally left in place so user
 * content survives an uninstall/reinstall cycle.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!function_exists('gallop_delete_auth_transients')) {
    function gallop_delete_auth_transients(): void
    {
        global $wpdb;

        $like = $wpdb->esc_like('_transient_gallop_auth_') . '%';
        $timeoutLike = $wpdb->esc_like('_transient_timeout_gallop_auth_') . '%';

        // Direct query is intentional: this runs once at uninstall, has no caching surface,
        // and is the only way to enumerate transients by name prefix in the options table.
        // Note: on sites using a persistent object cache, cached transient values may
        // linger in the cache until their TTL expires.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like,
                $timeoutLike
            )
        );

        foreach ($names as $name) {
            if (strpos($name, '_transient_timeout_') === 0) {
                $key = substr($name, strlen('_transient_timeout_'));
            } else {
                $key = substr($name, strlen('_transient_'));
            }
            delete_transient($key);
        }
    }
}

if (!function_exists('gallop_uninstall')) {
    function gallop_uninstall(): void
    {
        $options = [
            'gallop_post_types',
            'gallop_nextjs_production_url',
            'gallop_trust_forwarded_ip',
        ];

        if (is_multisite()) {
            $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach ($site_ids as $site_id) {
                switch_to_blog((int) $site_id);
                foreach ($options as $option) {
                    delete_option($option);
                }
                gallop_delete_auth_transients();
                restore_current_blog();
            }
        } else {
            foreach ($options as $option) {
                delete_option($option);
            }
            gallop_delete_auth_transients();
        }
    }
}

gallop_uninstall();
