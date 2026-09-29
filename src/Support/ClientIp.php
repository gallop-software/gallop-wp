<?php

declare(strict_types=1);

namespace Gallop\Support;

if (!defined('ABSPATH')) {
    exit;
}

use Gallop\Admin\Settings;

/**
 * The address a request came from, for counting attempts against.
 */
final class ClientIp
{
    public static function resolve(): string
    {
        $trustForwarded = (bool) get_option(Settings::OPTION_TRUST_FORWARDED_IP, false);

        /**
         * Filter whether to trust reverse-proxy IP headers when rate-limiting REST auth.
         *
         * Only enable on sites that sit behind a trusted proxy (Cloudflare, load balancer,
         * etc.) which overwrites these headers; otherwise an attacker can spoof them to
         * bypass per-IP rate limits.
         */
        $trustForwarded = (bool) apply_filters('gallop_trust_forwarded_ip', $trustForwarded);

        $candidates = $trustForwarded
            ? ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR']
            : ['REMOTE_ADDR'];

        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $value = is_string($_SERVER[$key]) ? sanitize_text_field(wp_unslash($_SERVER[$key])) : '';
            $first = trim(explode(',', $value)[0]);
            $ip = filter_var($first, FILTER_VALIDATE_IP);
            if ($ip !== false) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }
}
