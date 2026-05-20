<?php
require_once '../includes/config.php';
requireRole('student');

$uid     = (int)$_SESSION['user_id'];
$success = '';
$error   = '';

// --- Upload profile picture ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_pic'])) {
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $ftype = mime_content_type($_FILES['profile_pic']['tmp_name']);
        if (!in_array($ftype, $allowed)) {
            $error = 'Only JPG, PNG, GIF, or WEBP images are allowed.';
        } elseif ($_FILES['profile_pic']['size'] > 2 * 1024 * 1024) {
            $error = 'Image must be under 2MB.';
        } else {
            $avatarDir = UPLOAD_DIR . 'avatars/';
            if (!is_dir($avatarDir)) mkdir($avatarDir, 0775, true);
            $ext      = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $filename = 'user_' . $uid . '_' . time() . '.' . strtolower($ext);
            // Delete old pic
            $old = $pdo->prepare('SELECT profile_pic FROM students WHERE id=?');
            $old->execute([$uid]);
            $oldpic = $old->fetchColumn();
            if ($oldpic && file_exists($avatarDir . $oldpic)) unlink($avatarDir . $oldpic);
            move_uploaded_file($_FILES['profile_pic']['tmp_name'], $avatarDir . $filename);
            $pdo->prepare('UPDATE students SET profile_pic=? WHERE id=?')->execute([$filename, $uid]);
            $success = 'Profile picture updated successfully.';
        }
    } else {
        $error = 'Please select an image file to upload.';
    }
}

// --- Update profile ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_update'])) {
    $full_name  = trim($_POST['full_name']  ?? '');
    $programme  = trim($_POST['programme']  ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $bio        = trim($_POST['bio']        ?? '');

    if (!$full_name) {
        $error = 'Full name cannot be empty.';
    } else {
        $pdo->prepare('UPDATE students SET full_name=?, department=?, programme=?, student_id=?, bio=? WHERE id=?')
            ->execute([$full_name, 'Department of ICT', $programme, $student_id, $bio, $uid]);
        $_SESSION['full_name'] = $full_name;
        $success = 'Profile updated successfully.';
    }
}

