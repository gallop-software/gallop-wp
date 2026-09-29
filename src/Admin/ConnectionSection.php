<?php

declare(strict_types=1);

namespace Gallop\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Gallop\Auth\ApiKey;

/**
 * The "Front-end connection" part of the settings screen: the API key, and what the
 * key is allowed to do.
 *
 * What the key may do is a setting, saved with the rest of the form. Generating a
 * key is an action, with a handler and nonce of its own, because it has to happen
 * at once and its result is shown a single time.
 */
final class ConnectionSection
{
    private const SECTION = 'gallop_connection_section';
    private const PAGE = 'gallop';
    private const NONCE = 'gallop_api_key';
    private const GENERATE = 'gallop_generate_api_key';
    private const FORM_ID = 'gallop-api-key-form';

    /**
     * Holds a new key just long enough to show it after the redirect that follows
     * generating it, and only to the user who generated it.
     */
    private const REVEAL = 'gallop_key_reveal_';
    private const REVEAL_TTL = 2 * MINUTE_IN_SECONDS;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private static function notices(): array
    {
        return [
            'key_generated' => ['success', __('API key generated. Copy it now: it will not be shown again.', 'gallop')],
            'key_failed' => ['error', __('The API key could not be generated. Nothing was changed.', 'gallop')],
            'key_constant' => ['error', __('The API key is set in wp-config.php, so a generated key would not be used. Remove the constant first.', 'gallop')],
        ];
    }

    public function register(string $group, string $page): void
    {
        register_setting($group, ApiKey::OPTION_PERMISSIONS, [
            'type' => 'array',
            'description' => 'What the front-end API key is allowed to do.',
            'sanitize_callback' => [self::class, 'sanitizePermissions'],
            'show_in_rest' => false,
            'default' => [],
        ]);

        add_settings_section(
            self::SECTION,
            __('Front-end connection', 'gallop'),
            [$this, 'renderSection'],
            $page,
        );

        add_settings_field(
            'gallop_api_key',
            __('API key', 'gallop'),
            [$this, 'renderKeyField'],
            $page,
            self::SECTION,
        );

        add_settings_field(
            ApiKey::OPTION_PERMISSIONS,
            __('Permissions', 'gallop'),
            [$this, 'renderPermissionsField'],
            $page,
            self::SECTION,
        );
    }

    public function registerHandlers(): void
    {
        add_action('admin_post_' . self::GENERATE, [$this, 'handleGenerate']);
        add_action('admin_notices', [$this, 'renderConstantNotice']);
    }

