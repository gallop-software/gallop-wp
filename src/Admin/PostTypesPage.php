<?php

declare(strict_types=1);

namespace Gallop\Admin;

use Gallop\PostTypes\Definition;
use Gallop\PostTypes\Storage;

final class PostTypesPage
{
    private const PAGE = 'gallop';
    private const NONCE = 'gallop_post_types';
    private const SAVE = 'gallop_save_post_type';
    private const DELETE = 'gallop_delete_post_type';

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private static function notices(): array
    {
        return [
            'saved'     => ['success', __('Post type saved.', 'gallop')],
            'deleted'   => ['success', __('Post type deleted.', 'gallop')],
            'invalid'   => ['error',   __('Name is required.', 'gallop')],
            /* translators: %s: post type slug */
            'duplicate' => ['error',   __('A post type with slug <code>%s</code> already exists. Delete it below first, or choose a different name.', 'gallop')],
            /* translators: %s: post type slug */
            'reserved'  => ['error',   __('The slug <code>%s</code> is already used by WordPress or another plugin. Choose a different name.', 'gallop')],
        ];
    }

    public function __construct(
        private readonly Storage $storage,
        private readonly Settings $settings,
    ) {
    }

    public function registerHandlers(): void
    {
        add_action('admin_post_' . self::SAVE, [$this, 'handleSave']);
        add_action('admin_post_' . self::DELETE, [$this, 'handleDelete']);
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer(self::NONCE);

        $plural = isset($_POST['plural']) ? sanitize_text_field(wp_unslash((string) $_POST['plural'])) : '';
        $singular = isset($_POST['singular']) ? sanitize_text_field(wp_unslash((string) $_POST['singular'])) : '';
        $slug = sanitize_title($plural);
        $def = new Definition($slug, $singular, $plural);

        if (!$def->isValid()) {
            $this->redirect('invalid');
        }
        if ($this->storage->find($def->slug) !== null) {
            $this->redirect('duplicate', $def->slug);
        }
        if (self::slugIsReserved($def->slug)) {
            $this->redirect('reserved', $def->slug);
        }

        $this->storage->save($def);
        $this->redirect('saved');
    }

    public function handleDelete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer(self::NONCE);

        $slug = isset($_POST['slug']) ? sanitize_key(wp_unslash((string) $_POST['slug'])) : '';
        if ($slug !== '') {
            $this->storage->delete($slug);
        }
        $this->redirect('deleted');
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector, no state change.
        $rawTab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'settings';
        $tab = in_array($rawTab, ['settings', 'post-types'], true) ? $rawTab : 'settings';

        echo '<div class="wrap"><h1>Gallop</h1>';
        settings_errors();
        $this->renderTabs($tab);
        $this->renderStyles();

