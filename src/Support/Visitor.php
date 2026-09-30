<?php

declare(strict_types=1);

namespace Gallop\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs a piece of WordPress as if the visitor had made the request themselves.
 *
 * WordPress and its plugins read a visitor's address and browser from $_SERVER,
 * where PHP puts the details of whoever made the request. When a front end's server
 * sends a visitor's comment or login on their behalf, that is the server, so every
 * visitor would be recorded, throttled and judged as coming from one address. For
 * the length of the call the visitor's details are put in their place, and
 * everything is put back afterwards whether or not the call succeeds.
 *
 * The visitor's details came from the front end. They are believed because the
 * front end presented the site's key, which is checked before this can run.
 */
final class Visitor
{
    /**
     * Headers that describe the front end's server or the route the request took,
     * not the visitor. They are hidden while the call runs, so nothing reads the
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

    /**
     * @param string   $ip        The visitor's address, already validated.
     * @param string   $userAgent The visitor's browser, already cleaned. Empty leaves none.
     * @param string   $referer   The page the visitor was on. Empty leaves none.
     * @param int      $userId    Who WordPress should take the visitor to be. 0 for a visitor
     *                            who is not logged in, which is also what a request that
     *                            arrived logged in through an Application Password becomes:
     *                            WordPress would otherwise credit the action to that user.
     * @param callable $fn        What to run.
     */
    public static function run(string $ip, string $userAgent, string $referer, int $userId, callable $fn): mixed
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
            // again when it stores a comment.
            if ($userAgent !== '') {
                $_SERVER['HTTP_USER_AGENT'] = wp_slash($userAgent);
            } else {
                unset($_SERVER['HTTP_USER_AGENT']);
            }

            if ($referer !== '') {
                $_SERVER['HTTP_REFERER'] = wp_slash($referer);
            } else {
                unset($_SERVER['HTTP_REFERER']);
            }

            wp_set_current_user($userId);
            // Setting the user the request already has changes nothing and fires
            // nothing, so the content filters are set for that user by hand.
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
}
