<?php

declare(strict_types=1);

namespace Gallop\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Gallop\Auth\ApiKey;
use Gallop\Members\Mailer;
use Gallop\Members\Member;
use Gallop\Members\Pending;
use Gallop\Support\Visitor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * Members for a headless front end: log in, sign up, reset a password, and read
 * or change a profile.
 *
 * Every route needs the site's API key with the "Manage members" permission,
 * because every one acts on a person's behalf and the request comes from the front
 * end's own server. The front end keeps its own session for the member; WordPress
 * is only asked when the member logs in or changes something.
 *
 * Nothing here sets WordPress cookies. Login is wp_authenticate(), the check
 * behind wp-login.php, run as the visitor so that login-protection plugins see
 * the visitor's address and not the front end's server.
 */
final class MembersEndpoint
{
    private const NAMESPACE = 'gallop/v1';

    /** Wrong passwords for one login name from one address before a wait. */
    private const RATE_LIMIT_WINDOW = 15 * MINUTE_IN_SECONDS;
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;

    private const LOGIN_MAX = 254;
    private const PASSWORD_MAX = 4096;

    public function register(): void
    {
        $keyed = ApiKey::requires(ApiKey::CAP_MEMBERS);

        $string = static fn (int $max) => [
            'required' => false,
            'default' => '',
            'validate_callback' => static fn ($value): bool => is_string($value) && strlen($value) <= $max,
        ];
        // No default: a profile field that was not sent is left as it is, and one
        // sent empty is cleared or refused. With a default the two look alike.
        $optional = static fn (int $max) => [
            'required' => false,
            'validate_callback' => static fn ($value): bool => is_string($value) && strlen($value) <= $max,
        ];
        $bool = [
            'required' => false,
            'type' => 'boolean',
        ];
        $id = [
            'required' => true,
            'sanitize_callback' => 'absint',
            'validate_callback' => static fn ($value): bool => is_numeric($value) && (int) $value > 0,
        ];
        $version = [
            'required' => true,
            'sanitize_callback' => 'absint',
            'validate_callback' => static fn ($value): bool => is_numeric($value) && (int) $value > 0,
        ];

        register_rest_route(self::NAMESPACE, '/members/login', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'login'],
            'permission_callback' => $keyed,
            'args' => [
                'login' => $string(self::LOGIN_MAX),
                'password' => $string(self::PASSWORD_MAX),
                'ip' => $string(45),
                'userAgent' => $string(1024),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/members', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'signUp'],
            'permission_callback' => $keyed,
            'args' => [
                'email' => $string(self::LOGIN_MAX),
                'firstName' => $string(Member::NAME_MAX * 4),
                'lastName' => $string(Member::NAME_MAX * 4),
                'subscribe' => $bool + ['default' => true],
                'ip' => $string(45),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/members/confirm', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'confirm'],
            'permission_callback' => $keyed,
            'args' => [
                'id' => $string(64),
                'key' => $string(64),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/members/reset-request', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'resetRequest'],
            'permission_callback' => $keyed,
            'args' => [
                'login' => $string(self::LOGIN_MAX),
                'ip' => $string(45),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/members/reset', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reset'],
            'permission_callback' => $keyed,
            'args' => [
                'id' => $id,
                'key' => $string(64),
                'password' => $string(self::PASSWORD_MAX),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/members/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
                'permission_callback' => $keyed,
                'args' => [
                    'id' => $id,
                    'sessionVersion' => $version,
                ],
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'update'],
                'permission_callback' => $keyed,
                'args' => [
                    'id' => $id,
                    'sessionVersion' => $version,
                    'displayName' => $optional(Member::NAME_MAX * 4),
                    'firstName' => $optional(Member::NAME_MAX * 4),
                    'lastName' => $optional(Member::NAME_MAX * 4),
                    'email' => $optional(self::LOGIN_MAX),
                    'currentPassword' => $optional(self::PASSWORD_MAX),
                    'newPassword' => $optional(self::PASSWORD_MAX),
                    'subscribed' => $bool,
                    'replyEmails' => $bool,
                ],
            ],
        ]);
    }

