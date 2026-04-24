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

    private const NOTICES = [
        'saved'     => ['success', 'Post type saved.'],
        'deleted'   => ['success', 'Post type deleted.'],
        'invalid'   => ['error',   'Name is required.'],
        'duplicate' => ['error',   'A post type with slug <code>%s</code> already exists. Delete it below first, or choose a different name.'],
        'reserved'  => ['error',   'The slug <code>%s</code> is already used by WordPress or another plugin. Choose a different name.'],
    ];

    public function __construct(private readonly Storage $storage)
    {
    }

    public function registerHandlers(): void
    {
        add_action('admin_post_' . self::SAVE, [$this, 'handleSave']);
        add_action('admin_post_' . self::DELETE, [$this, 'handleDelete']);
    }

    public function handleSave(): void
    {
        $this->assertAllowed(self::NONCE);

        $slug = sanitize_title((string)($_POST['rest_base'] ?? ''));
        [$singular, $plural] = self::deriveLabels($slug);
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
        $this->assertAllowed(self::NONCE);

        $slug = sanitize_key((string)($_POST['slug'] ?? ''));
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
        $defs = $this->storage->all();
        $postAction = esc_url(admin_url('admin-post.php'));
        $nonce = wp_nonce_field(self::NONCE, '_wpnonce', true, false);

        echo '<div class="wrap"><h1>Gallop — Post Types</h1>';
        $this->renderNotice();
        $this->renderStyles();
        $this->renderList($defs, $postAction, $nonce);
        $this->renderForm($postAction, $nonce);
        echo '</div>';
    }

    private function assertAllowed(string $nonceAction): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer($nonceAction);
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
        $msg = (string)($_GET['gallop_msg'] ?? '');
        if (!isset(self::NOTICES[$msg])) {
            return;
        }
        [$kind, $template] = self::NOTICES[$msg];
        $slug = sanitize_key((string)($_GET['slug'] ?? ''));
        $body = $slug !== '' ? sprintf($template, esc_html($slug)) : $template;
        echo '<div class="notice notice-' . $kind . ' is-dismissible"><p>' . $body . '</p></div>';
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
    private function renderList(array $defs, string $postAction, string $nonce): void
    {
        echo '<h2>Registered post types</h2>';
        if ($defs === []) {
            echo '<p><em>No post types yet. Add one below.</em></p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Slug</th><th>Singular</th><th>Plural</th><th>REST endpoint</th>';
        echo '<th class="gallop-actions-col">Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ($defs as $d) {
            $restUrl = esc_url(rest_url('wp/v2/' . $d->slug));
            echo '<tr>';
            echo '<td><code>' . esc_html($d->slug) . '</code></td>';
            echo '<td>' . esc_html($d->singular) . '</td>';
            echo '<td>' . esc_html($d->plural) . '</td>';
            echo '<td><a href="' . $restUrl . '" target="_blank"><code>/wp-json/wp/v2/' . esc_html($d->slug) . '</code></a></td>';
            echo '<td class="gallop-actions-col">';
            $confirm = sprintf(
                'Delete the &quot;%s&quot; post type?\n\nExisting posts remain in the database but become inaccessible until you re-add the post type.',
                esc_js($d->plural)
            );
            echo '<form method="post" action="' . $postAction . '" style="display:inline" onsubmit="return confirm(\'' . $confirm . '\');">';
            echo '<input type="hidden" name="action" value="' . self::DELETE . '">';
            echo '<input type="hidden" name="slug" value="' . esc_attr($d->slug) . '">';
            echo $nonce;
            echo '<button type="submit" class="gallop-delete-btn" aria-label="Delete ' . esc_attr($d->plural) . '">Delete</button>';
            echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderForm(string $postAction, string $nonce): void
    {
        echo '<h2 style="margin-top:2em">Add a post type</h2>';
        echo '<form method="post" action="' . $postAction . '">';
        echo '<input type="hidden" name="action" value="' . self::SAVE . '">';
        echo $nonce;
        echo '<table class="form-table"><tbody><tr>';
        echo '<th><label for="gallop-name">Name (plural)</label></th>';
        echo '<td><input name="rest_base" id="gallop-name" type="text" class="regular-text" required placeholder="Books">';
        echo ' <p class="description">e.g. "Books", "Team Members", "Case Studies". The REST endpoint and singular label are derived automatically.</p>';
        echo '</td></tr></tbody></table>';
        submit_button('Add post type');
        echo '</form>';
    }

    /** @return array{0: string, 1: string} */
    private static function deriveLabels(string $slug): array
    {
        $plural = ucwords(str_replace(['-', '_'], ' ', $slug));
        $singular = self::singularize($plural);
        return [$singular, $plural];
    }

    private static function singularize(string $word): string
    {
        return preg_match('/s$/i', $word) ? (string)preg_replace('/s$/i', '', $word) : $word;
    }

    private static function slugIsReserved(string $slug): bool
    {
        return post_type_exists($slug)
            || post_type_exists(self::singularize($slug))
            || post_type_exists($slug . 's');
    }
}
