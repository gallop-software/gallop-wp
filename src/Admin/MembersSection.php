<?php

declare(strict_types=1);

namespace Gallop\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use Gallop\Members\Member;

/**
 * The "Members" part of the settings screen: what the front end may do with
 * accounts, and the two choices about them that are the owner's to make.
 */
final class MembersSection
{
    public const OPTION_UNINSTALL_MEMBER_DATA = 'gallop_uninstall_member_data';

    private const SECTION = 'gallop_members_section';

    public function register(string $group, string $page): void
    {
        register_setting($group, Member::OPTION_LEGACY_SUBSCRIBED, [
            'type' => 'boolean',
            'description' => 'Whether accounts with the Subscriber role made before Gallop recorded subscriptions count as subscribed to new posts by email.',
            'sanitize_callback' => [Settings::class, 'sanitizeBool'],
            'show_in_rest' => false,
            'default' => true,
        ]);

        register_setting($group, self::OPTION_UNINSTALL_MEMBER_DATA, [
            'type' => 'boolean',
            'description' => 'Whether deleting the plugin also removes the member details it added to users.',
            'sanitize_callback' => [Settings::class, 'sanitizeBool'],
            'show_in_rest' => false,
            'default' => false,
        ]);

        add_settings_section(
            self::SECTION,
            __('Members', 'gallop'),
            [$this, 'renderSection'],
            $page,
        );

        add_settings_field(
            Member::OPTION_LEGACY_SUBSCRIBED,
            __('Existing subscribers', 'gallop'),
            [$this, 'renderLegacyField'],
            $page,
            self::SECTION,
            ['label_for' => 'gallop-members-legacy-subscribed'],
        );

        add_settings_field(
            self::OPTION_UNINSTALL_MEMBER_DATA,
            __('When the plugin is deleted', 'gallop'),
            [$this, 'renderUninstallField'],
            $page,
            self::SECTION,
            ['label_for' => 'gallop-uninstall-member-data'],
        );
    }

    public function renderSection(): void
    {
        $allowed = ['strong' => [], 'code' => []];

        echo '<p>' . esc_html__('With the "Manage members" permission ticked above, your front end can let readers log in with their WordPress account, sign up, reset a password, and edit their profile. Accounts are ordinary WordPress users with the Subscriber role. Nothing is created until the reader opens the confirmation email.', 'gallop') . '</p>';
        echo '<p>' . wp_kses(__('The confirmation and reset emails are sent by WordPress and link to your front end, so the <strong>Next.js Production URL</strong> above must be set. Logging in through the front end checks the password with <code>wp_authenticate()</code> and sets no WordPress cookies: the front end keeps its own session, and a two-factor plugin on the WordPress login screen does not apply to it.', 'gallop'), $allowed) . '</p>';
    }

    public function renderLegacyField(): void
    {
        $value = (bool) get_option(Member::OPTION_LEGACY_SUBSCRIBED, true);
        echo '<input type="hidden" name="' . esc_attr(Member::OPTION_LEGACY_SUBSCRIBED) . '" value="0">';
        echo '<label><input name="' . esc_attr(Member::OPTION_LEGACY_SUBSCRIBED) . '" id="gallop-members-legacy-subscribed" type="checkbox" value="1"' . checked($value, true, false) . '> ';
        echo esc_html__('Treat accounts with the Subscriber role that have never chosen as subscribed to new posts by email. A member\'s own choice, once made, always wins.', 'gallop');
        echo '</label>';
    }

    public function renderUninstallField(): void
    {
        $value = (bool) get_option(self::OPTION_UNINSTALL_MEMBER_DATA, false);
        echo '<input type="hidden" name="' . esc_attr(self::OPTION_UNINSTALL_MEMBER_DATA) . '" value="0">';
        echo '<label><input name="' . esc_attr(self::OPTION_UNINSTALL_MEMBER_DATA) . '" id="gallop-uninstall-member-data" type="checkbox" value="1"' . checked($value, true, false) . '> ';
        echo esc_html__('Also remove what Gallop recorded about members: whether their address was confirmed, their email preferences, and their session version. Accounts and comments are never removed.', 'gallop');
        echo '</label>';
    }
}