        if ($tab === 'settings') {
            $this->settings->renderForm();
        } else {
            $this->renderNotice();
            $this->renderList($this->storage->all());
            $this->renderForm();
        }
        echo '</div>';
    }

    private function renderTabs(string $active): void
    {
        $tabs = [
            'settings'   => __('Settings', 'gallop'),
            'post-types' => __('Post Types', 'gallop'),
        ];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(['page' => self::PAGE, 'tab' => $slug], admin_url('admin.php'));
            $class = 'nav-tab' . ($active === $slug ? ' nav-tab-active' : '');
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';
    }

    private function redirect(string $msg, string $slug = ''): never
    {
        $args = ['gallop_msg' => $msg];
        if ($slug !== '') {
            $args['slug'] = $slug;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::PAGE)));
        exit;
    }

    private function renderNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only post-redirect notice, no state change.
        $msg = isset($_GET['gallop_msg']) ? sanitize_key(wp_unslash((string) $_GET['gallop_msg'])) : '';
        $notices = self::notices();
        if (!isset($notices[$msg])) {
            return;
        }
        [$kind, $template] = $notices[$msg];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only post-redirect notice.
        $slug = isset($_GET['slug']) ? sanitize_key(wp_unslash((string) $_GET['slug'])) : '';
        $body = $slug !== '' ? sprintf($template, esc_html($slug)) : $template;
        $allowed = ['code' => []];
        echo '<div class="notice notice-' . esc_attr($kind) . ' is-dismissible"><p>' . wp_kses($body, $allowed) . '</p></div>';
    }

    private function renderStyles(): void
    {
        echo '<style>
            .gallop-delete-btn { background: #fbeaea; border: 0; color: #b32d2e; padding: 0 10px; border-radius: 3px; cursor: pointer; font: inherit; font-weight: 500; transition: background .15s, color .15s; }
            .gallop-delete-btn:hover, .gallop-delete-btn:focus { background: #b32d2e; color: #fff; outline: none; }
            .gallop-actions-col { width: 90px; text-align: left; }
        </style>';
    }

    /** @param list<Definition> $defs */
    private function renderList(array $defs): void
    {
        echo '<h2>' . esc_html__('Registered post types', 'gallop') . '</h2>';
        if ($defs === []) {
            echo '<p><em>' . esc_html__('No post types yet. Add one below.', 'gallop') . '</em></p>';
            return;
        }

        $postAction = admin_url('admin-post.php');

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Slug', 'gallop') . '</th>';
        echo '<th>' . esc_html__('Singular', 'gallop') . '</th>';
        echo '<th>' . esc_html__('Plural', 'gallop') . '</th>';
        echo '<th>' . esc_html__('REST endpoint', 'gallop') . '</th>';
        echo '<th class="gallop-actions-col">' . esc_html__('Actions', 'gallop') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($defs as $d) {
            $restUrl = rest_url('wp/v2/' . $d->slug);
            $confirm = sprintf(
                /* translators: %s: plural label of the post type being deleted */
                __('Delete the "%s" post type? Existing posts remain in the database but become inaccessible until you re-add the post type.', 'gallop'),
                $d->plural
            );

            echo '<tr>';
            echo '<td><code>' . esc_html($d->slug) . '</code></td>';
            echo '<td>' . esc_html($d->singular) . '</td>';
            echo '<td>' . esc_html($d->plural) . '</td>';
            echo '<td><a href="' . esc_url($restUrl) . '" target="_blank" rel="noopener noreferrer"><code>/wp-json/wp/v2/' . esc_html($d->slug) . '</code></a></td>';
            echo '<td class="gallop-actions-col">';
            echo '<form method="post" action="' . esc_url($postAction) . '" style="display:inline" onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::DELETE) . '">';
            echo '<input type="hidden" name="slug" value="' . esc_attr($d->slug) . '">';
            wp_nonce_field(self::NONCE);
            echo '<button type="submit" class="gallop-delete-btn" aria-label="' . esc_attr(sprintf(/* translators: %s: post type plural label */ __('Delete %s', 'gallop'), $d->plural)) . '">' . esc_html__('Delete', 'gallop') . '</button>';
            echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderForm(): void
    {
        $postAction = admin_url('admin-post.php');

        echo '<h2 style="margin-top:2em">' . esc_html__('Add a post type', 'gallop') . '</h2>';
        echo '<form method="post" action="' . esc_url($postAction) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE) . '">';
        wp_nonce_field(self::NONCE);
        echo '<table class="form-table"><tbody>';
        echo '<tr>';
        echo '<th><label for="gallop-plural">' . esc_html__('Plural name', 'gallop') . '</label></th>';
        echo '<td><input name="plural" id="gallop-plural" type="text" class="regular-text" required placeholder="' . esc_attr__('Books', 'gallop') . '">';
        echo ' <p class="description">' . esc_html__('Used for the admin menu and the REST endpoint slug (lowercased and hyphenated).', 'gallop') . '</p>';
        echo '</td></tr>';
        echo '<tr>';
        echo '<th><label for="gallop-singular">' . esc_html__('Singular name', 'gallop') . '</label></th>';
        echo '<td><input name="singular" id="gallop-singular" type="text" class="regular-text" required placeholder="' . esc_attr__('Book', 'gallop') . '">';
        echo ' <p class="description">' . esc_html__('Used in admin labels for a single item (e.g. "Add New Book").', 'gallop') . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Add post type', 'gallop'));
        echo '</form>';
    }

    private static function slugIsReserved(string $slug): bool
    {
        return post_type_exists($slug);
    }
}
