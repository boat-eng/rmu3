<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/' . $_SESSION['role'] . '/dashboard.php');
    exit;
}

// ── Verification email ───────────────────────────────────────────────────────
function sendVerificationEmail(string $toEmail, string $toName, string $token): bool {
    $verifyUrl = BASE_URL . '/verify.php?token=' . $token;
    $body = buildEmailTemplate(
        'Verify your RMU E-Learning account',
        '<p style="font-size:15px;color:rgba(255,255,255,.9);margin:0 0 10px">
            Hi <strong style="color:#fff">' . htmlspecialchars($toName) . '</strong>,
         </p>
         <p style="font-size:14px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 0 24px">
            Thank you for registering on the <strong style="color:#c8a84b">RMU E-Learning Platform</strong>.<br>
            Click the button below to verify your email address and activate your account.
         </p>
         <div style="text-align:center;margin-bottom:28px">
            <a href="' . $verifyUrl . '"
               style="display:inline-block;background:#c8a84b;color:#001a3e;font-weight:700;
                      font-size:15px;padding:14px 36px;border-radius:8px;text-decoration:none;
                      letter-spacing:.03em">
               ✓ Verify My Email Address
            </a>
         </div>
         <p style="font-size:12px;color:rgba(255,255,255,.35);line-height:1.7;margin:0">
            This link expires in <strong style="color:rgba(255,255,255,.6)">24 hours</strong>.<br>
            If you did not create this account, you can safely ignore this email.
         </p>'
    );
    return sendMail($toEmail, $toName, 'Verify Your RMU E-Learning Account', $body);
}

// ── Forgot Password handler ──────────────────────────────────────────────────
$forgotMsg = $forgotErr = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_forgot'])) {
    $femail = strtolower(trim($_POST['forgot_email'] ?? ''));
    if (!$femail) {
        $forgotErr = 'Please enter your email address.';
    } else {
        // ── Check students table first, then users (instructors/admin) ──
        $fuser = null;
        $table = null;

        $stmt = $pdo->prepare('SELECT id, full_name FROM students WHERE email=? LIMIT 1');
        $stmt->execute([$femail]);
        $row = $stmt->fetch();
        if ($row) { $fuser = $row; $table = 'students'; }

        if (!$fuser) {
            $stmt = $pdo->prepare('SELECT id, full_name FROM users WHERE email=? LIMIT 1');
            $stmt->execute([$femail]);
            $row = $stmt->fetch();
            if ($row) { $fuser = $row; $table = 'users'; }
        }

        if ($fuser) {
            // Generate reset token and store it
            $resetToken = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE $table SET verify_token=? WHERE id=?")->execute([$resetToken, $fuser['id']]);
            $resetLink = BASE_URL . '/set_password.php?token=' . $resetToken;

            // Send reset email via mailer.php
            $sent = false;
            try {
                require_once __DIR__ . '/includes/mailer.php';
                $emailHtml = buildEmailTemplate(
                    '🔐 Reset Your Password',
                    '<p style="font-size:16px;color:rgba(255,255,255,.9);margin:0 0 10px">Hi <strong style="color:#fff">' . htmlspecialchars($fuser['full_name']) . '</strong>,</p>
                     <p style="font-size:14px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 0 24px">
                       We received a request to reset your <strong style="color:#c8a84b">RMU E-Learning</strong> password.
                       Click the button below to set a new password. This link expires in <strong style="color:#fff">1 hour</strong>.
                     </p>
                     <div style="text-align:center;margin-bottom:24px">
                       <a href="' . $resetLink . '"
                          style="display:inline-block;background:#c8a84b;color:#001a3e;font-weight:700;font-size:14px;
                                 padding:13px 32px;border-radius:8px;text-decoration:none">
                         🔐 Reset My Password
                       </a>
                     </div>
                     <p style="font-size:12px;color:rgba(255,255,255,.35);line-height:1.7;margin:0">
                       If you did not request a password reset, ignore this email — your account is safe.<br>
                       Or copy this link:<br>
                       <a href="' . $resetLink . '" style="color:#c8a84b;word-break:break-all;font-size:11px">' . $resetLink . '</a>
                     </p>'
                );
                $sent = sendMail($femail, $fuser['full_name'], 'Reset Your RMU E-Learning Password', $emailHtml);
            } catch (\Exception $e) {
                error_log('[RMU] Forgot password mail error: ' . $e->getMessage());
            }

            if ($sent) {
                $forgotMsg = 'Password reset link sent to <strong>' . htmlspecialchars($femail) . '</strong>. Check your inbox (and spam folder).';
            } else {
                $forgotMsg = 'Email could not be sent. Use this link to reset your password: <a href="' . $resetLink . '" style="color:#c8a84b">' . $resetLink . '</a>';
            }
        } else {
            // Don't reveal whether the email exists — security best practice
            $forgotMsg = 'If that email is registered, a reset link has been sent.';
        }
    }
    $showTab = 'forgot';
}

// ── Student ID validation ────────────────────────────────────────────────────
function validateStudentId(string $sid, string &$error): bool {
    $sid = strtoupper(trim($sid));

    // Allowed prefixes ONLY — nothing else accepted
    $validPrefixes = ['DIT', 'BIT', 'BCE', 'BCS'];

    // Must be exactly 10 characters: 3 prefix + 7 digits
    if (strlen($sid) !== 10) {
        $error = 'Student ID must be exactly 10 characters (e.g. DIT2244926).';
        return false;
    }

    $prefix = substr($sid, 0, 3);
    if (!in_array($prefix, $validPrefixes)) {
        $error = 'Invalid Student ID prefix. Only DIT, BIT, BCE, or BCS are accepted.';
        return false;
    }

    // The remaining 7 characters must all be digits
    $rest = substr($sid, 3);
    if (!ctype_digit($rest)) {
        $error = 'The 7 characters after the prefix must all be digits (e.g. DIT2244926).';
        return false;
    }

    // Last 2 digits = graduation year (e.g. DIT2244926 → 26 → 2026)
    $gradYear    = 2000 + (int)substr($rest, -2);
    $currentYear = (int)date('Y');
    if ($gradYear < $currentYear || $gradYear > $currentYear + 6) {
        $error = 'The graduation year in your Student ID (' . $gradYear . ') is not valid. Expected between ' . $currentYear . ' and ' . ($currentYear + 6) . '.';
        return false;
    }

    return true;
}

