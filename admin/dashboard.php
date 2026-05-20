<?php
require_once '../includes/config.php';
requireRole('admin');

$uid = (int)$_SESSION['user_id'];

// Quiz overview stats for admin
$quizOverview = [];
try {
    $qzOv = $pdo->prepare('
        SELECT qz.title AS quiz_title, c.title AS course_title,
               u.full_name AS instructor,
               COUNT(qa.id) AS total_attempts,
               SUM(qa.passed) AS passed,
               ROUND(AVG(qa.score),1) AS avg_score
        FROM quizzes qz
        JOIN courses c ON c.id = qz.course_id
        JOIN users u ON u.id = qz.created_by
        LEFT JOIN quiz_attempts qa ON qa.quiz_id = qz.id
        GROUP BY qz.id
        ORDER BY total_attempts DESC
        LIMIT 10
    ');
    $qzOv->execute();
    $quizOverview = $qzOv->fetchAll();
} catch(Exception $e) {}
$msg = '';
$err = '';

// ---- SCHEMA MIGRATION (runs first, before any DML) ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_instructors (
        course_id     INT NOT NULL,
        instructor_id INT NOT NULL,
        PRIMARY KEY (course_id, instructor_id)
    )");
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $checks = [
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='course_instructors' AND COLUMN_NAME='is_primary'"  => "ALTER TABLE course_instructors ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0",
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='course_instructors' AND COLUMN_NAME='added_at'"    => "ALTER TABLE course_instructors ADD COLUMN added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='lessons'            AND COLUMN_NAME='uploaded_by'" => "ALTER TABLE lessons ADD COLUMN uploaded_by INT NULL",
    ];
    foreach ($checks as $sel => $ddl) {
        $st = $pdo->prepare($sel); $st->execute([$dbName]);
        if (!(int)$st->fetchColumn()) $pdo->exec($ddl);
    }
} catch(Exception $e){}

// ---- CREATE COURSE ----
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_create_course'])) {
    $title        = trim($_POST['c_title']       ?? '');
    $code         = strtoupper(trim($_POST['c_code'] ?? '')) ?: null;
    $desc         = trim($_POST['c_desc']        ?? '');
    $level        = in_array($_POST['c_level']??'',['100','200','300','400']) ? $_POST['c_level'] : '100';
    $instructors_sel = array_map('intval', (array)($_POST['c_instructors'] ?? []));
    $primary      = $instructors_sel[0] ?? 0;

    if ($title && $primary) {
        if ($code) {
            $codeChk = $pdo->prepare('SELECT id FROM courses WHERE course_code=? LIMIT 1');
            $codeChk->execute([$code]);
            if ($codeChk->fetch()) { $err = 'That course code is already in use.'; goto skip_create; }
        }
        $pdo->prepare('INSERT INTO courses (instructor_id,title,course_code,description,level,is_published) VALUES (?,?,?,?,?,1)')
            ->execute([$primary,$title,$code,$desc,$level]);
        $newCid = (int)$pdo->lastInsertId();
        $pdo->prepare('DELETE FROM course_instructors WHERE course_id=?')->execute([$newCid]);
        foreach ($instructors_sel as $idx => $iid) {
            $isPrimary = ($idx === 0) ? 1 : 0;
            $pdo->prepare('INSERT IGNORE INTO course_instructors (course_id,instructor_id,is_primary) VALUES (?,?,?)')->execute([$newCid,$iid,$isPrimary]);
        }
        $msg = 'Course created successfully.';
    } else {
        $err = 'Course title and at least one instructor are required.';
    }
    skip_create:;
}

// ---- EDIT COURSE ----
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_edit_course'])) {
    $ecid         = (int)($_POST['ec_id'] ?? 0);
    $title        = trim($_POST['ec_title']       ?? '');
    $code         = strtoupper(trim($_POST['ec_code'] ?? '')) ?: null;
    $desc         = trim($_POST['ec_desc']        ?? '');
    $level        = in_array($_POST['ec_level']??'',['100','200','300','400']) ? $_POST['ec_level'] : '100';
    $instructors_sel = array_map('intval', (array)($_POST['ec_instructors'] ?? []));
    $primary      = $instructors_sel[0] ?? 0;

    if ($ecid && $title && $primary) {
        if ($code) {
            $codeChk = $pdo->prepare('SELECT id FROM courses WHERE course_code=? AND id!=? LIMIT 1');
            $codeChk->execute([$code, $ecid]);
            if ($codeChk->fetch()) { $err = 'That course code is already in use.'; goto skip_edit; }
        }
        $pdo->prepare('UPDATE courses SET instructor_id=?,title=?,course_code=?,description=?,level=? WHERE id=?')
            ->execute([$primary,$title,$code,$desc,$level,$ecid]);
        $pdo->prepare('DELETE FROM course_instructors WHERE course_id=?')->execute([$ecid]);
        foreach ($instructors_sel as $idx => $iid) {
            $isPrimary = ($idx === 0) ? 1 : 0;
            $pdo->prepare('INSERT IGNORE INTO course_instructors (course_id,instructor_id,is_primary) VALUES (?,?,?)')->execute([$ecid,$iid,$isPrimary]);
        }
        $msg = 'Course updated successfully.';
    } else {
        $err = 'Course title and at least one instructor are required.';
    }
    skip_edit:;
}

// ---- DELETE COURSE ----
if (isset($_GET['del_course']) && is_numeric($_GET['del_course'])) {
    $cid = (int)$_GET['del_course'];
    $lRows = $pdo->prepare('SELECT file_path FROM lessons WHERE course_id=?');
    $lRows->execute([$cid]);
    foreach ($lRows->fetchAll() as $lr) {
        $fp = dirname(__DIR__) . DIRECTORY_SEPARATOR . $lr['file_path'];
        if (file_exists($fp)) unlink($fp);
    }
    $pdo->prepare('DELETE FROM course_instructors WHERE course_id=?')->execute([$cid]);
    $pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$cid]);
    header('Location: dashboard.php?deleted=1'); exit;
}

