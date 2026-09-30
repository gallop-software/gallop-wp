<?php

declare(strict_types=1);

namespace Gallop\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The "Members" part of the settings screen. There is nothing to set: it says
 * what the front end may do with accounts once the permission is ticked.
 */
final class MembersSection
{
    private const SECTION = 'gallop_members_section';

    public function register(string $page): void
    {
        add_settings_section(
            self::SECTION,
            __('Members', 'gallop'),
            [$this, 'renderSection'],
            $page,
        );
    }

    public function renderSection(): void
    {
        $allowed = ['strong' => [], 'code' => []];

        echo '<p>' . esc_html__('With the "Manage members" permission ticked above, your front end can let readers log in with their WordPress account, sign up, reset a password, and edit their profile. Accounts are ordinary WordPress users with the Subscriber role. Nothing is created until the reader opens the confirmation email.', 'gallop') . '</p>';
        echo '<p>' . wp_kses(__('The confirmation and reset emails are sent by WordPress and link to your front end, so the <strong>Next.js Production URL</strong> above must be set. Logging in through the front end checks the password with <code>wp_authenticate()</code> and sets no WordPress cookies: the front end keeps its own session, and a two-factor plugin on the WordPress login screen does not apply to it.', 'gallop'), $allowed) . '</p>';
        echo '<p>' . esc_html__('Accounts with the Subscriber role that have never chosen count as subscribed to new posts by email; a member\'s own choice on their profile always wins. Deleting the plugin removes what it recorded about members and leaves accounts and comments alone.', 'gallop') . '</p>';
    }
}
