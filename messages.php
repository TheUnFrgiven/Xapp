<?php
declare(strict_types=1);

require_once __DIR__ . '/partials.php';

$viewer = require_auth();
$users = fetch_users_for_message((int) $viewer['id']);

$stmt = db()->prepare(
    'SELECT
        m.*,
        sender.username AS sender_username,
        receiver.username AS receiver_username,
        COALESCE(sp.display_name, sender.display_name, sender.username) AS sender_name,
        COALESCE(rp.display_name, receiver.display_name, receiver.username) AS receiver_name
     FROM messages m
     INNER JOIN users sender ON sender.id = m.sender_id
     INNER JOIN users receiver ON receiver.id = m.receiver_id
     LEFT JOIN profiles sp ON sp.user_id = sender.id
     LEFT JOIN profiles rp ON rp.user_id = receiver.id
     WHERE m.sender_id = ? OR m.receiver_id = ?
     ORDER BY m.created_at DESC
     LIMIT 80'
);
$stmt->execute([(int) $viewer['id'], (int) $viewer['id']]);
$messages = $stmt->fetchAll();

render_head('Messages');
render_shell_start($viewer, 'messages');
?>
<header class="page-header">
  <div>
    <h1>Messages</h1>
    <p>Send and read direct messages.</p>
  </div>
</header>
<form class="settings-form" action="actions.php" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="send_message">
  <label>
    Send to
    <select name="receiver_id" required>
      <option value="">Choose a user</option>
      <?php foreach ($users as $user): ?>
        <option value="<?= (int) $user['id'] ?>"><?= h($user['display_name']) ?> (@<?= h($user['username']) ?>)</option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    Message
    <textarea name="content" maxlength="1000" rows="4" required></textarea>
  </label>
  <button type="submit">Send message</button>
</form>
<section class="feed">
  <?php if (!$messages): ?>
    <div class="empty-state">
      <h2>No messages yet</h2>
      <p>Send a message to another user account.</p>
    </div>
  <?php endif; ?>
  <?php foreach ($messages as $message): ?>
    <article class="message-row <?= (int) $message['sender_id'] === (int) $viewer['id'] ? 'sent' : 'received' ?>">
      <div>
        <strong><?= (int) $message['sender_id'] === (int) $viewer['id'] ? 'You' : h($message['sender_name']) ?></strong>
        <span>to <?= (int) $message['receiver_id'] === (int) $viewer['id'] ? 'you' : h($message['receiver_name']) ?> · <?= h(time_ago($message['created_at'])) ?></span>
      </div>
      <p><?= nl2br(h($message['content'])) ?></p>
    </article>
  <?php endforeach; ?>
</section>
<?php render_shell_end($viewer); ?>
