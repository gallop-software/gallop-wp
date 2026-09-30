<?php
/**
 * Fires when the user deletes the Gallop plugin from the Plugins screen.
 *
 * Removes plugin-owned options, including the stored hash of the API key, and the
 * plugin's transients. Posts created under Gallop-registered custom post types are
 * intentionally left in place so user content survives an uninstall/reinstall
 * cycle. So are comments and member accounts: they belong to WordPress, not to this
 * plugin. What the plugin recorded about members, a few user meta rows, goes.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!function_exists('gallop_delete_transients')) {
    /**
     * @param string $prefix The start of the transients' names, such as 'gallop_auth_'.
     */
    function gallop_delete_transients(string $prefix): void
    {
        global $wpdb;

        $like = $wpdb->esc_like('_transient_' . $prefix) . '%';
        $timeoutLike = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';

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

if (!function_exists('gallop_delete_member_data')) {
    /**
     * Removes the user meta and comment meta the plugin added.
     *
     * @param list<string> $memberMeta
     */
    function gallop_delete_member_data(array $memberMeta): void
    {
        foreach ($memberMeta as $key) {
            delete_metadata('user', 0, $key, '', true);
        }
        delete_metadata('comment', 0, '_gallop_reply_notified', '', true);
    }
}

if (!function_exists('gallop_uninstall')) {
    function gallop_uninstall(): void
    {
        $options = [
            'gallop_post_types',
            'gallop_nextjs_production_url',
            'gallop_trust_forwarded_ip',
            'gallop_api_key_hash',
            'gallop_api_key_permissions',
        ];

        // Login attempts, wrong API keys, a key waiting to be shown, sign-ups waiting
        // to be confirmed, and the count of emails sent to each address.
        $transients = [
            'gallop_auth_',
            'gallop_key_fail_',
            'gallop_key_reveal_',
            'gallop_pending_',
            'gallop_confirm_sent_',
        ];

        // What was recorded about members.
        $memberMeta = [
            'gallop_verified',
            'gallop_subscribed',
            'gallop_reply_emails',
            'gallop_session_version',
        ];

        if (is_multisite()) {
            $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach ($site_ids as $site_id) {
                switch_to_blog((int) $site_id);
                gallop_delete_member_data($memberMeta);
                foreach ($options as $option) {
                    delete_option($option);
                }
                foreach ($transients as $prefix) {
                    gallop_delete_transients($prefix);
                }
                restore_current_blog();
            }
        } else {
            gallop_delete_member_data($memberMeta);
            foreach ($options as $option) {
                delete_option($option);
            }
            foreach ($transients as $prefix) {
                gallop_delete_transients($prefix);
            }
        }
    }
}

gallop_uninstall();