    // --- Logging in ---

    public function login(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $this->begin($request);

        $ip = $this->visitorIp($request);
        if ($ip instanceof WP_Error) {
            return $ip;
        }

        $login = trim((string) $request->get_param('login'));
        $password = (string) $request->get_param('password');
        if ($login === '' || $password === '') {
            return $this->invalidCredentials();
        }

        $rateKey = 'gallop_auth_' . md5('members|' . strtolower($login) . '|' . $ip);
        $attempts = (int) get_transient($rateKey);
        if ($attempts >= self::RATE_LIMIT_MAX_ATTEMPTS) {
            return new WP_Error(
                'gallop_member_rate_limited',
                __('Too many login attempts. Please try again later.', 'gallop'),
                ['status' => 429]
            );
        }

        $result = Visitor::run(
            $ip,
            $this->cleanUserAgent((string) $request->get_param('userAgent')),
            '',
            0,
            static fn () => wp_authenticate($login, $password)
        );

        if (!$result instanceof WP_User) {
            set_transient($rateKey, $attempts + 1, self::RATE_LIMIT_WINDOW);

            // An unknown login name is refused before any hashing, which would make
            // it faster to refuse than a wrong password. The same work is done here.
            if (Member::findByLogin($login) === null) {
                wp_hash_password($password);
            }

            /**
             * Fires when a member's login was refused.
             *
             * @param string          $login   What was typed.
             * @param WP_Error|mixed  $result  Why, as WordPress said it.
             * @param WP_REST_Request $request The request, with the key removed.
             */
            do_action('gallop_member_login_failed', $login, $result, $request);

            return $this->invalidCredentials();
        }

        delete_transient($rateKey);

        /** This action is documented in wp-includes/user.php. */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, fired as wp_signon() fires it, so plugins that act on a login see this one.
        do_action('wp_login', $result->user_login, $result);

        /**
         * Fires when a member has logged in through the front end.
         *
         * @param WP_User         $user    The member.
         * @param WP_REST_Request $request The request, with the key removed.
         */
        do_action('gallop_member_login', $result, $request);

        return new WP_REST_Response(['member' => Member::payload($result, true)], 200);
    }

    // --- Signing up ---

    /**
     * Records a sign-up and emails a link to confirm it. Answers the same whether
     * or not the address already has an account, and whether or not an email went
     * out, so the route cannot be used to find out who is a member.
     */
    public function signUp(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $this->begin($request);

        $ip = $this->visitorIp($request);
        if ($ip instanceof WP_Error) {
            return $ip;
        }
        $missing = $this->requireFrontEnd();
        if ($missing !== null) {
            return $missing;
        }

        $email = sanitize_email((string) $request->get_param('email'));
        if ($email === '' || !is_email($email)) {
            return new WP_Error(
                'gallop_member_invalid_email',
                __('Please enter a valid email address.', 'gallop'),
                ['status' => 400]
            );
        }

        $subscribe = (bool) $request->get_param('subscribe');
        $existing = get_user_by('email', $email);

        if ($existing instanceof WP_User) {
            if ($subscribe) {
                $pending = Pending::create($email, ['subscribe' => true, 'existing' => true]);
                if ($pending !== null) {
                    Mailer::confirm($email, $pending['id'], $pending['key'], true);
                }
            } elseif (Pending::allowSend('exists', $email)) {
                Mailer::alreadyRegistered($email);
            }
        } else {
            $pending = Pending::create($email, [
                'firstName' => Member::cleanName($request->get_param('firstName')),
                'lastName' => Member::cleanName($request->get_param('lastName')),
                'subscribe' => $subscribe,
                'existing' => false,
            ]);
            if ($pending !== null) {
                Mailer::confirm($email, $pending['id'], $pending['key'], false);
            }
        }

        /**
         * Fires when someone asked to sign up. The address is not passed: it is not
         * yet known to belong to them.
         *
         * @param string          $ip      The visitor's address.
         * @param WP_REST_Request $request The request, with the key removed.
         */
        do_action('gallop_member_signup_requested', $ip, $request);

        return new WP_REST_Response(['sent' => true], 202);
    }

