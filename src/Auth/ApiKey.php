<?php

declare(strict_types=1);

namespace Gallop\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Gallop\Support\ClientIp;
use WeakMap;
use WP_Error;
use WP_REST_Request;

/**
 * The credential a front end's own server presents before it writes to WordPress.
 *
 * Nothing in this file is secret: the plugin is public, so the route, the header and
 * the way a key is checked are all known to an attacker. The only secret is the key
 * itself, which is 256 bits from the system's random source, unique to the site, and
 * stored as a hash.
 *
 * A key does nothing until its owner grants it a capability, and a capability added
 * by a later version of the plugin is never granted to a key that already exists.
 */
final class ApiKey
{
    public const HEADER = 'X-Gallop-WP-Key';
    public const CONSTANT = 'GALLOP_WP_API_KEY';
    public const PREFIX = 'gallopwp_';

    /**
     * A list of records rather than one hash, so a site can later hold a key per
     * front end -- staging and production, say -- without its stored data changing
     * shape.
     */
    public const OPTION_KEYS = 'gallop_api_key_hash';
    public const OPTION_PERMISSIONS = 'gallop_api_key_permissions';

    /** The one key a site has today. */
    public const DEFAULT_ID = 'default';

    public const CAP_COMMENTS = 'comments';
    public const CAP_MEMBERS = 'members';

    private const SECRET_LENGTH = 43;
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * What a key must look like, generated or set in wp-config.php. The wider range
     * than generate() produces lets an owner supply a key made elsewhere.
     */
    private const FORMAT = '/\Agallopwp_[A-Za-z0-9_-]{32,128}\z/';

    /** Rules out gallopwp_aaaaaaaa... and the like in wp-config.php. */
    private const MIN_DISTINCT = 10;

    private const HASH_FORMAT = '/\Asha256:[0-9a-f]{64}\z/';

    private const FAIL_TRANSIENT = 'gallop_key_fail_';
    private const FAIL_WINDOW = 15 * MINUTE_IN_SECONDS;
    private const FAIL_MAX = 10;

    /**
     * Which key each request presented, or why it was refused.
     *
     * @var WeakMap<WP_REST_Request, string|WP_Error>|null
     */
    private static ?WeakMap $presented = null;

    /**
     * Requests already announced as verified, by capability.
     *
     * @var WeakMap<WP_REST_Request, array<string, true>>|null
     */
    private static ?WeakMap $announced = null;

    /**
     * What a key can be allowed to do.
     *
     * @return array<string, string> Capability slug => label shown in settings.
     */
    public static function capabilities(): array
    {
        $capabilities = [
            self::CAP_COMMENTS => __('Submit comments', 'gallop'),
            self::CAP_MEMBERS => __('Manage members (log in, sign up, profiles)', 'gallop'),
        ];

        /**
         * Filter the capabilities a key can be granted.
         *
         * Adding one here makes it available to tick in settings. It grants it to
         * nothing: a key holds only what its owner has switched on.
         *
         * @param array<string, string> $capabilities Capability slug => label.
         */
        $filtered = apply_filters('gallop_api_key_capabilities', $capabilities);

        return is_array($filtered) ? $filtered : $capabilities;
    }

    /**
     * A permission callback for a route that needs a key holding $capability.
     *
     * @return callable(WP_REST_Request): (bool|WP_Error)
     */
    public static function requires(string $capability): callable
    {
        return static fn (WP_REST_Request $request): bool|WP_Error => self::authorize($request, $capability);
    }

    /**
     * Whether the request carries one of this site's keys, and that key holds
     * $capability.
     */
    private static function authorize(WP_REST_Request $request, string $capability): bool|WP_Error
    {
        $keyId = self::identify($request);
        if ($keyId instanceof WP_Error) {
            return $keyId;
        }

        if (!self::allows($keyId, $capability)) {
            return new WP_Error(
                'gallop_key_forbidden',
                __('This API key is not allowed to do that.', 'gallop'),
                ['status' => 403]
            );
        }

        self::$announced ??= new WeakMap();
        $announced = self::$announced[$request] ?? [];
        if (!isset($announced[$capability])) {
            $announced[$capability] = true;
            self::$announced[$request] = $announced;

            /**
             * Fires when a request has presented a valid key for a capability it holds.
             *
             * @param string          $keyId      Which key. 'default' for now.
             * @param string          $capability The capability the route required.
             * @param WP_REST_Request $request    The request, with the key removed.
             */
            do_action('gallop_api_key_verified', $keyId, $capability, $request);
        }

        return true;
    }

