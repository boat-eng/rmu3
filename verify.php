<?php
require_once __DIR__ . '/includes/config.php';

$token  = trim($_GET['token'] ?? '');
$status = 'error';
$msg    = 'Invalid or expired verification link.';

if ($token) {
    // ── Check students table first (student accounts) ────────────────────────
    $stmt = $pdo->prepare('SELECT id, full_name, email_verified FROM students WHERE verify_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['email_verified']) {
            $status = 'already';
            $msg    = 'Your email address has already been verified. You can sign in.';
        } else {
            $pdo->prepare('UPDATE students SET email_verified=1, verify_token=NULL WHERE id=?')
                ->execute([$user['id']]);
            $status = 'success';
            $msg    = 'Your email has been verified successfully! You can now sign in to the RMU E-Learning Platform.';
        }
    } else {
        // ── Fall back to users table (admin / instructor accounts) ───────────
        $stmt = $pdo->prepare('SELECT id, full_name, email_verified FROM users WHERE verify_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            if ($user['email_verified']) {
                $status = 'already';
                $msg    = 'Your email address has already been verified. You can sign in.';
            } else {
                $pdo->prepare('UPDATE users SET email_verified=1, verify_token=NULL WHERE id=?')
                    ->execute([$user['id']]);
                $status = 'success';
                $msg    = 'Your email has been verified successfully! You can now sign in to the RMU E-Learning Platform.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Email Verification — RMU E-Learning</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Raleway:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;font-family:'Raleway',sans-serif;background:#07111f;color:#dce8f5;display:flex;align-items:center;justify-content:center;padding:20px}
.box{max-width:480px;width:100%;background:#0f1e33;border:1px solid rgba(200,168,75,.2);border-radius:16px;overflow:hidden;text-align:center}
.box-top{padding:40px 32px 28px}
.icon{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:36px;margin:0 auto 20px}
.icon.success{background:rgba(39,174,96,.15);color:#4caf82}
.icon.error{background:rgba(231,76,60,.15);color:#ff8a80}
.icon.already{background:rgba(41,128,185,.15);color:#64b5f6}
h1{font-family:'Cinzel',serif;font-size:20px;color:#fff;margin-bottom:12px}
p{font-size:14px;color:#a0b4c8;line-height:1.7;margin-bottom:24px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;background:#c8a84b;color:#001a3e;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;transition:opacity .2s}
.btn:hover{opacity:.85}
.box-foot{background:rgba(0,0,0,.2);padding:16px 32px}
.box-foot p{font-size:11px;color:#6e849e;margin:0}
</style>
</head>
<body>
<div class="box">
    <div class="box-top">
        <div class="icon <?= $status ?>">
            <?php if($status === 'success'): ?>
                <i class="fas fa-check-circle"></i>
            <?php elseif($status === 'already'): ?>
                <i class="fas fa-info-circle"></i>
            <?php else: ?>
                <i class="fas fa-times-circle"></i>
            <?php endif; ?>
        </div>

        <?php if($status === 'success'): ?>
            <h1>Email Verified!</h1>
        <?php elseif($status === 'already'): ?>
            <h1>Already Verified</h1>
        <?php else: ?>
            <h1>Verification Failed</h1>
        <?php endif; ?>

        <p><?= htmlspecialchars($msg) ?></p>

        <?php if($status === 'success' || $status === 'already'): ?>
        <a href="<?= BASE_URL ?>/index.php" class="btn">
            <i class="fas fa-sign-in-alt"></i> Sign In Now
        </a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>/index.php" class="btn" style="background:rgba(200,168,75,.15);color:#c8a84b;border:1px solid rgba(200,168,75,.3)">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>
        <?php endif; ?>
    </div>
    <div class="box-foot">
        <p>© <?= date('Y') ?> Regional Maritime University · Department of ICT · E-Learning Platform</p>
    </div>
</div>
</body>
</html>