    /**
     * The link in the confirmation email was opened. A new address becomes a
     * subscriber; an address that already has an account is marked verified and,
     * if that is what was asked, subscribed. Only a brand new account is logged in:
     * for an existing one, an email is never a way past the password.
     */
    public function confirm(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $this->begin($request);

        $pending = Pending::take((string) $request->get_param('id'), (string) $request->get_param('key'));
        if ($pending === null) {
            return $this->linkInvalid();
        }

        $email = $pending['email'];
        $data = $pending['data'];
        $subscribe = (bool) ($data['subscribe'] ?? false);

        $existing = get_user_by('email', $email);
        if ($existing instanceof WP_User) {
            update_user_meta($existing->ID, Member::META_VERIFIED, '1');
            if ($subscribe) {
                update_user_meta($existing->ID, Member::META_SUBSCRIBED, '1');
            }

            /**
             * Fires when a member confirmed their email address.
             *
             * @param WP_User $user The member.
             */
            do_action('gallop_member_verified', $existing);

            return new WP_REST_Response([
                'loggedIn' => false,
                'existing' => true,
                'subscribed' => Member::isSubscribed($existing),
            ], 200);
        }

        $user = $this->createSubscriber(
            $email,
            (string) ($data['firstName'] ?? ''),
            (string) ($data['lastName'] ?? ''),
            $subscribe
        );
        if ($user instanceof WP_Error) {
            return $user;
        }

        do_action('gallop_member_verified', $user);

        /**
         * Fires after a member's account was created through the front end.
         *
         * @param WP_User         $user    The new member.
         * @param WP_REST_Request $request The request, with the key removed.
         */
        do_action('gallop_member_registered', $user, $request);

        // The reader has no password yet. A reset key lets the front end offer
        // them one now, on the page they are on, without another email. It is
        // WordPress's own key: single use, and gone in a day if not used.
        $passwordKey = get_password_reset_key($user);

        return new WP_REST_Response([
            'loggedIn' => true,
            'existing' => false,
            'subscribed' => $subscribe,
            'member' => Member::payload($user, true),
            'passwordKey' => is_string($passwordKey) ? $passwordKey : null,
        ], 201);
    }

    // --- Passwords ---

    /**
     * Emails a password reset link. Answers the same whether or not the account
     * exists, and does the same work either way.
     */
    public function resetRequest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $this->begin($request);

        $ip = $this->visitorIp($request);
        if ($ip instanceof WP_Error) {
            return $ip;
        }
        $missing = $this->requireFrontEnd();
        if ($missing !== null) {
            return $missing;
        }

        $user = Member::findByLogin((string) $request->get_param('login'));

        if ($user instanceof WP_User && Pending::allowSend('reset', $user->user_email)) {
            $key = get_password_reset_key($user);
            if (is_string($key)) {
                Mailer::reset($user, $key);
            }
        } else {
            // A reset key is hashed before it is stored; do as much for a miss.
            wp_hash_password(wp_generate_password(20, false));
        }

