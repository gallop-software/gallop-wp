<?php

declare(strict_types=1);

namespace Gallop\Members;

if (!defined('ABSPATH')) {
    exit;
}

use Gallop\Admin\Settings;
use WP_Comment;
use WP_User;

/**
 * The emails members receive, sent with wp_mail() like every email WordPress
 * sends, so the site's mail setup and plugins apply.
 *
 * Every link points at the front end, which is where the member is. The front
 * end's address is the "Next.js Production URL" setting; nothing here can be sent
 * until it is set.
 */
final class Mailer
{
    /**
     * The front end's address without a trailing slash, or null if none is set.
     */
    public static function frontEndUrl(): ?string
    {
        $url = untrailingslashit(trim((string) get_option(Settings::OPTION_NEXTJS_URL, '')));

        return $url === '' ? null : $url;
    }

    /**
     * A link on the front end. Paths end in a slash, so a front end that adds one
     * never has to redirect a link someone clicked in an email.
     *
     * @param array<string, string> $query
     */
    public static function link(string $path, array $query = []): string
    {
        $base = self::frontEndUrl() ?? untrailingslashit(home_url());

        return add_query_arg(array_map('rawurlencode', $query), $base . trailingslashit($path));
    }

    /**
     * The front end's address for a post.
     */
    public static function postLink(int $postId): string
    {
        $permalink = (string) get_permalink($postId);
        $frontEnd = self::frontEndUrl();
        if ($frontEnd === null) {
            return $permalink;
        }

        $path = (string) wp_parse_url($permalink, PHP_URL_PATH);

        return $frontEnd . ($path === '' ? '/' : $path);
    }

    /**
     * "Click to confirm your address", for a new sign-up or an existing account
     * that asked to subscribe or changed its address.
     */
    public static function confirm(string $to, string $id, string $key, bool $existing): bool
    {
        $link = self::link('/account/confirm', ['id' => $id, 'key' => $key]);
        $site = self::siteName();

        $subject = $existing
            /* translators: %s: site name. */
            ? sprintf(__('Confirm your email address for %s', 'gallop'), $site)
            /* translators: %s: site name. */
            : sprintf(__('Confirm your account on %s', 'gallop'), $site);

        $lines = [
            $existing
                /* translators: %s: site name. */
                ? sprintf(__('Please confirm your email address for %s by opening this link:', 'gallop'), $site)
                /* translators: %s: site name. */
                : sprintf(__('Thanks for signing up to %s. Please confirm your email address by opening this link:', 'gallop'), $site),
            '',
            $link,
            '',
            __('The link works once and expires in 48 hours. If you did not ask for this, you can ignore this email; nothing happens until the link is opened.', 'gallop'),
        ];

        return self::send('confirm', $to, $subject, implode("\n", $lines), ['existing' => $existing]);
    }

    /**
     * Sent when someone signs up with an address that already has an account, so
     * the person who owns it is not left waiting for a confirmation that will
     * never come.
     */
    public static function alreadyRegistered(string $to): bool
    {
        $site = self::siteName();

        $lines = [
            /* translators: %s: site name. */
            sprintf(__('Someone, probably you, tried to sign up to %s with this email address. You already have an account, so you can just log in:', 'gallop'), $site),
            '',
            self::link('/account'),
            '',
            __('If you have forgotten your password, use the "Forgot password?" link there. If this was not you, you can ignore this email.', 'gallop'),
        ];

        /* translators: %s: site name. */
        return self::send('exists', $to, sprintf(__('You already have an account on %s', 'gallop'), $site), implode("\n", $lines), []);
    }

    /**
     * The password reset link. The key is WordPress's own, so it expires and works
     * once as WordPress's do.
     */
    public static function reset(WP_User $user, string $key): bool
    {
        $link = self::link('/account/reset', ['id' => (string) $user->ID, 'key' => $key]);
        $site = self::siteName();

        $lines = [
            /* translators: %s: site name. */
            sprintf(__('Someone asked to reset the password for your account on %s. To choose a new password, open this link:', 'gallop'), $site),
            '',
            $link,
            '',
            __('The link works once and expires in 24 hours. If you did not ask for this, you can ignore this email; your password will not change.', 'gallop'),
        ];

        /* translators: %s: site name. */
        return self::send('reset', $user->user_email, sprintf(__('Reset your password for %s', 'gallop'), $site), implode("\n", $lines), ['user' => $user]);
    }

    /**
     * "Someone answered your comment."
     */
    public static function reply(WP_User $user, WP_Comment $reply, WP_Comment $parent): bool
    {
        $post = get_post((int) $reply->comment_post_ID);
        $title = $post ? wp_specialchars_decode(get_the_title($post), ENT_QUOTES) : '';
        $link = self::postLink((int) $reply->comment_post_ID) . '#comment-' . (int) $reply->comment_ID;
        $author = wp_specialchars_decode(get_comment_author($reply), ENT_QUOTES);

        $lines = [
            /* translators: 1: who replied, 2: post title. */
            sprintf(__('%1$s replied to your comment on "%2$s":', 'gallop'), $author, $title),
            '',
            wp_strip_all_tags((string) $reply->comment_content),
            '',
            __('Read it and answer here:', 'gallop'),
            $link,
            '',
            __('You are receiving this because you asked to be told about replies. You can turn that off on your profile page:', 'gallop'),
            self::link('/account'),
        ];

        /* translators: 1: who replied, 2: post title. */
        $subject = sprintf(__('%1$s replied to your comment on "%2$s"', 'gallop'), $author, $title);

        return self::send('reply', $user->user_email, $subject, implode("\n", $lines), ['user' => $user, 'comment' => $reply]);
    }

    /**
     * @param array<string, mixed> $context What the email is about, for the filter.
     */
    private static function send(string $kind, string $to, string $subject, string $message, array $context): bool
    {
        $email = [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => [],
        ];

        /**
         * Filter an email before it is sent to a member.
         *
         * Return false to send nothing. The message is plain text; add a
         * Content-Type header to send HTML.
         *
         * @param array<string, mixed>|false $email   `to`, `subject`, `message`, `headers`.
         * @param string                     $kind    `confirm`, `exists`, `reset` or `reply`.
         * @param array<string, mixed>       $context What the email is about.
         */
        $email = apply_filters('gallop_member_email', $email, $kind, $context);
        if (!is_array($email)) {
            return false;
        }

        return wp_mail(
            (string) ($email['to'] ?? $to),
            (string) ($email['subject'] ?? $subject),
            (string) ($email['message'] ?? $message),
            $email['headers'] ?? []
        );
    }

    private static function siteName(): string
    {
        return wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    }
}
