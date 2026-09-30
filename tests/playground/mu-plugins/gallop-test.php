<?php
/**
 * Test helpers for the Gallop plugin. Never shipped: build.sh leaves tests/ out
 * of the zip, and this file is only ever mounted into a local WordPress
 * Playground by tests/playground/run.sh.
 *
 * Everything here is open to anyone who can reach the server. Do not install it
 * on a real site.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const GALLOP_TEST_MAIL = 'gallop_test_mail_log';
const GALLOP_TEST_SERVER = 'gallop_test_server_log';

/**
 * Playground cannot send mail. Record what would have been sent and report
 * success, so the code under test behaves as it would on a real site.
 */
add_filter('pre_wp_mail', static function ($short, array $atts) {
    $log = get_option(GALLOP_TEST_MAIL, []);
    $log = is_array($log) ? $log : [];
    $log[] = [
        'to' => $atts['to'] ?? '',
        'subject' => $atts['subject'] ?? '',
        'message' => $atts['message'] ?? '',
        'headers' => $atts['headers'] ?? '',
    ];
    update_option(GALLOP_TEST_MAIL, $log, false);

    return true;
}, 10, 2);

/**
 * What another plugin would see while a comment is being saved: the request
 * headers, the address, and who WordPress thinks is logged in.
 */
add_filter('preprocess_comment', static function (array $commentdata): array {
    $headers = [];
    foreach (array_keys($_SERVER) as $name) {
        if (is_string($name) && (str_starts_with($name, 'HTTP_') || str_starts_with($name, 'REDIRECT_HTTP_'))) {
            $headers[] = $name;
        }
    }
    sort($headers);

    update_option(GALLOP_TEST_SERVER, [
        'headers' => $headers,
        'remoteAddr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : null,
        'referer' => isset($_SERVER['HTTP_REFERER']) ? wp_unslash($_SERVER['HTTP_REFERER']) : null,
        'currentUser' => get_current_user_id(),
    ], false);

    return $commentdata;
}, 1);