        return new WP_REST_Response(['sent' => true], 202);
    }

    public function reset(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $this->begin($request);

        $user = get_user_by('id', (int) $request->get_param('id'));
        if (!$user instanceof WP_User) {
            return $this->linkInvalid();
        }

        $checked = check_password_reset_key((string) $request->get_param('key'), $user->user_login);
        if (!$checked instanceof WP_User) {
            return $this->linkInvalid();
        }

        $password = (string) $request->get_param('password');
        $weak = Member::checkPassword($password);
        if ($weak !== null) {
            return $weak;
        }

        /** This action is documented in wp-login.php. */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, fired as reset_password() fires it.
        do_action('password_reset', $user, $password);

        // wp_set_password() also clears the reset key, so the link works once.
        // reset_password() is not used: it emails the site's administrator about
        // every member's reset.
        wp_set_password($password, $user->ID);

        // Opening the link proved the address belongs to them.
        update_user_meta($user->ID, Member::META_VERIFIED, '1');
        Member::bumpSessionVersion($user);

        $user = get_user_by('id', $user->ID);

        /**
         * Fires after a member set a new password through a reset link.
         *
         * @param WP_User $user The member.
         */
        do_action('gallop_member_password_reset', $user);

        return new WP_REST_Response(['member' => Member::payload($user, true)], 200);
    }

    // --- Profiles ---

    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $this->begin($request);

        $user = Member::assertSession((int) $request->get_param('id'), (int) $request->get_param('sessionVersion'));
        if ($user instanceof WP_Error) {
            return $user;
        }

        return new WP_REST_Response(['member' => Member::payload($user, true)], 200);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $this->begin($request);

        $user = Member::assertSession((int) $request->get_param('id'), (int) $request->get_param('sessionVersion'));
        if ($user instanceof WP_Error) {
            return $user;
        }

        $fields = ['ID' => $user->ID];
        $emailChanged = false;
        $passwordChanged = false;

        if ($request->has_param('displayName')) {
            $name = Member::cleanName($request->get_param('displayName'));
            if ($name === '') {
                return new WP_Error(
                    'gallop_member_invalid_name',
                    __('Please enter a name.', 'gallop'),
                    ['status' => 400]
                );
            }
            $fields['display_name'] = $name;
        }
        if ($request->has_param('firstName')) {
            $fields['first_name'] = Member::cleanName($request->get_param('firstName'));
        }
        if ($request->has_param('lastName')) {
            $fields['last_name'] = Member::cleanName($request->get_param('lastName'));
        }

        $email = $request->has_param('email') ? sanitize_email((string) $request->get_param('email')) : '';
        if ($email !== '' && strcasecmp($email, $user->user_email) !== 0) {
            if (!is_email($email)) {
                return new WP_Error(
                    'gallop_member_invalid_email',
                    __('Please enter a valid email address.', 'gallop'),
                    ['status' => 400]
                );
            }
            $missing = $this->requireFrontEnd();
            if ($missing !== null) {
                return $missing;
            }
            $fields['user_email'] = $email;
            $emailChanged = true;
        }

        $newPassword = (string) $request->get_param('newPassword');
        if ($newPassword !== '') {
            $weak = Member::checkPassword($newPassword);
            if ($weak !== null) {
                return $weak;
            }
            $fields['user_pass'] = $newPassword;
            $passwordChanged = true;
        }

        // Changing how the member logs in needs the password they have now, so a
        // session left open on a shared computer cannot be turned into a takeover.
        if ($emailChanged || $passwordChanged) {
            $current = (string) $request->get_param('currentPassword');
            if ($current === '' || !wp_check_password($current, $user->user_pass, $user->ID)) {
                return new WP_Error(
                    'gallop_member_wrong_password',
                    __('Your current password is not right.', 'gallop'),
                    ['status' => 403]
                );
            }
        }

        if ($emailChanged) {
            $taken = email_exists($email);
            if ($taken !== false && (int) $taken !== $user->ID) {
                return new WP_Error(
                    'gallop_member_email_taken',
                    __('That email address is already in use.', 'gallop'),
                    ['status' => 409]
                );
            }
        }

        if (count($fields) > 1) {
            $saved = wp_update_user($fields);
            if ($saved instanceof WP_Error) {
                return new WP_Error(
                    'gallop_member_update_failed',
                    __('The changes could not be saved.', 'gallop'),
                    ['status' => 400, 'reason' => $saved->get_error_code()]
                );
            }
        }

        if ($request->has_param('subscribed')) {
            update_user_meta($user->ID, Member::META_SUBSCRIBED, $request->get_param('subscribed') ? '1' : '0');
        }
        if ($request->has_param('replyEmails')) {
            update_user_meta($user->ID, Member::META_REPLY_EMAILS, $request->get_param('replyEmails') ? '1' : '0');
        }

        if ($emailChanged) {
            // The new address has not been proven yet.
            update_user_meta($user->ID, Member::META_VERIFIED, '0');
            $pending = Pending::create($email, ['subscribe' => false, 'existing' => true]);
            if ($pending !== null) {
                Mailer::confirm($email, $pending['id'], $pending['key'], true);
            }
        }

        if ($emailChanged || $passwordChanged) {
            Member::bumpSessionVersion($user);
        }

        $user = get_user_by('id', $user->ID);

        /**
         * Fires after a member changed their profile through the front end.
         *
         * @param WP_User         $user    The member, as saved.
         * @param WP_REST_Request $request The request, with the key removed.
         */
        do_action('gallop_member_updated', $user, $request);

        return new WP_REST_Response(['member' => Member::payload($user, true)], 200);
    }

    // --- Helpers ---

    /**
     * What every handler does first: the key is gone from the request, and nothing
     * answered here is for a cache.
     */
    private function begin(WP_REST_Request $request): void
    {
        ApiKey::scrub($request);
        add_filter('rest_send_nocache_headers', '__return_true');
    }

    private function visitorIp(WP_REST_Request $request): string|WP_Error
    {
        $ip = filter_var(trim((string) $request->get_param('ip')), FILTER_VALIDATE_IP);
        if ($ip === false) {
            return new WP_Error(
                'gallop_member_invalid_ip',
                __('A valid visitor IP address is required.', 'gallop'),
                ['status' => 400]
            );
        }

        return $ip;
    }

    /**
     * Emails carry links to the front end, so its address has to be known.
     */
    private function requireFrontEnd(): ?WP_Error
    {
        if (Mailer::frontEndUrl() !== null) {
            return null;
        }

        return new WP_Error(
            'gallop_member_front_end_missing',
            __('Set the Next.js Production URL under Gallop → Settings before members can sign up.', 'gallop'),
            ['status' => 500]
        );
    }

    private function invalidCredentials(): WP_Error
    {
        return new WP_Error(
            'gallop_member_invalid_credentials',
            __('The email address or password is not right.', 'gallop'),
            ['status' => 401]
        );
    }

    private function linkInvalid(): WP_Error
    {
        return new WP_Error(
            'gallop_member_link_invalid',
            __('This link has expired or was already used. Please ask for a new one.', 'gallop'),
            ['status' => 400]
        );
    }

    private function cleanUserAgent(string $userAgent): string
    {
        return substr(sanitize_text_field($userAgent), 0, 254);
    }

    /**
     * The WordPress user for a confirmed sign-up.
     *
     * The username comes from the address, since the member typed no other, made
     * unique if it is taken. The nicename, which WordPress puts in author URLs, is
     * random so the address is not published through it.
     */
    private function createSubscriber(string $email, string $firstName, string $lastName, bool $subscribe): WP_User|WP_Error
    {
        $displayName = trim($firstName . ' ' . $lastName);
        $local = (string) strstr($email, '@', true);
        if ($displayName === '') {
            $displayName = $local !== '' ? $local : __('Reader', 'gallop');
        }

        $id = wp_insert_user([
            'user_login' => $this->uniqueLogin($local),
            'user_pass' => wp_generate_password(32, true, true),
            'user_email' => $email,
            'user_nicename' => 'member-' . strtolower(wp_generate_password(12, false)),
            'display_name' => $displayName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => 'subscriber',
        ]);

        if ($id instanceof WP_Error) {
            return new WP_Error(
                'gallop_member_create_failed',
                __('The account could not be created.', 'gallop'),
                ['status' => 500, 'reason' => $id->get_error_code()]
            );
        }

        update_user_meta($id, Member::META_VERIFIED, '1');
        update_user_meta($id, Member::META_SUBSCRIBED, $subscribe ? '1' : '0');
        update_user_meta($id, Member::META_REPLY_EMAILS, '1');

        $user = get_user_by('id', $id);

        return $user instanceof WP_User ? $user : new WP_Error(
            'gallop_member_create_failed',
            __('The account could not be created.', 'gallop'),
            ['status' => 500]
        );
    }

    private function uniqueLogin(string $local): string
    {
        $base = substr(sanitize_user(strtolower($local), true), 0, 50);
        if ($base === '') {
            $base = 'member';
        }

        $login = $base;
        while (username_exists($login) !== false) {
            $login = $base . '-' . strtolower(wp_generate_password(4, false));
        }

        return $login;
    }
}