    public function handleGenerate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'gallop'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);

        if (ApiKey::constantDefined()) {
            $this->redirect('key_constant');
        }

        try {
            $key = ApiKey::generate();
        } catch (\Throwable $e) {
            $this->redirect('key_failed');
        }

        set_transient(self::REVEAL . get_current_user_id(), $key, self::REVEAL_TTL);

        $this->redirect('key_generated');
    }

    /**
     * Keeps what is already granted to a key the form did not mention, so that
     * saving the form for one key never takes another's permissions away.
     *
     * @return array<string, list<string>>
     */
    public static function sanitizePermissions(mixed $value): array
    {
        $known = array_keys(ApiKey::capabilities());

        $stored = get_option(ApiKey::OPTION_PERMISSIONS, []);
        $out = [];
        if (is_array($stored)) {
            foreach ($stored as $keyId => $capabilities) {
                $keyId = sanitize_key((string) $keyId);
                if ($keyId !== '' && is_array($capabilities)) {
                    $out[$keyId] = array_values(array_unique(array_intersect($known, array_map('strval', array_filter($capabilities, 'is_scalar')))));
                }
            }
        }

        if (!is_array($value)) {
            return $out;
        }

        foreach ($value as $keyId => $capabilities) {
            $keyId = sanitize_key((string) $keyId);
            if ($keyId === '' || !is_array($capabilities)) {
                continue;
            }
            $capabilities = array_map('strval', array_filter($capabilities, 'is_scalar'));
            // Only capabilities that exist. Anything else in the request is dropped
            // rather than stored for a later version to find switched on.
            $out[$keyId] = array_values(array_unique(array_intersect($known, $capabilities)));
        }

        return $out;
    }

    public function renderSection(): void
    {
        $this->renderNotice();

        echo '<p>' . esc_html__('The API key proves that a request comes from your front-end site\'s own server. Gallop asks for it before anything is written to WordPress, such as a visitor\'s comment. Reading content does not need a key.', 'gallop') . '</p>';
    }

    public function renderKeyField(): void
    {
        $status = ApiKey::status();

        $this->renderReveal();

        echo '<p class="gallop-key-status">' . wp_kses($this->statusLine($status), ['code' => []]) . '</p>';

        if ($status['source'] === 'none' || $status['source'] === 'generated') {
            $this->renderButton($status['source'] === 'generated');
        }

        $this->renderNotes();
    }

    public function renderPermissionsField(): void
    {
        $name = ApiKey::OPTION_PERMISSIONS . '[' . ApiKey::DEFAULT_ID . '][]';
        $granted = ApiKey::granted(ApiKey::DEFAULT_ID);

        echo '<fieldset>';
        echo '<legend class="screen-reader-text"><span>' . esc_html__('Permissions', 'gallop') . '</span></legend>';

        // An empty value is always sent, so that unticking the last box is saved.
        // The Settings API leaves alone an option that is missing from the form.
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="">';

        foreach (ApiKey::capabilities() as $capability => $label) {
            $id = 'gallop-api-key-can-' . sanitize_html_class((string) $capability);
            echo '<label for="' . esc_attr($id) . '">';
            echo '<input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $capability) . '"' . checked(in_array($capability, $granted, true), true, false) . '> ';
            echo esc_html((string) $label);
            echo '</label><br>';
        }

        echo '<p class="description">' . esc_html__('The key can do only what is ticked here. WordPress\'s Discussion settings still decide whether a submitted comment is published or held for moderation.', 'gallop') . '</p>';
        echo '</fieldset>';
    }

    /**
     * The form the Generate button submits.
     *
     * The button sits inside the settings form, where the key's status is, and a
     * form cannot contain another. So this one is printed after the settings form
     * has closed, empty, and the button names it with its `form` attribute.
     */
    public function renderActionForm(): void
    {
        echo '<form id="' . esc_attr(self::FORM_ID) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::GENERATE) . '">';
        wp_nonce_field(self::NONCE);
        echo '</form>';
    }

    /**
     * Shown on every admin screen, because the effect is not confined to this one:
     * while the constant is wrong, nothing the front end submits is accepted.
     */
    public function renderConstantNotice(): void
    {
        if (!current_user_can('manage_options') || ApiKey::status()['source'] !== 'constant_invalid') {
            return;
        }

        $message = __('<strong>Gallop:</strong> <code>GALLOP_WP_API_KEY</code> is defined in wp-config.php but is not a valid key, so requests that need a key are refused. It must start with <code>gallopwp_</code> followed by at least 32 random letters and digits.', 'gallop');

        echo '<div class="notice notice-error"><p>' . wp_kses($message, ['strong' => [], 'code' => []]) . '</p></div>';
    }

    /**
     * @param array{source: string, last4: string, created: int} $status
     */
    private function statusLine(array $status): string
    {
        $last4 = '<code>' . esc_html($status['last4']) . '</code>';

        return match ($status['source']) {
            'generated' => sprintf(
                /* translators: 1: date the key was generated, 2: last four characters of the key */
                __('Generated on %1$s, ending in %2$s.', 'gallop'),
                esc_html(wp_date((string) get_option('date_format'), $status['created']) ?: ''),
                $last4
            ),
            'constant' => sprintf(
                /* translators: %s: last four characters of the key */
                __('Set in wp-config.php, ending in %s. A generated key is ignored while the constant is defined.', 'gallop'),
                $last4
            ),
            'constant_invalid' => __('<code>GALLOP_WP_API_KEY</code> is defined in wp-config.php but is not a valid key, so every request that needs a key is refused.', 'gallop'),
            default => esc_html__('No key yet. Your front end cannot submit anything until you generate one.', 'gallop'),
        };
    }

    private function renderButton(bool $replacing): void
    {
        $label = $replacing ? __('Regenerate key', 'gallop') : __('Generate key', 'gallop');
        $class = $replacing ? 'button' : 'button button-primary';

        echo '<p><button type="submit" form="' . esc_attr(self::FORM_ID) . '" class="' . esc_attr($class) . '"';

        if ($replacing) {
            $confirm = __('Regenerate the API key? The current key stops working immediately, and your front end cannot submit anything until the new key is installed.', 'gallop');
            echo ' onclick="return confirm(' . esc_attr((string) wp_json_encode($confirm)) . ');"';
        }

        echo '>' . esc_html($label) . '</button></p>';
    }

    /**
     * The key itself, once. It is removed from where it was held before it is
     * printed, so reloading the page does not show it again.
     */
    private function renderReveal(): void
    {
        $name = self::REVEAL . get_current_user_id();
        $key = get_transient($name);
        if (!is_string($key) || $key === '') {
            return;
        }
        delete_transient($name);

        echo '<div class="gallop-key-reveal">';
        echo '<label for="gallop-new-key"><strong>' . esc_html__('Your new API key', 'gallop') . '</strong></label>';
        echo '<p class="gallop-key-reveal__row">';
        echo '<input type="text" readonly id="gallop-new-key" class="regular-text code" value="' . esc_attr($key) . '" autocomplete="off" spellcheck="false" onfocus="this.select()">';
        echo ' <button type="button" class="button" data-gallop-copy="gallop-new-key" data-gallop-copied="' . esc_attr__('Copied', 'gallop') . '">' . esc_html__('Copy', 'gallop') . '</button>';
        echo '</p>';
        echo '<p class="description">' . esc_html__('This is the only time the key is shown. Copy it before you leave or reload this page.', 'gallop') . '</p>';
        echo '</div>';
    }

    private function renderNotes(): void
    {
        $allowed = ['strong' => [], 'code' => []];

        $notes = [
            __('<strong>Shown once.</strong> The key appears only right after you generate it. Gallop stores a hash, not the key, so it cannot be shown again. If you lose it, regenerate.', 'gallop'),
            __('<strong>Where it goes.</strong> Add it to your site\'s <code>.env.production</code> as <code>GALLOP_WP_API_KEY=…</code>, then run <code>npm run cf:secrets</code> to send it to Cloudflare. Other hosts have their own secret store; use that.', 'gallop'),
            __('<strong>Keep it on the server.</strong> Never name the variable with a <code>NEXT_PUBLIC_</code> prefix and never put the key in your code. Either one publishes it.', 'gallop'),
            __('<strong>Regenerating.</strong> The old key stops working immediately. Your front end cannot submit anything until the new key is installed.', 'gallop'),
            __('<strong>Using wp-config.php instead.</strong> Add <code>define( \'GALLOP_WP_API_KEY\', \'gallopwp_…\' );</code> above the "stop editing" line. A key set there takes priority over a generated one. It must start with <code>gallopwp_</code> followed by at least 32 random letters and digits.', 'gallop'),
            __('<strong>Permissions.</strong> The key can do only what is ticked below. New permissions are always off until you turn them on.', 'gallop'),
        ];

        echo '<ul class="gallop-key-notes">';
        foreach ($notes as $note) {
            echo '<li>' . wp_kses($note, $allowed) . '</li>';
        }
        echo '</ul>';
    }

    private function renderNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only post-redirect notice, no state change.
        $msg = isset($_GET['gallop_msg']) ? sanitize_key(wp_unslash((string) $_GET['gallop_msg'])) : '';
        $notices = self::notices();
        if (!isset($notices[$msg])) {
            return;
        }
        [$kind, $text] = $notices[$msg];

        // `inline` keeps WordPress from moving the notice to the top of the screen,
        // away from the key it is about.
        echo '<div class="notice inline notice-' . esc_attr($kind) . '"><p>' . esc_html($text) . '</p></div>';
    }

    private function redirect(string $msg): never
    {
        wp_safe_redirect(add_query_arg(
            ['gallop_msg' => $msg, 'tab' => 'settings'],
            admin_url('admin.php?page=' . self::PAGE)
        ));
        exit;
    }
}