// ---- TOGGLE PUBLISH ----
if (isset($_GET['toggle_pub']) && is_numeric($_GET['toggle_pub'])) {
    $cid = (int)$_GET['toggle_pub'];
    $pdo->prepare('UPDATE courses SET is_published=NOT is_published WHERE id=?')->execute([$cid]);
    // Get new status to show correct toast
    $newStatus = $pdo->prepare('SELECT is_published FROM courses WHERE id=? LIMIT 1');
    $newStatus->execute([$cid]);
    $newStatus = (int)($newStatus->fetchColumn());
    header('Location: dashboard.php?section=courses&pub_updated=1&pub_status=' . $newStatus); exit;
}

// ---- CREATE USER ----
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_create_user'])) {
    $full_name  = trim($_POST['u_name']     ?? '');
    $email      = strtolower(trim($_POST['u_email']    ?? ''));
    $role_new   = in_array($_POST['u_role']??'',['student','instructor']) ? $_POST['u_role'] : 'student';
    $programme  = trim($_POST['u_programme']?? '');
    $student_id = trim($_POST['u_sid']      ?? '');

    if (!$full_name || !$email) {
        $err = 'Full name and email are required.';
    } else {
        // Check both tables for duplicate email
        $chkUsers = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $chkUsers->execute([$email]);
        $chkStudents = $pdo->prepare('SELECT id FROM students WHERE email=? LIMIT 1');
        $chkStudents->execute([$email]);

        if ($chkUsers->fetch() || $chkStudents->fetch()) {
            $err = 'An account with that email already exists.';
        } else {
            $tempHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

            if ($role_new === 'student') {
                // Students go into the students table
                $pdo->prepare('INSERT INTO students
                    (full_name, email, password, department, programme, student_id, email_verified)
                    VALUES (?,?,?,?,?,?,1)')
                    ->execute([$full_name, $email, $tempHash,
                               'Information Technology Department',
                               $programme ?: null, $student_id ?: null]);
            } else {
                // Instructors and admins go into users table (no programme/student_id)
                $pdo->prepare('INSERT INTO users
                    (full_name, email, password, role, department, email_verified)
                    VALUES (?,?,?,?,?,1)')
                    ->execute([$full_name, $email, $tempHash, $role_new,
                               'Information Technology Department']);
            }

            $msg = ucfirst($role_new) . ' account created successfully.';
            $redirectTab = ($role_new === 'instructor') ? 'instructors' : 'students';
            header('Location: dashboard.php?tab='.$redirectTab.'&created=1'); exit;
        }
    }
}

// ---- DELETE USER ----
if (isset($_GET['del_user']) && is_numeric($_GET['del_user'])) {
    $did = (int)$_GET['del_user'];
    if ($did !== $uid) {
        // Try students table first, then users (instructors)
        $isStudent = $pdo->prepare('SELECT id FROM students WHERE id=? LIMIT 1');
        $isStudent->execute([$did]);
        if ($isStudent->fetch()) {
            $pdo->prepare('DELETE FROM students WHERE id=?')->execute([$did]);
        } else {
            $pdo->prepare('DELETE FROM users WHERE id=? AND role != "admin"')->execute([$did]);
        }
    }
    $tab = $_GET['from_tab'] ?? 'students';
    header('Location: dashboard.php?tab='.$tab.'&user_deleted=1'); exit;
}

// ---- TOGGLE USER ACTIVE ----
if (isset($_GET['toggle_user']) && is_numeric($_GET['toggle_user'])) {
    $tid = (int)$_GET['toggle_user'];
    $isStudent = $pdo->prepare('SELECT id FROM students WHERE id=? LIMIT 1');
    $isStudent->execute([$tid]);
    if ($isStudent->fetch()) {
        $pdo->prepare('UPDATE students SET is_active=NOT is_active WHERE id=?')->execute([$tid]);
    } else {
        $pdo->prepare('UPDATE users SET is_active=NOT is_active WHERE id=? AND role != "admin"')->execute([$tid]);
    }
    $tab = $_GET['from_tab'] ?? 'students';
    header('Location: dashboard.php?tab='.$tab); exit;
}

// ---- DATA LOAD ----

/**
 * Returns true if an instructor has access to a course
 * (either as primary instructor OR co-instructor).
 */
function instructor_can_access_course(PDO $pdo, int $instructorId, int $courseId): bool {
    $st = $pdo->prepare(
        "SELECT 1 FROM courses WHERE id=? AND instructor_id=?
         UNION
         SELECT 1 FROM course_instructors WHERE course_id=? AND instructor_id=?
         LIMIT 1"
    );
    $st->execute([$courseId, $instructorId, $courseId, $instructorId]);
    return (bool)$st->fetchColumn();
}

$instructors = $pdo->query("SELECT id,full_name,email FROM users WHERE role='instructor' AND is_active=1 ORDER BY full_name")->fetchAll();

$allCourses = $pdo->query("
    SELECT c.*,u.full_name AS instructor_name,
           COUNT(DISTINCT e.student_id) AS n_students,
           COUNT(DISTINCT l.id)         AS n_lessons
    FROM courses c
    JOIN users u ON u.id=c.instructor_id
    LEFT JOIN enrollments e ON e.course_id=c.id
    LEFT JOIN lessons l ON l.course_id=c.id
    GROUP BY c.id ORDER BY c.created_at DESC
")->fetchAll();

$ciMap = [];
try {
    $ciRows = $pdo->query("
        SELECT ci.course_id, ci.is_primary, u.id, u.full_name, u.email
        FROM course_instructors ci
        JOIN users u ON u.id = ci.instructor_id
        ORDER BY ci.is_primary DESC, u.full_name
    ")->fetchAll();
    foreach ($ciRows as $ci) { $ciMap[$ci['course_id']][] = $ci; }
} catch(Exception $e){}

$allStudents    = $pdo->query("SELECT * FROM students ORDER BY full_name")->fetchAll();
$allInstructors = $pdo->query("SELECT * FROM users WHERE role='instructor' ORDER BY full_name")->fetchAll();

$stats = [
    'courses'     => $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
    'students'    => $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'instructors' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='instructor'")->fetchColumn(),
    'lessons'     => $pdo->query('SELECT COUNT(*) FROM lessons')->fetchColumn(),
];

// Determine active section from URL: 'courses' | 'students' | 'instructors'
$activeSection = $_GET['section'] ?? 'courses';
$userTab = ($_GET['tab'] ?? 'students'); // for backwards compat inside users section

$pageTitle    = 'Admin Panel';
$pageSubtitle = 'Platform Management';
$activePage   = 'dashboard';
$depth        = 1;

ob_start();
?>

<style>
/* ── Sidebar Navigation Pills ── */
.admin-sidenav {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 0 0 24px 0;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--border);
}
.admin-sidenav-label {
    font-size: 9px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 2px;
    padding: 0 4px 6px;
}
.admin-snav-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 14px;
    border-radius: 9px;
    border: 1px solid transparent;
    background: transparent;
    color: var(--muted);
    font-family: 'Raleway', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s;
    text-align: left;
    width: 100%;
    position: relative;
}
.admin-snav-btn i.nav-icon { width: 18px; text-align: center; font-size: 15px; }
.admin-snav-btn:hover { background: rgba(200,168,75,.08); color: var(--white); }
.admin-snav-btn.active {
    background: rgba(200,168,75,.12);
    color: var(--gold);
    border-color: rgba(200,168,75,.25);
}
.admin-snav-btn.active::before {
    content: '';
    position: absolute;
    left: 0; top: 20%; bottom: 20%;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: var(--gold);
}
.admin-snav-badge {
    margin-left: auto;
    background: rgba(200,168,75,.15);
    color: var(--gold);
    border: 1px solid rgba(200,168,75,.3);
    font-size: 9px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 999px;
}
.admin-snav-btn.students-btn.active { color: #64b5f6; background: rgba(41,128,185,.12); border-color: rgba(41,128,185,.25); }
.admin-snav-btn.students-btn.active::before { background: #64b5f6; }
.admin-snav-btn.students-btn .admin-snav-badge { background: rgba(41,128,185,.15); color: #64b5f6; border-color: rgba(41,128,185,.3); }
.admin-snav-btn.instructors-btn.active { color: #4caf82; background: rgba(39,174,96,.12); border-color: rgba(39,174,96,.25); }
.admin-snav-btn.instructors-btn.active::before { background: #4caf82; }
.admin-snav-btn.instructors-btn .admin-snav-badge { background: rgba(39,174,96,.15); color: #4caf82; border-color: rgba(39,174,96,.3); }

/* ── Two-column layout ── */
.admin-layout {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 24px;
    align-items: start;
}
.admin-sidebar-panel {
    position: sticky;
    top: 80px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px 14px;
}
.admin-content-panel { min-width: 0; }

/* ── Section heading with add button ── */
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}
.section-header-left { display: flex; align-items: center; gap: 10px; }
.section-title-main {
    font-family: 'Cinzel', serif;
    font-size: 16px;
    color: var(--white);
}
.section-subtitle { font-size: 12px; color: var(--muted); margin-top: 2px; }

/* ── User cards (grid view for students/instructors) ── */
.user-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 16px;
}
.user-card {
    background: var(--surf2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    transition: border-color .2s, transform .2s;
}
.user-card:hover { border-color: rgba(200,168,75,.3); transform: translateY(-2px); }
.user-card-top { display: flex; align-items: center; gap: 12px; }
.user-card-avatar {
    width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Cinzel', serif; font-size: 18px; font-weight: 700;
}
.user-card-name { font-size: 14px; font-weight: 700; color: var(--white); }
.user-card-email { font-size: 11px; color: var(--muted); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.user-card-meta { display: flex; flex-wrap: wrap; gap: 6px; }
.user-card-actions { display: flex; gap: 8px; margin-top: auto; }
.user-card-actions .btn { flex: 1; justify-content: center; }

/* ── Search bar ── */
.search-bar-wrap {
    position: relative;
    margin-bottom: 18px;
}
.search-bar-wrap i {
    position: absolute;
    left: 12px; top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: 13px;
}
.search-bar-wrap input {
    width: 100%;
    padding: 9px 12px 9px 36px;
    background: var(--surf2);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-family: 'Raleway', sans-serif;
    font-size: 13px;
    outline: none;
    transition: border-color .2s;
}
.search-bar-wrap input:focus { border-color: var(--gold); }
.search-bar-wrap input::placeholder { color: var(--muted); }

/* ── Co-instructor chips ── */
.ci-chips { display:flex; flex-wrap:wrap; gap:5px; margin-top:4px; }
.ci-chip {
    font-size:10px; background:rgba(200,168,75,.12); color:var(--gold);
    border:1px solid rgba(200,168,75,.3); border-radius:20px;
    padding:2px 9px; display:flex; align-items:center; gap:4px;
}

/* ── Multi-select instructor box ── */
.ins-multi-wrap {
    border:1px solid var(--border); border-radius:9px; overflow:hidden;
    background:var(--surface); max-height:180px; overflow-y:auto;
}
.ins-multi-item {
    display:flex; align-items:center; gap:10px; padding:9px 12px;
    cursor:pointer; border-bottom:1px solid var(--border);
    transition:background .15s;
}
.ins-multi-item:last-child { border-bottom:none; }
.ins-multi-item:hover { background:rgba(200,168,75,.06); }
.ins-multi-item input[type=checkbox] { accent-color:var(--gold); width:15px; height:15px; cursor:pointer; }
.ins-multi-item label { cursor:pointer; font-size:13px; color:var(--text); flex:1; }
.ins-multi-item .ins-email { font-size:11px; color:var(--muted); }
.ins-note { font-size:11px; color:var(--muted); margin-top:5px; }

/* ── Empty state ── */
.empty-state {
    text-align: center;
    padding: 48px 20px;
    color: var(--muted);
}
.empty-state i { font-size: 40px; display: block; margin-bottom: 12px; opacity: .5; }
.empty-state p { font-size: 13px; }

@media(max-width: 900px) {
    .admin-layout { grid-template-columns: 1fr; }
    .admin-sidebar-panel { position: static; }
}
</style>

<!-- ── Toast container ── -->
<div id="toast-container" style="position:fixed;top:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none"></div>

<style>
.toast {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px 18px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    font-family: 'Raleway', sans-serif;
    min-width: 280px;
    max-width: 400px;
    pointer-events: all;
    box-shadow: 0 8px 32px rgba(0,0,0,.45);
    animation: toastIn .3s cubic-bezier(.34,1.56,.64,1) forwards;
}
.toast.hide {
    animation: toastOut .4s ease forwards;
}
.toast-ok  { background:#0d2e1a; border:1px solid rgba(39,174,96,.4); color:#6fcf97; }
.toast-err { background:#2e0d0d; border:1px solid rgba(231,76,60,.4); color:#ff8a80; }
.toast i   { font-size:15px; flex-shrink:0; }
.toast-progress {
    position: absolute;
    bottom: 0; left: 0;
    height: 3px;
    border-radius: 0 0 10px 10px;
    animation: toastProgress 2s linear forwards;
}
.toast-ok  .toast-progress { background: #27ae60; }
.toast-err .toast-progress { background: #e74c3c; }
@keyframes toastIn {
    from { opacity:0; transform:translateX(60px) scale(.92); }
    to   { opacity:1; transform:translateX(0)    scale(1);   }
}
@keyframes toastOut {
    from { opacity:1; transform:translateX(0)    scale(1);   max-height:80px; margin-bottom:0; }
    to   { opacity:0; transform:translateX(60px) scale(.92); max-height:0;    margin-bottom:-10px; }
}
@keyframes toastProgress {
    from { width: 100%; }
    to   { width: 0%; }
}
</style>

<script>
function showToast(message, type) {
    type = type || 'ok';
    var container = document.getElementById('toast-container');
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.style.position = 'relative';
    toast.style.overflow = 'hidden';
    toast.innerHTML =
        '<i class="fas fa-' + (type === 'ok' ? 'check-circle' : 'exclamation-circle') + '"></i>' +
        '<span style="flex:1">' + message + '</span>' +
        '<div class="toast-progress"></div>';
    container.appendChild(toast);
    // Auto dismiss after 2s
    setTimeout(function() {
        toast.classList.add('hide');
        setTimeout(function() {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 400);
    }, 2000);
}

// Fire toasts for PHP-generated messages on page load
window.addEventListener('DOMContentLoaded', function() {
    <?php if ($msg): ?>
    showToast(<?= json_encode(h($msg)) ?>, 'ok');
    <?php endif; ?>
    <?php if ($err): ?>
    showToast(<?= json_encode(h($err)) ?>, 'err');
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
    showToast('Course deleted successfully.', 'ok');
    <?php endif; ?>
    <?php if (isset($_GET['user_deleted'])): ?>
    showToast('User removed successfully.', 'ok');
    <?php endif; ?>
    <?php if (isset($_GET['created'])): ?>
    showToast('Account created successfully.', 'ok');
    <?php endif; ?>
    <?php if (isset($_GET['pub_updated'])): ?>
    var pubStatus = <?= (int)($_GET['pub_status'] ?? 1) ?>;
    showToast(pubStatus ? 'Course published successfully.' : 'Course unpublished successfully.', 'ok');
    <?php endif; ?>
});
</script>

<!-- Stats -->
<div class="stat-grid" style="margin-bottom:28px">
  <div class="stat-card" style="--sc:var(--gold)">
    <div class="stat-ico" style="background:rgba(200,168,75,.15);color:var(--gold)"><i class="fas fa-book"></i></div>
    <div><div class="stat-val"><?= $stats['courses'] ?></div><div class="stat-lbl">Total Courses</div></div>
  </div>
  <div class="stat-card" style="--sc:#2980b9">
    <div class="stat-ico" style="background:rgba(41,128,185,.15);color:#64b5f6"><i class="fas fa-user-graduate"></i></div>
    <div><div class="stat-val"><?= $stats['students'] ?></div><div class="stat-lbl">Students</div></div>
  </div>
  <div class="stat-card" style="--sc:#27ae60">
    <div class="stat-ico" style="background:rgba(39,174,96,.15);color:#4caf82"><i class="fas fa-chalkboard-teacher"></i></div>
    <div><div class="stat-val"><?= $stats['instructors'] ?></div><div class="stat-lbl">Instructors</div></div>
  </div>
  <div class="stat-card" style="--sc:#8e44ad">
    <div class="stat-ico" style="background:rgba(142,68,173,.15);color:#ce93d8"><i class="fas fa-film"></i></div>
    <div><div class="stat-val"><?= $stats['lessons'] ?></div><div class="stat-lbl">Lessons</div></div>
  </div>
</div>

<!-- ===== TWO-COLUMN ADMIN LAYOUT ===== -->
<div class="admin-layout">

  <!-- LEFT SIDEBAR NAVIGATION -->
  <div class="admin-sidebar-panel">
    <div class="admin-sidenav">
      <div class="admin-sidenav-label">Manage</div>

      <button class="admin-snav-btn <?= $activeSection==='courses'?'active':'' ?>"
              onclick="showSection('courses')">
        <i class="fas fa-book nav-icon"></i>
        Courses
        <span class="admin-snav-badge"><?= $stats['courses'] ?></span>
      </button>

      <button class="admin-snav-btn students-btn <?= $activeSection==='students'?'active':'' ?>"
              onclick="showSection('students')">
        <i class="fas fa-user-graduate nav-icon"></i>
        Students
        <span class="admin-snav-badge"><?= $stats['students'] ?></span>
      </button>

      <button class="admin-snav-btn instructors-btn <?= $activeSection==='instructors'?'active':'' ?>"
              onclick="showSection('instructors')">
        <i class="fas fa-chalkboard-teacher nav-icon"></i>
        Instructors
        <span class="admin-snav-badge"><?= $stats['instructors'] ?></span>
      </button>
    </div>

    <!-- Quick actions -->
    <div style="display:flex;flex-direction:column;gap:8px">
      <div style="font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:2px;padding:0 4px 6px">Quick Add</div>
      <button class="btn btn-primary btn-sm" style="justify-content:center" onclick="openModal('m-course')">
        <i class="fas fa-plus"></i> New Course
      </button>
      <button class="btn btn-secondary btn-sm" style="justify-content:center" onclick="setUserRole('student');openModal('m-user')">
        <i class="fas fa-user-graduate"></i> Add Student
      </button>
      <button class="btn btn-secondary btn-sm" style="justify-content:center" onclick="setUserRole('instructor');openModal('m-user')">
        <i class="fas fa-chalkboard-teacher"></i> Add Instructor
      </button>
    </div>
  </div>

  <!-- RIGHT CONTENT PANEL -->
  <div class="admin-content-panel">

    <!-- ===== COURSES SECTION ===== -->
    <div id="section-courses" class="admin-section" style="display:<?= $activeSection==='courses'?'block':'none' ?>">
      <div class="section-header">
        <div class="section-header-left">
          <i class="fas fa-book" style="color:var(--gold);font-size:18px"></i>
          <div>
            <div class="section-title-main">Courses</div>
            <div class="section-subtitle"><?= $stats['courses'] ?> course<?= $stats['courses']!=1?'s':'' ?> total</div>
          </div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="openModal('m-course')">
          <i class="fas fa-plus"></i> Add Course
        </button>
      </div>

      <div class="search-bar-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="course-search" placeholder="Search courses by title or code…" oninput="filterCourses()">
      </div>

      <div class="card" style="padding:0">
        <div class="tbl-wrap">
        <table>
          <thead><tr><th>Code</th><th>Title</th><th>Instructors</th><th>Level</th><th>Students</th><th>Lessons</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody id="courses-tbody">
          <?php if(empty($allCourses)): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:30px">No courses yet.</td></tr>
          <?php else: ?>
          <?php foreach($allCourses as $c): ?>
          <tr class="course-row" data-search="<?= strtolower(h($c['title']).' '.h($c['course_code']??'')) ?>"><?php $coIns_unused=null; // handled inline ?>
            <td>
              <?php if(!empty($c['course_code'])): ?>
                <span class="badge bg-gold" style="font-family:monospace;font-size:11px;letter-spacing:.5px"><?= h($c['course_code']) ?></span>
              <?php else: ?>
                <span style="color:var(--muted);font-size:11px">—</span>
              <?php endif; ?>
            </td>
            <td>
              <strong style="color:var(--white)"><?= h($c['title']) ?></strong>
              <?php if($c['description']): ?>
              <div style="font-size:11px;color:var(--muted)"><?= h(mb_substr($c['description'],0,55)) ?>…</div>
              <?php endif; ?>
            </td>
            <td style="font-size:12px">
              <?php
                $coIns = $ciMap[$c['id']] ?? [];
                $shownIds = [];
                foreach($coIns as $ci):
                    $shownIds[] = $ci['id'];
                    $isPrimary = $ci['is_primary'] || $ci['id'] == $c['instructor_id'];
              ?>
                <div style="display:flex;align-items:center;gap:5px;margin-bottom:3px">
                  <span style="font-weight:<?= $isPrimary?'700':'500' ?>;color:<?= $isPrimary?'var(--white)':'var(--text)' ?>"><?= h($ci['full_name']) ?></span>
                  <?php if($isPrimary): ?>
                  <span style="font-size:9px;background:rgba(200,168,75,.15);color:var(--gold);border:1px solid rgba(200,168,75,.3);border-radius:20px;padding:1px 6px;font-weight:700">PRIMARY</span>
                  <?php else: ?>
                  <span style="font-size:9px;background:rgba(100,181,246,.1);color:#64b5f6;border:1px solid rgba(100,181,246,.2);border-radius:20px;padding:1px 6px">CO-INSTRUCTOR</span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php
                // Fallback: show primary instructor if not in course_instructors yet
                if(empty($coIns)):
              ?>
                <div style="font-weight:600;color:var(--white)"><?= h($c['instructor_name']) ?> <span style="font-size:9px;background:rgba(200,168,75,.15);color:var(--gold);border:1px solid rgba(200,168,75,.3);border-radius:20px;padding:1px 6px;font-weight:700">PRIMARY</span></div>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-blue">Level <?= h($c['level']) ?></span></td>
            <td><?= $c['n_students'] ?></td>
            <td><?= $c['n_lessons'] ?></td>
            <td>
              <span class="badge <?= $c['is_published'] ? 'bg-green' : 'bg-muted' ?>">
                <?= $c['is_published'] ? 'Published' : 'Draft' ?>
              </span>
            </td>
            <td>
              <div class="flex gap">
                <button class="btn btn-secondary btn-sm" title="Edit Course"
                  onclick="openEditCourse(<?= $c['id'] ?>,<?= htmlspecialchars(json_encode($c['title']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['course_code']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['description']??''),ENT_QUOTES) ?>,'<?= h($c['level']) ?>',<?= htmlspecialchars(json_encode(array_column($ciMap[$c['id']] ?? [['id'=>$c['instructor_id']]],'id')),ENT_QUOTES) ?>)">
                  <i class="fas fa-edit"></i>
                </button>
                <a href="dashboard.php?toggle_pub=<?= $c['id'] ?>" 
                   class="btn btn-sm <?= $c['is_published'] ? 'btn-danger' : 'btn-primary' ?>" 
                   title="<?= $c['is_published'] ? 'Unpublish course' : 'Publish course' ?>"
                   style="<?= $c['is_published'] ? 'background:rgba(231,76,60,.13);color:#ff8a80;border:1px solid rgba(231,76,60,.3);' : '' ?>">
                  <i class="fas fa-<?= $c['is_published'] ? 'eye-slash' : 'eye' ?>"></i>
                  <?= $c['is_published'] ? 'Unpublish' : 'Publish' ?>
                </a>
                <a href="dashboard.php?del_course=<?= $c['id'] ?>" class="btn btn-danger btn-sm"
                   onclick="return confirm('Delete this course and ALL its lessons? This cannot be undone.')" title="Delete">
                  <i class="fas fa-trash"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div><!-- /section-courses -->


    <!-- ===== STUDENTS SECTION ===== -->
    <div id="section-students" class="admin-section" style="display:<?= $activeSection==='students'?'block':'none' ?>">
      <div class="section-header">
        <div class="section-header-left">
          <i class="fas fa-user-graduate" style="color:#64b5f6;font-size:18px"></i>
          <div>
            <div class="section-title-main">Students</div>
            <div class="section-subtitle"><?= count($allStudents) ?> registered student<?= count($allStudents)!=1?'s':'' ?></div>
          </div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="setUserRole('student');openModal('m-user')">
          <i class="fas fa-user-plus"></i> Add Student
        </button>
      </div>

      <div class="search-bar-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="student-search" placeholder="Search students by name, email or ID…" oninput="filterStudents()">
      </div>

      <?php if(empty($allStudents)): ?>
      <div class="empty-state">
        <i class="fas fa-user-graduate"></i>
        <p>No students registered yet.</p>

      </div>
      <?php else: ?>
      <div class="user-grid" id="student-grid">
        <?php foreach($allStudents as $u): ?>
        <div class="user-card" data-search="<?= strtolower(h($u['full_name']).' '.h($u['email']).' '.h($u['student_id']??'')) ?>">
          <div class="user-card-top">
            <div class="user-card-avatar" style="background:linear-gradient(135deg,#0d47a1,#1565c0);color:#fff">
              <?= strtoupper(mb_substr($u['full_name'],0,1)) ?>
            </div>
            <div style="min-width:0;flex:1">
              <div class="user-card-name"><?= h($u['full_name']) ?></div>
              <div class="user-card-email"><?= h($u['email']) ?></div>
            </div>
          </div>
          <div class="user-card-meta">
            <?php if($u['programme']): ?><span class="badge bg-blue" style="font-size:10px"><?= h($u['programme']) ?></span><?php endif; ?>
            <?php if($u['student_id']): ?><span class="badge bg-gold" style="font-size:10px;font-family:monospace"><?= h($u['student_id']) ?></span><?php endif; ?>
            <span class="badge <?= $u['is_active'] ? 'bg-green' : 'bg-red' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span>
          </div>
          <div style="font-size:11px;color:var(--muted)">
            <i class="fas fa-calendar"></i> Joined <?= date('M d, Y', strtotime($u['created_at'])) ?>
          </div>
          <div class="user-card-actions">
            <a href="dashboard.php?toggle_user=<?= $u['id'] ?>&from_tab=students&section=students" class="btn btn-secondary btn-sm" title="<?= $u['is_active']?'Disable':'Enable' ?>">
              <i class="fas fa-<?= $u['is_active']?'ban':'check' ?>"></i> <?= $u['is_active']?'Disable':'Enable' ?>
            </a>
            <a href="dashboard.php?del_user=<?= $u['id'] ?>&from_tab=students&section=students"
               class="btn btn-danger btn-sm"
               onclick="return confirm('Delete this student permanently?')">
              <i class="fas fa-trash"></i>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div><!-- /section-students -->


    <!-- ===== INSTRUCTORS SECTION ===== -->
    <div id="section-instructors" class="admin-section" style="display:<?= $activeSection==='instructors'?'block':'none' ?>">
      <div class="section-header">
        <div class="section-header-left">
          <i class="fas fa-chalkboard-teacher" style="color:#4caf82;font-size:18px"></i>
          <div>
            <div class="section-title-main">Instructors</div>
            <div class="section-subtitle"><?= count($allInstructors) ?> registered instructor<?= count($allInstructors)!=1?'s':'' ?></div>
          </div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="setUserRole('instructor');openModal('m-user')">
          <i class="fas fa-user-plus"></i> Add Instructor
        </button>
      </div>

      <div class="search-bar-wrap">
        <i class="fas fa-search"></i>
        <input type="text" id="instructor-search" placeholder="Search instructors by name or email…" oninput="filterInstructors()">
      </div>

      <?php if(empty($allInstructors)): ?>
      <div class="empty-state">
        <i class="fas fa-chalkboard-teacher"></i>
        <p>No instructors registered yet.</p>
        <button class="btn btn-primary" style="margin-top:16px" onclick="setUserRole('instructor');openModal('m-user')">
          <i class="fas fa-user-plus"></i> Add First Instructor
        </button>
      </div>
      <?php else: ?>
      <div class="user-grid" id="instructor-grid">
        <?php foreach($allInstructors as $u): ?>
        <?php
          // Count courses for this instructor
          $instCourseCount = 0;
          foreach($allCourses as $ic) {
              if($ic['instructor_id'] == $u['id']) $instCourseCount++;
              else {
                  foreach(($ciMap[$ic['id']] ?? []) as $ci) {
                      if($ci['id'] == $u['id']) { $instCourseCount++; break; }
                  }
              }
          }
        ?>
        <div class="user-card" data-search="<?= strtolower(h($u['full_name']).' '.h($u['email'])) ?>">
          <div class="user-card-top">
            <div class="user-card-avatar" style="background:linear-gradient(135deg,#1b5e20,#2e7d32);color:#fff">
              <?= strtoupper(mb_substr($u['full_name'],0,1)) ?>
            </div>
            <div style="min-width:0;flex:1">
              <div class="user-card-name"><?= h($u['full_name']) ?></div>
              <div class="user-card-email"><?= h($u['email']) ?></div>
            </div>
          </div>
          <div class="user-card-meta">
            <span class="badge bg-green" style="font-size:10px"><i class="fas fa-book"></i> <?= $instCourseCount ?> course<?= $instCourseCount!=1?'s':'' ?></span>
            <span class="badge <?= $u['is_active'] ? 'bg-green' : 'bg-red' ?>"><?= $u['is_active'] ? 'Active' : 'Disabled' ?></span>
          </div>
          <div style="font-size:11px;color:var(--muted)">
            <i class="fas fa-calendar"></i> Joined <?= date('M d, Y', strtotime($u['created_at'])) ?>
          </div>
          <div class="user-card-actions">
            <a href="dashboard.php?toggle_user=<?= $u['id'] ?>&from_tab=instructors&section=instructors" class="btn btn-secondary btn-sm" title="<?= $u['is_active']?'Disable':'Enable' ?>">
              <i class="fas fa-<?= $u['is_active']?'ban':'check' ?>"></i> <?= $u['is_active']?'Disable':'Enable' ?>
            </a>
            <a href="dashboard.php?del_user=<?= $u['id'] ?>&from_tab=instructors&section=instructors"
               class="btn btn-danger btn-sm"
               onclick="return confirm('Delete this instructor permanently?')">
              <i class="fas fa-trash"></i>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div><!-- /section-instructors -->

  </div><!-- /admin-content-panel -->
</div><!-- /admin-layout -->


<!-- ===== CREATE COURSE MODAL ===== -->
<div class="modal-wrap" id="m-course">
  <div class="modal-box">
    <div class="modal-head">
      <h3><i class="fas fa-plus-circle" style="color:var(--gold)"></i> Create New Course</h3>
      <button class="modal-close" onclick="closeModal('m-course')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <div class="g2">
          <div class="fg">
            <label class="lbl2">Course Title *</label>
            <input class="fc" type="text" name="c_title" placeholder="e.g. Introduction to Networking" required>
          </div>
          <div class="fg">
            <label class="lbl2">Course Code</label>
            <input class="fc" type="text" name="c_code" placeholder="e.g. IT101, CS204" maxlength="20"
                   style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
            <small style="color:var(--muted);font-size:11px;display:block;margin-top:4px"><i class="fas fa-info-circle"></i> Optional but must be unique</small>
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Description</label>
          <textarea class="fc" name="c_desc" placeholder="What will students learn?"></textarea>
        </div>
        <div class="fg">
          <label class="lbl2">Level *</label>
          <select class="fc" name="c_level" required>
            <option value="100">Level 100</option>
            <option value="200">Level 200</option>
            <option value="300">Level 300</option>
            <option value="400">Level 400</option>
          </select>
        </div>
        <div class="fg">
          <label class="lbl2">Assign Instructors * <span style="color:var(--muted);font-weight:400;font-size:11px">(first selected = primary)</span></label>
          <div class="ins-multi-wrap">
            <?php foreach($instructors as $ins): ?>
            <div class="ins-multi-item">
              <input type="checkbox" name="c_instructors[]" value="<?= $ins['id'] ?>" id="ci_<?= $ins['id'] ?>">
              <div style="flex:1">
                <label for="ci_<?= $ins['id'] ?>"><?= h($ins['full_name']) ?></label>
                <div class="ins-email"><?= h($ins['email']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="ins-note"><i class="fas fa-info-circle"></i> Select one or more instructors. The first checked becomes the primary instructor.</div>
        </div>
        <button class="btn btn-primary" style="width:100%" type="submit" name="do_create_course">
          <i class="fas fa-rocket"></i> Create Course
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ===== EDIT COURSE MODAL ===== -->
<div class="modal-wrap" id="m-edit-course">
  <div class="modal-box">
    <div class="modal-head">
      <h3><i class="fas fa-edit" style="color:var(--gold)"></i> Edit Course</h3>
      <button class="modal-close" onclick="closeModal('m-edit-course')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="ec_id" id="ec_id">
        <div class="g2">
          <div class="fg">
            <label class="lbl2">Course Title *</label>
            <input class="fc" type="text" name="ec_title" id="ec_title" required>
          </div>
          <div class="fg">
            <label class="lbl2">Course Code</label>
            <input class="fc" type="text" name="ec_code" id="ec_code" maxlength="20"
                   style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Description</label>
          <textarea class="fc" name="ec_desc" id="ec_desc"></textarea>
        </div>
        <div class="fg">
          <label class="lbl2">Level *</label>
          <select class="fc" name="ec_level" id="ec_level" required>
            <option value="100">Level 100</option>
            <option value="200">Level 200</option>
            <option value="300">Level 300</option>
            <option value="400">Level 400</option>
          </select>
        </div>
        <div class="fg">
          <label class="lbl2">Assign Instructors * <span style="color:var(--muted);font-weight:400;font-size:11px">(first selected = primary)</span></label>
          <div class="ins-multi-wrap" id="ec-ins-wrap">
            <?php foreach($instructors as $ins): ?>
            <div class="ins-multi-item">
              <input type="checkbox" name="ec_instructors[]" value="<?= $ins['id'] ?>" id="eci_<?= $ins['id'] ?>">
              <div style="flex:1">
                <label for="eci_<?= $ins['id'] ?>"><?= h($ins['full_name']) ?></label>
                <div class="ins-email"><?= h($ins['email']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="ins-note"><i class="fas fa-info-circle"></i> Select one or more. First checked = primary instructor.</div>
        </div>
        <button class="btn btn-primary" style="width:100%" type="submit" name="do_edit_course">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ===== CREATE USER MODAL ===== -->
<div class="modal-wrap" id="m-user">
  <div class="modal-box">
    <div class="modal-head">
      <h3><i class="fas fa-user-plus" style="color:var(--gold)"></i> Add User</h3>
      <button class="modal-close" onclick="closeModal('m-user')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <div class="g2">
          <div class="fg">
            <label class="lbl2">Full Name *</label>
            <input class="fc" type="text" name="u_name" placeholder="e.g. Kwame Asante" required>
          </div>
          <div class="fg">
            <label class="lbl2">Role *</label>
            <select class="fc" name="u_role" id="u_role_sel" onchange="toggleStudentFields()">
              <option value="student">Student</option>
              <option value="instructor">Instructor</option>
            </select>
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Email Address *</label>
          <input class="fc" type="email" name="u_email" placeholder="e.g. kwame.asante@st.rmu.edu.gh" required>
        </div>
        <div class="g2" id="student-fields">
          <div class="fg">
            <label class="lbl2">Programme</label>
            <select class="fc" name="u_programme">
              <option value="">— Select —</option>
              <option value="IT">Information Technology (IT)</option>
              <option value="CS">Computer Science (CS)</option>
              <option value="CE">Computer Engineering (CE)</option>
            </select>
          </div>
          <div class="fg">
            <label class="lbl2">Student ID</label>
            <input class="fc" type="text" name="u_sid" placeholder="RMU/2024/001">
          </div>
        </div>
        <div class="fg" style="background:rgba(30,144,255,.07);border:1px solid rgba(30,144,255,.2);border-radius:8px;padding:11px 14px">
          <i class="fas fa-info-circle" style="color:#63b3ff;margin-right:6px"></i>
          <span style="font-size:12px;color:#a0b4c8">No password needed — the student will set their own password when they register on the login page.</span>
        </div>
        <button class="btn btn-primary" style="width:100%" type="submit" name="do_create_user">
          <i class="fas fa-user-plus"></i> Create Account
        </button>
      </form>
    </div>
  </div>
</div>

<script>
// ── Section switcher ──
function showSection(section) {
    document.querySelectorAll('.admin-section').forEach(function(s){ s.style.display = 'none'; });
    document.querySelectorAll('.admin-snav-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('section-' + section).style.display = 'block';
    document.querySelectorAll('.admin-snav-btn').forEach(function(b){
        if(b.getAttribute('onclick') && b.getAttribute('onclick').indexOf("'" + section + "'") !== -1) {
            b.classList.add('active');
        }
    });
    // Update URL without reload
    var url = new URL(window.location);
    url.searchParams.set('section', section);
    history.replaceState(null, '', url);
}

// ── Search/filter functions ──
function filterCourses() {
    var q = document.getElementById('course-search').value.toLowerCase();
    document.querySelectorAll('.course-row').forEach(function(row){
        row.style.display = row.getAttribute('data-search').indexOf(q) !== -1 ? '' : 'none';
    });
}
function filterStudents() {
    var q = document.getElementById('student-search').value.toLowerCase();
    document.querySelectorAll('#student-grid .user-card').forEach(function(card){
        card.style.display = card.getAttribute('data-search').indexOf(q) !== -1 ? '' : 'none';
    });
}
function filterInstructors() {
    var q = document.getElementById('instructor-search').value.toLowerCase();
    document.querySelectorAll('#instructor-grid .user-card').forEach(function(card){
        card.style.display = card.getAttribute('data-search').indexOf(q) !== -1 ? '' : 'none';
    });
}

// ── Open Edit Course modal ──
function openEditCourse(id, title, code, desc, level, instructorIds) {
    document.getElementById('ec_id').value    = id;
    document.getElementById('ec_title').value = title;
    document.getElementById('ec_code').value  = code;
    document.getElementById('ec_desc').value  = desc;
    document.getElementById('ec_level').value = level;
    document.querySelectorAll('#ec-ins-wrap input[type=checkbox]').forEach(function(cb){
        cb.checked = instructorIds.indexOf(parseInt(cb.value)) !== -1;
    });
    openModal('m-edit-course');
}

// ── User role modal preset ──
function setUserRole(role) {
    var sel = document.getElementById('u_role_sel');
    if(sel) { sel.value = role; toggleStudentFields(); }
}
function toggleStudentFields(){
    var role = document.getElementById('u_role_sel').value;
    document.getElementById('student-fields').style.display = role === 'student' ? 'grid' : 'none';
}
toggleStudentFields();

// ── Auto-show section from URL param on load ──
(function(){
    var params = new URLSearchParams(window.location.search);
    var sec = params.get('section');
    if(sec && ['courses','students','instructors'].indexOf(sec) !== -1) {
        showSection(sec);
    }
})();
</script>

<!-- Quiz Overview Section -->
<?php if(!empty($quizOverview)): ?>
<div style="margin-top:32px">
<div class="flex-between mb2">
    <span class="card-title"><i class="fas fa-question-circle" style="color:var(--gold)"></i> Quiz Overview — All Instructors</span>
</div>
<div class="card">
    <div class="tbl-wrap">
    <table>
        <thead>
            <tr><th>Quiz</th><th>Course</th><th>Instructor</th><th>Attempts</th><th>Pass Rate</th><th>Avg Score</th></tr>
        </thead>
        <tbody>
        <?php foreach($quizOverview as $qz): ?>
        <tr>
            <td><strong style="color:var(--white)"><?= h($qz['quiz_title']) ?></strong></td>
            <td><span class="badge bg-blue" style="font-size:10px"><?= h($qz['course_title']) ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= h($qz['instructor']) ?></td>
            <td><?= $qz['total_attempts'] ?? 0 ?></td>
            <td>
                <?php if($qz['total_attempts'] > 0):
                    $rate = round(($qz['passed'] / $qz['total_attempts']) * 100);
                ?>
                <div style="display:flex;align-items:center;gap:8px">
                    <div style="width:70px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;height:6px">
                        <div style="height:100%;width:<?= $rate ?>%;background:<?= $rate>=70?'#27ae60':'#e74c3c' ?>;border-radius:4px"></div>
                    </div>
                    <span style="font-size:12px;font-weight:700;color:<?= $rate>=70?'#4caf82':'#ff8a80' ?>"><?= $rate ?>%</span>
                </div>
                <?php else: ?>
                <span style="color:var(--muted);font-size:12px">No attempts</span>
                <?php endif; ?>
            </td>
            <td style="font-weight:700;color:var(--gold)"><?= $qz['avg_score'] ?? '—' ?><?= $qz['total_attempts']>0?'%':'' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
