<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(?string $message = null, string $type = 'info'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function require_valid_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        flash('Security check failed. Please try again.', 'error');
        redirect('index.php');
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    if ($user !== null && (int) $user['id'] === (int) $_SESSION['user_id']) {
        return $user;
    }

    $stmt = db()->prepare(
        'SELECT
            u.*,
            COALESCE(pr.display_name, u.display_name, u.username) AS display_name,
            COALESCE(pr.bio, u.bio, "") AS bio,
            COALESCE(pr.profile_pic, u.avatar_color, "#111111") AS avatar_color,
            COALESCE(pr.updated_at, u.updated_at) AS profile_updated_at
         FROM users u
         LEFT JOIN profiles pr ON pr.user_id = u.id
         WHERE u.id = ?
         LIMIT 1'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        flash('Log in to continue.', 'error');
        redirect('auth.php');
    }

    return $user;
}

function time_ago(string $datetime): string
{
    $seconds = max(1, time() - strtotime($datetime));
    $units = [
        31536000 => 'y',
        2592000 => 'mo',
        604800 => 'w',
        86400 => 'd',
        3600 => 'h',
        60 => 'm',
    ];

    foreach ($units as $unitSeconds => $label) {
        if ($seconds >= $unitSeconds) {
            return (string) floor($seconds / $unitSeconds) . $label;
        }
    }

    return $seconds . 's';
}

function ensure_profile(int $userId, string $displayName, string $avatarColor): void
{
    $stmt = db()->prepare('INSERT IGNORE INTO profiles (user_id, display_name, profile_pic) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $displayName, $avatarColor]);
}

function notify_user(int $userId, int $actorId, string $type, ?int $postId = null, ?int $messageId = null): void
{
    if ($userId === $actorId) {
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, actor_id, type, post_id, message_id) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $actorId, $type, $postId, $messageId]);
}

function post_owner_id(int $postId): ?int
{
    $stmt = db()->prepare('SELECT user_id FROM posts WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$postId]);
    $owner = $stmt->fetchColumn();
    return $owner === false ? null : (int) $owner;
}

function user_stats(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT
            (SELECT COUNT(*) FROM posts WHERE user_id = ? AND deleted_at IS NULL) AS posts,
            (SELECT COUNT(*) FROM follows WHERE follower_id = ?) AS following,
            (SELECT COUNT(*) FROM follows WHERE following_id = ?) AS followers,
            (SELECT COUNT(*) FROM comments WHERE user_id = ?) AS comments,
            (SELECT COUNT(*) FROM friendships WHERE (user_1 = ? OR user_2 = ?) AND status = "accepted") AS friends'
    );
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    return $stmt->fetch() ?: ['posts' => 0, 'following' => 0, 'followers' => 0, 'comments' => 0, 'friends' => 0];
}

function app_metrics(): array
{
    $stmt = db()->query(
        'SELECT
            (SELECT COUNT(*) FROM users) AS users,
            (SELECT COUNT(*) FROM profiles) AS profiles,
            (SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL) AS posts,
            (SELECT COUNT(*) FROM comments) AS comments,
            (SELECT COUNT(*) FROM likes) AS likes,
            (SELECT COUNT(*) FROM follows) AS follows,
            (SELECT COUNT(*) FROM messages) AS messages,
            (SELECT COUNT(*) FROM notifications) AS notifications'
    );

    return $stmt->fetch() ?: [];
}

