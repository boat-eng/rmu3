<?php
/**
 * Shared Dashboard Layout
 *
 * Required vars before including:
 *   $pageTitle   string  - page heading
 *   $activePage  string  - nav item to highlight
 *   $depth       int     - folder depth from root (1 = instructor/ or student/)
 *
 * Usage:
 *   ob_start();
 *   // ... your page HTML ...
 *   $pageContent = ob_get_clean();
 *   require_once '../includes/layout.php';
 */

$role  = $_SESSION['role']      ?? '';
$uname = $_SESSION['full_name'] ?? 'User';
$base  = str_repeat('../', $depth ?? 1);

// Fetch profile pic from DB — wrapped in try/catch in case column doesn't exist
$profile_pic = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $pp = $pdo->prepare('SELECT profile_pic FROM users WHERE id=? LIMIT 1');
        $pp->execute([$_SESSION['user_id']]);
        $profile_pic = $pp->fetchColumn() ?: null;
    } catch(Exception $e) {
        $profile_pic = null; // column doesn't exist yet — ignore
    }
}

// Fetch unread notifications
$notifs = [];
$unreadCount = 0;
if (!empty($_SESSION['user_id'])) {
    $sid = (int)$_SESSION['user_id'];
    $role_for_notif = $_SESSION['role'] ?? '';

    if (isset($_GET['mark_notifs_read'])) {
        try {
            if ($role_for_notif === 'student') {
                $pdo->prepare('UPDATE notifications SET is_read=1 WHERE student_id=?')->execute([$sid]);
            } else {
                $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$sid]);
            }
        } catch(Exception $e){}
    }
    try {
        if ($role_for_notif === 'student') {
            $nq = $pdo->prepare('SELECT * FROM notifications WHERE student_id=? ORDER BY created_at DESC LIMIT 15');
        } else {
            $nq = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 15');
        }
        $nq->execute([$sid]);
        $notifs = $nq->fetchAll();
        $unreadCount = count(array_filter($notifs, fn($n) => !$n['is_read']));
    } catch (Exception $e) { /* table may not exist yet */ }
}

// Fetch active announcement
$announcement = null;
try {
    $aq = $pdo->query('SELECT * FROM announcements WHERE is_active=1 ORDER BY created_at DESC LIMIT 1');
    $announcement = $aq->fetch();
} catch (Exception $e) { /* table may not exist yet */ }

// Build nav items
$nav = [];
if ($role === 'admin') {
    $nav = [
        ['dashboard',    'fa-shield-alt',          'Admin Panel',  'dashboard.php'],
        ['courses',      'fa-book',                'Courses',      'courses.php'],
        ['students',     'fa-user-graduate',       'Students',     'students.php'],
        ['instructors',  'fa-chalkboard-teacher',  'Instructors',  'instructors.php'],
        ['announcements','fa-bullhorn',             'Announcements','announcements.php'],
        ['profile',      'fa-user-circle',         'My Profile',   'profile.php'],
    ];
} elseif ($role === 'instructor') {
    $nav = [
        ['dashboard',  'fa-th-large',          'Dashboard',      'dashboard.php'],
        ['courses',    'fa-book',              'My Courses',     'courses.php'],
        ['upload',     'fa-cloud-upload-alt',  'Upload Content', 'upload.php'],
        ['students',   'fa-users',             'Students',       'students.php'],
        ['analytics',  'fa-chart-bar',         'Analytics',      'analytics.php'],
        ['report',     'fa-file-alt',          'My Report',      'report.php'],
        ['profile',    'fa-user-circle',       'My Profile',     'profile.php'],
        ['Disscusions', 'fa-comments', 'Student disscusion', 'qa.php'],
    ];
} else {
    $nav = [
        ['dashboard', 'fa-th-large',       'Dashboard',   'dashboard.php'],
        ['enrolled',  'fa-book-open',      'My Courses',  'enrolled.php'],
        ['browse',    'fa-compass',        'Browse',      'browse.php'],
        ['report',    'fa-file-alt',       'My Report',   'report.php'],
        ['profile',   'fa-user-circle',    'My Profile',  'profile.php'],
        ['quizze',   'fa-file-alt',          'Quizze',    'quizzes.php'],
    ];
}

