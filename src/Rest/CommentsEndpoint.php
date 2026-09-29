<?php

declare(strict_types=1);

namespace Gallop\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Gallop\Auth\ApiKey;
use WP_Comment;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Comments for a headless front end: read a post's, and submit a visitor's.
 *
 * Reading is public, as it is on any WordPress site. Submitting needs the site's API
 * key, because it comes from the front end's server on a visitor's behalf.
 *
 * A submission goes through wp_handle_comment_submission(), the function WordPress's
 * own comment form uses. Core's REST route for comments does not: it inserts the
 * comment directly, so no notification is sent. Going the same way as the form means
 * Discussion settings, moderation, the duplicate and flood checks, anti-spam plugins
 * and notification emails all behave as they do for a comment left on the site.
 */
final class CommentsEndpoint
{
    private const NAMESPACE = 'gallop/v1';

    /**
     * Ceiling on how many comments one request returns.
     *
     * The route is public, so without one a post with a very long thread is an
     * unbounded query anyone can ask for. A site that needs more raises it through
     * `gallop_comments_max`.
     */
    private const MAX = 500;

    /** WordPress's own limit on the stored user agent. */
    private const USER_AGENT_MAX = 254;

    /**
     * Headers that describe the front end's server or the route the request took,
     * not the visitor. They are hidden while a comment is saved, so nothing reads the
     * server's address out of them in place of the visitor's.
     */
    private const PROXY_HEADERS = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_CLIENT_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_CF_CONNECTING_IPV6',
    ];

    public function register(): void
    {
        $post = [
            'required' => true,
            'sanitize_callback' => 'absint',
            'validate_callback' => static fn ($value): bool => is_numeric($value) && (int) $value > 0,
        ];

        // Type checks only. What a value has to be for a comment to be accepted is
        // decided in create(), where a refusal carries one of this plugin's codes.
        $text = [
            'required' => false,
            'default' => '',
            'validate_callback' => static fn ($value): bool => is_string($value),
        ];

        register_rest_route(self::NAMESPACE, '/comments', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => '__return_true',
                'args' => [
                    'post' => $post,
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create'],
                'permission_callback' => ApiKey::requires(ApiKey::CAP_COMMENTS),
                'args' => [
                    'post' => $post,
                    'parent' => [
                        'required' => false,
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static fn ($value): bool => is_numeric($value) && (int) $value >= 0,
                    ],
                    'authorName' => $text,
                    'authorEmail' => $text,
                    'authorUrl' => $text,
                    // No sanitize_callback: the comment has to reach WordPress as
                    // written, for WordPress to filter as it does any comment.
                    'content' => $text,
                    'ip' => $text,
                    'userAgent' => $text,
                    'referer' => $text,
                ],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = $this->readablePost((int) $request->get_param('post'));
        if ($post === null) {
            return $this->postNotFound();
        }

        /**
         * Filter the most comments one request returns. Default 500.
         *
         * @param int     $max  The ceiling.
         * @param WP_Post $post The post whose comments were asked for.
         */
        $max = max(1, (int) apply_filters('gallop_comments_max', self::MAX, $post));

        // The newest, when there are more than the ceiling: a comment a visitor has
        // just left must never be the one cut. Put back oldest first below.
        $comments = get_comments([
            'post_id' => $post->ID,
            'status' => 'approve',
            'type' => 'comment',
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
            'number' => $max,
        ]);
        $comments = is_array($comments) ? array_reverse($comments) : [];

        $count = $this->countFor($post);

        $items = [];
        foreach ($comments as $comment) {
            if ($comment instanceof WP_Comment) {
                $items[] = $this->serialize($comment, $post);
            }
        }

        $data = [
            'post' => $post->ID,
            'open' => $this->isOpen($post),
            'count' => $count,
            'requireNameEmail' => (bool) get_option('require_name_email'),
            'threadDepth' => $this->threadDepth(),
            // Surfaced rather than left implicit. A reply whose parent was cut
            // arrives with a parent id that is not in the list.
            'truncated' => $count > count($items),
            'comments' => $items,
        ];

        /**
         * Filter the response to a request for a post's comments.
         *
         * @param array<string, mixed> $data    The response.
         * @param WP_Post              $post    The post.
         * @param WP_REST_Request      $request The request.
         */
        $data = apply_filters('gallop_comments_data', $data, $post, $request);

        $response = new WP_REST_Response($data, 200);

        /**
         * Filter the Cache-Control header sent with a post's comments.
         *
         * Comments change more often than the post they are on, so by default
         * caches are asked to check back each time. Return an empty string to send
         * no header.
         *
         * @param string  $value The header value.
         * @param WP_Post $post  The post.
         */
        $cacheControl = (string) apply_filters('gallop_comments_cache_control', 'no-cache, must-revalidate, max-age=0', $post);
        if ($cacheControl !== '') {
            $response->header('Cache-Control', $cacheControl);
        }

        return $response;
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // The permission callback has removed the key already. Done again here so
        // that saving a comment never depends on the order things ran in.
        ApiKey::scrub($request);
        add_filter('rest_send_nocache_headers', '__return_true');

        $ip = filter_var(trim((string) $request->get_param('ip')), FILTER_VALIDATE_IP);
        if ($ip === false) {
            return new WP_Error(
                'gallop_comment_invalid_ip',
                __('A valid visitor IP address is required.', 'gallop'),
                ['status' => 400]
            );
        }

        $post = $this->readablePost((int) $request->get_param('post'));
        if ($post === null) {
            return $this->postNotFound();
        }

        $parent = (int) $request->get_param('parent');
        if ($parent > 0) {
            $invalid = $this->checkParent($parent, $post);
            if ($invalid !== null) {
                return $invalid;
            }
        }

        // WordPress only checks the address when the site requires one, and would
        // store anything typed here otherwise.
        $email = trim((string) $request->get_param('authorEmail'));
        if ($email !== '' && !is_email($email)) {
            return new WP_Error(
                'gallop_comment_invalid_email',
                __('Please enter a valid email address.', 'gallop'),
                ['status' => 400, 'reason' => 'require_valid_email']
            );
        }

        // Unslashed, as wp-comments-post.php hands it over.
        $submission = [
            'comment_post_ID' => $post->ID,
            'author' => (string) $request->get_param('authorName'),
            'email' => $email,
            'url' => (string) $request->get_param('authorUrl'),
            'comment' => (string) $request->get_param('content'),
            'comment_parent' => $parent,
        ];

        $result = $this->asVisitor(
            $ip,
            $this->cleanUserAgent((string) $request->get_param('userAgent')),
            $this->cleanReferer((string) $request->get_param('referer')),
            static fn () => wp_handle_comment_submission($submission)
        );

        if (!$result instanceof WP_Comment) {
            $error = $result instanceof WP_Error
                ? $result
                : new WP_Error('comment_save_error', 'The comment could not be saved.');

            /**
             * Fires when WordPress refused a submitted comment.
             *
             * @param WP_Error        $error   WordPress's own error, before it is given one of this plugin's codes.
             * @param WP_REST_Request $request The request, with the key removed.
             */
            do_action('gallop_comment_rejected', $error, $request);

            return $this->mapError($error);
        }

        /**
         * Fires after a submitted comment has been saved, whatever its status.
         *
         * @param WP_Comment      $comment The comment.
         * @param WP_REST_Request $request The request, with the key removed.
         */
        do_action('gallop_comment_submitted', $result, $request);

        return new WP_REST_Response([
            'comment' => $this->serialize($result, $post, true),
            'count' => $this->countFor($post),
        ], 201);
    }

    /**
     * The post, if its comments are anyone's to read.
     *
     * A post that is missing, not published, of a type that is not public, or
     * protected by a password all come back as null, and so as the same 404. A
     * caller cannot use this route to learn that a draft exists.
     */
    private function readablePost(int $id): ?WP_Post
    {
        if ($id <= 0) {
            return null;
        }

        $post = get_post($id);
        if (!$post instanceof WP_Post) {
            return null;
        }

        if (get_post_status($post) !== 'publish') {
            return null;
        }

        if (!PostEndpoint::postTypeQueryable($post->post_type)) {
            return null;
        }

        return post_password_required($post) ? null : $post;
    }

    private function postNotFound(): WP_Error
    {
        return new WP_Error(
            'gallop_comments_post_not_found',
            __('No post with comments to read was found.', 'gallop'),
            ['status' => 404]
        );
    }

    /**
     * Whether a visitor who is not logged in may comment.
     */
    private function isOpen(WP_Post $post): bool
    {
        return comments_open($post) && !get_option('comment_registration');
    }

    /**
     * How deep replies may nest. 1 means no replies: threading is switched off.
     */
    private function threadDepth(): int
    {
        if (!get_option('thread_comments')) {
            return 1;
        }

        return max(1, (int) get_option('thread_comments_depth'));
    }

    /**
     * Approved comments only. The count on the post itself includes pingbacks and
     * trackbacks, which this route does not return.
     */
    private function countFor(WP_Post $post): int
    {
        return (int) get_comments([
            'post_id' => $post->ID,
            'status' => 'approve',
            'type' => 'comment',
            'count' => true,
        ]);
    }

    /**
     * What WordPress's comment form handling does not check about a reply: that its
     * parent is on the same post, that replies are allowed, and how deep they go.
     * Without the first, a reply could be attached to a comment on another post.
     */
    private function checkParent(int $parentId, WP_Post $post): ?WP_Error
    {
        if ($this->threadDepth() < 2) {
            return new WP_Error(
                'gallop_comment_threading_disabled',
                __('Replies are switched off on this site.', 'gallop'),
                ['status' => 400]
            );
        }

        $parent = get_comment($parentId);
        $valid = $parent instanceof WP_Comment
            && (int) $parent->comment_post_ID === $post->ID
            && wp_get_comment_status($parent) === 'approved'
            && in_array($parent->comment_type, ['', 'comment'], true);

        if (!$valid) {
            return new WP_Error(
                'gallop_comment_invalid_parent',
                __('The comment being replied to is not available.', 'gallop'),
                ['status' => 400]
            );
        }

        if ($this->depthOf($parent) >= $this->threadDepth()) {
            return new WP_Error(
                'gallop_comment_thread_too_deep',
                __('This thread cannot be replied to any further.', 'gallop'),
                ['status' => 400]
            );
        }

        return null;
    }

    /**
     * 1 for a comment on the post itself, 2 for a reply to one, and so on.
     */
    private function depthOf(WP_Comment $comment): int
    {
        $depth = 1;
        $seen = [(int) $comment->comment_ID => true];
        $parentId = (int) $comment->comment_parent;

        // Bounded, and stops on a comment already visited: stored parents are not
        // guaranteed to form a tree.
        while ($parentId > 0 && $depth < 100 && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $parent = get_comment($parentId);
            if (!$parent instanceof WP_Comment) {
                break;
            }
            $depth++;
            $parentId = (int) $parent->comment_parent;
        }

        return $depth;
    }

    private function cleanUserAgent(string $userAgent): string
    {
        $userAgent = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $userAgent);

        return substr(trim($userAgent), 0, self::USER_AGENT_MAX);
    }

    /**
     * The page the visitor commented from, or an empty string if what was sent is
     * not a web address.
     */
    private function cleanReferer(string $referer): string
    {
        $referer = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $referer));
        if ($referer === '') {
            return '';
        }

        $clean = esc_url_raw($referer, ['http', 'https']);

        return (is_string($clean) && wp_parse_url($clean, PHP_URL_HOST)) ? $clean : '';
    }

    /**
     * Run a submission as the visitor: their address and browser, and logged out.
     *
     * WordPress and anti-spam plugins read a commenter's address and browser from
     * $_SERVER, where PHP puts the details of whoever made the request. Here that is
     * the front end's server, so every comment would be recorded, throttled and
     * judged as coming from one address. For the length of the call the visitor's
     * details are put in their place, and everything is put back afterwards whether
     * or not the call succeeds.
     *
     * The visitor's details came from the front end. They are believed because the
     * front end presented the site's key, which is checked before this can run.
     *
     * The request is also made anonymous. It normally is already, but if it arrived
     * logged in -- through an Application Password, say -- WordPress would credit the
     * comment to that user, skip the flood check, and filter its HTML as that
     * user's.
     */
    private function asVisitor(string $ip, string $userAgent, string $referer, callable $fn): mixed
    {
        $names = array_merge(['REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_REFERER'], self::PROXY_HEADERS);

        $snapshot = [];
        foreach ($names as $name) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- kept only to be put back unchanged; never read or output.
            $snapshot[$name] = array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null;
        }
        $previousUser = get_current_user_id();

        try {
            foreach (self::PROXY_HEADERS as $name) {
                unset($_SERVER[$name]);
            }

            $_SERVER['REMOTE_ADDR'] = $ip;
            // WordPress adds slashes to $_SERVER when it starts, and takes them off
            // again when it stores the comment.
            $_SERVER['HTTP_USER_AGENT'] = wp_slash($userAgent);

            if ($referer !== '') {
                $_SERVER['HTTP_REFERER'] = wp_slash($referer);
            } else {
                unset($_SERVER['HTTP_REFERER']);
            }

            wp_set_current_user(0);
            // Setting the user the request already has changes nothing and fires
            // nothing, so the comment filters are set for a visitor by hand.
            kses_init();

            return $fn();
        } finally {
            foreach ($snapshot as $name => $value) {
                if ($value === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $value;
                }
            }

            wp_set_current_user($previousUser);
            kses_init();
        }
    }

    /**
     * One comment, as both routes return it.
     *
     * The commenter's email address, IP address and browser are never part of it.
     *
     * @return array<string, mixed>
     */
    private function serialize(WP_Comment $comment, WP_Post $post, bool $withStatus = false): array
    {
        $data = [
            'id' => (int) $comment->comment_ID,
            'parent' => (int) $comment->comment_parent,
            // Plain text, not HTML. The front end escapes it.
            'authorName' => get_comment_author($comment),
            'authorUrl' => get_comment_author_url($comment),
            // Only a comment left while logged in as the post's author. Typing
            // the author's name into the form does not make it true.
            'isPostAuthor' => (int) $comment->user_id > 0 && (int) $comment->user_id === (int) $post->post_author,
            'avatar' => $this->avatar($comment),
            'dateGmt' => mysql_to_rfc3339($comment->comment_date_gmt),
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, applied as core's REST API applies it.
            'content' => apply_filters('comment_text', $comment->comment_content, $comment, []),
        ];

        if ($withStatus) {
            $data['status'] = $this->statusOf($comment);
        }

        /**
         * Filter one comment as it is returned.
         *
         * @param array<string, mixed> $data    The comment.
         * @param WP_Comment           $comment The comment it was built from.
         * @param WP_Post              $post    The post it is on.
         */
        $filtered = apply_filters('gallop_comment_data', $data, $comment, $post);

        return is_array($filtered) ? $filtered : $data;
    }

    /**
     * Avatar addresses by size, or null when the site has avatars switched off.
     *
     * @return array<string, string>|null
     */
    private function avatar(WP_Comment $comment): ?array
    {
        if (!get_option('show_avatars')) {
            return null;
        }

        /**
         * Filter the avatar sizes returned with each comment, in pixels.
         *
         * @param int[] $sizes Default 48 and 96.
         */
        $sizes = apply_filters('gallop_comment_avatar_sizes', [48, 96]);
        if (!is_array($sizes)) {
            $sizes = [48, 96];
        }

        $avatar = [];
        foreach ($sizes as $size) {
            $size = (int) $size;
            if ($size <= 0) {
                continue;
            }
            $url = get_avatar_url($comment, ['size' => $size]);
            if (is_string($url) && $url !== '') {
                $avatar[(string) $size] = $url;
            }
        }

        return $avatar === [] ? null : $avatar;
    }

    /**
     * @return 'approved'|'hold'|'spam'|'trash'
     */
    private function statusOf(WP_Comment $comment): string
    {
        return match (wp_get_comment_status($comment)) {
            'approved' => 'approved',
            'spam' => 'spam',
            'trash' => 'trash',
            default => 'hold',
        };
    }

    /**
     * Give a refusal from WordPress one of this plugin's codes and a status.
     *
     * WordPress's own errors here are written for its comment form. Their data is a
     * bare number, not a status a REST response can use, so returned unchanged every
     * one of them would reach the caller as a 500. Their messages contain HTML.
     */
    private function mapError(WP_Error $error): WP_Error
    {
        $reason = (string) $error->get_error_code();

        $notFound = [
            'gallop_comments_post_not_found',
            __('No post with comments to read was found.', 'gallop'),
            404,
        ];

        $map = [
            'comment_id_not_found' => $notFound,
            'comment_on_trash' => $notFound,
            'comment_on_draft' => $notFound,
            'comment_on_password_protected' => $notFound,
            'comment_closed' => [
                'gallop_comment_closed',
                __('Comments are closed.', 'gallop'),
                403,
            ],
            'not_logged_in' => [
                'gallop_comment_login_required',
                __('This site only accepts comments from logged-in users.', 'gallop'),
                403,
            ],
            'require_name_email' => [
                'gallop_comment_name_email_required',
                __('A name and an email address are required.', 'gallop'),
                400,
            ],
            'require_valid_email' => [
                'gallop_comment_invalid_email',
                __('Please enter a valid email address.', 'gallop'),
                400,
            ],
            'require_valid_comment' => [
                'gallop_comment_empty',
                __('Please write a comment.', 'gallop'),
                400,
            ],
            'comment_author_column_length' => [
                'gallop_comment_name_too_long',
                __('The name is too long.', 'gallop'),
                400,
            ],
            'comment_author_email_column_length' => [
                'gallop_comment_email_too_long',
                __('The email address is too long.', 'gallop'),
                400,
            ],
            'comment_author_url_column_length' => [
                'gallop_comment_url_too_long',
                __('The website address is too long.', 'gallop'),
                400,
            ],
            'comment_content_column_length' => [
                'gallop_comment_too_long',
                __('The comment is too long.', 'gallop'),
                400,
            ],
            'comment_reply_to_unapproved_comment' => [
                'gallop_comment_invalid_parent',
                __('The comment being replied to is not available.', 'gallop'),
                400,
            ],
            'comment_duplicate' => [
                'gallop_comment_duplicate',
                __('This comment has already been posted.', 'gallop'),
                409,
            ],
            'comment_flood' => [
                'gallop_comment_flood',
                __('Comments are being posted too quickly. Please wait a moment.', 'gallop'),
                429,
            ],
            'comment_save_error' => [
                'gallop_comment_save_failed',
                __('The comment could not be saved.', 'gallop'),
                500,
            ],
        ];

        // Anything else is another plugin's refusal, made through one of
        // WordPress's filters.
        [$code, $message, $status] = $map[$reason] ?? [
            'gallop_comment_rejected',
            __('The comment was not accepted.', 'gallop'),
            400,
        ];

        return new WP_Error($code, $message, ['status' => $status, 'reason' => $reason]);
    }
}
