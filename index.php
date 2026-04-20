<?php
declare(strict_types=1);

require_once __DIR__ . '/partials.php';

$viewer = current_user();
if (!$viewer) {
    redirect('auth.php');
}

$mode = ($_GET['feed'] ?? 'all') === 'following' ? 'following' : 'all';
$posts = fetch_posts((int) $viewer['id'], $mode);

render_head('Home');
render_shell_start($viewer, 'home');
?>
<header class="page-header">
  <div>
    <h1>Home</h1>
    <p>Post updates, comment, repost, like, and follow people.</p>
  </div>
  <div class="segmented">
    <a class="<?= $mode === 'all' ? 'active' : '' ?>" href="index.php?feed=all">For you</a>
    <a class="<?= $mode === 'following' ? 'active' : '' ?>" href="index.php?feed=following">Following</a>
  </div>
</header>
<?php render_composer($viewer); ?>
<section class="feed" aria-label="Timeline">
  <?php if (!$posts): ?>
    <div class="empty-state">
      <h2>No posts yet</h2>
      <p>Create the first post or follow another user to populate this feed.</p>
    </div>
  <?php endif; ?>
  <?php foreach ($posts as $post): ?>
    <?php render_post($post, $viewer); ?>
  <?php endforeach; ?>
</section>
<?php render_shell_end($viewer); ?>
