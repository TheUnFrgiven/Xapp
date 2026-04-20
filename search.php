<?php
declare(strict_types=1);

require_once __DIR__ . '/partials.php';

$viewer = require_auth();
$query = trim((string) ($_GET['q'] ?? ''));
$posts = $query === '' ? [] : fetch_posts((int) $viewer['id'], 'all', null, $query);

if ($query !== '') {
    $historyStmt = db()->prepare('INSERT INTO search_history (user_id, keyword) VALUES (?, ?)');
    $historyStmt->execute([(int) $viewer['id'], $query]);
}

$users = [];
if ($query !== '') {
    $stmt = db()->prepare(
        'SELECT u.*, COALESCE(pr.display_name, u.display_name, u.username) AS display_name, COALESCE(pr.profile_pic, u.avatar_color, "#111111") AS avatar_color
         FROM users u
         LEFT JOIN profiles pr ON pr.user_id = u.id
         WHERE u.username LIKE ? OR COALESCE(pr.display_name, u.display_name) LIKE ?
         ORDER BY u.created_at DESC
         LIMIT 20'
    );
    $term = '%' . $query . '%';
    $stmt->execute([$term, $term]);
    $users = $stmt->fetchAll();
}

render_head('Search');
render_shell_start($viewer, 'search');
?>
<header class="page-header">
  <div>
    <h1>Search</h1>
    <p>Find people and posts.</p>
  </div>
</header>
<form class="search-page-form" action="search.php" method="get">
  <input type="search" name="q" value="<?= h($query) ?>" placeholder="Search by post, username, or display name" autofocus>
  <button type="submit">Search</button>
</form>
<?php if ($query !== ''): ?>
  <section class="people-list">
    <h2>People</h2>
    <?php if (!$users): ?><p class="muted">No matching users.</p><?php endif; ?>
    <?php foreach ($users as $user): ?>
      <a class="person-row" href="profile.php?u=<?= h($user['username']) ?>">
        <span class="avatar small" style="--avatar: <?= h($user['avatar_color']) ?>"><?= h(strtoupper(substr($user['display_name'], 0, 1))) ?></span>
        <span><strong><?= h($user['display_name']) ?></strong><small>@<?= h($user['username']) ?></small></span>
      </a>
    <?php endforeach; ?>
  </section>
  <section class="feed">
    <h2 class="section-title">Posts</h2>
    <?php if (!$posts): ?><div class="empty-state"><p>No matching posts.</p></div><?php endif; ?>
    <?php foreach ($posts as $post): ?>
      <?php render_post($post, $viewer); ?>
    <?php endforeach; ?>
  </section>
<?php endif; ?>
<?php render_shell_end($viewer); ?>
