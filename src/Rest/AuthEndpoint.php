<?php

declare(strict_types=1);

namespace Gallop\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Gallop\Support\ClientIp;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

final class AuthEndpoint
{
    private const NAMESPACE = 'gallop/v1';
    private const RATE_LIMIT_WINDOW = 15 * MINUTE_IN_SECONDS;
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/auth/login', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'login'],
            'permission_callback' => '__return_true',
            'args' => [
                'username' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_user',
                    'validate_callback' => static fn ($value) => is_string($value) && $value !== '',
                ],
                'password' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => static fn ($value) => is_string($value) && $value !== '',
                ],
                'remember' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => true,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/auth/logout', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'logout'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        register_rest_route(self::NAMESPACE, '/auth/session', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'session'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function login(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $username = (string) $request->get_param('username');
        $password = (string) $request->get_param('password');
        $remember = (bool) $request->get_param('remember');

        $rateKey = $this->rateLimitKey($username);
        $attempts = (int) get_transient($rateKey);
        if ($attempts >= self::RATE_LIMIT_MAX_ATTEMPTS) {
            return new WP_Error(
                'gallop_auth_rate_limited',
                __('Too many login attempts. Please try again later.', 'gallop'),
                ['status' => 429]
            );
        }

        // wp_signon() sets the auth cookies with setcookie(), which does not populate
        // $_COOKIE for the current request. wp_create_nonce() derives its nonce from
        // wp_get_session_token(), which reads the logged-in cookie out of $_COOKIE --
        // so without this the nonce handed back below is tied to an empty session
        // token, and every request replaying it is rejected with a 403. Capture the
        // cookie as core sets it and prime $_COOKIE so the nonce is generated against
        // the real session.
        $primeCookie = static function (string $loggedInCookie): void {
            $_COOKIE[LOGGED_IN_COOKIE] = $loggedInCookie;
        };
        add_action('set_logged_in_cookie', $primeCookie);

        $user = wp_signon([
            'user_login' => $username,
            'user_password' => $password,
            'remember' => $remember,
        ], is_ssl());

        remove_action('set_logged_in_cookie', $primeCookie);

        if ($user instanceof WP_Error) {
            set_transient($rateKey, $attempts + 1, self::RATE_LIMIT_WINDOW);

            do_action('gallop_auth_login_failed', $username, $request);

            return new WP_Error(
                'gallop_auth_invalid_credentials',
                __('Invalid username or password.', 'gallop'),
                ['status' => 401]
            );
        }

        delete_transient($rateKey);
        wp_set_current_user($user->ID);

        do_action('gallop_auth_login_success', $user, $request);

        return new WP_REST_Response([
            'user' => $this->buildUserPayload($user),
            // WordPress rejects a cookie-authenticated REST request carrying no
            // matching wp_rest nonce -- rest_cookie_check_errors() drops it to user 0
            // -- so a browser session is unusable without one. It can only be minted
            // where the user is resolved, which is here: a later request has the
            // cookies but no nonce yet, so it would be read as anonymous and could
            // never bootstrap one for itself.
            'nonce' => wp_create_nonce('wp_rest'),
        ], 200);
    }

    public function logout(WP_REST_Request $request): WP_REST_Response
    {
        $user = wp_get_current_user();

        wp_logout();

        do_action('gallop_auth_logout', $user, $request);

        return new WP_REST_Response(null, 204);
    }

    public function session(): WP_REST_Response
    {
        if (!is_user_logged_in()) {
            return new WP_REST_Response(['user' => null, 'nonce' => null], 200);
        }

        return new WP_REST_Response([
            'user' => $this->buildUserPayload(wp_get_current_user()),
            /** See login(): the nonce is minted where the user resolves. */
            'nonce' => wp_create_nonce('wp_rest'),
        ], 200);
    }

    private function buildUserPayload(WP_User $user): array
    {
        return [
            'id' => $user->ID,
            'username' => $user->user_login,
            'displayName' => $user->display_name,
            'email' => $user->user_email,
            'roles' => array_values($user->roles),
        ];
    }

    private function rateLimitKey(string $username): string
    {
        $ip = $this->clientIp();
        return 'gallop_auth_' . md5(strtolower($username) . '|' . $ip);
    }

    private function clientIp(): string
    {
        return ClientIp::resolve();
    }
}