add_action('rest_api_init', static function (): void {
    $open = ['permission_callback' => '__return_true'];

    register_rest_route('gallop-test/v1', '/seed', $open + [
        'methods' => 'POST',
        'callback' => 'gallop_test_seed',
    ]);

    register_rest_route('gallop-test/v1', '/option', $open + [
        'methods' => 'POST',
        'callback' => static function (WP_REST_Request $request) {
            $name = (string) $request->get_param('name');
            $allowed = [
                'comment_moderation', 'comment_previously_approved', 'require_name_email',
                'thread_comments', 'thread_comments_depth', 'comments_notify', 'moderation_notify',
                'show_avatars', 'comment_registration', 'close_comments_for_old_posts',
                'close_comments_days_old', 'disallowed_keys', 'moderation_keys', 'comment_max_links',
                'gallop_api_key_permissions', 'gallop_nextjs_production_url',
            ];
            if (!in_array($name, $allowed, true)) {
                return new WP_Error('gallop_test_option', 'Not an option the tests may set.', ['status' => 400]);
            }
            update_option($name, $request->get_param('value'));

            return ['name' => $name, 'value' => get_option($name)];
        },
    ]);

    register_rest_route('gallop-test/v1', '/comment/(?P<id>\d+)', $open + [
        'methods' => 'GET',
        'callback' => static function (WP_REST_Request $request) {
            $comment = get_comment((int) $request['id']);
            if (!$comment instanceof WP_Comment) {
                return new WP_Error('gallop_test_comment', 'No such comment.', ['status' => 404]);
            }

            return [
                'id' => (int) $comment->comment_ID,
                'post' => (int) $comment->comment_post_ID,
                'parent' => (int) $comment->comment_parent,
                'userId' => (int) $comment->user_id,
                'author' => $comment->comment_author,
                'email' => $comment->comment_author_email,
                'url' => $comment->comment_author_url,
                'ip' => $comment->comment_author_IP,
                'agent' => $comment->comment_agent,
                'approved' => $comment->comment_approved,
                'type' => $comment->comment_type,
                'content' => $comment->comment_content,
            ];
        },
    ]);

    // A user as WordPress stores them, with what the plugin recorded about them.
    $user = static function (?WP_User $user) {
        if (!$user instanceof WP_User) {
            return null;
        }
        $meta = static fn (string $key) => get_user_meta($user->ID, $key, true);

        return [
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'nicename' => $user->user_nicename,
            'displayName' => $user->display_name,
            'firstName' => (string) $user->first_name,
            'lastName' => (string) $user->last_name,
            'roles' => array_values($user->roles),
            'hasPassword' => $user->user_pass !== '',
            'meta' => [
                'verified' => $meta('gallop_verified'),
                'subscribed' => $meta('gallop_subscribed'),
                'replyEmails' => $meta('gallop_reply_emails'),
                'sessionVersion' => $meta('gallop_session_version'),
            ],
        ];
    };

    register_rest_route('gallop-test/v1', '/user/(?P<id>\d+)', $open + [
        'methods' => 'GET',
        'callback' => static fn (WP_REST_Request $request) => $user(get_user_by('id', (int) $request['id']) ?: null),
    ]);

    register_rest_route('gallop-test/v1', '/user-by-email', $open + [
        'methods' => 'GET',
        'callback' => static fn (WP_REST_Request $request) => $user(get_user_by('email', (string) $request->get_param('email')) ?: null),
    ]);

    // A user made some other way, before the plugin: role and password only.
    register_rest_route('gallop-test/v1', '/user', $open + [
        'methods' => 'POST',
        'callback' => static function (WP_REST_Request $request) use ($user) {
            $id = wp_insert_user([
                'user_login' => (string) $request->get_param('login'),
                'user_email' => (string) $request->get_param('email'),
                'user_pass' => (string) $request->get_param('password'),
                'role' => (string) ($request->get_param('role') ?: 'subscriber'),
                'display_name' => (string) ($request->get_param('displayName') ?: $request->get_param('login')),
            ]);

            return $id instanceof WP_Error ? $id : $user(get_user_by('id', $id) ?: null);
        },
    ]);

    register_rest_route('gallop-test/v1', '/approve/(?P<id>\d+)', $open + [
        'methods' => 'POST',
        'callback' => static fn (WP_REST_Request $request) => ['approved' => wp_set_comment_status((int) $request['id'], 'approve')],
    ]);

    register_rest_route('gallop-test/v1', '/mail', [
        $open + ['methods' => 'GET', 'callback' => static fn () => array_values((array) get_option(GALLOP_TEST_MAIL, []))],
        $open + ['methods' => 'DELETE', 'callback' => static function () {
            delete_option(GALLOP_TEST_MAIL);

            return ['cleared' => true];
        }],
    ]);

    register_rest_route('gallop-test/v1', '/server', $open + [
        'methods' => 'GET',
        'callback' => static fn () => get_option(GALLOP_TEST_SERVER, null),
    ]);

    // Every option and transient the plugin owns, as stored.
    register_rest_route('gallop-test/v1', '/state', $open + [
        'methods' => 'GET',
        'callback' => static function () {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options}
                 WHERE option_name LIKE 'gallop\\_%' OR option_name LIKE '\\_transient\\_gallop\\_%'
                 ORDER BY option_name"
            );
            $state = [];
            foreach ($rows as $row) {
                if (str_starts_with($row->option_name, 'gallop_test_')) {
                    continue;
                }
                $state[$row->option_name] = maybe_unserialize($row->option_value);
            }

            return (object) $state;
        },
    ]);

    // Clears the counters that would otherwise carry over between checks.
    register_rest_route('gallop-test/v1', '/reset-limits', $open + [
        'methods' => 'POST',
        'callback' => static function () {
            global $wpdb;
            $names = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_gallop\\_%'"
            );
            foreach ($names as $name) {
                delete_transient(substr($name, strlen('_transient_')));
            }

            return ['cleared' => count($names)];
        },
    ]);

    register_rest_route('gallop-test/v1', '/generate-key', $open + [
        'methods' => 'POST',
        'callback' => static function () {
            if (!class_exists(\Gallop\Auth\ApiKey::class)) {
                return new WP_Error('gallop_test_key', 'This version of the plugin has no API key.', ['status' => 404]);
            }

            return ['key' => \Gallop\Auth\ApiKey::generate()];
        },
    ]);

    register_rest_route('gallop-test/v1', '/app-password', $open + [
        'methods' => 'POST',
        'callback' => static function () {
            $admin = get_user_by('login', 'admin');
            if (!$admin instanceof WP_User) {
                return new WP_Error('gallop_test_admin', 'No admin user.', ['status' => 500]);
            }
            $created = WP_Application_Passwords::create_new_application_password($admin->ID, ['name' => 'gallop-test-' . wp_generate_password(6, false)]);
            if (is_wp_error($created)) {
                return $created;
            }

            return ['user' => 'admin', 'password' => $created[0]];
        },
    ]);

    // What deleting the plugin from the Plugins screen would do.
    register_rest_route('gallop-test/v1', '/uninstall', $open + [
        'methods' => 'POST',
        'callback' => static function () {
            if (!defined('WP_UNINSTALL_PLUGIN')) {
                define('WP_UNINSTALL_PLUGIN', 'gallop/gallop.php');
            }
            require WP_PLUGIN_DIR . '/gallop/uninstall.php';

            return ['uninstalled' => true];
        },
    ]);
});

