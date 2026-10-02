<?php

declare(strict_types=1);

namespace Gallop\Members;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;
use WP_User;

/**
 * A WordPress user as a front end sees one of its members.
 *
 * Members are ordinary WordPress users. What this plugin adds is a little user
 * meta: whether the address was confirmed, what email the member asked for, and a
 * session version a front end can hand back to prove the session it holds is
 * still current.
 */
final class Member
{
    public const META_VERIFIED = 'gallop_verified';
    public const META_SUBSCRIBED = 'gallop_subscribed';
    public const META_REPLY_EMAILS = 'gallop_reply_emails';
    public const META_SESSION_VERSION = 'gallop_session_version';

    public const PASSWORD_MIN = 8;
    public const PASSWORD_MAX = 128;
    public const NAME_MAX = 100;

    /** The session version a user has before any change raised it. */
    private const FIRST_VERSION = 1;

    /**
     * The member as returned to the front end.
     *
     * The email address is included only where the front end has to show or check
     * it. It is never in the list a session cookie is built from.
     *
     * @return array<string, mixed>
     */
    public static function payload(WP_User $user, bool $withEmail = false): array
    {
        $data = [
            'id' => $user->ID,
            'username' => $user->user_login,
            'displayName' => $user->display_name,
            'firstName' => (string) $user->first_name,
            'lastName' => (string) $user->last_name,
            'avatar' => self::avatar($user),
            'isAdmin' => self::isAdmin($user),
            'subscribed' => self::isSubscribed($user),
            'replyEmails' => self::wantsReplyEmails($user),
            'verified' => self::isVerified($user),
            'sessionVersion' => self::sessionVersion($user),
        ];

        if ($withEmail) {
            $data['email'] = $user->user_email;
        }

        /**
         * Filter a member as returned to the front end.
         *
         * @param array<string, mixed> $data The member.
         * @param WP_User              $user The user.
         */
        $filtered = apply_filters('gallop_member_data', $data, $user);

        return is_array($filtered) ? $filtered : $data;
    }

    /**
     * The user as one row of the list a front end sends mailings from. Always
     * with the email address: the list is only given to a key allowed to send.
     *
     * @return array<string, mixed>
     */
    public static function listing(WP_User $user): array
    {
        return [
            'id' => $user->ID,
            'displayName' => $user->display_name,
            'firstName' => (string) $user->first_name,
            'lastName' => (string) $user->last_name,
            'email' => $user->user_email,
            'roles' => array_values(array_filter((array) $user->roles, 'is_string')),
            'subscribed' => self::isSubscribed($user),
            // They said no themselves, which is not the same as never having
            // been asked: an editor who never chose is not subscribed either.
            'optedOut' => get_user_meta($user->ID, self::META_SUBSCRIBED, true) === '0',
            'verified' => self::isVerified($user),
            'registered' => (string) mysql_to_rfc3339($user->user_registered),
        ];
    }

    /**
     * The user, if the session a front end holds for them is still current.
     *
     * One answer for a user that does not exist and a version that is out of date:
     * both mean the session is no good, and neither is a reason to say which.
     */
    public static function assertSession(int $id, int $version): WP_User|WP_Error
    {
        $user = $id > 0 ? get_user_by('id', $id) : false;

        if (!$user instanceof WP_User || $version !== self::sessionVersion($user)) {
            return new WP_Error(
                'gallop_member_session_invalid',
                __('Please log in again.', 'gallop'),
                ['status' => 401]
            );
        }

        return $user;
    }

    public static function sessionVersion(WP_User $user): int
    {
        $stored = (int) get_user_meta($user->ID, self::META_SESSION_VERSION, true);

        return $stored > 0 ? $stored : self::FIRST_VERSION;
    }

    /**
     * Ends every session a front end holds for the user. Called when the password
     * or the email address changes.
     */
    public static function bumpSessionVersion(WP_User $user): int
    {
        $next = self::sessionVersion($user) + 1;
        update_user_meta($user->ID, self::META_SESSION_VERSION, $next);

        return $next;
    }

    public static function isAdmin(WP_User $user): bool
    {
        return user_can($user, 'manage_options');
    }

    /**
     * Whether the member's address is known to be theirs. An account made before
     * this plugin recorded anything counts as verified: it was made some other
     * way, and its owner logs in with a password. Only an address waiting for
     * its confirmation, after a change, is not.
     */
    public static function isVerified(WP_User $user): bool
    {
        return get_user_meta($user->ID, self::META_VERIFIED, true) !== '0';
    }

    /**
     * Whether the member wants new posts by email.
     *
     * A user the plugin has never recorded a choice for is taken to want them if
     * they hold the Subscriber role: that is what such accounts were made for.
     * Their own choice, once made, is what counts.
     */
    public static function isSubscribed(WP_User $user): bool
    {
        $stored = get_user_meta($user->ID, self::META_SUBSCRIBED, true);
        if ($stored !== '') {
            return $stored === '1';
        }

        return in_array('subscriber', (array) $user->roles, true);
    }

    /**
     * Whether the member wants an email when someone answers their comment. Off
     * until they say so, except for accounts this plugin created, which start on.
     */
    public static function wantsReplyEmails(WP_User $user): bool
    {
        return get_user_meta($user->ID, self::META_REPLY_EMAILS, true) === '1';
    }

    public static function avatar(WP_User $user): string
    {
        $url = get_avatar_url($user->ID, ['size' => 96]);

        return is_string($url) ? $url : '';
    }

    /**
     * The user a login form's first field names: an email address or a username.
     */
    public static function findByLogin(string $login): ?WP_User
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        $user = str_contains($login, '@')
            ? get_user_by('email', $login)
            : get_user_by('login', $login);

        return $user instanceof WP_User ? $user : null;
    }

    /**
     * Why a password is not acceptable, or null if it is.
     */
    public static function checkPassword(string $password): ?WP_Error
    {
        $length = mb_strlen($password);
        if ($length < self::PASSWORD_MIN || $length > self::PASSWORD_MAX) {
            return new WP_Error(
                'gallop_member_weak_password',
                sprintf(
                    /* translators: 1: fewest characters, 2: most characters. */
                    __('Please choose a password of %1$d to %2$d characters.', 'gallop'),
                    self::PASSWORD_MIN,
                    self::PASSWORD_MAX
                ),
                ['status' => 400]
            );
        }

        return null;
    }

    /**
     * A name as typed, trimmed and cut to what WordPress stores.
     */
    public static function cleanName(mixed $value): string
    {
        return mb_substr(sanitize_text_field((string) $value), 0, self::NAME_MAX);
    }
}