function fetch_posts(int $viewerId, string $mode = 'all', ?int $profileId = null, string $search = ''): array
{
    $where = ['p.deleted_at IS NULL'];
    $params = [$viewerId, $viewerId];
    $joinFollowing = '';

    if ($mode === 'following') {
        $joinFollowing = 'INNER JOIN follows feed_follows ON feed_follows.following_id = p.user_id AND feed_follows.follower_id = ?';
        $params[] = $viewerId;
    }

    if ($profileId !== null) {
        $where[] = 'p.user_id = ?';
        $params[] = $profileId;
    }

    if ($search !== '') {
        $where[] = '(p.content LIKE ? OR u.username LIKE ? OR COALESCE(pr.display_name, u.display_name) LIKE ?)';
        $term = '%' . $search . '%';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    $sql = "SELECT
            p.*,
            u.username,
            COALESCE(pr.display_name, u.display_name, u.username) AS display_name,
            COALESCE(pr.profile_pic, u.avatar_color, '#111111') AS avatar_color,
            COALESCE(pr.bio, u.bio, '') AS bio,
            rp.content AS repost_content,
            ru.username AS repost_username,
            COALESCE(rpr.display_name, ru.display_name, ru.username) AS repost_display_name,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM reposts r WHERE r.post_id = p.id) AS repost_count,
            (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS reply_count,
            EXISTS(SELECT 1 FROM likes viewer_likes WHERE viewer_likes.post_id = p.id AND viewer_likes.user_id = ?) AS viewer_liked,
            EXISTS(SELECT 1 FROM reposts viewer_reposts WHERE viewer_reposts.post_id = p.id AND viewer_reposts.user_id = ?) AS viewer_reposted
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        LEFT JOIN profiles pr ON pr.user_id = u.id
        LEFT JOIN posts rp ON rp.id = p.repost_of_id
        LEFT JOIN users ru ON ru.id = rp.user_id
        LEFT JOIN profiles rpr ON rpr.user_id = ru.id
        $joinFollowing
        WHERE " . implode(' AND ', $where) . '
        ORDER BY p.created_at DESC
        LIMIT 80';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_post(int $viewerId, int $postId): ?array
{
    $posts = fetch_posts($viewerId);
    foreach ($posts as $post) {
        if ((int) $post['id'] === $postId) {
            return $post;
        }
    }

    $stmt = db()->prepare(
        'SELECT
            p.*,
            u.username,
            COALESCE(pr.display_name, u.display_name, u.username) AS display_name,
            COALESCE(pr.profile_pic, u.avatar_color, "#111111") AS avatar_color,
            COALESCE(pr.bio, u.bio, "") AS bio,
            rp.content AS repost_content,
            ru.username AS repost_username,
            COALESCE(rpr.display_name, ru.display_name, ru.username) AS repost_display_name,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM reposts r WHERE r.post_id = p.id) AS repost_count,
            (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS reply_count,
            EXISTS(SELECT 1 FROM likes viewer_likes WHERE viewer_likes.post_id = p.id AND viewer_likes.user_id = ?) AS viewer_liked,
            EXISTS(SELECT 1 FROM reposts viewer_reposts WHERE viewer_reposts.post_id = p.id AND viewer_reposts.user_id = ?) AS viewer_reposted
         FROM posts p
         INNER JOIN users u ON u.id = p.user_id
         LEFT JOIN profiles pr ON pr.user_id = u.id
         LEFT JOIN posts rp ON rp.id = p.repost_of_id
         LEFT JOIN users ru ON ru.id = rp.user_id
         LEFT JOIN profiles rpr ON rpr.user_id = ru.id
         WHERE p.id = ? AND p.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$viewerId, $viewerId, $postId]);
    return $stmt->fetch() ?: null;
}

function fetch_comments(int $postId): array
{
    $stmt = db()->prepare(
        'SELECT
            c.*,
            u.username,
            COALESCE(pr.display_name, u.display_name, u.username) AS display_name,
            COALESCE(pr.profile_pic, u.avatar_color, "#111111") AS avatar_color
         FROM comments c
         INNER JOIN users u ON u.id = c.user_id
         LEFT JOIN profiles pr ON pr.user_id = u.id
         WHERE c.post_id = ?
         ORDER BY c.created_at ASC'
    );
    $stmt->execute([$postId]);
    return $stmt->fetchAll();
}

function fetch_user_by_username(string $username): ?array
{
    $stmt = db()->prepare(
        'SELECT
            u.*,
            COALESCE(pr.display_name, u.display_name, u.username) AS display_name,
            COALESCE(pr.bio, u.bio, "") AS bio,
            COALESCE(pr.profile_pic, u.avatar_color, "#111111") AS avatar_color,
            pr.updated_at AS profile_updated_at
         FROM users u
         LEFT JOIN profiles pr ON pr.user_id = u.id
         WHERE u.username = ?
         LIMIT 1'
    );
    $stmt->execute([$username]);
    return $stmt->fetch() ?: null;
}

function fetch_users_for_message(int $viewerId): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.username, COALESCE(pr.display_name, u.display_name, u.username) AS display_name
         FROM users u
         LEFT JOIN profiles pr ON pr.user_id = u.id
         WHERE u.id <> ?
         ORDER BY display_name ASC'
    );
    $stmt->execute([$viewerId]);
    return $stmt->fetchAll();
}

