<?php
require_once '../includes/config.php';
requireRole('admin');

$uid = (int)$_SESSION['user_id'];
$msg = '';
$err = '';

// ── Ensure course_instructors table exists ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_instructors (
        course_id     INT NOT NULL,
        instructor_id INT NOT NULL,
        PRIMARY KEY (course_id, instructor_id)
    )");
} catch(Exception $e){}

// ── CREATE INSTRUCTOR ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_create_instructor'])) {
    $full_name = trim($_POST['i_name']     ?? '');
    $email     = strtolower(trim($_POST['i_email']    ?? ''));
    $password  = trim($_POST['i_password'] ?? '');
    $bio       = trim($_POST['i_bio']      ?? '');

    if (!$full_name || !$email || !$password) {
        $err = 'Full name, email and password are required.';
    } elseif (strlen($password) < 6) {
        $err = 'Password must be at least 6 characters.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $err = 'An account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users (full_name,email,password,role,department,bio) VALUES (?,?,?,?,?,?)')
                ->execute([$full_name, $email, $hash, 'instructor', 'Information Technology Department', $bio]);
            $msg = 'Instructor account created successfully.';
        }
    }
}

// ── EDIT INSTRUCTOR ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_edit_instructor'])) {
    $eiid      = (int)($_POST['ei_id']       ?? 0);
    $full_name = trim($_POST['ei_name']      ?? '');
    $email     = strtolower(trim($_POST['ei_email']   ?? ''));
    $bio       = trim($_POST['ei_bio']       ?? '');
    $newpass   = trim($_POST['ei_password']  ?? '');

    if (!$eiid || !$full_name || !$email) {
        $err = 'Name and email are required.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM users WHERE email=? AND id!=? LIMIT 1');
        $chk->execute([$email, $eiid]);
        if ($chk->fetch()) {
            $err = 'That email is already used by another account.';
        } else {
            if ($newpass) {
                if (strlen($newpass) < 6) { $err = 'New password must be at least 6 characters.'; goto skip_edit_i; }
                $hash = password_hash($newpass, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET full_name=?,email=?,password=?,bio=? WHERE id=? AND role="instructor"')
                    ->execute([$full_name, $email, $hash, $bio, $eiid]);
            } else {
                $pdo->prepare('UPDATE users SET full_name=?,email=?,bio=? WHERE id=? AND role="instructor"')
                    ->execute([$full_name, $email, $bio, $eiid]);
            }
            $msg = 'Instructor updated successfully.';
        }
    }
    skip_edit_i:;
}

// ── DELETE INSTRUCTOR ──
if (isset($_GET['del']) && is_numeric($_GET['del'])) {
    $did = (int)$_GET['del'];
    if ($did !== $uid) {
        $pdo->prepare('DELETE FROM users WHERE id=? AND role="instructor"')->execute([$did]);
    }
    header('Location: instructors.php?deleted=1'); exit;
}

// ── TOGGLE ACTIVE ──
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $pdo->prepare('UPDATE users SET is_active=NOT is_active WHERE id=? AND role="instructor"')->execute([(int)$_GET['toggle']]);
    header('Location: instructors.php'); exit;
}

