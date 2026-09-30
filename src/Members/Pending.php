<?php

declare(strict_types=1);

namespace Gallop\Members;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A sign-up waiting for its email address to be confirmed.
 *
 * Nothing is written to the users table until the owner of the address clicks the
 * link they were sent. Until then the sign-up is a transient: the address, the
 * names typed, and a hash of the key in the link. Anyone can type any address into
 * a form; only the person who receives the email can finish.
 *
 * The same record serves an existing account that asked to subscribe or changed its
 * address: confirming it marks the address verified.
 */
final class Pending
{
    private const PREFIX = 'gallop_pending_';
    private const SENT_PREFIX = 'gallop_confirm_sent_';

    /** How long a link is good for. */
    public const TTL = 2 * DAY_IN_SECONDS;

    /** How often one address may be emailed: once in this many seconds, and so many a day. */
    private const COOLDOWN = 10 * MINUTE_IN_SECONDS;
    private const DAILY_MAX = 5;

    /** 32 random bytes, base64url: what a key in a link looks like. */
    private const KEY_FORMAT = '/\A[A-Za-z0-9_-]{43}\z/';
    private const ID_FORMAT = '/\A[0-9a-f]{64}\z/';

    /**
     * The id a link carries for an address: a hash, so no address appears in a URL
     * or in the options table's key.
     */
    public static function id(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * Records a sign-up and returns the id and key for its link, or null when the
     * address has been emailed too recently. The caller answers the same either
     * way, so nobody learns from the answer whether an email went out.
     *
     * @param array<string, mixed> $data What to keep until the link is clicked.
     * @return array{id: string, key: string}|null
     */
    public static function create(string $email, array $data): ?array
    {
        if (!self::allowSend('confirm', $email)) {
            return null;
        }

        $id = self::id($email);
        $key = self::newKey();

        set_transient(self::PREFIX . $id, [
            'email' => strtolower(trim($email)),
            'hash' => hash('sha256', $key),
            'data' => $data,
            'expires' => time() + self::TTL,
        ], self::TTL);

        return ['id' => $id, 'key' => $key];
    }

    /**
     * The sign-up a link stands for, removed so the link works once. Null when the
     * id or key is wrong, or the link has expired.
     *
     * @return array{email: string, data: array<string, mixed>}|null
     */
    public static function take(string $id, string $key): ?array
    {
        if (!preg_match(self::ID_FORMAT, $id) || !preg_match(self::KEY_FORMAT, $key)) {
            return null;
        }

        $stored = get_transient(self::PREFIX . $id);
        if (!is_array($stored) || !isset($stored['hash'], $stored['email']) || !is_string($stored['hash'])) {
            return null;
        }

        if (!hash_equals($stored['hash'], hash('sha256', $key))) {
            return null;
        }

        delete_transient(self::PREFIX . $id);

        if ((int) ($stored['expires'] ?? 0) < time()) {
            return null;
        }

        return [
            'email' => (string) $stored['email'],
            'data' => is_array($stored['data'] ?? null) ? $stored['data'] : [],
        ];
    }

    /**
     * Whether one more email of this kind may go to the address now, and notes
     * that it did. Keeps a form from being used to flood someone's inbox.
     */
    public static function allowSend(string $kind, string $email): bool
    {
        $name = self::SENT_PREFIX . hash('sha256', $kind . '|' . strtolower(trim($email)));
        $now = time();

        $sent = get_transient($name);
        $sent = is_array($sent) ? array_values(array_filter(
            $sent,
            static fn ($at): bool => is_int($at) && $at > $now - DAY_IN_SECONDS
        )) : [];

        $last = $sent === [] ? 0 : max($sent);
        if ($now - $last < self::COOLDOWN || count($sent) >= self::DAILY_MAX) {
            return false;
        }

        $sent[] = $now;
        set_transient($name, $sent, DAY_IN_SECONDS);

        return true;
    }

    private static function newKey(): string
    {
        // random_bytes() throws rather than fall back to a weaker source.
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