function render_post(array $post, array $viewer): void
{
    $isOwnPost = (int) $post['user_id'] === (int) $viewer['id'];
    ?>
    <article class="post">
      <a class="avatar" style="--avatar: <?= h($post['avatar_color']) ?>" href="profile.php?u=<?= h($post['username']) ?>">
        <?= h(strtoupper(substr($post['display_name'], 0, 1))) ?>
      </a>
      <div class="post-body">
        <header class="post-header">
          <a class="name" href="profile.php?u=<?= h($post['username']) ?>"><?= h($post['display_name']) ?></a>
          <a class="handle" href="profile.php?u=<?= h($post['username']) ?>">@<?= h($post['username']) ?></a>
          <a class="time" href="post.php?id=<?= (int) $post['id'] ?>"><?= h(time_ago($post['created_at'])) ?></a>
        </header>
        <?php if (!empty($post['repost_of_id'])): ?>
          <div class="repost-card">
            <span>Reposted from @<?= h($post['repost_username']) ?></span>
            <p><?= nl2br(h($post['repost_content'])) ?></p>
          </div>
        <?php endif; ?>
        <p class="post-copy"><?= nl2br(h($post['content'])) ?></p>
        <?php if (!empty($post['image_url'])): ?>
          <img class="post-image" src="<?= h($post['image_url']) ?>" alt="">
        <?php endif; ?>
        <footer class="post-actions">
          <a class="action-link" href="post.php?id=<?= (int) $post['id'] ?>">Comments <?= (int) $post['reply_count'] ?></a>
          <form action="actions.php" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_like">
            <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
            <button class="ghost-button <?= $post['viewer_liked'] ? 'active' : '' ?>" type="submit">Like <?= (int) $post['like_count'] ?></button>
          </form>
          <form action="actions.php" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_repost">
            <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
            <button class="ghost-button <?= $post['viewer_reposted'] ? 'active' : '' ?>" type="submit">Repost <?= (int) $post['repost_count'] ?></button>
          </form>
          <?php if ($isOwnPost): ?>
            <form action="actions.php" method="post" onsubmit="return confirm('Delete this post?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_post">
              <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
              <button class="ghost-button danger" type="submit">Delete</button>
            </form>
          <?php endif; ?>
        </footer>
      </div>
    </article>
    <?php
}

function render_comment(array $comment): void
{
    ?>
    <article class="comment">
      <a class="avatar small" style="--avatar: <?= h($comment['avatar_color']) ?>" href="profile.php?u=<?= h($comment['username']) ?>">
        <?= h(strtoupper(substr($comment['display_name'], 0, 1))) ?>
      </a>
      <div>
        <header class="post-header">
          <a class="name" href="profile.php?u=<?= h($comment['username']) ?>"><?= h($comment['display_name']) ?></a>
          <a class="handle" href="profile.php?u=<?= h($comment['username']) ?>">@<?= h($comment['username']) ?></a>
          <span class="time"><?= h(time_ago($comment['created_at'])) ?></span>
        </header>
        <p class="post-copy"><?= nl2br(h($comment['comment'])) ?></p>
      </div>
    </article>
    <?php
}
