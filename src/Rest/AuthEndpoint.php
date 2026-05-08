<?php

declare(strict_types=1);

namespace Gallop\Rest;

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

        $user = wp_signon([
            'user_login' => $username,
            'user_password' => $password,
            'remember' => $remember,
        ], is_ssl());

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
            return new WP_REST_Response(['user' => null], 200);
        }

        return new WP_REST_Response([
            'user' => $this->buildUserPayload(wp_get_current_user()),
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
        $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $value = is_string($_SERVER[$key]) ? $_SERVER[$key] : '';
            $first = trim(explode(',', $value)[0]);
            $ip = filter_var($first, FILTER_VALIDATE_IP);
            if ($ip !== false) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }
}