/**
 * Posts and comments for the checks to run against. Safe to call again: it
 * makes a new set each time and returns their ids.
 */
function gallop_test_seed(): array
{
    $admin = get_user_by('login', 'admin');
    $author = $admin instanceof WP_User ? $admin->ID : 1;

    $post = static fn (array $args): int => (int) wp_insert_post($args + [
        'post_type' => 'post',
        'post_author' => $author,
        'post_content' => 'Test post.',
        'comment_status' => 'open',
        'post_status' => 'publish',
    ]);

    $open = $post(['post_title' => 'Open post']);
    $other = $post(['post_title' => 'Another open post']);
    $closed = $post(['post_title' => 'Closed post', 'comment_status' => 'closed']);
    $draft = $post(['post_title' => 'Draft post', 'post_status' => 'draft']);
    $private = $post(['post_title' => 'Private post', 'post_status' => 'private']);
    $protected = $post(['post_title' => 'Protected post', 'post_password' => 'secret']);
    $old = $post([
        'post_title' => 'Old post',
        'post_date' => gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS),
        'post_date_gmt' => gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS),
    ]);
    $page = (int) wp_insert_post([
        'post_type' => 'page',
        'post_title' => 'A page',
        'post_status' => 'publish',
        'post_author' => $author,
        'comment_status' => 'open',
    ]);

    $minutesAgo = 100;
    $comment = static function (array $args) use (&$minutesAgo): int {
        $minutesAgo -= 5;
        $when = gmdate('Y-m-d H:i:s', time() - $minutesAgo * MINUTE_IN_SECONDS);

        return (int) wp_insert_comment($args + [
            'comment_author' => 'Reader',
            'comment_author_email' => 'reader@example.com',
            'comment_author_url' => '',
            'comment_author_IP' => '198.51.100.20',
            'comment_agent' => 'Seed',
            'comment_approved' => 1,
            'comment_type' => 'comment',
            'comment_parent' => 0,
            'user_id' => 0,
            'comment_date' => get_date_from_gmt($when),
            'comment_date_gmt' => $when,
        ]);
    };

    $first = $comment(['comment_post_ID' => $open, 'comment_content' => 'First comment.']);
    $reply = $comment([
        'comment_post_ID' => $open,
        'comment_content' => 'The author replies.',
        'comment_parent' => $first,
        'comment_author' => 'admin',
        'comment_author_email' => $admin instanceof WP_User ? $admin->user_email : 'admin@example.com',
        'user_id' => $author,
    ]);
    $second = $comment(['comment_post_ID' => $open, 'comment_content' => 'Second comment, with a <a href="https://example.com">link</a>.']);
    $nested = $comment(['comment_post_ID' => $open, 'comment_content' => 'A reply to the reply.', 'comment_parent' => $reply]);
    $third = $comment(['comment_post_ID' => $open, 'comment_content' => 'Third comment.']);
    $held = $comment(['comment_post_ID' => $open, 'comment_content' => 'Waiting for approval.', 'comment_approved' => 0]);
    $spam = $comment(['comment_post_ID' => $open, 'comment_content' => 'Buy things.', 'comment_approved' => 'spam']);
    $pingback = $comment(['comment_post_ID' => $open, 'comment_content' => 'A pingback.', 'comment_type' => 'pingback']);
    $elsewhere = $comment(['comment_post_ID' => $other, 'comment_content' => 'On the other post.']);

    return [
        'posts' => compact('open', 'other', 'closed', 'draft', 'private', 'protected', 'old', 'page'),
        'comments' => compact('first', 'reply', 'second', 'nested', 'third', 'held', 'spam', 'pingback', 'elsewhere'),
        'author' => $author,
    ];
}
