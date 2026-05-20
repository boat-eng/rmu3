<?php
require_once '../includes/config.php';
requireRole('admin');

$uid = (int)$_SESSION['user_id'];
$msg = '';
$err = '';

// ── Ensure course_instructors table exists ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_instructors (
        course_id    INT NOT NULL,
        instructor_id INT NOT NULL,
        PRIMARY KEY (course_id, instructor_id)
    )");
} catch(Exception $e){}

// ── CREATE COURSE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_create_course'])) {
    $title    = trim($_POST['c_title']  ?? '');
    $code     = strtoupper(trim($_POST['c_code'] ?? ''));
    $desc     = trim($_POST['c_desc']   ?? '');
    $level    = in_array($_POST['c_level']   ?? '', ['100','200','300','400']) ? $_POST['c_level']   : '100';
    $semester = in_array($_POST['c_semester'] ?? '', ['1','2'])                ? $_POST['c_semester'] : '1';
    $ins_sel  = array_map('intval', (array)($_POST['c_instructors'] ?? []));
    $primary  = $ins_sel[0] ?? 0;

    if (!$title || !$primary) {
        $err = 'Course title and at least one instructor are required.';
    } else {
        if ($code) {
            $ck = $pdo->prepare('SELECT id FROM courses WHERE course_code=? LIMIT 1');
            $ck->execute([$code]);
            if ($ck->fetch()) { $err = 'That course code is already in use.'; goto skip_create; }
        }
        $pdo->prepare('INSERT INTO courses (instructor_id,title,course_code,description,level,semester,is_published) VALUES (?,?,?,?,?,?,1)')
            ->execute([$primary, $title, $code, $desc, $level, $semester]);
        $newCid = (int)$pdo->lastInsertId();
        $pdo->prepare('DELETE FROM course_instructors WHERE course_id=?')->execute([$newCid]);
        foreach ($ins_sel as $iid) {
            $pdo->prepare('INSERT IGNORE INTO course_instructors (course_id,instructor_id) VALUES (?,?)')->execute([$newCid, $iid]);
        }
        $msg = 'Course created successfully.';
    }
    skip_create:;
}

// ── EDIT COURSE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_edit_course'])) {
    $ecid    = (int)($_POST['ec_id']    ?? 0);
    $title   = trim($_POST['ec_title']  ?? '');
    $code    = strtoupper(trim($_POST['ec_code'] ?? ''));
    $desc    = trim($_POST['ec_desc']   ?? '');
    $level    = in_array($_POST['ec_level']   ?? '', ['100','200','300','400']) ? $_POST['ec_level']   : '100';
    $semester = in_array($_POST['ec_semester'] ?? '', ['1','2'])                ? $_POST['ec_semester'] : '1';
    $ins_sel = array_map('intval', (array)($_POST['ec_instructors'] ?? []));
    $primary = $ins_sel[0] ?? 0;

    if (!$ecid || !$title || !$primary) {
        $err = 'Course title and at least one instructor are required.';
    } else {
        if ($code) {
            $ck = $pdo->prepare('SELECT id FROM courses WHERE course_code=? AND id!=? LIMIT 1');
            $ck->execute([$code, $ecid]);
            if ($ck->fetch()) { $err = 'That course code is already in use.'; goto skip_edit; }
        }
        $pdo->prepare('UPDATE courses SET instructor_id=?,title=?,course_code=?,description=?,level=?,semester=? WHERE id=?')
            ->execute([$primary, $title, $code, $desc, $level, $semester, $ecid]);
        $pdo->prepare('DELETE FROM course_instructors WHERE course_id=?')->execute([$ecid]);
        foreach ($ins_sel as $iid) {
            $pdo->prepare('INSERT IGNORE INTO course_instructors (course_id,instructor_id) VALUES (?,?)')->execute([$ecid, $iid]);
        }
        $msg = 'Course updated successfully.';
    }
    skip_edit:;
}

