<?php

declare(strict_types=1);

namespace Gallop\Admin;

final class Settings
{
    public const OPTION_NEXTJS_URL = 'gallop_nextjs_production_url';

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

        add_settings_section(
            self::SECTION,
            'Settings',
            static function (): void {
                echo '<p>When set, public front-end requests are 301-redirected to this URL with the same path. Leave blank to keep the default WordPress front end.</p>';
            },
            self::PAGE,
        );

        add_settings_field(
            self::OPTION_NEXTJS_URL,
            'Next.js Production URL',
            [self::class, 'renderField'],
            self::PAGE,
            self::SECTION,
            ['label_for' => 'gallop-nextjs-url'],
        );
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

    public function renderForm(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
        settings_fields(self::GROUP);
        do_settings_sections(self::PAGE);
        submit_button('Save settings');
        echo '</form>';
    }
}