// --- Change password ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_password'])) {
    $current = $_POST['current_pw'] ?? '';
    $newpw   = $_POST['new_pw']     ?? '';
    $confirm = $_POST['confirm_pw'] ?? '';
    $stmt    = $pdo->prepare('SELECT password FROM students WHERE id=?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();

    if (!password_verify($current, $row['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newpw) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newpw !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $pdo->prepare('UPDATE students SET password=? WHERE id=?')
            ->execute([password_hash($newpw, PASSWORD_DEFAULT), $uid]);
        $success = 'Password changed successfully.';
    }
}

// Load fresh student data
$stmt = $pdo->prepare('SELECT * FROM students WHERE id=? LIMIT 1');
$stmt->execute([$uid]);
$user = $stmt->fetch() ?: [];

// Ensure all keys exist to prevent undefined index warnings
$user = array_merge([
    'full_name'   => '',
    'email'       => '',
    'student_id'  => null,
    'programme'   => null,
    'department'  => null,
    'profile_pic' => null,
    'bio'         => null,
    'created_at'  => date('Y-m-d H:i:s'),
], $user);

// Stats
$nEnrolled = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id=?');
$nEnrolled->execute([$uid]); $nEnrolled = (int)$nEnrolled->fetchColumn();

$nDone = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress WHERE student_id=?');
$nDone->execute([$uid]); $nDone = (int)$nDone->fetchColumn();

$initial  = strtoupper(mb_substr($user['full_name'], 0, 1));
$joinDate = date('F j, Y', strtotime($user['created_at']));

$pageTitle    = 'My Profile';
$pageSubtitle = 'Account information and settings';
$activePage   = 'profile';
$depth        = 1;

ob_start();
?>

<style>
.pg { display:grid; grid-template-columns:310px 1fr; gap:22px; align-items:start; }
.pcard {
    background:var(--surface); border:1px solid var(--border);
    border-radius:14px; overflow:hidden; position:sticky; top:76px;
}
.pbanner {
    height:88px;
    background:linear-gradient(135deg,#001a3e 0%,#003580 45%,#0070c8 80%,#1a90e0 100%);
    position:relative;
}
.pbanner::after {
    content:'';position:absolute;inset:0;
    background:radial-gradient(ellipse 60% 80% at 65% 50%,rgba(255,255,255,0.09),transparent);
}
.pavatar-wrap { display:flex;justify-content:center;margin-top:-40px;position:relative;z-index:1; }
.pavatar {
    width:80px;height:80px;border-radius:50%;
    background:linear-gradient(135deg,#003580,#1a7fd4);
    border:4px solid var(--surface);
    display:flex;align-items:center;justify-content:center;
    font-family:'Cinzel',serif;font-size:30px;font-weight:700;color:#fff;
    box-shadow:0 6px 24px rgba(0,53,128,0.5);
}
.pinfo { padding:12px 20px 18px;text-align:center; }
.pname { font-family:'Cinzel',serif;font-size:16px;font-weight:700;color:var(--white);margin-bottom:4px;line-height:1.3; }
.prole { font-size:10px;letter-spacing:2.5px;text-transform:uppercase;color:#64b5f6;margin-bottom:5px; }
.pemail { font-size:12px;color:var(--muted);word-break:break-all;margin-bottom:16px;line-height:1.5; }

.pstats { display:grid;grid-template-columns:repeat(2,1fr);gap:1px;background:var(--border); }
.ps { background:var(--surface);padding:14px;text-align:center; }
.ps-val { font-family:'Cinzel',serif;font-size:22px;font-weight:700;color:#64b5f6; }
.ps-lbl { font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:3px; }

.pdetails { border-top:1px solid var(--border); }
.prow {
    display:flex;align-items:flex-start;gap:12px;
    padding:13px 18px;border-bottom:1px solid rgba(255,255,255,0.04);
}
.prow:last-child { border-bottom:none; }
.pico {
    width:36px;height:36px;border-radius:9px;flex-shrink:0;margin-top:2px;
    display:flex;align-items:center;justify-content:center;
    background:rgba(0,87,184,0.15);color:#64b5f6;font-size:13px;
}
.plbl { font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:3px; }
.pval { font-size:13px;font-weight:600;color:var(--white);line-height:1.5;word-break:break-all; }
.pval.muted { font-weight:400;color:var(--muted);font-style:italic; }

@media(max-width:820px){ .pg{grid-template-columns:1fr;} .pcard{position:static;} }
</style>

<?php if($success): ?>
<div class="alert alert-ok" style="margin-bottom:20px">
    <i class="fas fa-check-circle"></i> <?= h($success) ?>
</div>
<?php endif; ?>
<?php if($error): ?>
<div class="alert alert-err" style="margin-bottom:20px">
    <i class="fas fa-exclamation-circle"></i> <?= h($error) ?>
</div>
<?php endif; ?>

<div class="pg">

    <!-- ===== LEFT: Profile Summary Card ===== -->
    <div>
        <div class="pcard">
            <div class="pbanner"></div>
            <div class="pavatar-wrap">
                <?php if(!empty($user['profile_pic'])): ?>
                    <div class="pavatar" style="padding:0;overflow:hidden;background:none;">
                        <img src="../uploads/avatars/<?= h($user['profile_pic']) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                <?php else: ?>
                    <div class="pavatar"><?= $initial ?></div>
                <?php endif; ?>
                <!-- Upload trigger -->
                <label for="picInput" title="Change photo" style="position:absolute;bottom:4px;right:4px;width:28px;height:28px;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px solid var(--surf1);">
                    <i class="fas fa-camera" style="font-size:11px;color:#fff;"></i>
                </label>
            </div>
            <!-- Hidden pic upload form -->
            <form method="POST" enctype="multipart/form-data" style="text-align:center;margin-top:8px;margin-bottom:-8px;">
                <input type="file" id="picInput" name="profile_pic" accept="image/*" style="display:none" onchange="this.form.submit()">
                <input type="hidden" name="do_pic" value="1">
                <small style="color:var(--muted);font-size:10px;"><i class="fas fa-camera"></i> Click camera icon to change photo</small>
            </form>
            <div class="pinfo">
                <div class="pname"><?= h($user['full_name']) ?></div>
                <div class="prole"><i class="fas fa-user-graduate"></i>&nbsp; Student</div>
                <div class="pemail"><?= h($user['email']) ?></div>
            </div>

            <!-- Quick stats -->
            <div class="pstats">
                <div class="ps">
                    <div class="ps-val"><?= $nEnrolled ?></div>
                    <div class="ps-lbl">Courses</div>
                </div>
                <div class="ps">
                    <div class="ps-val"><?= $nDone ?></div>
                    <div class="ps-lbl">Lessons Done</div>
                </div>
            </div>

            <!-- Detail rows -->
            <div class="pdetails">
                <div class="prow">
                    <div class="pico"><i class="fas fa-id-badge"></i></div>
                    <div>
                        <div class="plbl">Student ID</div>
                        <div class="pval <?= $user['student_id']?'':'muted' ?>">
                            <?= $user['student_id'] ? h($user['student_id']) : 'Not set' ?>
                        </div>
                    </div>
                </div>
                <div class="prow">
                    <div class="pico"><i class="fas fa-envelope"></i></div>
                    <div>
                        <div class="plbl">Email Address</div>
                        <div class="pval"><?= h($user['email']) ?></div>
                    </div>
                </div>
                <div class="prow">
                    <div class="pico"><i class="fas fa-university"></i></div>
                    <div>
                        <div class="plbl">Department</div>
                        <div class="pval">Department of ICT</div>
                    </div>
                </div>
                <div class="prow">
                    <div class="pico"><i class="fas fa-code-branch"></i></div>
                    <div>
                        <div class="plbl">Programme</div>
                        <div class="pval <?= $user['programme']?'':'muted' ?>">
                            <?php
                            $progLabels = ['IT'=>'Information Technology (IT)','CS'=>'Computer Science (CS)','CE'=>'Computer Engineering (CE)'];
                            echo $user['programme'] ? h($progLabels[$user['programme']] ?? $user['programme']) : 'Not set';
                            ?>
                        </div>
                    </div>
                </div>
                <div class="prow">
                    <div class="pico"><i class="fas fa-calendar-check"></i></div>
                    <div>
                        <div class="plbl">Member Since</div>
                        <div class="pval"><?= $joinDate ?></div>
                    </div>
                </div>
                <?php if($user['bio']): ?>
                <div class="prow">
                    <div class="pico"><i class="fas fa-quote-left"></i></div>
                    <div>
                        <div class="plbl">About Me</div>
                        <div class="pval" style="font-weight:400;line-height:1.65;font-size:12px">
                            <?= h($user['bio']) ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== RIGHT: Edit Forms ===== -->
    <div>

        <!-- Edit Profile Form -->
        <div class="card" style="margin-bottom:20px">
            <div class="card-hd">
                <span class="card-title">
                    <i class="fas fa-user-edit" style="color:#64b5f6"></i> Edit Profile
                </span>
            </div>
            <form method="POST">
                <div class="g2">
                    <div class="fg">
                        <label class="lbl2">Full Name *</label>
                        <input class="fc" type="text" name="full_name"
                               value="<?= h($user['full_name']) ?>" required>
                    </div>
                    <div class="fg">
                        <label class="lbl2">Student ID</label>
                        <input class="fc" type="text" name="student_id"
                               value="<?= h($user['student_id'] ?? '') ?>"
                               placeholder="e.g. RMU/2024/001">
                    </div>
                </div>
                <div class="fg">
                    <label class="lbl2">Department</label>
                    <input class="fc" type="text" value="Department of ICT" disabled style="opacity:.6;cursor:not-allowed">
                </div>
                <div class="fg">
                    <label class="lbl2">Programme</label>
                    <select class="fc" name="programme">
                        <option value="">— Select Programme —</option>
                        <?php foreach(['IT'=>'Information Technology (IT)','CS'=>'Computer Science (CS)','CE'=>'Computer Engineering (CE)'] as $val=>$label): ?>
                        <option value="<?= $val ?>" <?= ($user['programme']===$val)?'selected':'' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label class="lbl2">Email Address</label>
                    <input class="fc" type="text" value="<?= h($user['email']) ?>"
                           disabled style="opacity:.5;cursor:not-allowed">
                    <small style="color:var(--muted);font-size:11px;margin-top:-10px;display:block">
                        <i class="fas fa-lock"></i> Your RMU email cannot be changed
                    </small>
                </div>
                <div class="fg">
                    <label class="lbl2">About Me</label>
                    <textarea class="fc" name="bio" rows="3"
                              placeholder="Tell us a little about yourself..."><?= h($user['bio'] ?? '') ?></textarea>
                </div>
                <button class="btn btn-primary" type="submit" name="do_update">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </form>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-hd">
                <span class="card-title">
                    <i class="fas fa-lock" style="color:#64b5f6"></i> Change Password
                </span>
            </div>
            <form method="POST">
                <div class="fg">
                    <label class="lbl2">Current Password</label>
                    <input class="fc" type="password" name="current_pw"
                           placeholder="Enter your current password" required>
                </div>
                <div class="g2">
                    <div class="fg">
                        <label class="lbl2">New Password</label>
                        <input class="fc" type="password" name="new_pw"
                               placeholder="Minimum 6 characters" required>
                    </div>
                    <div class="fg">
                        <label class="lbl2">Confirm New Password</label>
                        <input class="fc" type="password" name="confirm_pw"
                               placeholder="Repeat new password" required>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit" name="do_password">
                    <i class="fas fa-key"></i> Update Password
                </button>
            </form>
        </div>

    </div>
</div>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
