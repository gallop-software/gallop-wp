<?php

declare(strict_types=1);

namespace Gallop\Admin;

final class Settings
{
    public const OPTION_NEXTJS_URL = 'gallop_nextjs_production_url';
    public const OPTION_TRUST_FORWARDED_IP = 'gallop_trust_forwarded_ip';

    private const GROUP = 'gallop_settings';
    private const SECTION = 'gallop_settings_section';
    public const PAGE = 'gallop';

    public function register(): void
    {
        register_setting(self::GROUP, self::OPTION_NEXTJS_URL, [
            'type' => 'string',
            'description' => 'Next.js production URL to redirect public front-end requests to.',
            'sanitize_callback' => [self::class, 'sanitize'],
            'show_in_rest' => true,
            'default' => '',
        ]);

        register_setting(self::GROUP, self::OPTION_TRUST_FORWARDED_IP, [
            'type' => 'boolean',
            'description' => 'Trust client IP from reverse-proxy headers (X-Forwarded-For, CF-Connecting-IP) when rate-limiting REST auth.',
            'sanitize_callback' => [self::class, 'sanitizeBool'],
            'show_in_rest' => true,
            'default' => false,
        ]);

        add_settings_section(
            self::SECTION,
            __('Settings', 'gallop'),
            static function (): void {
                echo '<p>' . esc_html__('When set, public front-end requests are 301-redirected to this URL with the same path. Leave blank to keep the default WordPress front end.', 'gallop') . '</p>';
            },
            self::PAGE,
        );

        add_settings_field(
            self::OPTION_NEXTJS_URL,
            __('Next.js Production URL', 'gallop'),
            [self::class, 'renderField'],
            self::PAGE,
            self::SECTION,
            ['label_for' => 'gallop-nextjs-url'],
        );

        add_settings_field(
            self::OPTION_TRUST_FORWARDED_IP,
            __('Trust proxy IP headers', 'gallop'),
            [self::class, 'renderTrustForwardedField'],
            self::PAGE,
            self::SECTION,
            ['label_for' => 'gallop-trust-forwarded-ip'],
        );
    }

    public static function sanitizeBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function sanitize(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        return esc_url_raw(untrailingslashit($value));
    }

    public static function renderField(): void
    {
        $value = (string)get_option(self::OPTION_NEXTJS_URL, '');
        echo '<input name="' . esc_attr(self::OPTION_NEXTJS_URL) . '" id="gallop-nextjs-url" type="url" class="regular-text" value="' . esc_attr($value) . '" placeholder="https://example.com">';
    }

    public static function renderTrustForwardedField(): void
    {
        $value = (bool) get_option(self::OPTION_TRUST_FORWARDED_IP, false);
        // Hidden 0 ensures the option is sent (and can be unchecked) even when the
        // checkbox is unticked — the Settings API skips fields missing from $_POST.
        echo '<input type="hidden" name="' . esc_attr(self::OPTION_TRUST_FORWARDED_IP) . '" value="0">';
        echo '<label><input name="' . esc_attr(self::OPTION_TRUST_FORWARDED_IP) . '" id="gallop-trust-forwarded-ip" type="checkbox" value="1"' . checked($value, true, false) . '> ';
        echo esc_html__('Enable only if this site sits behind a trusted reverse proxy (Cloudflare, a load balancer, etc.) that overwrites these headers. Leaving it off is safer on direct-served sites; turning it on without a trusted proxy lets attackers bypass login rate limits by spoofing the header.', 'gallop');
        echo '</label>';
    }

    public function renderForm(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
        settings_fields(self::GROUP);
        do_settings_sections(self::PAGE);
        submit_button(__('Save settings', 'gallop'));
        echo '</form>';
    }
}