    /**
     * The id of the key a request presented, worked out once per request.
     *
     * WordPress runs a route's permission callback more than once per request:
     * rest_send_allow_header() calls every handler's callback on the matched route to
     * build the Allow header. Remembering the answer keeps one wrong key from being
     * counted as two, and lets the key be removed from the request the first time
     * it is read.
     */
    private static function identify(WP_REST_Request $request): string|WP_Error
    {
        self::$presented ??= new WeakMap();

        if (!isset(self::$presented[$request])) {
            self::$presented[$request] = self::read($request);
        }

        return self::$presented[$request];
    }

    private static function read(WP_REST_Request $request): string|WP_Error
    {
        $presented = $request->get_header('x_gallop_wp_key');

        // Not counted as a failed attempt. A read on a route that also accepts
        // writes arrives here with no key, through the Allow header described above.
        if (!is_string($presented) || $presented === '') {
            return new WP_Error(
                'gallop_key_missing',
                __('An API key is required.', 'gallop'),
                ['status' => 401]
            );
        }

        // The key has been read. Nothing that runs after this needs it, and
        // anti-spam plugins forward request headers to their own services.
        self::scrub($request);

        if (!self::transportIsSecure()) {
            return new WP_Error(
                'gallop_https_required',
                __('The API key is only accepted over HTTPS.', 'gallop'),
                ['status' => 403]
            );
        }

        return self::match($presented) ?? self::recordFailure($request);
    }

    /**
     * The id of the key that was presented, or null if it is not one of this site's.
     * With no key configured nothing matches.
     */
    public static function match(string $presented): ?string
    {
        if (!self::isWellFormed($presented)) {
            return null;
        }

        $presentedHash = self::hash($presented);

        if (self::constantDefined()) {
            // The constant wins, including when it is unusable. Falling back to the
            // generated key would keep alive a key the owner believes they replaced.
            $constant = self::constantKey();

            return ($constant !== null && hash_equals(self::hash($constant), $presentedHash))
                ? self::DEFAULT_ID
                : null;
        }

        // Every record is compared, so how long this takes does not depend on
        // which one matched.
        $matched = null;
        foreach (self::records() as $record) {
            if (hash_equals($record['hash'], $presentedHash)) {
                $matched = $record['id'];
            }
        }

        return $matched;
    }

    public static function allows(string $keyId, string $capability): bool
    {
        if (!array_key_exists($capability, self::capabilities())) {
            return false;
        }

        return in_array($capability, self::granted($keyId), true);
    }

    /**
     * The capabilities switched on for a key.
     *
     * @return list<string>
     */
    public static function granted(string $keyId): array
    {
        $stored = get_option(self::OPTION_PERMISSIONS, []);
        if (!is_array($stored) || !isset($stored[$keyId]) || !is_array($stored[$keyId])) {
            return [];
        }

        return array_values(array_filter($stored[$keyId], 'is_string'));
    }

    /**
     * Create a key, replacing the site's current one, and return it.
     *
     * This is the only moment the key exists in readable form: what is stored is its
     * hash. The key's capabilities are kept, since they belong to the site's
     * connection rather than to one spelling of its key.
     *
     * @throws \Throwable When no secure random source is available, or the key could not be saved.
     */
    public static function generate(): string
    {
        $secret = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            // random_int() is unbiased, and throws rather than fall back to a
            // weaker source.
            $secret .= self::ALPHABET[random_int(0, $max)];
        }
        $key = self::PREFIX . $secret;

        $record = [
            'id' => self::DEFAULT_ID,
            'hash' => self::hash($key),
            'last4' => substr($key, -4),
            'created' => time(),
        ];

        $records = array_values(array_filter(
            self::records(),
            static fn (array $existing): bool => $existing['id'] !== self::DEFAULT_ID
        ));
        $records[] = $record;

        update_option(self::OPTION_KEYS, $records, false);

        // Read back. Handing the owner a key that was not saved would be worse
        // than failing: they would install it and nothing would accept it.
        $saved = self::record(self::DEFAULT_ID);
        if ($saved === null || !hash_equals($record['hash'], $saved['hash'])) {
            throw new \RuntimeException('The API key could not be saved.');
        }

        /**
         * Fires after a key has been generated. The key itself is not passed.
         *
         * @param string $keyId Which key. 'default' for now.
         */
        do_action('gallop_api_key_generated', self::DEFAULT_ID);

