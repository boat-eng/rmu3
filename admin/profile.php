<?php
require_once '../includes/config.php';
requireRole('admin');

$uid     = (int)$_SESSION['user_id'];
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_update'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    if (!$full_name) {
        $error = 'Full name cannot be empty.';
    } else {
        $pdo->prepare('UPDATE users SET full_name=? WHERE id=?')->execute([$full_name, $uid]);
        $_SESSION['full_name'] = $full_name;
        $success = 'Profile updated.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_password'])) {
    $current = $_POST['current_pw'] ?? '';
    $newpw   = $_POST['new_pw']     ?? '';
    $confirm = $_POST['confirm_pw'] ?? '';
    $stmt    = $pdo->prepare('SELECT password FROM users WHERE id=?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!password_verify($current, $row['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newpw) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newpw !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($newpw, PASSWORD_DEFAULT), $uid]);
        $success = 'Password changed.';
    }
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
$stmt->execute([$uid]);
$user = $stmt->fetch();

$pageTitle    = 'My Profile';
$pageSubtitle = 'Administrator Account';
$activePage   = 'profile';
$depth        = 1;

ob_start();
?>

<?php if($success): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> <?= h($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> <?= h($error) ?></div><?php endif; ?>

<div class="g2" style="align-items:start">
  <div class="card">
    <div class="card-hd"><span class="card-title"><i class="fas fa-user-edit" style="color:var(--gold)"></i> Edit Profile</span></div>
    <form method="POST">
      <div class="fg">
        <label class="lbl2">Full Name *</label>
        <input class="fc" type="text" name="full_name" value="<?= h($user['full_name']) ?>" required>
      </div>
      <div class="fg">
        <label class="lbl2">Email Address</label>
        <input class="fc" type="text" value="<?= h($user['email']) ?>" disabled style="opacity:.5">
      </div>
      <button class="btn btn-primary" type="submit" name="do_update"><i class="fas fa-save"></i> Save</button>
    </form>
  </div>
  <div class="card">
    <div class="card-hd"><span class="card-title"><i class="fas fa-lock" style="color:var(--gold)"></i> Change Password</span></div>
    <form method="POST">
      <div class="fg"><label class="lbl2">Current Password</label><input class="fc" type="password" name="current_pw" required></div>
      <div class="fg"><label class="lbl2">New Password</label><input class="fc" type="password" name="new_pw" required></div>
      <div class="fg"><label class="lbl2">Confirm Password</label><input class="fc" type="password" name="confirm_pw" required></div>
      <button class="btn btn-primary" type="submit" name="do_password"><i class="fas fa-key"></i> Update Password</button>
    </form>
  </div>
</div>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
