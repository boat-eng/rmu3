<?php
require_once __DIR__ . '/includes/config.php';

$token  = trim($_GET['token'] ?? '');
$status = 'form'; // form | success | error
$msg    = '';

// Validate token
$student = null;
if ($token) {
    $stmt = $pdo->prepare('SELECT id, full_name, email FROM students WHERE verify_token=? LIMIT 1');
    $stmt->execute([$token]);
    $student = $stmt->fetch();
    if (!$student) {
        $status = 'error';
        $msg    = 'This link is invalid or has already been used. Please contact your administrator.';
    }
} else {
    $status = 'error';
    $msg    = 'No token provided. Please use the link sent to your email.';
}

// Handle form submission
if ($status === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $msg = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $msg = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE students SET password=?, verify_token=NULL, email_verified=1 WHERE id=?')
            ->execute([$hash, $student['id']]);
        $status = 'success';
        $msg    = 'Your password has been set. You can now sign in.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Set Your Password — RMU E-Learning</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Raleway:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;font-family:'Raleway',sans-serif;background:#07111f;color:#dce8f5;display:flex;align-items:center;justify-content:center;padding:20px}
.box{max-width:440px;width:100%;background:#0f1e33;border:1px solid rgba(200,168,75,.2);border-radius:16px;overflow:hidden;text-align:center}
.box-top{padding:36px 32px 28px}
.icon{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 18px}
.icon.form   {background:rgba(200,168,75,.12);color:#c8a84b}
.icon.success{background:rgba(39,174,96,.15);color:#4caf82}
.icon.error  {background:rgba(231,76,60,.15);color:#ff8a80}
h1{font-family:'Cinzel',serif;font-size:18px;color:#fff;margin-bottom:8px}
.sub{font-size:13px;color:#a0b4c8;line-height:1.6;margin-bottom:22px}
.fg{margin-bottom:14px;text-align:left}
label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6e849e;display:block;margin-bottom:5px}
input[type=password]{width:100%;padding:11px 14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:'Raleway',sans-serif;font-size:14px;outline:none;transition:border-color .2s}
input[type=password]:focus{border-color:#c8a84b}
.alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;text-align:left}
.alert-err{background:rgba(231,76,60,.1);border:1px solid rgba(231,76,60,.3);color:#ff8a80}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 24px;background:#c8a84b;color:#001a3e;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;border:none;cursor:pointer;width:100%;transition:opacity .2s;margin-top:4px}
.btn:hover{opacity:.88}
.btn-ghost{background:rgba(200,168,75,.1);color:#c8a84b;border:1px solid rgba(200,168,75,.25)}
.box-foot{background:rgba(0,0,0,.15);border-top:1px solid rgba(255,255,255,.06);padding:14px 32px}
.box-foot p{font-size:11px;color:#4a6070;margin:0}
.strength-bar{height:3px;border-radius:2px;margin-top:5px;background:#1a2e44;overflow:hidden}
.strength-fill{height:100%;width:0;border-radius:2px;transition:width .3s,background .3s}
</style>
</head>
<body>
<div class="box">
  <div class="box-top">
    <div class="icon <?= $status ?>">
      <?php if($status === 'success'): ?>
        <i class="fas fa-check-circle"></i>
      <?php elseif($status === 'error'): ?>
        <i class="fas fa-times-circle"></i>
      <?php else: ?>
        <i class="fas fa-lock"></i>
      <?php endif; ?>
    </div>

    <?php if($status === 'success'): ?>
      <h1>Password Set!</h1>
      <p class="sub">You're all set, <?= htmlspecialchars($student['full_name']) ?>. Sign in with your email and new password.</p>
      <a href="<?= BASE_URL ?>/index.php" class="btn"><i class="fas fa-sign-in-alt"></i> Sign In Now</a>

    <?php elseif($status === 'error'): ?>
      <h1>Link Invalid</h1>
      <p class="sub"><?= htmlspecialchars($msg) ?></p>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back to Login</a>

    <?php else: ?>
      <h1>Set Your Password</h1>
      <p class="sub">Hi <?= htmlspecialchars($student['full_name']) ?>, choose a password to activate your RMU E-Learning account.</p>

      <?php if($msg): ?>
      <div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <form method="POST" action="set_password.php?token=<?= htmlspecialchars($token) ?>">
        <div class="fg">
          <label>New Password</label>
          <input type="password" name="password" id="pw" placeholder="At least 6 characters" required oninput="checkStrength(this.value)">
          <div class="strength-bar"><div class="strength-fill" id="sf"></div></div>
        </div>
        <div class="fg">
          <label>Confirm Password</label>
          <input type="password" name="confirm_password" placeholder="Repeat your password" required>
        </div>
        <button type="submit" class="btn"><i class="fas fa-key"></i> Set Password &amp; Activate</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="box-foot">
    <p>© <?= date('Y') ?> Regional Maritime University · Department of ICT · E-Learning Platform</p>
  </div>
</div>

<script>
function checkStrength(val) {
    var sf = document.getElementById('sf');
    var score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    var colors = ['#ff4444','#ff8800','#ffcc00','#88cc00','#27ae60'];
    var widths  = ['20%','40%','60%','80%','100%'];
    sf.style.width      = score ? widths[score-1] : '0';
    sf.style.background = score ? colors[score-1] : 'transparent';
}
</script>
</body>
</html>