// ── LOAD DATA ──
$allInstructors = $pdo->query("
    SELECT u.*,
           COUNT(DISTINCT c.id)  AS n_courses,
           COUNT(DISTINCT e.student_id) AS n_students
    FROM   users u
    LEFT JOIN courses c ON c.instructor_id = u.id
    LEFT JOIN enrollments e ON e.course_id = c.id
    WHERE  u.role = 'instructor'
    GROUP  BY u.id
    ORDER  BY u.full_name
")->fetchAll();

// Also count co-instructor courses
try {
    $coMap = [];
    $coRows = $pdo->query("SELECT instructor_id, COUNT(DISTINCT course_id) AS n FROM course_instructors GROUP BY instructor_id")->fetchAll();
    foreach ($coRows as $r) { $coMap[$r['instructor_id']] = (int)$r['n']; }
} catch(Exception $e){ $coMap = []; }

$totalInstructors = count($allInstructors);
$totalActive      = count(array_filter($allInstructors, fn($i) => $i['is_active']));
$totalCoursesTaught = array_sum(array_column($allInstructors, 'n_courses'));

$pageTitle    = 'Instructors';
$pageSubtitle = 'Manage All Instructors';
$activePage   = 'instructors';
$depth        = 1;

ob_start();
?>

<style>
/* ── Search bar ── */
.search-wrap { position:relative; margin-bottom:18px; }
.search-wrap i { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:13px; }
.search-wrap input {
    width:100%; padding:10px 14px 10px 38px;
    background:var(--surf2); border:1px solid var(--border);
    border-radius:9px; color:var(--text);
    font-family:'Raleway',sans-serif; font-size:13px; outline:none; transition:border-color .2s;
}
.search-wrap input:focus { border-color:var(--gold); }
.search-wrap input::placeholder { color:var(--muted); }

/* ── Status filter pills ── */
.filter-tabs { display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; }
.ftab {
    padding:6px 16px; border-radius:20px; font-size:12px; font-weight:700;
    border:1px solid var(--border); background:var(--surf2); color:var(--muted);
    cursor:pointer; transition:all .2s;
}
.ftab:hover { color:var(--white); border-color:rgba(255,255,255,.2); }
.ftab.active { background:rgba(39,174,96,.15); color:#4caf82; border-color:rgba(39,174,96,.4); }

/* ── Instructor cards ── */
.instructors-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:18px; }
.instructor-card {
    background:var(--surface); border:1px solid var(--border); border-radius:14px;
    overflow:hidden; transition:transform .2s, border-color .2s;
    display:flex; flex-direction:column;
}
.instructor-card:hover { transform:translateY(-3px); border-color:rgba(39,174,96,.35); }

.instructor-card-top {
    padding:20px 18px 16px;
    background:linear-gradient(135deg, rgba(39,174,96,.08), rgba(39,174,96,.02));
    border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:14px;
}
.instructor-avatar {
    width:56px; height:56px; border-radius:14px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-family:'Cinzel',serif; font-size:22px; font-weight:700; color:#fff;
    background:linear-gradient(135deg,#1b5e20,#2e7d32);
    position:relative;
}
.instructor-avatar img {
    width:100%; height:100%; object-fit:cover; border-radius:14px;
}
.instructor-name { font-family:'Cinzel',serif; font-size:15px; font-weight:700; color:var(--white); line-height:1.3; }
.instructor-email { font-size:11px; color:var(--muted); margin-top:3px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:190px; }
.instructor-card-body { padding:14px 18px; flex:1; display:flex; flex-direction:column; gap:10px; }

/* Stats row inside card */
.ins-stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.ins-stat {
    background:var(--surf2); border:1px solid var(--border); border-radius:8px;
    padding:9px; text-align:center;
}
.ins-stat-val { font-family:'Cinzel',serif; font-size:18px; font-weight:700; color:var(--white); }
.ins-stat-lbl { font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-top:2px; }

.instructor-bio {
    font-size:12px; color:var(--muted); line-height:1.6;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    min-height:38px;
}

.instructor-card-footer {
    padding:12px 18px; border-top:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between; gap:8px;
}

/* ── Empty state ── */
.empty-state { text-align:center; padding:60px 20px; color:var(--muted); }
.empty-state i { font-size:48px; display:block; margin-bottom:14px; opacity:.4; }
.empty-state h3 { font-family:'Cinzel',serif; font-size:16px; color:var(--white); margin-bottom:8px; }
</style>

<?php if ($msg): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> <?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> <?= h($err) ?></div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> Instructor removed.</div><?php endif; ?>

<!-- Stats -->
<div class="stat-grid" style="margin-bottom:26px">
    <div class="stat-card" style="--sc:#27ae60">
        <div class="stat-ico" style="background:rgba(39,174,96,.15);color:#4caf82"><i class="fas fa-chalkboard-teacher"></i></div>
        <div><div class="stat-val"><?= $totalInstructors ?></div><div class="stat-lbl">Total Instructors</div></div>
    </div>
    <div class="stat-card" style="--sc:#2980b9">
        <div class="stat-ico" style="background:rgba(41,128,185,.15);color:#64b5f6"><i class="fas fa-check-circle"></i></div>
        <div><div class="stat-val"><?= $totalActive ?></div><div class="stat-lbl">Active</div></div>
    </div>
    <div class="stat-card" style="--sc:var(--gold)">
        <div class="stat-ico" style="background:rgba(200,168,75,.15);color:var(--gold)"><i class="fas fa-book"></i></div>
        <div><div class="stat-val"><?= $totalCoursesTaught ?></div><div class="stat-lbl">Courses Taught</div></div>
    </div>
    <div class="stat-card" style="--sc:#e74c3c">
        <div class="stat-ico" style="background:rgba(231,76,60,.15);color:#ff8a80"><i class="fas fa-ban"></i></div>
        <div><div class="stat-val"><?= $totalInstructors - $totalActive ?></div><div class="stat-lbl">Disabled</div></div>
    </div>
</div>

<!-- Header -->
<div class="flex-between mb2">
    <div>
        <div style="font-family:'Cinzel',serif;font-size:18px;color:var(--white)">All Instructors</div>
        <div style="font-size:12px;color:var(--muted);margin-top:3px"><?= $totalInstructors ?> registered instructor<?= $totalInstructors!=1?'s':'' ?></div>
    </div>
    <button class="btn btn-primary" onclick="openModal('m-add-instructor')">
        <i class="fas fa-user-plus"></i> Add Instructor
    </button>
</div>

<!-- Search -->
<div class="search-wrap">
    <i class="fas fa-search"></i>
    <input type="text" id="instructor-search" placeholder="Search by name or email…" oninput="filterInstructors()">
</div>
<div class="filter-tabs">
    <span class="ftab active" onclick="setStatusFilter(this,'all')">All</span>
    <span class="ftab" onclick="setStatusFilter(this,'active')">Active Only</span>
    <span class="ftab" onclick="setStatusFilter(this,'disabled')">Disabled</span>
    <span class="ftab" onclick="setStatusFilter(this,'courses')">Has Courses</span>
</div>

<!-- Instructors grid -->
<?php if (empty($allInstructors)): ?>
<div class="empty-state">
    <i class="fas fa-chalkboard-teacher"></i>
    <h3>No Instructors Yet</h3>
    <p>Add your first instructor to start creating courses.</p>
    <button class="btn btn-primary" style="margin-top:18px" onclick="openModal('m-add-instructor')">
        <i class="fas fa-user-plus"></i> Add First Instructor
    </button>
</div>
<?php else: ?>
<div class="instructors-grid" id="instructors-grid">
<?php foreach ($allInstructors as $ins):
    $coCourses = $coMap[$ins['id']] ?? 0;
    $totalCourses = $ins['n_courses']; // primary courses only shown in stat
?>
<div class="instructor-card"
     data-search="<?= strtolower(h($ins['full_name']).' '.h($ins['email'])) ?>"
     data-active="<?= $ins['is_active'] ? 'active' : 'disabled' ?>"
     data-courses="<?= $ins['n_courses'] > 0 ? 'yes' : 'no' ?>">

    <div class="instructor-card-top">
        <div class="instructor-avatar">
            <?php if (!empty($ins['profile_pic'])): ?>
            <img src="../uploads/avatars/<?= h($ins['profile_pic']) ?>" alt="Avatar">
            <?php else: ?>
            <?= strtoupper(mb_substr($ins['full_name'],0,1)) ?>
            <?php endif; ?>
        </div>
        <div style="min-width:0;flex:1">
            <div class="instructor-name"><?= h($ins['full_name']) ?></div>
            <div class="instructor-email" title="<?= h($ins['email']) ?>"><?= h($ins['email']) ?></div>
            <div style="margin-top:6px">
                <span class="badge <?= $ins['is_active'] ? 'bg-green' : 'bg-red' ?>" style="font-size:9px">
                    <?= $ins['is_active'] ? 'Active' : 'Disabled' ?>
                </span>
            </div>
        </div>
    </div>

    <div class="instructor-card-body">
        <!-- Stats -->
        <div class="ins-stats-row">
            <div class="ins-stat">
                <div class="ins-stat-val"><?= $ins['n_courses'] ?></div>
                <div class="ins-stat-lbl">Courses</div>
            </div>
            <div class="ins-stat">
                <div class="ins-stat-val"><?= $ins['n_students'] ?></div>
                <div class="ins-stat-lbl">Students</div>
            </div>
            <div class="ins-stat">
                <div class="ins-stat-val"><?= $coCourses ?></div>
                <div class="ins-stat-lbl">Co-Teach</div>
            </div>
        </div>

        <!-- Bio -->
        <div class="instructor-bio">
            <?= $ins['bio'] ? h($ins['bio']) : '<span style="font-style:italic;color:var(--muted)">No bio provided.</span>' ?>
        </div>

        <div style="font-size:11px;color:var(--muted);display:flex;align-items:center;gap:6px">
            <i class="fas fa-calendar" style="color:var(--gold)"></i>
            Joined <?= date('M d, Y', strtotime($ins['created_at'])) ?>
        </div>
    </div>

    <div class="instructor-card-footer">
        <div style="font-size:11px;color:var(--muted)">
            <i class="fas fa-users" style="color:var(--gold)"></i> <?= $ins['n_students'] ?> student<?= $ins['n_students']!=1?'s':'' ?>
        </div>
        <div class="flex gap">
            <button class="btn btn-secondary btn-sm" title="Edit"
                onclick="openEditInstructor(
                    <?= $ins['id'] ?>,
                    <?= htmlspecialchars(json_encode($ins['full_name']), ENT_QUOTES) ?>,
                    <?= htmlspecialchars(json_encode($ins['email']), ENT_QUOTES) ?>,
                    <?= htmlspecialchars(json_encode($ins['bio'] ?? ''), ENT_QUOTES) ?>
                )">
                <i class="fas fa-edit"></i>
            </button>
            <a href="instructors.php?toggle=<?= $ins['id'] ?>" class="btn btn-secondary btn-sm"
               title="<?= $ins['is_active'] ? 'Disable' : 'Enable' ?>">
                <i class="fas fa-<?= $ins['is_active'] ? 'ban' : 'check' ?>"></i>
            </a>
            <a href="instructors.php?del=<?= $ins['id'] ?>" class="btn btn-danger btn-sm"
               onclick="return confirm('Permanently delete this instructor? Their courses will remain but be unassigned.')">
                <i class="fas fa-trash"></i>
            </a>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<div id="no-results" style="display:none" class="empty-state">
    <i class="fas fa-search"></i>
    <h3>No Matches</h3>
    <p>Try a different search or filter.</p>
</div>
<?php endif; ?>


<!-- ===== ADD INSTRUCTOR MODAL ===== -->
<div class="modal-wrap" id="m-add-instructor">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-head">
      <h3><i class="fas fa-user-plus" style="color:#4caf82"></i> Add New Instructor</h3>
      <button class="modal-close" onclick="closeModal('m-add-instructor')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <div class="fg">
          <label class="lbl2">Full Name *</label>
          <input class="fc" type="text" name="i_name" placeholder="e.g. Dr. Ama Mensah" required>
        </div>
        <div class="fg">
          <label class="lbl2">Email Address *</label>
          <input class="fc" type="email" name="i_email" placeholder="e.g. ama.mensah@rmu.edu.gh" required>
        </div>
        <div class="fg">
          <label class="lbl2">Password *</label>
          <input class="fc" type="password" name="i_password" placeholder="Minimum 6 characters" required>
        </div>
        <div class="fg">
          <label class="lbl2">Bio / About</label>
          <textarea class="fc" name="i_bio" rows="3" placeholder="Brief description of the instructor's expertise…"></textarea>
        </div>
        <button class="btn btn-primary" style="width:100%;background:linear-gradient(135deg,#1b5e20,#2e7d32)" type="submit" name="do_create_instructor">
          <i class="fas fa-user-plus"></i> Create Instructor Account
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ===== EDIT INSTRUCTOR MODAL ===== -->
<div class="modal-wrap" id="m-edit-instructor">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-head">
      <h3><i class="fas fa-user-edit" style="color:var(--gold)"></i> Edit Instructor</h3>
      <button class="modal-close" onclick="closeModal('m-edit-instructor')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="ei_id" id="ei_id">
        <div class="fg">
          <label class="lbl2">Full Name *</label>
          <input class="fc" type="text" name="ei_name" id="ei_name" required>
        </div>
        <div class="fg">
          <label class="lbl2">Email Address *</label>
          <input class="fc" type="email" name="ei_email" id="ei_email" required>
        </div>
        <div class="fg">
          <label class="lbl2">New Password</label>
          <input class="fc" type="password" name="ei_password" placeholder="Leave blank to keep current password">
          <small style="color:var(--muted);font-size:11px;display:block;margin-top:4px"><i class="fas fa-info-circle"></i> Only fill in if you want to change the password.</small>
        </div>
        <div class="fg">
          <label class="lbl2">Bio / About</label>
          <textarea class="fc" name="ei_bio" id="ei_bio" rows="3" placeholder="Brief description…"></textarea>
        </div>
        <button class="btn btn-primary" style="width:100%" type="submit" name="do_edit_instructor">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </form>
    </div>
  </div>
</div>

<script>
var activeStatus = 'all';

function filterInstructors() {
    var q = document.getElementById('instructor-search').value.toLowerCase().trim();
    var cards = document.querySelectorAll('#instructors-grid .instructor-card');
    var visible = 0;
    cards.forEach(function(card) {
        var matchSearch = !q || card.getAttribute('data-search').indexOf(q) !== -1;
        var matchStatus;
        if (activeStatus === 'all') {
            matchStatus = true;
        } else if (activeStatus === 'active') {
            matchStatus = card.getAttribute('data-active') === 'active';
        } else if (activeStatus === 'disabled') {
            matchStatus = card.getAttribute('data-active') === 'disabled';
        } else if (activeStatus === 'courses') {
            matchStatus = card.getAttribute('data-courses') === 'yes';
        }
        var show = matchSearch && matchStatus;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var noRes = document.getElementById('no-results');
    if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
}

function setStatusFilter(el, status) {
    activeStatus = status;
    document.querySelectorAll('.ftab').forEach(function(t){ t.classList.remove('active'); });
    el.classList.add('active');
    filterInstructors();
}

function openEditInstructor(id, name, email, bio) {
    document.getElementById('ei_id').value    = id;
    document.getElementById('ei_name').value  = name;
    document.getElementById('ei_email').value = email;
    document.getElementById('ei_bio').value   = bio;
    openModal('m-edit-instructor');
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