// ── DELETE COURSE ──
if (isset($_GET['del']) && is_numeric($_GET['del'])) {
    $cid = (int)$_GET['del'];
    $lRows = $pdo->prepare('SELECT file_path,type FROM lessons WHERE course_id=?');
    $lRows->execute([$cid]);
    foreach ($lRows->fetchAll() as $lr) {
        if ($lr['type'] !== 'youtube' && $lr['file_path']) {
            $fp = dirname(__DIR__) . DIRECTORY_SEPARATOR . $lr['file_path'];
            if (file_exists($fp)) unlink($fp);
        }
    }
    $pdo->prepare('DELETE FROM course_instructors WHERE course_id=?')->execute([$cid]);
    $pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$cid]);
    header('Location: courses.php?deleted=1'); exit;
}

// ── TOGGLE PUBLISH ──
if (isset($_GET['toggle_pub']) && is_numeric($_GET['toggle_pub'])) {
    $pdo->prepare('UPDATE courses SET is_published=NOT is_published WHERE id=?')->execute([(int)$_GET['toggle_pub']]);
    header('Location: courses.php'); exit;
}

// ── LOAD DATA ──
$instructors = $pdo->query("SELECT id,full_name,email FROM users WHERE role='instructor' AND is_active=1 ORDER BY full_name")->fetchAll();

// Drop programme column if it still exists
try { $pdo->exec("ALTER TABLE courses DROP COLUMN programme"); } catch(Exception $e){}

