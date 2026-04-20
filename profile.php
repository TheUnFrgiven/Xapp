<?php
declare(strict_types=1);

require_once __DIR__ . '/partials.php';

$viewer = require_auth();
$username = (string) ($_GET['u'] ?? $viewer['username']);
$profile = fetch_user_by_username($username);

if (!$profile) {
    render_head('Profile not found');
    render_shell_start($viewer, 'profile');
    ?>
    <div class="empty-state">
      <h1>Profile not found</h1>
      <p>No user exists with that username.</p>
    </div>
    <?php
    render_shell_end($viewer);
    exit;
}

$stats = user_stats((int) $profile['id']);
$isOwnProfile = (int) $profile['id'] === (int) $viewer['id'];
$followStmt = db()->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?');
$followStmt->execute([(int) $viewer['id'], (int) $profile['id']]);
$isFollowing = (bool) $followStmt->fetch();
$friendStmt = db()->prepare('SELECT status FROM friendships WHERE user_1 = LEAST(?, ?) AND user_2 = GREATEST(?, ?) LIMIT 1');
$friendStmt->execute([(int) $viewer['id'], (int) $profile['id'], (int) $viewer['id'], (int) $profile['id']]);
$friendStatus = $friendStmt->fetchColumn() ?: null;
$posts = fetch_posts((int) $viewer['id'], 'all', (int) $profile['id']);

render_head($profile['display_name']);
render_shell_start($viewer, 'profile');
?>
<section class="profile-cover">
  <div class="profile-banner"></div>
  <div class="profile-main">
    <span class="avatar profile-avatar" style="--avatar: <?= h($profile['avatar_color']) ?>"><?= h(strtoupper(substr($profile['display_name'], 0, 1))) ?></span>
    <div class="profile-actions">
      <?php if ($isOwnProfile): ?>
        <a class="outline-button" href="settings.php">Edit profile</a>
      <?php else: ?>
        <form action="actions.php" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_follow">
          <input type="hidden" name="target_id" value="<?= (int) $profile['id'] ?>">
          <button class="outline-button <?= $isFollowing ? 'active' : '' ?>" type="submit"><?= $isFollowing ? 'Following' : 'Follow' ?></button>
        </form>
        <form action="actions.php" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="request_friend">
          <input type="hidden" name="target_id" value="<?= (int) $profile['id'] ?>">
          <button class="outline-button <?= $friendStatus ? 'active' : '' ?>" type="submit"><?= $friendStatus === 'accepted' ? 'Friend' : ($friendStatus === 'pending' ? 'Requested' : 'Add friend') ?></button>
        </form>
      <?php endif; ?>
    </div>
    <h1><?= h($profile['display_name']) ?></h1>
    <p class="handle">@<?= h($profile['nickname'] ?? $profile['username']) ?></p>
    <?php if (!empty($profile['bio'])): ?>
      <p class="bio"><?= nl2br(h($profile['bio'])) ?></p>
    <?php endif; ?>
    <div class="profile-meta">
      <?php if (!empty($profile['first_name']) || !empty($profile['last_name'])): ?><span><?= h(trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''))) ?></span><?php endif; ?>
      <?php if (!empty($profile['gender'])): ?><span><?= h($profile['gender']) ?></span><?php endif; ?>
      <?php if (!empty($profile['age'])): ?><span><?= (int) $profile['age'] ?> years old</span><?php endif; ?>
      <?php if (!empty($profile['birthday'])): ?><span>Birthday <?= h(date('M j, Y', strtotime($profile['birthday']))) ?></span><?php endif; ?>
      <span>Joined <?= h(date('M Y', strtotime($profile['created_at']))) ?></span>
    </div>
    <div class="profile-stats">
      <span><strong><?= (int) $stats['posts'] ?></strong> Posts</span>
      <span><strong><?= (int) $stats['comments'] ?></strong> Comments</span>
      <span><strong><?= (int) $stats['friends'] ?></strong> Friends</span>
      <span><strong><?= (int) $stats['following'] ?></strong> Following</span>
      <span><strong><?= (int) $stats['followers'] ?></strong> Followers</span>
    </div>
  </div>
</section>
<section class="feed">
  <?php if (!$posts): ?>
    <div class="empty-state">
      <h2>No posts</h2>
      <p>This profile has not posted yet.</p>
    </div>
  <?php endif; ?>
  <?php foreach ($posts as $post): ?>
    <?php render_post($post, $viewer); ?>
  <?php endforeach; ?>
</section>
<?php render_shell_end($viewer); ?>
