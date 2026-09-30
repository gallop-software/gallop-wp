<?php

declare(strict_types=1);

namespace Gallop\Members;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Comment;
use WP_User;

/**
 * Tells a member when someone answers their comment.
 *
 * WordPress emails the post's author about new comments and nobody else. A member
 * who asked for it is told once, when the answer becomes visible: at once if it was
 * approved as it was posted, or when a moderator approves it.
 */
final class ReplyNotifier
{
    /** Comment meta marking a reply already told about, so nobody is told twice. */
    private const META_NOTIFIED = '_gallop_reply_notified';

    public function register(): void
    {
        add_action('comment_post', [$this, 'onPosted'], 10, 2);
        add_action('transition_comment_status', [$this, 'onStatusChange'], 10, 3);
    }

    /**
     * @param int|string $approved 1 when the comment went live as it was posted.
     */
    public function onPosted(int $id, int|string $approved): void
    {
        if ((string) $approved === '1') {
            $this->notify(get_comment($id));
        }
    }

    public function onStatusChange(string $new, string $old, WP_Comment $comment): void
    {
        if ($new === 'approved' && $old !== 'approved') {
            $this->notify($comment);
        }
    }

    private function notify(?WP_Comment $reply): void
    {
        if (!$reply instanceof WP_Comment || (int) $reply->comment_parent <= 0) {
            return;
        }
        if ($reply->comment_type !== 'comment' && $reply->comment_type !== '') {
            return;
        }

        $parent = get_comment((int) $reply->comment_parent);
        if (!$parent instanceof WP_Comment || (int) $parent->user_id <= 0) {
            return;
        }

        // Answering yourself is not news.
        if ((int) $parent->user_id === (int) $reply->user_id) {
            return;
        }

        $member = get_user_by('id', (int) $parent->user_id);
        if (!$member instanceof WP_User || !Member::wantsReplyEmails($member)) {
            return;
        }
        if ($member->user_email === '' || strcasecmp($member->user_email, (string) $reply->comment_author_email) === 0) {
            return;
        }

        if (get_comment_meta((int) $reply->comment_ID, self::META_NOTIFIED, true) === '1') {
            return;
        }
        update_comment_meta((int) $reply->comment_ID, self::META_NOTIFIED, '1');

        Mailer::reply($member, $reply, $parent);
    }
}