$allCourses = $pdo->query("
    SELECT c.*, u.full_name AS instructor_name,
           COUNT(DISTINCT e.student_id) AS n_students,
           COUNT(DISTINCT l.id)         AS n_lessons
    FROM   courses c
    JOIN   users u ON u.id = c.instructor_id
    LEFT JOIN enrollments e ON e.course_id = c.id
    LEFT JOIN lessons l     ON l.course_id = c.id
    GROUP  BY c.id
    ORDER  BY c.level ASC, c.semester ASC, c.created_at DESC
")->fetchAll();

$ciMap = [];
try {
    $rows = $pdo->query("SELECT ci.course_id, u.id, u.full_name
                         FROM course_instructors ci
                         JOIN users u ON u.id=ci.instructor_id
                         ORDER BY u.full_name")->fetchAll();
    foreach ($rows as $r) { $ciMap[$r['course_id']][] = $r; }
} catch(Exception $e){}

$totalCourses     = count($allCourses);
$totalPublished   = count(array_filter($allCourses, fn($c) => $c['is_published']));
$totalEnrollments = array_sum(array_column($allCourses, 'n_students'));
$totalLessons     = array_sum(array_column($allCourses, 'n_lessons'));

$pageTitle    = 'Courses';
$pageSubtitle = 'Manage All Courses';
$activePage   = 'courses';
$depth        = 1;

ob_start();
?>

<style>
/* ── Instructor chips ── */
.ci-chips { display:flex; flex-wrap:wrap; gap:5px; margin-top:5px; }
.ci-chip {
    font-size:10px; background:rgba(200,168,75,.12); color:var(--gold);
    border:1px solid rgba(200,168,75,.25); border-radius:20px;
    padding:2px 9px; display:inline-flex; align-items:center; gap:4px;
}

/* ── Multi-select instructor list ── */
.ins-multi-wrap {
    border:1px solid var(--border); border-radius:9px; overflow:hidden;
    background:var(--surface); max-height:200px; overflow-y:auto;
}
.ins-multi-item {
    display:flex; align-items:center; gap:10px; padding:9px 12px;
    cursor:pointer; border-bottom:1px solid var(--border); transition:background .15s;
}
.ins-multi-item:last-child { border-bottom:none; }
.ins-multi-item:hover { background:rgba(200,168,75,.06); }
.ins-multi-item input[type=checkbox] { accent-color:var(--gold); width:15px; height:15px; cursor:pointer; }
.ins-multi-item label { cursor:pointer; font-size:13px; color:var(--text); flex:1; margin:0; }
.ins-multi-item .ins-email { font-size:11px; color:var(--muted); }
.ins-note { font-size:11px; color:var(--muted); margin-top:5px; }

/* ── Search bar ── */
.search-wrap { position:relative; margin-bottom:18px; }
.search-wrap i { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:13px; }
.search-wrap input {
    width:100%; padding:10px 14px 10px 38px;
    background:var(--surf2); border:1px solid var(--border);
    border-radius:9px; color:var(--text);
    font-family:'Raleway',sans-serif; font-size:13px; outline:none;
    transition:border-color .2s;
}
.search-wrap input:focus { border-color:var(--gold); }
.search-wrap input::placeholder { color:var(--muted); }

/* ── Level filter tabs ── */
.filter-tabs { display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; }
.ftab {
    padding:6px 16px; border-radius:20px; font-size:12px; font-weight:700;
    border:1px solid var(--border); background:var(--surf2); color:var(--muted);
    cursor:pointer; transition:all .2s;
}
.ftab:hover { color:var(--white); border-color:rgba(255,255,255,.2); }
.ftab.active { background:rgba(200,168,75,.15); color:var(--gold); border-color:rgba(200,168,75,.4); }

/* ── Course cards grid ── */
.courses-grid {
    display:grid; grid-template-columns:repeat(auto-fill, minmax(320px,1fr)); gap:18px;
}
.course-card {
    background:var(--surface); border:1px solid var(--border); border-radius:14px;
    overflow:hidden; transition:transform .2s, border-color .2s;
    display:flex; flex-direction:column;
}
.course-card:hover { transform:translateY(-3px); border-color:rgba(200,168,75,.35); }
.course-card-header {
    padding:16px 18px 14px;
    background:linear-gradient(135deg, rgba(200,168,75,.08), rgba(200,168,75,.02));
    border-bottom:1px solid var(--border);
}
.course-card-code {
    font-family:monospace; font-size:11px; font-weight:700; letter-spacing:.5px;
    color:var(--gold); background:rgba(200,168,75,.12); border:1px solid rgba(200,168,75,.25);
    border-radius:5px; padding:2px 8px; display:inline-block; margin-bottom:8px;
}
.course-card-title {
    font-family:'Cinzel',serif; font-size:15px; font-weight:700;
    color:var(--white); line-height:1.35; margin-bottom:6px;
}
.course-card-desc {
    font-size:12px; color:var(--muted); line-height:1.6;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
.course-card-body { padding:14px 18px; flex:1; display:flex; flex-direction:column; gap:10px; }
.course-card-instructors { font-size:12px; }
.course-card-instructors .primary-ins {
    font-weight:700; color:var(--white); display:flex; align-items:center; gap:6px;
}
.course-card-instructors .primary-ins i { color:var(--gold); font-size:11px; }
.course-card-stats {
    display:grid; grid-template-columns:repeat(4,1fr); gap:8px;
}
.cstat {
    background:var(--surf2); border:1px solid var(--border); border-radius:8px;
    padding:8px; text-align:center;
}
.cstat-val { font-family:'Cinzel',serif; font-size:16px; font-weight:700; color:var(--white); }
.cstat-lbl { font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-top:2px; }
.course-card-footer {
    padding:12px 18px; border-top:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between; gap:8px;
}

/* ── Empty state ── */
.empty-state { text-align:center; padding:60px 20px; color:var(--muted); }
.empty-state i { font-size:48px; display:block; margin-bottom:14px; opacity:.4; }
.empty-state h3 { font-family:'Cinzel',serif; font-size:16px; color:var(--white); margin-bottom:8px; }
.empty-state p  { font-size:13px; }
</style>

<?php if ($msg): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> <?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> <?= h($err) ?></div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> Course deleted successfully.</div><?php endif; ?>

<!-- Stats row -->
<div class="stat-grid" style="margin-bottom:26px">
    <div class="stat-card" style="--sc:var(--gold)">
        <div class="stat-ico" style="background:rgba(200,168,75,.15);color:var(--gold)"><i class="fas fa-book"></i></div>
        <div><div class="stat-val"><?= $totalCourses ?></div><div class="stat-lbl">Total Courses</div></div>
    </div>
    <div class="stat-card" style="--sc:#27ae60">
        <div class="stat-ico" style="background:rgba(39,174,96,.15);color:#4caf82"><i class="fas fa-eye"></i></div>
        <div><div class="stat-val"><?= $totalPublished ?></div><div class="stat-lbl">Published</div></div>
    </div>
    <div class="stat-card" style="--sc:#2980b9">
        <div class="stat-ico" style="background:rgba(41,128,185,.15);color:#64b5f6"><i class="fas fa-users"></i></div>
        <div><div class="stat-val"><?= $totalEnrollments ?></div><div class="stat-lbl">Enrollments</div></div>
    </div>
    <div class="stat-card" style="--sc:#8e44ad">
        <div class="stat-ico" style="background:rgba(142,68,173,.15);color:#ce93d8"><i class="fas fa-film"></i></div>
        <div><div class="stat-val"><?= $totalLessons ?></div><div class="stat-lbl">Lessons</div></div>
    </div>
</div>

<!-- Header + Add button -->
<div class="flex-between mb2">
    <div>
        <div style="font-family:'Cinzel',serif;font-size:18px;color:var(--white)">All Courses</div>
        <div style="font-size:12px;color:var(--muted);margin-top:3px"><?= $totalCourses ?> course<?= $totalCourses!=1?'s':'' ?> in the system</div>
    </div>
    <button class="btn btn-primary" onclick="openModal('m-create-course')">
        <i class="fas fa-plus"></i> New Course
    </button>
</div>

<!-- Search + filter -->
<div class="search-wrap">
    <i class="fas fa-search"></i>
    <input type="text" id="course-search" placeholder="Search by title, code or instructor…" oninput="filterCourses()">
</div>
<div class="filter-tabs" style="margin-bottom:8px">
    <span class="ftab active" onclick="setLevelFilter(this,'all')">All Levels</span>
    <span class="ftab" onclick="setLevelFilter(this,'100')">Level 100</span>
    <span class="ftab" onclick="setLevelFilter(this,'200')">Level 200</span>
    <span class="ftab" onclick="setLevelFilter(this,'300')">Level 300</span>
    <span class="ftab" onclick="setLevelFilter(this,'400')">Level 400</span>
</div>


<!-- Courses grid -->
<?php if (empty($allCourses)): ?>
<div class="empty-state">
    <i class="fas fa-book-open"></i>
    <h3>No Courses Yet</h3>
    <p>Get started by creating your first course.</p>
    <button class="btn btn-primary" style="margin-top:18px" onclick="openModal('m-create-course')">
        <i class="fas fa-plus"></i> Create First Course
    </button>
</div>
<?php else: ?>
<div class="courses-grid" id="courses-grid">
<?php foreach ($allCourses as $c):
    $coIns = $ciMap[$c['id']] ?? [];
?>
<div class="course-card"
     data-search="<?= strtolower(h($c['title']).' '.h($c['course_code']??'').' '.h($c['instructor_name'])) ?>"
     data-level="<?= h($c['level']) ?>">

    <div class="course-card-header">
        <?php if ($c['course_code']): ?>
        <div class="course-card-code"><?= h($c['course_code']) ?></div>
        <?php endif; ?>
        <div class="course-card-title"><?= h($c['title']) ?></div>

        <?php if ($c['description']): ?>
        <div class="course-card-desc" style="margin-top:6px"><?= h($c['description']) ?></div>
        <?php endif; ?>
    </div>

    <div class="course-card-body">
        <!-- Instructors -->
        <div class="course-card-instructors">
            <div class="primary-ins">
                <i class="fas fa-chalkboard-teacher"></i>
                <?= h($c['instructor_name']) ?>
                <span class="badge bg-gold" style="font-size:9px;padding:1px 7px">Primary</span>
            </div>
            <?php
            $coOnly = array_filter($coIns, fn($ci) => $ci['id'] != $c['instructor_id']);
            if (!empty($coOnly)): ?>
            <div class="ci-chips">
                <?php foreach ($coOnly as $ci): ?>
                <span class="ci-chip"><i class="fas fa-user" style="font-size:9px"></i><?= h($ci['full_name']) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="course-card-stats">
            <div class="cstat">
                <div class="cstat-val"><?= $c['n_students'] ?></div>
                <div class="cstat-lbl">Students</div>
            </div>
            <div class="cstat">
                <div class="cstat-val"><?= $c['n_lessons'] ?></div>
                <div class="cstat-lbl">Lessons</div>
            </div>
            <div class="cstat">
                <div class="cstat-val">L<?= h($c['level']) ?></div>
                <div class="cstat-lbl">Level</div>
            </div>
            <div class="cstat">
                <div class="cstat-val">S<?= h($c['semester'] ?? 1) ?></div>
                <div class="cstat-lbl">Semester</div>
            </div>
        </div>
    </div>

    <div class="course-card-footer">
        <span class="badge <?= $c['is_published'] ? 'bg-green' : 'bg-muted' ?>">
            <i class="fas fa-<?= $c['is_published'] ? 'circle' : 'circle' ?>" style="font-size:7px"></i>
            <?= $c['is_published'] ? 'Published' : 'Draft' ?>
        </span>
        <div class="flex gap">
            <button class="btn btn-secondary btn-sm" title="Edit"
                onclick="openEditCourse(
                    <?= $c['id'] ?>,
                    <?= htmlspecialchars(json_encode($c['title']), ENT_QUOTES) ?>,
                    <?= htmlspecialchars(json_encode($c['course_code'] ?? ''), ENT_QUOTES) ?>,
                    <?= htmlspecialchars(json_encode($c['description'] ?? ''), ENT_QUOTES) ?>,
                    '<?= h($c['level']) ?>',
                    '<?= h($c['semester'] ?? 1) ?>',
                    <?= htmlspecialchars(json_encode(array_column(array_merge([['id'=>$c['instructor_id']]],$coIns),'id')), ENT_QUOTES) ?>
                )">
                <i class="fas fa-edit"></i>
            </button>
            <a href="courses.php?toggle_pub=<?= $c['id'] ?>" class="btn btn-secondary btn-sm" title="<?= $c['is_published'] ? 'Unpublish' : 'Publish' ?>">
                <i class="fas fa-<?= $c['is_published'] ? 'eye-slash' : 'eye' ?>"></i>
            </a>
            <a href="courses.php?del=<?= $c['id'] ?>" class="btn btn-danger btn-sm"
               onclick="return confirm('Delete this course and ALL its lessons? This cannot be undone.')">
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
    <p>Try a different search term or level filter.</p>
</div>
<?php endif; ?>


<!-- ===== CREATE COURSE MODAL ===== -->
<div class="modal-wrap" id="m-create-course">
  <div class="modal-box" style="max-width:560px">
    <div class="modal-head">
      <h3><i class="fas fa-plus-circle" style="color:var(--gold)"></i> Create New Course</h3>
      <button class="modal-close" onclick="closeModal('m-create-course')"><i class="fas fa-times"></i></button>
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
            <input class="fc" type="text" name="c_code" placeholder="e.g. IT101" maxlength="20"
                   oninput="this.value=this.value.toUpperCase()">
            <small style="color:var(--muted);font-size:11px;display:block;margin-top:4px"><i class="fas fa-info-circle"></i> Optional — must be unique</small>
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Description</label>
          <textarea class="fc" name="c_desc" placeholder="What will students learn?"></textarea>
        </div>
        <div class="g2">
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
            <label class="lbl2">Semester *</label>
            <select class="fc" name="c_semester" required>
              <option value="1">Semester 1</option>
              <option value="2">Semester 2</option>
            </select>
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Assign Instructors *
            <span style="color:var(--muted);font-weight:400;font-size:11px">(first checked = primary)</span>
          </label>
          <?php if (empty($instructors)): ?>
          <div style="padding:14px;background:rgba(231,76,60,.08);border:1px solid rgba(231,76,60,.25);border-radius:8px;font-size:13px;color:#ff8a80">
            <i class="fas fa-exclamation-circle"></i> No active instructors found.
            <a href="instructors.php" style="color:var(--gold);text-decoration:none"> Add an instructor first →</a>
          </div>
          <?php else: ?>
          <div class="ins-multi-wrap">
            <?php foreach ($instructors as $ins): ?>
            <div class="ins-multi-item">
              <input type="checkbox" name="c_instructors[]" value="<?= $ins['id'] ?>" id="ci_<?= $ins['id'] ?>">
              <div style="flex:1">
                <label for="ci_<?= $ins['id'] ?>"><?= h($ins['full_name']) ?></label>
                <div class="ins-email"><?= h($ins['email']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="ins-note"><i class="fas fa-info-circle"></i> Select one or more. First checked becomes the primary instructor.</div>
          <?php endif; ?>
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
  <div class="modal-box" style="max-width:560px">
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
                   oninput="this.value=this.value.toUpperCase()">
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Description</label>
          <textarea class="fc" name="ec_desc" id="ec_desc"></textarea>
        </div>
        <div class="g2">
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
            <label class="lbl2">Semester *</label>
            <select class="fc" name="ec_semester" id="ec_semester" required>
              <option value="1">Semester 1</option>
              <option value="2">Semester 2</option>
            </select>
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Assign Instructors *
            <span style="color:var(--muted);font-weight:400;font-size:11px">(first checked = primary)</span>
          </label>
          <div class="ins-multi-wrap" id="ec-ins-wrap">
            <?php foreach ($instructors as $ins): ?>
            <div class="ins-multi-item">
              <input type="checkbox" name="ec_instructors[]" value="<?= $ins['id'] ?>" id="eci_<?= $ins['id'] ?>">
              <div style="flex:1">
                <label for="eci_<?= $ins['id'] ?>"><?= h($ins['full_name']) ?></label>
                <div class="ins-email"><?= h($ins['email']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="ins-note"><i class="fas fa-info-circle"></i> First checked = primary instructor.</div>
        </div>
        <button class="btn btn-primary" style="width:100%" type="submit" name="do_edit_course">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </form>
    </div>
  </div>
</div>

<script>
// ── Search + level + programme filter ──
var activeLevel = 'all';
function filterCourses() {
    var q = document.getElementById('course-search').value.toLowerCase().trim();
    var cards = document.querySelectorAll('#courses-grid .course-card');
    var visible = 0;
    cards.forEach(function(card) {
        var matchSearch = !q || card.getAttribute('data-search').indexOf(q) !== -1;
        var matchLevel  = activeLevel === 'all' || card.getAttribute('data-level') === activeLevel;
        var show = matchSearch && matchLevel;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var noRes = document.getElementById('no-results');
    if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
}

function setLevelFilter(el, level) {
    activeLevel = level;
    document.querySelectorAll('.ftab:not(.ftab-prog)').forEach(function(t){ t.classList.remove('active'); });
    el.classList.add('active');
    filterCourses();
}

// ── Open Edit Course modal ──
function openEditCourse(id, title, code, desc, level, semester, instructorIds) {
    document.getElementById('ec_id').value        = id;
    document.getElementById('ec_title').value     = title;
    document.getElementById('ec_code').value      = code;
    document.getElementById('ec_desc').value      = desc;
    document.getElementById('ec_level').value     = level;
    document.getElementById('ec_semester').value  = semester;
    document.querySelectorAll('#ec-ins-wrap input[type=checkbox]').forEach(function(cb) {
        cb.checked = instructorIds.indexOf(parseInt(cb.value)) !== -1;
    });
    openModal('m-edit-course');
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