        return $key;
    }

    /**
     * Where the site's key comes from, and enough to recognise it by.
     *
     * @return array{source: 'none'|'generated'|'constant'|'constant_invalid', last4: string, created: int}
     */
    public static function status(): array
    {
        if (self::constantDefined()) {
            $constant = self::constantKey();

            return $constant === null
                ? ['source' => 'constant_invalid', 'last4' => '', 'created' => 0]
                : ['source' => 'constant', 'last4' => substr($constant, -4), 'created' => 0];
        }

        $record = self::record(self::DEFAULT_ID);

        return $record === null
            ? ['source' => 'none', 'last4' => '', 'created' => 0]
            : ['source' => 'generated', 'last4' => $record['last4'], 'created' => $record['created']];
    }

    public static function constantDefined(): bool
    {
        return defined(self::CONSTANT);
    }

    /**
     * Remove the key from everything code running later in the request can read.
     */
    public static function scrub(WP_REST_Request $request): void
    {
        $request->remove_header(self::HEADER);
        unset($_SERVER['HTTP_X_GALLOP_WP_KEY'], $_SERVER['REDIRECT_HTTP_X_GALLOP_WP_KEY']);
    }

    private static function constantKey(): ?string
    {
        $value = constant(self::CONSTANT);

        return self::isWellFormed($value) ? $value : null;
    }

    private static function isWellFormed(mixed $key): bool
    {
        if (!is_string($key) || preg_match(self::FORMAT, $key) !== 1) {
            return false;
        }

        $secret = substr($key, strlen(self::PREFIX));

        return count(array_unique(str_split($secret))) >= self::MIN_DISTINCT;
    }

    /**
     * A plain hash, not a password hash. A password hash is slow on purpose, to make
     * guessing a short human-chosen secret expensive; a 256-bit random key cannot be
     * guessed at any speed, and this runs on every request that writes.
     */
    private static function hash(string $key): string
    {
        return 'sha256:' . hash('sha256', $key);
    }

    /**
     * @return list<array{id: string, hash: string, last4: string, created: int}>
     */
    private static function records(): array
    {
        $raw = get_option(self::OPTION_KEYS, []);
        if (!is_array($raw)) {
            return [];
        }

        $records = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !is_string($item['hash'] ?? null)) {
                continue;
            }
            // A record that is not a hash this version wrote is never compared
            // against, so an empty or damaged value cannot match anything.
            if ($item['id'] === '' || preg_match(self::HASH_FORMAT, $item['hash']) !== 1) {
                continue;
            }
            $records[] = [
                'id' => $item['id'],
                'hash' => $item['hash'],
                'last4' => is_string($item['last4'] ?? null) ? substr($item['last4'], -4) : '',
                'created' => (int) ($item['created'] ?? 0),
            ];
        }

        return $records;
    }

    /**
     * @return array{id: string, hash: string, last4: string, created: int}|null
     */
    private static function record(string $id): ?array
    {
        foreach (self::records() as $record) {
            if ($record['id'] === $id) {
                return $record;
            }
        }

        return null;
    }

    private static function transportIsSecure(): bool
    {
        // The rule core applies to Application Passwords.
        $secure = is_ssl() || wp_get_environment_type() === 'local';

        /**
         * Filter whether this request arrived over a connection safe to carry the key.
         *
         * For a site behind a proxy that ends TLS and does not tell WordPress so.
         * Fixing is_ssl() in wp-config.php is the better answer, since core's own
         * Application Passwords need it too.
         *
         * @param bool $secure Whether the connection is considered secure.
         */
        return (bool) apply_filters('gallop_api_key_transport_secure', $secure);
    }

    /**
     * Count a wrong key against the address it came from.
     *
     * Only wrong keys are limited; a correct key is never refused. A front end whose
     * host shares outgoing addresses between customers could otherwise be cut off by
     * a stranger sending wrong keys from the same address. What protects the key
     * against guessing is its length, not this limit.
     */
    private static function recordFailure(WP_REST_Request $request): WP_Error
    {
        $transient = self::FAIL_TRANSIENT . md5(ClientIp::resolve());
        $attempts = (int) get_transient($transient);

        /**
         * Filter how many wrong keys one address may present in fifteen minutes.
         *
         * @param int $max Default 10.
         */
        $max = max(1, (int) apply_filters('gallop_api_key_max_failed_attempts', self::FAIL_MAX));

        /**
         * Fires when a request presented a key that is not this site's.
         *
         * @param WP_REST_Request $request The request, with the key removed.
         */
        do_action('gallop_api_key_failed', $request);

        if ($attempts >= $max) {
            return new WP_Error(
                'gallop_key_rate_limited',
                __('Too many invalid API keys. Please try again later.', 'gallop'),
                ['status' => 429]
            );
        }

        set_transient($transient, $attempts + 1, self::FAIL_WINDOW);

        return new WP_Error(
            'gallop_key_invalid',
            __('Invalid API key.', 'gallop'),
            ['status' => 401]
        );
    }
}