$loginError = '';
$regError   = '';
$regSuccess = '';
$showTab    = 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login'])) {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    if (!$email || !$password) {
        $loginError = 'Please enter your email address and password.';
    } else {
        // Students now live in `students` table; admin/instructor stay in `users`
        $detectedRole = validateRmuEmail($email);
        $user         = null;
        $resolvedRole = null;

        if ($detectedRole === 'student') {
            // Look up in students table; inject literal role for session use
            $stmt = $pdo->prepare("SELECT *, 'student' AS role FROM students WHERE email = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$email]);
            $user         = $stmt->fetch();
            $resolvedRole = 'student';
        } else {
            // admin / instructor stay in users
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $user         = $stmt->fetch();
            $resolvedRole = $user['role'] ?? null;
        }

        if ($user && password_verify($password, $user['password'])) {
            $roleOk = ($resolvedRole === 'admin') || ($detectedRole === $resolvedRole);
            if (!$roleOk) {
                $loginError = 'Invalid email address for your account type.';
            } elseif ($resolvedRole !== 'admin' && empty($user['email_verified'])) {
                $loginError = 'Please verify your email address before signing in. Check your inbox for the verification link.';
            } else {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email']     = $user['email'];
                $_SESSION['role']      = $resolvedRole;
                header('Location: ' . BASE_URL . '/' . $resolvedRole . '/dashboard.php');
                exit;
            }
        } else {
            $loginError = 'Incorrect email or password. Make sure you are using your official RMU email.';
        }
    }
}

