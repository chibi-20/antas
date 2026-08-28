<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$pdo = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    // Session doesn't carry password_hash — re-fetch fresh from the DB rather than trust
    // anything cached from login time.
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (mb_strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } elseif (password_verify($newPassword, $row['password_hash'])) {
        $error = 'New password must be different from your current password.';
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
        $_SESSION['user']['must_change_password'] = false;
        flash_set('success', 'Password changed.');
        redirect('/index.php');
    }
}

render_header('Change Password');
?>
<div class="max-w-md">
  <?php if (!empty($user['must_change_password'])): ?>
  <div class="mb-4 px-4 py-3 rounded-lg text-sm bg-amber-50 text-amber-700 border border-amber-200">
    You're using the default password from your account setup — set your own to continue.
  </div>
  <?php endif; ?>

  <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
    <?php if ($error): ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm bg-rose-50 text-rose-700 border border-rose-200"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label class="block text-sm font-medium text-slate-600 mb-1">Current password</label>
      <input type="password" name="current_password" required class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <label class="block text-sm font-medium text-slate-600 mb-1">New password</label>
      <input type="password" name="new_password" required minlength="8" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <label class="block text-sm font-medium text-slate-600 mb-1">Confirm new password</label>
      <input type="password" name="confirm_password" required minlength="8" class="w-full mb-4 px-3 py-2 border border-slate-300 rounded-lg">
      <button type="submit" class="bg-accent-600 hover:bg-accent-700 text-white font-medium px-4 py-2 rounded-lg text-sm">Change Password</button>
      <?php if (empty($user['must_change_password'])): ?>
      <a href="<?= htmlspecialchars(url('/index.php')) ?>" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">Cancel</a>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php render_footer(); ?>
