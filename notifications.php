<?php
declare(strict_types=1);

require_once __DIR__ . '/partials.php';

$viewer = require_auth();

$stmt = db()->prepare(
    'SELECT
        n.*,
        actor.username AS actor_username,
        COALESCE(ap.display_name, actor.display_name, actor.username) AS actor_name,
        p.content AS post_content
     FROM notifications n
     INNER JOIN users actor ON actor.id = n.actor_id
     LEFT JOIN profiles ap ON ap.user_id = actor.id
     LEFT JOIN posts p ON p.id = n.post_id
     WHERE n.user_id = ?
     ORDER BY n.created_at DESC
     LIMIT 100'
);
$stmt->execute([(int) $viewer['id']]);
$notifications = $stmt->fetchAll();

$labels = [
    'like' => 'liked your post',
    'repost' => 'reposted your post',
    'comment' => 'commented on your post',
    'follow' => 'followed you',
    'friend_request' => 'sent you a friend request',
    'message' => 'sent you a message',
];

render_head('Notifications');
render_shell_start($viewer, 'notifications');
?>
<header class="page-header">
  <div>
    <h1>Notifications</h1>
    <p>Alerts generated from likes, reposts, comments, follows, friend requests, and messages.</p>
  </div>
  <form action="actions.php" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mark_notifications_read">
    <button class="outline-button" type="submit">Mark read</button>
  </form>
</header>
<section class="feed">
  <?php if (!$notifications): ?>
    <div class="empty-state">
      <h2>No notifications</h2>
      <p>Activity from other users will appear here.</p>
    </div>
  <?php endif; ?>
  <?php foreach ($notifications as $notification): ?>
    <article class="notification-row <?= (int) $notification['is_read'] === 0 ? 'unread' : '' ?>">
      <div>
        <strong><?= h($notification['actor_name']) ?></strong>
        <span>@<?= h($notification['actor_username']) ?> <?= h($labels[$notification['type']] ?? $notification['type']) ?> · <?= h(time_ago($notification['created_at'])) ?></span>
      </div>
      <?php if (!empty($notification['post_id'])): ?>
        <a href="post.php?id=<?= (int) $notification['post_id'] ?>"><?= h(mb_strimwidth((string) $notification['post_content'], 0, 120, '...')) ?></a>
      <?php elseif ($notification['type'] === 'message'): ?>
        <a href="messages.php">Open messages</a>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>
<?php render_shell_end($viewer); ?>