// Check if an admin account already exists
$adminExists = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_register'])) {
    $showTab    = 'register';
    $full_name  = trim($_POST['full_name'] ?? '');
    $email      = strtolower(trim($_POST['reg_email'] ?? ''));
    $password   = $_POST['reg_password'] ?? '';
    $department = 'Department of ICT';
    $programme  = trim($_POST['programme'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $reg_role   = trim($_POST['reg_role'] ?? '');  // 'admin', 'student', or 'instructor'

    if (!$full_name || !$email || !$password) {
        $regError = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $regError = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $regError = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $regError = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $regError = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $regError = 'Password must contain at least one number.';
    } elseif (!preg_match('/[\W_]/', $password)) {
        $regError = 'Password must contain at least one special character (e.g. @, #, !, $).';
    } elseif ($password !== ($_POST['reg_confirm_pw'] ?? '')) {
        $regError = 'Passwords do not match. Please confirm your password.';
    } else {
        // --- ADMIN registration path ---
        if ($reg_role === 'admin') {
            if ($adminExists) {
                $regError = 'An administrator account already exists. Only one admin is allowed.';
            } elseif (!str_ends_with($email, '@rmu.edu.gh') || str_ends_with($email, '@st.rmu.edu.gh')) {
                $regError = 'Admin must use an @rmu.edu.gh email address.';
            } else {
                $chk = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
                $chk->execute([$email]);
                if ($chk->fetch()) {
                    $regError = 'An account with that email already exists.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare('INSERT INTO users (full_name,email,password,role,department) VALUES (?,?,?,?,?)')
                        ->execute([$full_name, $email, $hash, 'admin', $department]);
                    $adminExists = true; // lock it immediately
                    $regSuccess  = 'Administrator account created! You can now sign in.';
                    $showTab     = 'login';
                }
            }
        }
        // --- Student / Instructor registration path ---
        else {
            $detectedRole = validateRmuEmail($email);
            if (!$detectedRole) {
                $regError = 'Invalid email. Use your official RMU email (@rmu.edu.gh for lecturers, @st.rmu.edu.gh for students).';
            } elseif ($detectedRole === 'admin') {
                $regError = 'That email is reserved for administrators.';
            } else {
                // Email uniqueness: students live in `students`, staff in `users`
                $emailTaken = false;
                if ($detectedRole === 'student') {
                    $chk = $pdo->prepare('SELECT id FROM students WHERE email=? LIMIT 1');
                } else {
                    $chk = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
                }
                $chk->execute([$email]);
                $emailTaken = (bool)$chk->fetch();

                if ($emailTaken) {
                    $regError = 'An account with that email already exists.';
                } else {
                    // Validate student ID for students
                    $sidError = '';
                    if ($detectedRole === 'student') {
                        if (!$student_id) {
                            $regError = 'Student ID is required for student registration.';
                            goto skip_reg;
                        }
                        if (!validateStudentId($student_id, $sidError)) {
                            $regError = $sidError;
                            goto skip_reg;
                        }
                        $student_id = strtoupper(trim($student_id));
                        $sidChk = $pdo->prepare('SELECT id FROM students WHERE student_id=? LIMIT 1');
                        $sidChk->execute([$student_id]);
                        if ($sidChk->fetch()) {
                            $regError = 'That Student ID is already registered.';
                            goto skip_reg;
                        }

                        // ── REGISTRATION WINDOW CHECK ───────────────────────
                        try {
                            $rs = $pdo->query('SELECT * FROM reg_settings WHERE id=1 LIMIT 1')->fetch();
                            if ($rs) {
                                $windowOpen  = (bool)$rs['window_open'];
                                $openFrom    = $rs['open_from']  ? strtotime($rs['open_from'])  : null;
                                $openUntil   = $rs['open_until'] ? strtotime($rs['open_until']) : null;
                                $nowTs       = time();
                                $inWindow    = $windowOpen
                                    && ($openFrom  === null || $nowTs >= $openFrom)
                                    && ($openUntil === null || $nowTs <= $openUntil);
                                if (!$inWindow) {
                                    if (!$windowOpen) {
                                        $regError = 'Registration is currently closed. Please contact administration.';
                                    } elseif ($openFrom && $nowTs < $openFrom) {
                                        $regError = 'Registration has not opened yet. It opens on ' . date('F j, Y \a\t g:i A', $openFrom) . '.';
                                    } else {
                                        $regError = 'The registration window has closed. Please contact administration.';
                                    }
                                    goto skip_reg;
                                }
                            }
                        } catch (PDOException $e) { /* table not yet created — allow */ }

                        // ── PRE-APPROVAL CHECK (Student ID + Email) ─────────
                        try {
                            $approvalChk = $pdo->prepare(
                                'SELECT id FROM approved_students WHERE student_id=? AND email=? LIMIT 1'
                            );
                            $approvalChk->execute([$student_id, $email]);
                            if (!$approvalChk->fetch()) {
                                $regError = 'Student not recognized. Please contact administration.';
                                goto skip_reg;
                            }
                        } catch (PDOException $e) {
                            // Table not yet created — fail open
                        }
                    }
                    $hash  = password_hash($password, PASSWORD_DEFAULT);
                    $token = bin2hex(random_bytes(32));

                    if ($detectedRole === 'student') {
                        // Students go into the dedicated `students` table
                        $pdo->prepare('INSERT INTO students (full_name,email,password,department,programme,student_id,email_verified,verify_token) VALUES (?,?,?,?,?,?,0,?)')
                            ->execute([$full_name,$email,$hash,$department,$programme,$student_id,$token]);
                    } else {
                        // Instructors stay in `users`
                        $pdo->prepare('INSERT INTO users (full_name,email,password,role,department,email_verified,verify_token) VALUES (?,?,?,?,?,0,?)')
                            ->execute([$full_name,$email,$hash,$detectedRole,$department,$token]);
                    }
                    // Mark as registered in the approved_students list
                    try {
                        $pdo->prepare('UPDATE approved_students SET is_registered=1 WHERE student_id=? AND email=?')
                            ->execute([$student_id, $email]);
                    } catch (PDOException $e) {}

                    // Send verification email
                    $sent = sendVerificationEmail($email, $full_name, $token);
                    if ($sent) {
                        $regSuccess = '✅ Account created! A verification email has been sent to <strong>' . htmlspecialchars($email) . '</strong>. Please check your inbox (and spam folder) and click the link to activate your account before signing in.';
                    } else {
                        $verifyLink = BASE_URL . '/verify.php?token=' . $token;
                        $regSuccess = '✅ Account created! However, the verification email could not be sent. Please click the link below to verify your account:<br><br>'
                            . '<a href="' . $verifyLink . '" style="display:inline-block;background:#c8a84b;color:#001a3e;font-weight:700;padding:10px 22px;border-radius:8px;text-decoration:none;font-size:13px">✓ Verify My Email</a>'
                            . '<br><br><small style="opacity:.6;word-break:break-all">Or copy: ' . $verifyLink . '</small>';
                        error_log('[RMU Register] Verification email failed for ' . $email . ' | Link: ' . $verifyLink);
                    }
                    $showTab = 'login';
                }
                skip_reg:;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RMU E-Learning Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Raleway:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;font-family:'Raleway',sans-serif;color:#fff;overflow-x:hidden}

/* =============================================
   TECH BLUE + WHITE BACKGROUND BLEND
   ============================================= */
body {
  background:
    radial-gradient(ellipse 90% 60% at 0% 100%,  #001640 0%, transparent 55%),
    radial-gradient(ellipse 70% 50% at 100% 0%,   #002d7a 0%, transparent 55%),
    radial-gradient(ellipse 50% 40% at 50% 50%,   #0050a8 0%, transparent 60%),
    linear-gradient(155deg, #001233 0%, #003080 35%, #0060c0 65%, #1a80d4 85%, #5ab0ff 100%);
  background-attachment: fixed;
  min-height: 100vh;
}

/* White shimmer layer */
.shimmer {
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background:
    radial-gradient(ellipse 50% 30% at 70% 20%, rgba(255,255,255,0.13) 0%, transparent 60%),
    radial-gradient(ellipse 30% 22% at 15% 65%, rgba(180,220,255,0.10) 0%, transparent 55%),
    radial-gradient(ellipse 40% 25% at 85% 80%, rgba(255,255,255,0.07) 0%, transparent 55%);
  animation: shim 9s ease-in-out infinite alternate;
}
@keyframes shim {
  0%  { opacity:.5; transform: scale(1)     translate(0,0);     }
  100%{ opacity:1;  transform: scale(1.07)  translate(-10px,8px); }
}

/* Sweeping light beam across page */
.beam {
  position: fixed; top:-30%; left:-30%;
  width: 55%; height: 160%; z-index: 0; pointer-events: none;
  background: linear-gradient(108deg,
    transparent 38%, rgba(255,255,255,0.04) 46%,
    rgba(255,255,255,0.09) 50%, rgba(255,255,255,0.04) 54%,
    transparent 62%);
  animation: sweep 14s ease-in-out infinite;
}
@keyframes sweep {
  0%  { transform: translateX(-10%)  rotate(0deg);  opacity:.3; }
  50% { transform: translateX(200%)  rotate(4deg);  opacity:1;  }
  100%{ transform: translateX(-10%)  rotate(0deg);  opacity:.3; }
}

/* Floating ocean bubbles */
.bubbles { position:fixed; inset:0; z-index:0; overflow:hidden; pointer-events:none; }
.bub {
  position: absolute; bottom: -80px; border-radius: 50%;
  background: radial-gradient(circle at 33% 33%, rgba(255,255,255,0.32), rgba(255,255,255,0.03));
  border: 1px solid rgba(255,255,255,0.13);
  animation: floatUp linear infinite;
}
@keyframes floatUp {
  0%  { transform: translateY(0)       scale(1);    opacity:0;  }
  6%  { opacity:.65; }
  94% { opacity:.18; }
  100%{ transform: translateY(-115vh)  scale(1.1);  opacity:0;  }
}

/* Animated wave at the bottom */
.waves {
  position: fixed; bottom:0; left:0; right:0; height:115px;
  z-index: 0; pointer-events: none;
}
.waves svg { width:100%; height:100%; }

/* =============================================
   LAYOUT
   ============================================= */
.wrap { position:relative; z-index:1; min-height:100vh; display:flex; flex-direction:column; }

/* HEADER */
.hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 52px;
  background: rgba(0,12,45,0.6);
  backdrop-filter: blur(18px);
  border-bottom: 1px solid rgba(255,255,255,0.12);
  box-shadow: 0 2px 30px rgba(0,0,0,0.35);
}
.hdr-logo { display:flex; align-items:center; gap:14px; }
.hdr-logo img {
  height: 58px;
  filter: drop-shadow(0 0 14px rgba(255,255,255,0.22)) drop-shadow(0 0 5px rgba(0,180,255,0.3));
}
.hdr-name {
  font-family: 'Cinzel', serif; font-size:17px; font-weight:700; line-height:1.3;
  text-shadow: 0 0 20px rgba(100,190,255,0.4);
}
.hdr-tag { font-size:9px; color:#7dd3fc; letter-spacing:3px; text-transform:uppercase; margin-top:3px; }
.hdr-right { font-size:11px; color:rgba(255,255,255,0.42); letter-spacing:.8px; }

/* HERO */
.hero {
  flex:1; display:flex; align-items:center; justify-content:center;
  gap:72px; padding:55px 52px 90px;
}
.hero-l { max-width:500px; animation:fl .9s cubic-bezier(.23,1,.32,1) both; }
@keyframes fl { from{opacity:0;transform:translateX(-35px);} to{opacity:1;transform:translateX(0);} }

.pill {
  display:inline-flex; align-items:center; gap:8px;
  background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.24);
  border-radius:999px; padding:5px 16px;
  font-size:10px; color:#7dd3fc; letter-spacing:2.5px; text-transform:uppercase;
  margin-bottom:22px; backdrop-filter:blur(6px);
  box-shadow:0 0 18px rgba(0,180,255,0.14);
}

h1.ttl {
  font-family: 'Cinzel', serif; font-size:44px; font-weight:700; line-height:1.2; margin-bottom:18px;
  background: linear-gradient(135deg, #ffffff 0%, #bde0ff 45%, #ffffff 75%, #ddf0ff 100%);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
  filter: drop-shadow(0 2px 14px rgba(0,80,200,0.3));
}
.hero-sub { font-size:15px; color:rgba(255,255,255,0.6); line-height:1.85; margin-bottom:38px; }
.stats { display:flex; gap:36px; }
.sn {
  font-family:'Cinzel',serif; font-size:26px; font-weight:700; color:#fff;
  text-shadow:0 0 14px rgba(100,190,255,0.5);
}
.sl { font-size:11px; color:rgba(255,255,255,0.45); text-transform:uppercase; letter-spacing:.8px; margin-top:3px; }

/* AUTH CARD — frosted glass on blue */
.auth {
  width:420px; flex-shrink:0; position:relative;
  background: rgba(255,255,255,0.10);
  border: 1px solid rgba(255,255,255,0.22);
  border-radius: 20px; padding:36px;
  backdrop-filter: blur(28px);
  box-shadow: 0 12px 55px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.06) inset;
  animation: fr .9s cubic-bezier(.23,1,.32,1) both;
}
@keyframes fr { from{opacity:0;transform:translateX(35px);} to{opacity:1;transform:translateX(0);} }
.auth::before {
  content:''; position:absolute; top:0; left:12%; right:12%; height:1px;
  background:linear-gradient(90deg, transparent, rgba(255,255,255,0.38), transparent);
}

.tabs {
  display:flex; background:rgba(0,0,0,0.22);
  border:1px solid rgba(255,255,255,0.1); border-radius:10px; padding:4px; margin-bottom:26px;
}
.tab {
  flex:1; padding:9px; background:transparent; border:none; border-radius:7px;
  color:rgba(255,255,255,0.42);
  font-family:'Raleway',sans-serif; font-weight:700; font-size:13px; letter-spacing:.8px;
  cursor:pointer; transition:all .25s;
}
.tab.on {
  background:linear-gradient(135deg,rgba(255,255,255,0.24),rgba(255,255,255,0.11));
  color:#fff; border:1px solid rgba(255,255,255,0.24);
  box-shadow:0 2px 14px rgba(0,0,0,0.2);
}

.panel{display:none;} .panel.on{display:block;}

.lbl { display:block; font-size:11px; font-weight:700; color:rgba(255,255,255,0.65); text-transform:uppercase; letter-spacing:1px; margin-bottom:7px; }
.iw  { position:relative; margin-bottom:16px; }
.iw > i { position:absolute;left:13px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,0.3);font-size:13px;pointer-events:none;transition:color .2s; }
.iw:focus-within > i { color:#7dd3fc; }

.inp {
  width:100%; padding:11px 14px 11px 38px;
  background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.17);
  border-radius:9px; color:#fff; font-family:'Raleway',sans-serif; font-size:14px; outline:none; transition:all .22s;
}
.inp:focus { border-color:rgba(255,255,255,0.5); background:rgba(255,255,255,0.13); box-shadow:0 0 0 3px rgba(255,255,255,0.07),0 0 18px rgba(0,180,255,0.14); }
.inp::placeholder { color:rgba(255,255,255,0.27); }
.inp.err { border-color:#f87171 !important; }

.fc {
  width:100%; padding:11px 14px;
  background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.17);
  border-radius:9px; color:#fff; font-family:'Raleway',sans-serif; font-size:14px; outline:none; transition:all .22s; margin-bottom:16px;
}
.fc:focus { border-color:rgba(255,255,255,0.5); background:rgba(255,255,255,0.13); box-shadow:0 0 0 3px rgba(255,255,255,0.07); }
.fc::placeholder { color:rgba(255,255,255,0.27); }
select.fc option { background:#003080; color:#fff; }

.r2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

.hint { font-size:11px; margin-top:-10px; margin-bottom:14px; padding:7px 11px; border-radius:7px; display:none; }
.h-stu { background:rgba(0,150,255,0.15); border:1px solid rgba(0,150,255,0.35); color:#93c5fd; }
.h-ins { background:rgba(0,200,120,0.15); border:1px solid rgba(0,200,120,0.35); color:#6ee7b7; }
.h-bad { background:rgba(255,80,80,0.15);  border:1px solid rgba(255,80,80,0.35);  color:#fca5a5; }

/* WHITE submit button — pops beautifully against deep blue */
.subbtn {
  width:100%; padding:13px; margin-top:6px;
  background:linear-gradient(135deg, #ffffff 0%, #ddeeff 100%);
  color:#003080; border:none; border-radius:10px;
  font-family:'Cinzel',serif; font-size:13px; font-weight:700; letter-spacing:1.5px;
  cursor:pointer; transition:all .25s;
  box-shadow:0 4px 24px rgba(0,0,0,0.3), 0 1px 0 rgba(255,255,255,0.7) inset;
}
.subbtn:hover { transform:translateY(-2px); box-shadow:0 8px 34px rgba(0,0,0,0.35),0 0 24px rgba(255,255,255,0.18); background:linear-gradient(135deg,#fff,#c8e8ff); }
.subbtn:active { transform:translateY(0); }

.msg { padding:11px 14px; border-radius:9px; font-size:13px; margin-bottom:16px; display:flex; align-items:flex-start; gap:9px; line-height:1.5; }
.msg-e  { background:rgba(255,80,80,0.13); border:1px solid rgba(255,80,80,0.38); color:#fca5a5; }
.msg-ok { background:rgba(0,200,120,0.13); border:1px solid rgba(0,200,120,0.38); color:#6ee7b7; }

/* FEATURE STRIP */
.feats {
  display:flex; justify-content:center; flex-wrap:wrap; gap:26px; padding:20px 52px;
  background:rgba(0,10,38,0.65); backdrop-filter:blur(12px);
  border-top:1px solid rgba(255,255,255,0.08);
}
.feat { display:flex; align-items:center; gap:9px; font-size:12px; color:rgba(255,255,255,0.46); }
.feat i { color:#7dd3fc; font-size:15px; }

@media(max-width:900px){
  .hdr{padding:13px 18px;} .hdr-right{display:none;}
  .hero{flex-direction:column;padding:28px 18px 70px;gap:28px;}
  .hero-l{text-align:center;} .stats{justify-content:center;}
  h1.ttl{font-size:30px;}
  .auth{width:100%;max-width:420px;padding:24px;}
  .feats{padding:16px;gap:14px;}
}
</style>
</head>
<body>

<div class="shimmer"></div>
<div class="beam"></div>
<div class="bubbles" id="bubs"></div>

<div class="waves">
  <svg viewBox="0 0 1440 115" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M0,55 C360,105 720,5 1080,60 C1260,82 1380,28 1440,55 L1440,115 L0,115 Z" fill="rgba(0,10,38,0.7)"/>
    <path d="M0,80 C280,30 640,105 960,58 C1120,28 1320,72 1440,80 L1440,115 L0,115 Z" fill="rgba(0,6,24,0.45)"/>
  </svg>
</div>

<div class="wrap">

  <header class="hdr">
    <div class="hdr-logo">
      <img src="assets/img/logo.png" alt="RMU Logo">
      <div>
        <div class="hdr-name">Regional Maritime University</div>
        <div class="hdr-tag">E-Learning Portal</div>
      </div>
    </div>
    <div class="hdr-right"><i class="fas fa-globe-africa"></i>&nbsp; Accra, Ghana &nbsp;·&nbsp; Est. 1994</div>
  </header>

  <main class="hero">

    <div class="hero-l">
      <div class="pill"><i class="fas fa-laptop-code"></i> RMU School of Technology</div>
      <h1 class="ttl">Advance Your Tech Education</h1>
      <p class="hero-sub">Access world-class IT, Computer Science and Computer Engineering education from anywhere. Stream lectures, study course materials and advance your career — all in one secure platform built for RMU IT, CS &amp; CE students.</p>
      <div class="stats">
        <div><div class="sn">50+</div><div class="sl">Courses</div></div>
        <div><div class="sn">2,400+</div><div class="sl">Students</div></div>
        <div><div class="sn">80+</div><div class="sl">Lecturers</div></div>
        <div><div class="sn">5</div><div class="sl">Countries</div></div>
      </div>
    </div>

    <div class="auth">
      <div class="tabs">
        <button class="tab <?php echo $showTab==='login'?'on':''; ?>" id="t-l" onclick="sw('login')">Sign In</button>
        <button class="tab <?php echo $showTab==='register'?'on':''; ?>" id="t-r" onclick="sw('reg')">Register</button>
        <button class="tab <?php echo $showTab==='forgot'?'on':''; ?>" id="t-f" onclick="sw('forgot')" style="font-size:11px">Forgot Password</button>
      </div>

      <!-- LOGIN PANEL -->
      <div class="panel <?php echo $showTab==='login'?'on':''; ?>" id="p-l">
        <?php if($loginError): ?><div class="msg msg-e"><i class="fas fa-exclamation-circle"></i><span><?php echo h($loginError); ?></span></div><?php endif; ?>
        <?php if($regSuccess): ?><div class="msg msg-ok"><i class="fas fa-check-circle"></i><span><?php echo $regSuccess; ?></span></div><?php endif; ?>
        <?php if(isset($_GET['err'])&&$_GET['err']==='access'): ?><div class="msg msg-e"><i class="fas fa-lock"></i><span>Access denied. Please sign in.</span></div><?php endif; ?>
        <form method="POST" id="lf" novalidate>
          <label class="lbl">Email Address</label>
          <div class="iw">
            <input class="inp" type="email" name="email" id="le"
                   placeholder="yourname@rmu.edu.gh"
                   value="<?php echo isset($_POST['do_login'])?h($_POST['email'] ?? ''):''; ?>"
                   autocomplete="email" oninput="lc(this.value)" required>
            <i class="fas fa-envelope"></i>
          </div>
          <div class="hint" id="lh"></div>
          <label class="lbl">Password</label>
          <div class="iw" style="position:relative">
            <input class="inp" type="password" name="password" id="login-pw"
                   placeholder="Enter your password" autocomplete="current-password" required>
            <i class="fas fa-lock"></i>
            <span onclick="togglePw('login-pw','eye-login')"
                  style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;color:rgba(255,255,255,0.4);font-size:14px;z-index:2">
              <i class="fas fa-eye" id="eye-login"></i>
            </span>
          </div>
          <button class="subbtn" type="submit" name="do_login"><i class="fas fa-sign-in-alt"></i>&nbsp; SIGN IN</button>
          <div style="text-align:center;margin-top:14px">
            <a href="#" onclick="sw('forgot');return false"
               style="font-size:12px;color:rgba(255,255,255,.45);text-decoration:none;transition:color .2s"
               onmouseover="this.style.color='#1e90ff'" onmouseout="this.style.color='rgba(255,255,255,.45)'">
              <i class="fas fa-key" style="font-size:11px"></i> Forgot your password?
            </a>
          </div>
        </form>
      </div>

      <!-- FORGOT PASSWORD PANEL -->
      <div class="panel <?php echo $showTab==='forgot'?'on':''; ?>" id="p-f">
        <?php if($forgotErr): ?>
        <div class="msg msg-e"><i class="fas fa-exclamation-circle"></i><span><?= h($forgotErr) ?></span></div>
        <?php endif; ?>
        <?php if($forgotMsg): ?>
        <div class="msg msg-ok"><i class="fas fa-check-circle"></i><span><?= $forgotMsg ?></span></div>
        <?php endif; ?>
        <?php if(!$forgotMsg): ?>
        <div style="text-align:center;margin-bottom:20px">
          <div style="width:56px;height:56px;border-radius:50%;background:rgba(30,144,255,.12);border:1px solid rgba(30,144,255,.25);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:22px;color:#63b3ff">
            <i class="fas fa-key"></i>
          </div>
          <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:6px">Reset Your Password</div>
          <div style="font-size:12px;color:rgba(255,255,255,.45);line-height:1.6">Enter your RMU email address and we'll send you a link to reset your password.</div>
        </div>
        <form method="POST">
          <label class="lbl">Email Address</label>
          <div class="iw">
            <input class="inp" type="email" name="forgot_email"
                   placeholder="yourname@rmu.edu.gh"
                   autocomplete="email" required>
            <i class="fas fa-envelope"></i>
          </div>
          <button class="subbtn" type="submit" name="do_forgot">
            <i class="fas fa-paper-plane"></i>&nbsp; Send Reset Link
          </button>
          <div style="text-align:center;margin-top:14px">
            <a href="#" onclick="sw('login');return false"
               style="font-size:12px;color:rgba(255,255,255,.45);text-decoration:none"
               onmouseover="this.style.color='#1e90ff'" onmouseout="this.style.color='rgba(255,255,255,.45)'">
              <i class="fas fa-arrow-left" style="font-size:11px"></i> Back to Sign In
            </a>
          </div>
        </form>
        <?php endif; ?>
      </div>

      <!-- REGISTER PANEL -->
      <div class="panel <?php echo $showTab==='register'?'on':''; ?>" id="p-r">
        <?php if($regError): ?><div class="msg msg-e"><i class="fas fa-exclamation-circle"></i><span><?php echo h($regError); ?></span></div><?php endif; ?>

        <?php if(!$adminExists): ?>
        <!-- ===== STEP 1: No admin yet — show role choice first ===== -->
        <div id="role-choice">
          <p style="color:rgba(255,255,255,.6);font-size:12px;margin-bottom:14px;text-align:center">
            No administrator account exists yet.<br>Who are you registering as?
          </p>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:18px">
            <button type="button" onclick="chooseRole('admin')"
              style="padding:14px 6px;border-radius:10px;border:2px solid rgba(200,168,75,.4);background:rgba(200,168,75,.08);color:#f0cc6a;font-size:11px;font-weight:700;cursor:pointer;transition:all .2s"
              onmouseover="this.style.borderColor='#c8a84b';this.style.background='rgba(200,168,75,.18)'"
              onmouseout="this.style.borderColor='rgba(200,168,75,.4)';this.style.background='rgba(200,168,75,.08)'">
              <i class="fas fa-shield-alt" style="display:block;font-size:22px;margin-bottom:6px"></i>
              Administrator
            </button>
            <button type="button" onclick="chooseRole('instructor')"
              style="padding:14px 6px;border-radius:10px;border:2px solid rgba(39,174,96,.3);background:rgba(39,174,96,.06);color:#6ee7b7;font-size:11px;font-weight:700;cursor:pointer;transition:all .2s"
              onmouseover="this.style.borderColor='#27ae60';this.style.background='rgba(39,174,96,.18)'"
              onmouseout="this.style.borderColor='rgba(39,174,96,.3)';this.style.background='rgba(39,174,96,.06)'">
              <i class="fas fa-chalkboard-teacher" style="display:block;font-size:22px;margin-bottom:6px"></i>
              Lecturer
            </button>
            <button type="button" onclick="chooseRole('student')"
              style="padding:14px 6px;border-radius:10px;border:2px solid rgba(41,128,185,.3);background:rgba(41,128,185,.06);color:#93c5fd;font-size:11px;font-weight:700;cursor:pointer;transition:all .2s"
              onmouseover="this.style.borderColor='#2980b9';this.style.background='rgba(41,128,185,.18)'"
              onmouseout="this.style.borderColor='rgba(41,128,185,.3)';this.style.background='rgba(41,128,185,.06)'">
              <i class="fas fa-user-graduate" style="display:block;font-size:22px;margin-bottom:6px"></i>
              Student
            </button>
          </div>
        </div>
        <?php else: ?>
        <!-- ===== Admin already exists — skip choice, go straight to student/instructor form ===== -->
        <script>document.addEventListener('DOMContentLoaded',function(){ chooseRole('<?= isset($_POST['do_register']) ? h($_POST['reg_role']??'student') : 'student' ?>'); });</script>
        <?php endif; ?>

        <!-- ===== THE ACTUAL FORM (hidden until role chosen) ===== -->
        <form method="POST" novalidate id="reg-form" style="display:none">
          <input type="hidden" name="reg_role" id="reg_role_input" value="">

          <!-- Role badge -->
          <div id="chosen-role-badge" style="margin-bottom:16px;padding:10px 14px;border-radius:8px;font-size:12px;font-weight:700;display:flex;align-items:center;gap:9px"></div>

          <label class="lbl">Full Name *</label>
          <input class="fc" type="text" name="full_name" placeholder="e.g. George Kofi Boateng" required
                 value="<?php echo isset($_POST['do_register'])?h($_POST['full_name'] ?? ''):''; ?>">

          <label class="lbl">Email Address *</label>
          <div class="iw">
            <input class="inp" type="email" name="reg_email" id="re"
                   placeholder="your.name@rmu.edu.gh"
                   value="<?php echo isset($_POST['do_register'])?h($_POST['reg_email'] ?? ''):''; ?>"
                   oninput="rc(this.value)" required>
            <i class="fas fa-envelope"></i>
          </div>
          <div class="hint" id="rh" style="margin-bottom:10px"></div>

          <!-- Student-only fields -->
          <div id="prog-g" style="display:none">
            <div class="r2">
              <div>
                <label class="lbl">Programme</label>
                <select class="fc" name="programme" id="prog-sel" style="margin-bottom:0">
                  <option value="">— Select —</option>
                  <option value="IT" <?= (isset($_POST['do_register'])&&($_POST['programme']??'')==='IT')?'selected':'' ?>>Information Technology (IT)</option>
                  <option value="CS" <?= (isset($_POST['do_register'])&&($_POST['programme']??'')==='CS')?'selected':'' ?>>Computer Science (CS)</option>
                  <option value="CE" <?= (isset($_POST['do_register'])&&($_POST['programme']??'')==='CE')?'selected':'' ?>>Computer Engineering (CE)</option>
                </select>
              </div>
              <div>
                <label class="lbl">Student ID *</label>
                <input class="fc" type="text" name="student_id" id="sid-input"
                       placeholder="e.g. DIT2244926" style="margin-bottom:0;text-transform:uppercase"
                       oninput="validateSid(this.value)"
                       value="<?php echo isset($_POST['do_register'])?h(strtoupper($_POST['student_id'] ?? '')):''; ?>">
                <div id="sid-hint" style="font-size:11px;margin-top:5px"></div>
               
              </div>
            </div>
          </div>

          <label class="lbl" style="margin-top:14px">Password *</label>
          <div class="iw" style="position:relative;margin-bottom:0">
            <input class="inp" type="password" name="reg_password" id="reg-pw"
                   placeholder="Min 8 chars, upper, lower, number, symbol"
                   oninput="checkStrength(this.value)" required>
            <i class="fas fa-lock"></i>
            <span onclick="togglePw('reg-pw','eye-reg')"
                  style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;color:rgba(255,255,255,0.4);font-size:14px;z-index:2">
              <i class="fas fa-eye" id="eye-reg"></i>
            </span>
          </div>
          <!-- Strength meter -->
          <div style="margin-top:8px">
            <div style="display:flex;gap:4px;margin-bottom:5px">
              <div id="sb1" style="flex:1;height:4px;border-radius:2px;background:rgba(255,255,255,0.1);transition:background .3s"></div>
              <div id="sb2" style="flex:1;height:4px;border-radius:2px;background:rgba(255,255,255,0.1);transition:background .3s"></div>
              <div id="sb3" style="flex:1;height:4px;border-radius:2px;background:rgba(255,255,255,0.1);transition:background .3s"></div>
              <div id="sb4" style="flex:1;height:4px;border-radius:2px;background:rgba(255,255,255,0.1);transition:background .3s"></div>
            </div>
            <div id="pw-hint" style="font-size:11px;color:#6e849e"></div>
          </div>
          <!-- Requirements checklist -->
          <div style="margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:3px" id="pw-reqs">
            <div id="req-len"  style="font-size:10px;color:#6e849e"><i class="fas fa-circle" style="font-size:6px;margin-right:4px"></i>8+ characters</div>
            <div id="req-upp"  style="font-size:10px;color:#6e849e"><i class="fas fa-circle" style="font-size:6px;margin-right:4px"></i>Uppercase letter</div>
            <div id="req-low"  style="font-size:10px;color:#6e849e"><i class="fas fa-circle" style="font-size:6px;margin-right:4px"></i>Lowercase letter</div>
            <div id="req-num"  style="font-size:10px;color:#6e849e"><i class="fas fa-circle" style="font-size:6px;margin-right:4px"></i>Number</div>
            <div id="req-sym"  style="font-size:10px;color:#6e849e"><i class="fas fa-circle" style="font-size:6px;margin-right:4px"></i>Special character</div>
          </div>

          <label class="lbl" style="margin-top:14px">Confirm Password *</label>
          <div class="iw" style="position:relative;margin-bottom:0">
            <input class="inp" type="password" name="reg_confirm_pw" id="reg-cpw"
                   placeholder="Re-enter your password"
                   oninput="checkMatch()" required>
            <i class="fas fa-lock"></i>
            <span onclick="togglePw('reg-cpw','eye-cpw')"
                  style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;color:rgba(255,255,255,0.4);font-size:14px;z-index:2">
              <i class="fas fa-eye" id="eye-cpw"></i>
            </span>
          </div>
          <div id="match-hint" style="font-size:11px;margin-top:5px"></div>

          <div style="display:flex;gap:10px;margin-top:20px">
            <?php if(!$adminExists): ?>
            <button type="button" onclick="resetRoleChoice()"
              style="padding:13px 16px;border-radius:10px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);font-size:13px;font-weight:600;cursor:pointer">
              <i class="fas fa-arrow-left"></i>
            </button>
            <?php endif; ?>
            <button class="subbtn" type="submit" name="do_register" style="flex:1;margin-top:0">
              <i class="fas fa-user-plus"></i>&nbsp; CREATE ACCOUNT
            </button>
          </div>
        </form>
      </div>

    </div>
  </main>

  <div class="feats">
    <div class="feat"><i class="fas fa-video"></i> HD Video Lectures</div>
    <div class="feat"><i class="fas fa-file-powerpoint"></i> Slide Downloads</div>
    <div class="feat"><i class="fas fa-chart-line"></i> Progress Tracking</div>
    <div class="feat"><i class="fas fa-certificate"></i> Certificates</div>
    <div class="feat"><i class="fas fa-shield-alt"></i> Secure &amp; Private</div>
    <div class="feat"><i class="fas fa-mobile-alt"></i> Mobile Friendly</div>
      <div class="feat"><i class="fas fa-mobile-alt"></i> Quizzes</div>
  </div>

</div>

<script>
(function(){
  var c=document.getElementById('bubs');
  var sizes=[7,11,15,20,9,13,18,24,6,10,14,8];
  for(var i=0;i<20;i++){
    var b=document.createElement('div');b.className='bub';
    var s=sizes[i%sizes.length]+Math.random()*8;
    b.style.cssText='width:'+s+'px;height:'+s+'px;left:'+(Math.random()*100)+'%;animation-duration:'+(12+Math.random()*18)+'s;animation-delay:'+(Math.random()*14)+'s;';
    c.appendChild(b);
  }
})();

function sw(n){
  document.getElementById('t-l').classList.toggle('on',n==='login');
  document.getElementById('t-r').classList.toggle('on',n==='reg');
  document.getElementById('t-f').classList.toggle('on',n==='forgot');
  document.getElementById('p-l').classList.toggle('on',n==='login');
  document.getElementById('p-r').classList.toggle('on',n==='reg');
  document.getElementById('p-f').classList.toggle('on',n==='forgot');
}

var SD='@st.rmu.edu.gh',ID='@rmu.edu.gh';
function role(e){e=e.toLowerCase().trim();if(!e)return null;if(e.endsWith(SD))return 'student';if(e.endsWith(ID))return 'instructor';return 'invalid';}

function lc(v){
  var h=document.getElementById('lh'),i=document.getElementById('le');
  h.className='hint';i.classList.remove('err');
  if(!v){h.style.display='none';return;}
  // Show shield icon for admin email
  if(v.toLowerCase().trim()==='admin@rmu.edu.gh'){
    h.className='hint h-ins';h.style.display='block';
    h.innerHTML='<i class="fas fa-shield-alt"></i> Administrator account';
    return;
  }
  var r=role(v);
  if(r==='student'){h.className='hint h-stu';h.style.display='block';h.innerHTML='<i class="fas fa-user-graduate"></i> Student account';}
  else if(r==='instructor'){h.className='hint h-ins';h.style.display='block';h.innerHTML='<i class="fas fa-chalkboard-teacher"></i> Lecturer account';}
  else{h.className='hint h-bad';h.style.display='block';h.innerHTML='<i class="fas fa-times-circle"></i> Invalid RMU email address';i.classList.add('err');}
}

// ---- Role selection for registration ----
var chosenRole = null;
var roleConfig = {
  admin:      { icon:'fa-shield-alt',       color:'#f0cc6a', bg:'rgba(200,168,75,.15)',  border:'rgba(200,168,75,.4)',  label:'Administrator',  placeholder:'admin@rmu.edu.gh' },
  instructor: { icon:'fa-chalkboard-teacher',color:'#6ee7b7', bg:'rgba(39,174,96,.12)',   border:'rgba(39,174,96,.35)',  label:'Lecturer',       placeholder:'kwame.mensah@rmu.edu.gh' },
  student:    { icon:'fa-user-graduate',     color:'#93c5fd', bg:'rgba(41,128,185,.12)',  border:'rgba(41,128,185,.35)', label:'Student',        placeholder:'george.boateng@st.rmu.edu.gh' }
};

function chooseRole(r) {
  chosenRole = r;
  var cfg = roleConfig[r];
  document.getElementById('reg_role_input').value = r;

  // Show badge
  var badge = document.getElementById('chosen-role-badge');
  badge.style.cssText = 'margin-bottom:16px;padding:10px 14px;border-radius:8px;font-size:12px;font-weight:700;display:flex;align-items:center;gap:9px;background:'+cfg.bg+';border:1px solid '+cfg.border+';color:'+cfg.color;
  badge.innerHTML = '<i class="fas '+cfg.icon+'"></i> Registering as: '+cfg.label;

  // Update email placeholder
  var emailInp = document.getElementById('re');
  emailInp.placeholder = cfg.placeholder;

  // Show/hide student fields
  var pg = document.getElementById('prog-g');
  if(r === 'student') { pg.style.display='block'; }
  else { pg.style.display='none'; }

  // Hide role choice buttons, show form
  var rc = document.getElementById('role-choice');
  if(rc) rc.style.display = 'none';
  document.getElementById('reg-form').style.display = 'block';

  // Clear hints
  document.getElementById('rh').style.display = 'none';
  emailInp.value = '';
  emailInp.focus();
}

function resetRoleChoice() {
  chosenRole = null;
  document.getElementById('reg-form').style.display = 'none';
  var rc = document.getElementById('role-choice');
  if(rc) rc.style.display = 'block';
}

function rc(v){
  var h=document.getElementById('rh'),i=document.getElementById('re');
  h.className='hint';i.classList.remove('err');
  if(!v){h.style.display='none';return;}
  h.style.display='block';

  if(chosenRole==='admin'){
    var ok = v.toLowerCase().endsWith('@rmu.edu.gh') && !v.toLowerCase().endsWith('@st.rmu.edu.gh');
    if(ok){ h.className='hint h-ins'; h.innerHTML='<i class="fas fa-check-circle"></i> Valid admin email'; }
    else  { h.className='hint h-bad'; h.innerHTML='<i class="fas fa-times-circle"></i> Admin must use @rmu.edu.gh (not @st.)'; i.classList.add('err'); }
    return;
  }
  if(chosenRole==='instructor'){
    var ok = v.toLowerCase().endsWith('@rmu.edu.gh') && !v.toLowerCase().endsWith('@st.rmu.edu.gh');
    if(ok){ h.className='hint h-ins'; h.innerHTML='<i class="fas fa-check-circle"></i> Valid lecturer email'; }
    else  { h.className='hint h-bad'; h.innerHTML='<i class="fas fa-times-circle"></i> Lecturers must use @rmu.edu.gh'; i.classList.add('err'); }
    return;
  }
  if(chosenRole==='student'){
    var ok = v.toLowerCase().endsWith('@st.rmu.edu.gh');
    if(ok){ h.className='hint h-stu'; h.innerHTML='<i class="fas fa-check-circle"></i> Valid student email'; }
    else  { h.className='hint h-bad'; h.innerHTML='<i class="fas fa-times-circle"></i> Students must use @st.rmu.edu.gh'; i.classList.add('err'); }
    return;
  }
}

// On register error reload — restore the form state
<?php if(isset($_POST['do_register']) && $_POST['reg_role']??''): ?>
document.addEventListener('DOMContentLoaded',function(){
  chooseRole('<?= h($_POST['reg_role']??'student') ?>');
});
<?php endif; ?>

document.getElementById('lf').addEventListener('submit',function(e){
  var v=document.getElementById('le').value.toLowerCase().trim();
  if(!v.endsWith('@rmu.edu.gh') && !v.endsWith('@st.rmu.edu.gh')){
    e.preventDefault();
    document.getElementById('lh').className='hint h-bad';
    document.getElementById('lh').style.display='block';
    document.getElementById('lh').innerHTML='<i class="fas fa-times-circle"></i> Invalid RMU email address';
    document.getElementById('le').classList.add('err');
  }
});

// ── Show/hide password toggle ──────────────────────────────────────────────
function togglePw(inputId, iconId) {
  var inp  = document.getElementById(inputId);
  var icon = document.getElementById(iconId);
  if (!inp || !icon) return;
  var show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  icon.classList.remove('fa-eye', 'fa-eye-slash');
  icon.classList.add(show ? 'fa-eye-slash' : 'fa-eye');
  icon.parentElement.style.color = show ? 'rgba(200,168,75,0.7)' : 'rgba(255,255,255,0.4)';
}

// ── Password strength checker ───────────────────────────────────────────────
function checkStrength(pw) {
  var checks = {
    len: pw.length >= 8,
    upp: /[A-Z]/.test(pw),
    low: /[a-z]/.test(pw),
    num: /[0-9]/.test(pw),
    sym: /[\W_]/.test(pw)
  };

  // Update checklist
  ['len','upp','low','num','sym'].forEach(function(k) {
    var el = document.getElementById('req-' + k);
    if (checks[k]) {
      el.style.color = '#6fcf97';
      el.querySelector('i').className = 'fas fa-check-circle';
      el.querySelector('i').style.fontSize = '10px';
    } else {
      el.style.color = '#6e849e';
      el.querySelector('i').className = 'fas fa-circle';
      el.querySelector('i').style.fontSize = '6px';
    }
  });

  // Strength score
  var score = Object.values(checks).filter(Boolean).length;
  var bars  = [document.getElementById('sb1'), document.getElementById('sb2'),
               document.getElementById('sb3'), document.getElementById('sb4')];
  var hint  = document.getElementById('pw-hint');
  var colors = ['#e74c3c','#e67e22','#f1c40f','#27ae60'];
  var labels = ['Weak','Fair','Good','Strong'];

  bars.forEach(function(b, i) {
    b.style.background = i < score - 1 ? colors[Math.min(score-2, 3)] : 'rgba(255,255,255,0.1)';
  });

  if (!pw) { hint.textContent = ''; return; }
  hint.style.color  = colors[Math.min(score-1, 3)];
  hint.textContent  = score <= 2 ? 'Weak — add uppercase, number or symbol'
                    : score === 3 ? 'Almost there — add one more requirement'
                    : score === 4 ? 'Good password'
                    : 'Strong password ✓';

  checkMatch();
}

// ── Confirm password matcher ────────────────────────────────────────────────
function checkMatch() {
  var pw  = document.getElementById('reg-pw').value;
  var cpw = document.getElementById('reg-cpw').value;
  var el  = document.getElementById('match-hint');
  if (!cpw) { el.textContent = ''; return; }
  if (pw === cpw) {
    el.style.color = '#6fcf97';
    el.innerHTML   = '<i class="fas fa-check-circle"></i> Passwords match';
  } else {
    el.style.color = '#ff8a80';
    el.innerHTML   = '<i class="fas fa-times-circle"></i> Passwords do not match';
  }
}

// Student ID live validation
function validateSid(val) {
  val = val.toUpperCase();
  var hint = document.getElementById('sid-hint');
  if (!val) { hint.innerHTML = ''; return; }

  var validPrefixes = ['DIT','BIT','BCE','BCS'];

  // Must be exactly 10 chars
  if (val.length < 3) {
    hint.style.color='#6e849e';
    hint.innerHTML='<i class="fas fa-keyboard"></i> Keep typing...';
    return;
  }

  var prefix = val.substring(0,3);
  if (validPrefixes.indexOf(prefix) === -1) {
    hint.style.color='#ff8a80';
    hint.innerHTML='<i class="fas fa-times-circle"></i> Invalid prefix "' + prefix + '". ';
    return;
  }

  if (val.length < 10) {
    hint.style.color='#f5c842';
    hint.innerHTML='<i class="fas fa-exclamation-circle"></i> ' + prefix + '';
    return;
  }

  if (val.length > 10) {
    hint.style.color='#ff8a80';
    hint.innerHTML='<i class="fas fa-times-circle"></i> Student ID must be exactly 10 characters';
    return;
  }

  var rest = val.substring(3);
  if (!/^\d{7}$/.test(rest)) {
    hint.style.color='#ff8a80';
    hint.innerHTML='<i class="fas fa-times-circle"></i> Characters after ' + prefix + ' must all be digits';
    return;
  }

  // Last 2 digits = graduation year
  var gradYear = 2000 + parseInt(rest.substring(5,7));
  var now = new Date().getFullYear();
  if (gradYear < now || gradYear > now + 6) {
    hint.style.color='#ff8a80';
    hint.innerHTML='<i class="fas fa-times-circle"></i> Graduation year ' + gradYear + ' is not valid (expected ' + now + '–' + (now+6) + ')';
    return;
  }

  hint.style.color='#6fcf97';
  hint.innerHTML='<i class="fas fa-check-circle"></i> Valid ' + prefix + ' Student ID — graduating ' + gradYear;
}
</script>
</body>
</html>