$initial = strtoupper(mb_substr($uname, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= h($pageTitle) ?> — RMU E-Learning</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Raleway:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --navy:    #001433;
    --dark:    #000d22;
    --surface: #002366;
    --surf2:   #003080;
    --gold:    #ffffff;
    --gold-lt: #e8f0ff;
    --white:   #ffffff;
    --text:    #ffffff;
    --muted:   #b8cce4;
    --border:  rgba(255,255,255,.2);
    --success: #27ae60;
    --danger:  #e74c3c;
    --info:    #4d9fff;
    --sw: 250px;
}
/* ===== LIGHT MODE ===== */
body.light-mode {
    --navy:    #e8f0ff;
    --dark:    #d0dcf5;
    --surface: #ffffff;
    --surf2:   #f0f4ff;
    --white:   #001433;
    --text:    #001a4e;
    --muted:   #3a5a8c;
    --border:  rgba(0,35,102,.2);
}
/* Light mode — override all hardcoded navy colours */
body.light-mode .sidebar           { background:#001433; }
body.light-mode .topbar            { background:#1a3a6b !important; border-bottom:1px solid rgba(255,255,255,.15) !important; }
body.light-mode .main              { background:#e8f0ff !important; }
body.light-mode .page              { background:#e8f0ff !important; }
body.light-mode .card              { background:#ffffff !important; border:1px solid rgba(0,35,102,.15) !important; }
body.light-mode .stat-card         { background:#ffffff !important; border:1px solid rgba(0,35,102,.15) !important; }
body.light-mode .c-card            { background:#ffffff !important; border:1px solid rgba(0,35,102,.15) !important; }
body.light-mode .modal-box         { background:#ffffff !important; border:1px solid rgba(0,35,102,.2) !important; }
body.light-mode .notif-dropdown    { background:#ffffff !important; border:1px solid rgba(0,35,102,.15) !important; }
body.light-mode .fc                { background:#f0f4ff !important; color:#001433 !important; border:1px solid rgba(0,35,102,.2) !important; }
body.light-mode .fc::placeholder   { color:rgba(0,20,51,.35) !important; }
body.light-mode .icon-btn          { background:rgba(0,35,102,.08) !important; border:1px solid rgba(0,35,102,.2) !important; color:#001433 !important; }
body.light-mode .btn-secondary     { background:rgba(0,35,102,.06) !important; color:#001433 !important; border:1px solid rgba(0,35,102,.2) !important; }
body.light-mode .btn-secondary:hover { background:rgba(0,35,102,.12) !important; }
body.light-mode .btn-primary       { background:#001433 !important; color:#ffffff !important; }
body.light-mode tbody td           { color:#001433 !important; border-bottom:1px solid rgba(0,35,102,.08) !important; }
body.light-mode thead th           { color:#001433 !important; background:rgba(0,35,102,.05) !important; border-bottom:1px solid rgba(0,35,102,.15) !important; }
body.light-mode .card-title        { color:#001433 !important; }
body.light-mode .stat-val          { color:#001433 !important; }
body.light-mode .stat-lbl          { color:#3a5a8c !important; }
body.light-mode .c-title           { color:#001433 !important; }
body.light-mode .c-meta            { color:#3a5a8c !important; }
body.light-mode .topbar-title      { color:#ffffff !important; }
body.light-mode .topbar-sub        { color:rgba(255,255,255,.7) !important; }
body.light-mode .nav-a             { color:rgba(255,255,255,.7) !important; }
body.light-mode .nav-a:hover       { color:#ffffff !important; background:rgba(255,255,255,.12) !important; }
body.light-mode .nav-a.active      { color:#ffffff !important; background:rgba(255,255,255,.18) !important; }
body.light-mode .l-name            { color:#001433 !important; }
body.light-mode .l-sub             { color:#3a5a8c !important; }
body.light-mode .badge.bg-muted    { background:rgba(0,35,102,.08) !important; color:#3a5a8c !important; }
body.light-mode .alert-ok          { background:rgba(39,174,96,.1) !important; color:#1a5c36 !important; }
body.light-mode .alert-err         { background:rgba(231,76,60,.1) !important; color:#8b1a1a !important; }
body.light-mode .c-foot            { border-top:1px solid rgba(0,35,102,.1) !important; }
body.light-mode .pbar              { background:rgba(0,35,102,.1) !important; }
body.light-mode .pfill             { background:linear-gradient(90deg,#001433,#1a3a6b) !important; }

/* Light mode — ALL text black */
body.light-mode,
body.light-mode p,
body.light-mode span,
body.light-mode div,
body.light-mode h1,
body.light-mode h2,
body.light-mode h3,
body.light-mode h4,
body.light-mode h5,
body.light-mode label,
body.light-mode .lbl2,
body.light-mode .card-title,
body.light-mode .stat-val,
body.light-mode .stat-lbl,
body.light-mode .c-title,
body.light-mode .c-meta,
body.light-mode .c-foot,
body.light-mode .l-name,
body.light-mode .l-sub,
body.light-mode .l-ico,
body.light-mode .topbar-sub,
body.light-mode tbody td,
body.light-mode .notif-msg,
body.light-mode .notif-time,
body.light-mode .modal-head h3,
body.light-mode .modal-body,
body.light-mode .sb-uname,
body.light-mode .sb-role,
body.light-mode .uprog-text,
body.light-mode small,
body.light-mode strong   { color: #000000 !important; }

/* Keep white text where background stays dark */
body.light-mode .sidebar *,
body.light-mode .topbar-title,
body.light-mode .nav-a,
body.light-mode .nav-a:hover,
body.light-mode .nav-a.active,
body.light-mode .sb-foot a,
body.light-mode .btn-primary       { color: #ffffff !important; }

/* Muted text — dark grey not black */
body.light-mode .notif-time,
body.light-mode .stat-lbl,
body.light-mode .c-meta,
body.light-mode .l-sub             { color: #444444 !important; }
/* Notification dropdown */
.notif-wrap { position:relative; }
.notif-dropdown {
    display:none; position:absolute; right:0; top:44px;
    width:320px; background:#002366; border:1px solid rgba(255,255,255,.2);
    border-radius:12px; box-shadow:0 12px 40px rgba(0,0,0,.6);
    z-index:999; overflow:hidden;
}
.notif-dropdown.open { display:block; }
.notif-hd {
    padding:14px 18px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
}
.notif-hd span { font-family:'Cinzel',serif; font-size:13px; color:var(--white); }
.notif-hd a    { font-size:11px; color:var(--gold); text-decoration:none; }
.notif-list { max-height:320px; overflow-y:auto; }
.notif-item {
    padding:12px 18px; border-bottom:1px solid rgba(255,255,255,.04);
    display:flex; gap:12px; align-items:flex-start; text-decoration:none;
    transition:background .2s;
}
.notif-item:hover { background:rgba(255,255,255,.07); }
.notif-item.unread { background:rgba(255,255,255,.05); }
.notif-ico {
    width:34px; height:34px; border-radius:8px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:13px;
}
.notif-msg  { font-size:12px; color:var(--text); line-height:1.5; }
.notif-time { font-size:10px; color:var(--muted); margin-top:3px; }
.notif-badge {
    position:absolute; top:-5px; right:-5px;
    min-width:17px; height:17px; border-radius:999px;
    background:var(--danger); color:#fff;
    font-size:10px; font-weight:700;
    display:flex; align-items:center; justify-content:center;
    padding:0 4px;
}
/* Announcement banner */
.announce-bar {
    padding:10px 28px; font-size:13px;
    display:flex; align-items:center; gap:10px;
    border-bottom:1px solid var(--border);
}
.announce-bar.type-info    { background:rgba(41,128,185,.12); color:#64b5f6; }
.announce-bar.type-warning { background:rgba(243,156,18,.12);  color:#f5c842; }
.announce-bar.type-success { background:rgba(39,174,96,.12);   color:#6fcf97; }
.announce-bar.type-danger  { background:rgba(231,76,60,.12);   color:#ff8a80; }
/* Star rating */
.star-row { display:flex; gap:6px; margin:10px 0; }
.star-row i { font-size:26px; color:var(--muted); cursor:pointer; transition:color .15s; }
.star-row i.lit { color:var(--gold); }
html, body { height: 100%; font-family: 'Raleway', sans-serif; background: #001433; color: #ffffff; }

/* ===== SIDEBAR ===== */
.sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    width: var(--sw);
    background: var(--dark);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    z-index: 200;
    overflow-y: auto;
}
.sb-logo {
    display: flex; align-items: center; justify-content: center;
    padding: 16px 14px;
    border-bottom: 1px solid var(--border);
}
.sb-logo img {
    width: 100%;
    max-width: 210px;
    height: auto;
    filter: drop-shadow(0 2px 8px rgba(0,0,0,0.4)) brightness(1.05);
    display: block;
}
.sb-logo-name { font-family:'Cinzel',serif; font-size:12px; font-weight:700; line-height:1.35; }
.sb-logo-sub  { font-size:9px; color:rgba(255,255,255,.7); letter-spacing:2px; text-transform:uppercase; margin-top:2px; }

.sb-user {
    display: flex; align-items: center; gap: 11px;
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
}
.avatar {
    width: 40px; height: 40px; border-radius: 50%;
    background: linear-gradient(135deg,#ffffff,#a0b4d0);
    display: flex; align-items: center; justify-content: center;
    font-family:'Cinzel',serif; font-size:16px; font-weight:700; color:#001433;
    flex-shrink: 0;
}
.sb-uname { font-size:13px; font-weight:700; color:#ffffff; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.sb-role  { font-size:10px; color:var(--gold); text-transform:uppercase; letter-spacing:1px; margin-top:2px; }

.sb-nav { flex:1; padding: 12px 10px; }
.nav-label { font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:2px; padding: 10px 8px 5px; }
.nav-a {
    display: flex; align-items: center; gap: 11px;
    padding: 10px 10px;
    border-radius: 8px;
    color: #b8cce4;
    text-decoration: none;
    font-size: 13px; font-weight: 600;
    transition: all .2s;
    margin-bottom: 2px;
    position: relative;
}
.nav-a i { width: 18px; text-align: center; font-size: 14px; }
.nav-a:hover { background: rgba(255,255,255,.12); color: #ffffff; }
.nav-a.active { background: rgba(255,255,255,.15); color: #ffffff; }
.nav-a.active::before {
    content:''; position:absolute; left:0; top:20%; bottom:20%;
    width:3px; border-radius:0 3px 3px 0;
    background: #ffffff;
}

.sb-foot {
    padding: 12px 10px;
    border-top: 1px solid var(--border);
}
.sb-foot a {
    display:flex; align-items:center; gap:10px;
    padding:10px; border-radius:8px;
    color:var(--muted); text-decoration:none;
    font-size:13px; font-weight:600;
    transition: all .2s;
}
.sb-foot a:hover { color: var(--danger); background: rgba(231,76,60,.1); }

/* ===== MAIN ===== */
.main {
    margin-left: var(--sw);
    min-height: 100vh;
    display: flex; flex-direction: column;
    background: #001433;
}

.topbar {
    position: sticky; top:0; z-index:100;
    background: #002366;
    border-bottom: 1px solid rgba(255,255,255,.15);
    padding: 14px 28px;
    display: flex; align-items: center; justify-content: space-between;
}
.topbar-title { font-family:'Cinzel',serif; font-size:17px; font-weight:700; color:#ffffff; }
.topbar-sub   { font-size:11px; color:#b8cce4; margin-top:2px; }
.topbar-btns  { display:flex; gap:10px; align-items:center; }
.icon-btn {
    width:36px; height:36px; border-radius:8px;
    background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2);
    display:flex; align-items:center; justify-content:center;
    color:#ffffff; text-decoration:none;
    transition:all .2s; cursor:pointer;
}
.icon-btn:hover { color:var(--gold); border-color:var(--gold); }

.page { padding: 28px; flex:1; background: #001433; }

/* ===== COMPONENTS ===== */
/* Stats */
.stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:18px; margin-bottom:26px; }
.stat-card {
    background:#002366; border:1px solid rgba(255,255,255,.15); border-radius:12px;
    padding:20px 22px; display:flex; align-items:center; gap:14px;
    transition:transform .2s; position:relative; overflow:hidden;
}
.stat-card:hover { transform:translateY(-2px); }
.stat-card::after {
    content:''; position:absolute; right:-10px; top:-10px;
    width:70px; height:70px; border-radius:50%;
    background:var(--sc,#ffffff); opacity:.07;
}
.stat-ico {
    width:46px; height:46px; border-radius:11px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:19px;
}
.stat-val { font-family:'Cinzel',serif; font-size:26px; font-weight:700; color:#ffffff; line-height:1; }
.stat-lbl { font-size:11px; color:#b8cce4; margin-top:4px; text-transform:uppercase; letter-spacing:.5px; }

/* Card */
.card {
    background:#002366; border:1px solid rgba(255,255,255,.15);
    border-radius:12px; padding:22px;
}
.card + .card { margin-top:20px; }
.card-hd { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
.card-title { font-family:'Cinzel',serif; font-size:14px; color:#ffffff; font-weight:700; }

/* Table */
.tbl-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; }
thead th {
    padding:11px 14px; text-align:left;
    font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:1px;
    color:var(--gold); border-bottom:1px solid var(--border);
    background:rgba(255,255,255,.04);
}
tbody td {
    padding:13px 14px; font-size:13px; color:#ffffff;
    border-bottom:1px solid rgba(255,255,255,.06); vertical-align:middle;
}
tbody tr:hover { background:rgba(255,255,255,.02); }

/* Buttons */
.btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 18px; border-radius:8px;
    font-family:'Raleway',sans-serif; font-size:13px; font-weight:700;
    cursor:pointer; transition:all .2s; text-decoration:none; border:none;
    letter-spacing:.4px;
}
.btn-primary { background:linear-gradient(135deg,var(--gold),#e8f0ff); color:var(--navy); }
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 18px rgba(255,255,255,.38); }
.btn-secondary { background:rgba(255,255,255,.08); color:#ffffff; border:1px solid rgba(255,255,255,.25); }
.btn-secondary:hover { border-color:#ffffff; color:#ffffff; background:rgba(255,255,255,.15); }
.btn-danger { background:rgba(231,76,60,.13); color:#ff8a80; border:1px solid rgba(231,76,60,.3); }
.btn-danger:hover { background:rgba(231,76,60,.25); }
.btn-sm { padding:6px 13px; font-size:12px; }

/* Badges */
.badge {
    display:inline-flex; align-items:center; gap:4px;
    padding:3px 9px; border-radius:999px;
    font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px;
}
.bg-gold    { background:rgba(255,255,255,.15); color:var(--gold);    border:1px solid rgba(255,255,255,.3); }
.bg-green   { background:rgba(39,174,96,.15);  color:#4caf82;        border:1px solid rgba(39,174,96,.3); }
.bg-blue    { background:rgba(41,128,185,.15); color:#64b5f6;        border:1px solid rgba(41,128,185,.3); }
.bg-muted   { background:rgba(255,255,255,.05);color:var(--muted);   border:1px solid rgba(255,255,255,.1); }
.bg-red     { background:rgba(231,76,60,.15);  color:#ff8a80;        border:1px solid rgba(231,76,60,.3); }

/* Alerts */
.alert {
    padding:12px 16px; border-radius:9px; font-size:13px;
    margin-bottom:18px; display:flex; align-items:center; gap:9px;
}
.alert-ok  { background:rgba(39,174,96,.12); border:1px solid rgba(39,174,96,.35); color:#6fcf97; }
.alert-err { background:rgba(231,76,60,.12); border:1px solid rgba(231,76,60,.35); color:#ff8a80; }

/* Forms */
.fg { margin-bottom:18px; }
.lbl2 { display:block; font-size:11px; font-weight:700; color:var(--gold); text-transform:uppercase; letter-spacing:1px; margin-bottom:7px; }
.fc {
    width:100%; padding:11px 14px;
    background:var(--surf2); border:1px solid rgba(255,255,255,.1);
    border-radius:8px; color:var(--text);
    font-family:'Raleway',sans-serif; font-size:14px;
    outline:none; transition:border-color .2s,box-shadow .2s;
}
.fc:focus { border-color:var(--gold); box-shadow:0 0 0 3px rgba(255,255,255,.1); }
.fc::placeholder { color:rgba(255,255,255,.4); }
select.fc option { background:#0c1c35; }
textarea.fc { resize:vertical; min-height:90px; }

/* Upload zone */
.drop-zone {
    border:2px dashed rgba(255,255,255,.3); border-radius:11px;
    padding:36px 20px; text-align:center; cursor:pointer;
    background:rgba(255,255,255,.03); transition:all .3s;
}
.drop-zone:hover, .drop-zone.over {
    border-color:var(--gold); background:rgba(255,255,255,.08);
}
.drop-zone .dz-icon { font-size:38px; color:var(--gold); margin-bottom:10px; }
.drop-zone h4 { font-size:14px; color:var(--white); margin-bottom:5px; }
.drop-zone p  { font-size:12px; color:var(--muted); }

/* Upload progress */
.uprog { display:none; margin-top:14px; }
.uprog.show { display:block; }
.uprog-track { height:8px; background:rgba(255,255,255,.08); border-radius:4px; overflow:hidden; margin-bottom:7px; }
.uprog-fill  { height:100%; background:linear-gradient(90deg,var(--gold),#e8f0ff); border-radius:4px; transition:width .3s; width:0; }
.uprog-text  { font-size:12px; color:var(--muted); text-align:center; }

/* Course cards */
.course-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:18px; }
.c-card {
    background:#002366; border:1px solid rgba(255,255,255,.15); border-radius:12px;
    overflow:hidden; transition:all .2s; cursor:pointer;
}
.c-card:hover { transform:translateY(-3px); border-color:#ffffff; box-shadow:0 8px 30px rgba(255,255,255,.2); }
.c-thumb {
    height:130px;
    background:linear-gradient(135deg,#0d2a4e,#0a1a30);
    display:flex; align-items:center; justify-content:center; font-size:52px;
}
.c-body { padding:14px; }
.c-title { font-size:14px; font-weight:700; color:#ffffff; margin-bottom:5px; line-height:1.4; }
.c-meta  { font-size:12px; color:#b8cce4; line-height:1.7; }
.c-foot  { padding:11px 14px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }

/* Progress bar */
.pbar  { height:5px; background:rgba(255,255,255,.08); border-radius:3px; overflow:hidden; margin-top:7px; }
.pfill { height:100%; background:linear-gradient(90deg,var(--gold),#e8f0ff); border-radius:3px; transition:width .5s; }

/* Lesson list */
.l-item {
    display:flex; align-items:center; gap:12px;
    padding:12px 14px; border-radius:9px; cursor:pointer;
    transition:all .2s; border:1px solid transparent; margin-bottom:5px;
}
.l-item:hover, .l-item.act { background:rgba(255,255,255,.1); border-color:rgba(255,255,255,.25); }
.l-ico {
    width:38px; height:38px; border-radius:9px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    background:rgba(255,255,255,.1); color:var(--gold); font-size:15px;
}
.l-ico.done { background:rgba(39,174,96,.15); color:#4caf82; }
.l-name { font-size:13px; font-weight:600; color:var(--text); }
.l-sub  { font-size:11px; color:var(--muted); margin-top:2px; }

/* Modal */
.modal-wrap {
    position:fixed; inset:0; background:rgba(0,0,0,.72); backdrop-filter:blur(5px);
    z-index:500; display:none; align-items:center; justify-content:center; padding:16px;
}
.modal-wrap.open { display:flex; }
.modal-box {
    background:#002366; border:1px solid rgba(255,255,255,.2); border-radius:14px;
    width:100%; max-width:520px; max-height:90vh; overflow-y:auto;
}
.modal-head { padding:22px 24px 0; display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
.modal-head h3 { font-family:'Cinzel',serif; font-size:15px; color:var(--white); }
.modal-close { width:30px; height:30px; background:var(--surf2); border:none; border-radius:7px; color:var(--muted); cursor:pointer; font-size:15px; display:flex; align-items:center; justify-content:center; transition:all .2s; }
.modal-close:hover { color:var(--danger); }
.modal-body { padding:0 24px 24px; }

/* Video player */
.vid-wrap { background:#000; border-radius:11px; overflow:hidden; aspect-ratio:16/9; }
.vid-wrap video { width:100%; height:100%; display:block; }

/* Grids */
.g2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.g3 { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; }
.flex { display:flex; align-items:center; }
.flex-between { display:flex; align-items:center; justify-content:space-between; }
.gap { gap:10px; }
.mb1 { margin-bottom:10px; }
.mb2 { margin-bottom:18px; }
.mb3 { margin-bottom:26px; }

@media(max-width:768px){
    .sidebar { transform:translateX(-100%); transition:transform .28s cubic-bezier(.4,0,.2,1); }
    .sidebar.open { transform:translateX(0); }
    .main { margin-left:0; }
    .g2,.g3 { grid-template-columns:1fr; }
    .stat-grid { grid-template-columns:1fr 1fr; }
    .page { padding:16px; }
    .hamburger { display:flex !important; }
    .topbar { padding:12px 16px; }
}
/* Hamburger button — hidden on desktop */
.hamburger {
    display: none;
    align-items: center; justify-content: center;
    width: 36px; height: 36px; border-radius: 8px;
    background: var(--surf2); border: 1px solid var(--border);
    color: var(--muted); cursor: pointer;
    flex-shrink: 0; transition: all .2s;
}
.hamburger:hover { color: var(--gold); border-color: var(--gold); }
.hamburger i { font-size: 16px; }
/* Sidebar overlay */
.sidebar-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.55);
    backdrop-filter: blur(2px);
    z-index: 199;
}
</style>
</head>
<body>

<!-- Mobile sidebar overlay -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sb-logo">
        <?php
        $logoFile = dirname(__DIR__) . '/assets/img/logo.png';
        if (file_exists($logoFile)): ?>
            <img src="<?= $base ?>assets/img/logo.png" alt="Regional Maritime University">
        <?php else: ?>
            <svg width="42" height="42" viewBox="0 0 42 42" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;filter:drop-shadow(0 0 8px rgba(255,255,255,.4))">
              <circle cx="21" cy="21" r="20" fill="#001a3e" stroke="#ffffff" stroke-width="1.5"/>
              <path d="M21 8 L28 14 L28 22 L21 18 L14 22 L14 14 Z" fill="#ffffff"/>
              <path d="M14 22 L21 18 L28 22 L28 30 L21 34 L14 30 Z" fill="none" stroke="#ffffff" stroke-width="1.2"/>
              <circle cx="21" cy="21" r="2.5" fill="#e8f0ff"/>
            </svg>
            <div>
                <div class="sb-logo-name">Regional Maritime<br>University</div>
                <div class="sb-logo-sub">E-Learning</div>
            </div>
        <?php endif; ?>
    </div>
    <div class="sb-user">
        <div class="avatar" style="<?= $profile_pic ? 'padding:0;overflow:hidden;background:none;' : '' ?>">
            <?php if($profile_pic): ?>
                <img src="<?= $base ?>uploads/avatars/<?= h($profile_pic) ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
            <?php else: ?>
                <?= $initial ?>
            <?php endif; ?>
        </div>
        <div style="min-width:0">
            <div class="sb-uname"><?= h($uname) ?></div>
            <div class="sb-role">
                <i class="fas fa-<?= $role==='instructor' ? 'chalkboard-teacher' : ($role==='admin' ? 'shield-alt' : 'user-graduate') ?>"></i>
                <?= $role==='admin' ? 'Administrator' : ucfirst($role) ?>
            </div>
        </div>
    </div>
    <nav class="sb-nav">
        <div class="nav-label"><?= ucfirst($role) ?></div>
        <?php foreach ($nav as [$key, $icon, $label, $file]): ?>
        <a href="<?= $base . $role . '/' . $file ?>" class="nav-a <?= ($activePage ?? '') === $key ? 'active' : '' ?>">
            <i class="fas <?= $icon ?>"></i> <?= $label ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="sb-foot">
        <a href="<?= $base ?>logout.php"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
    </div>
</aside>

<!-- MAIN -->
<div class="main">
    <div class="topbar">
        <div style="display:flex;align-items:center;gap:12px">
            <!-- Hamburger — visible on mobile only -->
            <button class="hamburger" id="hamburger-btn" onclick="toggleSidebar()" title="Menu">
                <i class="fas fa-bars" id="hamburger-icon"></i>
            </button>
            <div>
                <div class="topbar-title"><?= h($pageTitle) ?></div>
                <?php if (!empty($pageSubtitle)): ?>
                <div class="topbar-sub"><?= $pageSubtitle ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="topbar-btns">
            <!-- Dark / Light mode toggle -->
            <button class="icon-btn" id="theme-toggle" title="Toggle Light/Dark Mode" onclick="toggleTheme()" style="border:none">
                <i class="fas fa-sun" id="theme-icon"></i>
            </button>
            <!-- Notification Bell -->
            <div class="notif-wrap">
                <button class="icon-btn" id="notif-btn" title="Notifications" onclick="toggleNotifs(event)" style="border:none;position:relative">
                    <i class="fas fa-bell"></i>
                    <?php if($unreadCount>0): ?>
                    <span class="notif-badge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notif-dropdown">
                    <div class="notif-hd">
                        <span>Notifications</span>
                        <?php if($unreadCount>0): ?>
                        <a href="?mark_notifs_read=1">Mark all read</a>
                        <?php endif; ?>
                    </div>
                    <div class="notif-list">
                        <?php if(empty($notifs)): ?>
                        <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">
                            <i class="fas fa-bell-slash" style="display:block;font-size:28px;margin-bottom:8px"></i>
                            No notifications yet
                        </div>
                        <?php else: foreach($notifs as $n):
                            $nType  = $n['type'] ?? '';
                            $nIcon  = match($nType) {
                                'course_complete' => 'fa-trophy',
                                'quiz_marked'     => 'fa-pen-to-square',
                                'new_lesson'      => 'fa-play-circle',
                                'quiz_published'  => 'fa-clipboard-list',
                                default           => 'fa-bell',
                            };
                            $nColor = match($nType) {
                                'course_complete' => 'rgba(255,255,255,.2);color:var(--gold)',
                                'quiz_marked'     => 'rgba(142,68,173,.2);color:#ce93d8',
                                'new_lesson'      => 'rgba(41,128,185,.2);color:#64b5f6',
                                'quiz_published'  => 'rgba(39,174,96,.2);color:#4caf82',
                                default           => 'rgba(255,255,255,.08);color:var(--muted)',
                            };
                            $nTime  = date('M j, g:ia', strtotime($n['created_at']));
                        ?>
                        <a href="<?= h($n['link'] ?: '#') ?>" class="notif-item <?= $n['is_read']?'':'unread' ?>">
                            <div class="notif-ico" style="background:<?= $nColor ?>">
                                <i class="fas <?= $nIcon ?>"></i>
                            </div>
                            <div>
                                <div class="notif-msg"><?= h($n['message']) ?></div>
                                <div class="notif-time"><?= $nTime ?></div>
                            </div>
                        </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php if($announcement): ?>
    <div class="announce-bar type-<?= h($announcement['type']) ?>">
        <i class="fas fa-<?= $announcement['type']==='warning'?'exclamation-triangle':($announcement['type']==='danger'?'exclamation-circle':($announcement['type']==='success'?'check-circle':'info-circle')) ?>"></i>
        <strong><?= h($announcement['title']) ?></strong>
        <?php if($announcement['body']): ?>&mdash; <?= h($announcement['body']) ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="page">

<?= $pageContent ?? '' ?>

    </div><!-- /page -->
</div><!-- /main -->

<script>
function openModal(id){ document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id){ document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
document.querySelectorAll('.modal-wrap').forEach(function(w){
    w.addEventListener('click', function(e){ if(e.target===this) closeModal(this.id); });
});
function fmtBytes(b){
    if(b>=1073741824) return (b/1073741824).toFixed(1)+' GB';
    if(b>=1048576)    return (b/1048576).toFixed(1)+' MB';
    return (b/1024).toFixed(0)+' KB';
}
</script>

<script>
// ===== MOBILE SIDEBAR =====
function toggleSidebar(){
    var sb  = document.getElementById('sidebar');
    var ov  = document.getElementById('sidebar-overlay');
    var ic  = document.getElementById('hamburger-icon');
    var isOpen = sb.classList.toggle('open');
    ov.style.display = isOpen ? 'block' : 'none';
    ic.className = isOpen ? 'fas fa-times' : 'fas fa-bars';
    document.body.style.overflow = isOpen ? 'hidden' : '';
}
function closeSidebar(){
    var sb = document.getElementById('sidebar');
    var ov = document.getElementById('sidebar-overlay');
    var ic = document.getElementById('hamburger-icon');
    sb.classList.remove('open');
    ov.style.display = 'none';
    ic.className = 'fas fa-bars';
    document.body.style.overflow = '';
}
// Close sidebar when a nav link is tapped on mobile
document.querySelectorAll('.nav-a, .sb-foot a').forEach(function(a){
    a.addEventListener('click', function(){
        if(window.innerWidth <= 768) closeSidebar();
    });
});
// Close on resize to desktop
window.addEventListener('resize', function(){
    if(window.innerWidth > 768) closeSidebar();
});
(function(){
    var saved = localStorage.getItem('rmu_theme') || 'dark';
    var ic = document.getElementById('theme-icon');
    if(saved === 'light') {
        document.body.classList.add('light-mode');
        if(ic){ ic.className = 'fas fa-moon'; }
    } else {
        document.body.classList.remove('light-mode');
        if(ic){ ic.className = 'fas fa-sun'; }
    }
})();
function toggleTheme(){
    var isLight = document.body.classList.toggle('light-mode');
    localStorage.setItem('rmu_theme', isLight ? 'light' : 'dark');
    var ic = document.getElementById('theme-icon');
    if(ic){
        // Dark mode (default) = sun icon, Light mode = moon icon
        ic.className = isLight ? 'fas fa-moon' : 'fas fa-sun';
    }
}

// ===== NOTIFICATION BELL =====
function toggleNotifs(e){
    e.stopPropagation();
    document.getElementById('notif-dropdown').classList.toggle('open');
}
document.addEventListener('click', function(e){
    var dd = document.getElementById('notif-dropdown');
    if(dd && !dd.contains(e.target) && e.target.id !== 'notif-btn'){
        dd.classList.remove('open');
    }
});
</script>
</body>
</html>