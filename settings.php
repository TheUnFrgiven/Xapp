<?php
declare(strict_types=1);

require_once __DIR__ . '/partials.php';

$viewer = require_auth();

render_head('Edit Profile');
render_shell_start($viewer, 'settings');
?>
<header class="page-header">
  <div>
    <h1>Edit profile</h1>
    <p>Update how your account appears to other people.</p>
  </div>
</header>
<form class="settings-form" action="actions.php" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_profile">
  <label>
    First name
    <input name="first_name" maxlength="60" value="<?= h($viewer['first_name'] ?? '') ?>" required>
  </label>
  <label>
    Last name
    <input name="last_name" maxlength="60" value="<?= h($viewer['last_name'] ?? '') ?>" required>
  </label>
  <label>
    Nickname
    <input name="nickname" maxlength="24" pattern="[a-z0-9_]{3,24}" value="<?= h($viewer['nickname'] ?? $viewer['username']) ?>" required>
  </label>
  <label>
    Bio
    <textarea name="bio" maxlength="180" rows="4"><?= h($viewer['bio']) ?></textarea>
  </label>
  <label>
    Profile color
    <input name="profile_pic" maxlength="7" value="<?= h($viewer['avatar_color']) ?>" placeholder="#1d9bf0">
  </label>
  <label>
    Gender
    <input name="gender" maxlength="40" value="<?= h($viewer['gender'] ?? '') ?>">
  </label>
  <label>
    Age
    <input type="number" name="age" min="13" max="120" value="<?= h((string) ($viewer['age'] ?? '')) ?>" required>
  </label>
  <label>
    Birthday
    <input type="date" name="birthday" value="<?= h($viewer['birthday'] ?? '') ?>">
  </label>
  <button type="submit">Save profile</button>
</form>
<?php render_shell_end($viewer); ?>
