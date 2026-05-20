<?php
require_once '../includes/config.php';
requireRole('instructor');
require_once '../includes/mailer.php';

$uid = (int)$_SESSION['user_id'];
$cid = (int)($_GET['course'] ?? 0);

// Verify course access
$course = null;
if ($cid) {
    $cs = $pdo->prepare('SELECT * FROM courses WHERE id=? AND instructor_id=? LIMIT 1');
    $cs->execute([$cid, $uid]);
    $course = $cs->fetch();
    if (!$course) {
        try {
            $ci = $pdo->prepare('SELECT c.* FROM courses c JOIN course_instructors ci ON ci.course_id=c.id WHERE c.id=? AND ci.instructor_id=? LIMIT 1');
            $ci->execute([$cid, $uid]);
            $course = $ci->fetch();
        } catch(Exception $e) {}
    }
}
if (!$course) { header('Location: courses.php'); exit; }

// Auto-create quiz tables if missing
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quizzes (
        id INT AUTO_INCREMENT PRIMARY KEY, course_id INT NOT NULL,
        title VARCHAR(255) NOT NULL, description TEXT,
        pass_mark TINYINT NOT NULL DEFAULT 70, time_limit INT DEFAULT NULL,
        allow_retake TINYINT(1) DEFAULT 0, is_published TINYINT(1) DEFAULT 0,
        created_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_questions (
        id INT AUTO_INCREMENT PRIMARY KEY, quiz_id INT NOT NULL,
        question TEXT NOT NULL, type ENUM('mcq','truefalse','short') NOT NULL,
        marks INT DEFAULT 1, sort_order INT DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_options (
        id INT AUTO_INCREMENT PRIMARY KEY, question_id INT NOT NULL,
        option_text TEXT NOT NULL, is_correct TINYINT(1) DEFAULT 0, sort_order INT DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY, quiz_id INT NOT NULL, student_id INT NOT NULL,
        score DECIMAL(5,2) DEFAULT 0, total_marks INT DEFAULT 0,
        passed TINYINT(1) DEFAULT 0, submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_answers (
        id INT AUTO_INCREMENT PRIMARY KEY, attempt_id INT NOT NULL, question_id INT NOT NULL,
        answer_text TEXT, is_correct TINYINT(1) DEFAULT NULL, marks_given DECIMAL(4,2) DEFAULT 0
    )");
} catch(Exception $e) {}

$err = '';

// ── Create Quiz ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_create_quiz'])) {
    $title  = trim($_POST['q_title']  ?? '');
    $desc   = trim($_POST['q_desc']   ?? '');
    $pass   = max(1, min(100, (int)($_POST['q_pass'] ?? 70)));
    $time   = (int)($_POST['q_time']  ?? 0);
    $retake = isset($_POST['q_retake']) ? 1 : 0;
    if (!$title) { $err = 'Quiz title is required.'; }
    else {
        $pdo->prepare('INSERT INTO quizzes (course_id,title,description,pass_mark,time_limit,allow_retake,is_published,created_by) VALUES (?,?,?,?,?,?,0,?)')
            ->execute([$cid, $title, $desc, $pass, $time ?: null, $retake, $uid]);
        header('Location: quiz_manage.php?id=' . (int)$pdo->lastInsertId() . '&created=1'); exit;
    }
}

// ── Toggle publish ──
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $tid  = (int)$_GET['toggle'];
    $qRow = $pdo->prepare('SELECT title, is_published FROM quizzes WHERE id=? AND created_by=? LIMIT 1');
    $qRow->execute([$tid, $uid]); $qRow = $qRow->fetch();
    $pdo->prepare('UPDATE quizzes SET is_published=NOT is_published WHERE id=? AND created_by=?')->execute([$tid, $uid]);

    // Email students when publishing
    if ($qRow && !$qRow['is_published']) {
        // Fetch instructor name from DB (session may not have full_name)
        $instrRow = $pdo->prepare('SELECT full_name FROM users WHERE id=? LIMIT 1');
        $instrRow->execute([$uid]);
        $instructorName = $instrRow->fetchColumn() ?: 'Your instructor';

        $courseTitle = $course['title'];
        $subject = '📝 New Quiz Available: ' . $qRow['title'] . ' — ' . $courseTitle;
        $body = buildEmailTemplate(
            'A new quiz is available in ' . $courseTitle,
            '<p style="font-size:16px;color:rgba(255,255,255,.9);margin:0 0 8px">Hi {name},</p>
             <p style="font-size:14px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 0 20px">
               A new quiz has been published in <strong style="color:#c8a84b">' . htmlspecialchars($courseTitle) . '</strong>
               by ' . htmlspecialchars($instructorName) . '.
             </p>
             <div style="background:#0d2a4e;border:1px solid #1e3a5f;border-left:4px solid #c8a84b;border-radius:8px;padding:16px 20px;margin-bottom:24px">
               <div style="font-size:11px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">New Quiz</div>
               <div style="font-size:15px;font-weight:700;color:#fff;margin-bottom:4px">' . htmlspecialchars($qRow['title']) . '</div>
               <div style="font-size:12px;color:rgba(255,255,255,.4)">Complete all lessons to unlock the quiz</div>
             </div>
             <a href="' . BASE_URL . '/student/watch.php?id=' . $cid . '"
                style="display:inline-block;background:#c8a84b;color:#001a3e;font-weight:700;font-size:14px;
                       padding:12px 28px;border-radius:8px;text-decoration:none">
               📝 Go to Course
             </a>'
        );
        notifyEnrolledStudents($pdo, $cid, $subject, $body);

        // Notify primary instructor if a co-instructor published the quiz
        notifyPrimaryInstructor($pdo, $cid, $instructorName, 'quiz', $qRow['title'], 'quiz');
    }
    header('Location: quiz_create.php?course=' . $cid); exit;
}

// ── Delete quiz ──
if (isset($_GET['del']) && is_numeric($_GET['del'])) {
    $qid = (int)$_GET['del'];
    $pdo->prepare('DELETE FROM quiz_answers  WHERE attempt_id IN (SELECT id FROM quiz_attempts WHERE quiz_id=?)')->execute([$qid]);
    $pdo->prepare('DELETE FROM quiz_attempts WHERE quiz_id=?')->execute([$qid]);
    $pdo->prepare('DELETE FROM quiz_options  WHERE question_id IN (SELECT id FROM quiz_questions WHERE quiz_id=?)')->execute([$qid]);
    $pdo->prepare('DELETE FROM quiz_questions WHERE quiz_id=?')->execute([$qid]);
    $pdo->prepare('DELETE FROM quizzes WHERE id=? AND created_by=?')->execute([$qid, $uid]);
    header('Location: quiz_create.php?course=' . $cid . '&deleted=1'); exit;
}

// ── Download CSV Template ──
// Load quizzes
$quizzes = $pdo->prepare('
    SELECT q.*, COUNT(DISTINCT qq.id) AS n_questions,
           COALESCE(SUM(qq.marks),0) AS total_marks,
           (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id=q.id) AS n_attempts
    FROM quizzes q
    LEFT JOIN quiz_questions qq ON qq.quiz_id=q.id
    WHERE q.course_id=?
    GROUP BY q.id ORDER BY q.created_at DESC
');
$quizzes->execute([$cid]);
$quizzes = $quizzes->fetchAll();

$pageTitle    = 'Quizzes';
$pageSubtitle = h($course['title']);
$activePage   = 'courses';
$depth        = 1;
ob_start();
?>

<style>
/* Page layout */
.qc-wrap { display:grid; grid-template-columns:380px 1fr; gap:20px; align-items:start; }
@media(max-width:860px){ .qc-wrap { grid-template-columns:1fr; } }

/* Create form card */
.create-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; overflow:hidden; position:sticky; top:76px; }
.create-card-head { padding:16px 20px; border-bottom:1px solid var(--border); background:rgba(200,168,75,.04); }
.create-card-head h3 { font-family:'Cinzel',serif; font-size:15px; color:var(--white); }
.create-card-body { padding:20px; }

/* Toggle switch */
.tog-row { display:flex; align-items:center; justify-content:space-between;
    font-size:13px; color:var(--text); padding:8px 0; }
.tog { width:36px; height:20px; border-radius:10px; background:var(--surf2);
    border:1px solid var(--border); cursor:pointer; position:relative;
    transition:background .2s; flex-shrink:0; }
.tog.on { background:rgba(200,168,75,.8); border-color:var(--gold); }
.tog::after { content:''; position:absolute; width:14px; height:14px; border-radius:50%;
    background:#fff; top:2px; left:2px; transition:left .2s; }
.tog.on::after { left:18px; }

/* Quiz list cards */
.quiz-card { background:var(--surface); border:1px solid var(--border); border-radius:14px;
    padding:18px 20px; margin-bottom:12px; transition:border-color .15s; }
.quiz-card:hover { border-color:rgba(200,168,75,.3); }
.quiz-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; }
.quiz-card-title { font-size:15px; font-weight:700; color:var(--white); font-family:'Cinzel',serif; margin-bottom:4px; }
.quiz-card-meta  { font-size:12px; color:var(--muted); display:flex; flex-wrap:wrap; gap:10px; }
.quiz-meta-item  { display:flex; align-items:center; gap:5px; }

/* Stat pills inside quiz card */
.qc-pills { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.qc-pill  { padding:5px 12px; border-radius:20px; font-size:12px; font-weight:700; }
.qc-pill-q  { background:rgba(200,168,75,.1);  color:var(--gold); }
.qc-pill-m  { background:rgba(41,128,185,.1);  color:#64b5f6; }
.qc-pill-a  { background:rgba(142,68,173,.1);  color:#ce93d8; }

/* Pass mark bar */
.pass-bar-wrap { margin-bottom:14px; }
.pass-bar-label { display:flex; justify-content:space-between; font-size:11px; color:var(--muted); margin-bottom:5px; }
.pass-bar-track { height:5px; background:var(--surf2); border-radius:3px; overflow:hidden; }
.pass-bar-fill  { height:100%; background:var(--gold); border-radius:3px; }

/* Actions row */
.quiz-actions { display:flex; gap:8px; flex-wrap:wrap; padding-top:14px; border-top:1px solid var(--border); }

/* Empty state */
.empty-list { text-align:center; padding:60px 20px; color:var(--muted); }
.empty-list i { font-size:48px; display:block; margin-bottom:14px; opacity:.25; }
</style>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
    <div>
        <a href="manage.php?id=<?= $cid ?>" class="btn btn-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Back to Course
        </a>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:13px;color:var(--muted)">
            <i class="fas fa-question-circle" style="color:var(--gold)"></i>
            <?= count($quizzes) ?> quiz<?= count($quizzes)!=1?'zes':'' ?> for this course
        </span>
    </div>
</div>

<?php if($err): ?><div class="alert alert-err" style="margin-bottom:16px"><i class="fas fa-exclamation-circle"></i> <?= $err ?></div><?php endif; ?>
<?php if(isset($_GET['deleted'])): ?><div class="alert alert-ok" style="margin-bottom:16px"><i class="fas fa-check-circle"></i> Quiz deleted.</div><?php endif; ?>

<div class="qc-wrap">

  <!-- LEFT: Create new quiz -->
  <div class="create-card">
    <div class="create-card-head">
        <h3><i class="fas fa-plus-circle" style="color:var(--gold);margin-right:8px"></i>Create New Quiz</h3>
        <p style="font-size:12px;color:var(--muted);margin-top:4px">Set up a quiz then add questions</p>
    </div>
    <div class="create-card-body">
        <form method="POST">
            <div class="fg">
                <label class="lbl2">Quiz Title *</label>
                <input class="fc" type="text" name="q_title" placeholder="e.g. Module 1 Assessment" required>
            </div>
            <div class="fg">
                <label class="lbl2">Description <span style="color:var(--muted);font-weight:400">(optional)</span></label>
                <textarea class="fc" name="q_desc" rows="2" placeholder="Brief instructions for students…"></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="fg" style="margin-bottom:0">
                    <label class="lbl2">Pass Mark (%)</label>
                    <input class="fc" type="number" name="q_pass" value="70" min="1" max="100" id="new-pass" oninput="updateNewPassHint()">
                    <div style="font-size:11px;color:var(--muted);margin-top:4px" id="new-pass-hint">Students need 70% to pass</div>
                </div>
                <div class="fg" style="margin-bottom:0">
                    <label class="lbl2">Time Limit <span style="color:var(--muted);font-weight:400">(min)</span></label>
                    <input class="fc" type="number" name="q_time" min="1" placeholder="No limit">
                </div>
            </div>

            <div style="border-top:1px solid var(--border);margin:16px 0 12px"></div>

            <div class="tog-row">
                <span>Allow retakes</span>
                <div class="tog" id="new-retake-tog" onclick="this.classList.toggle('on')"></div>
                <input type="hidden" name="q_retake" id="new-retake-val" value="0">
            </div>

            <button class="btn btn-primary" type="submit" name="do_create_quiz"
                    style="width:100%;justify-content:center;margin-top:16px;padding:11px"
                    onclick="syncRetake()">
                <i class="fas fa-plus"></i> Create Quiz &amp; Add Questions
            </button>
        </form>
    </div>
  </div>

  <!-- RIGHT: Existing quizzes -->
  <div>
    <?php if(empty($quizzes)): ?>
    <div class="empty-list">
        <i class="fas fa-clipboard-list"></i>
        No quizzes yet for this course.<br>
        <span style="font-size:13px">Create your first quiz using the form on the left.</span>
    </div>

    <?php else: ?>
    <?php foreach($quizzes as $q):
        $passRaw = $q['total_marks'] > 0 ? ceil($q['total_marks'] * $q['pass_mark'] / 100) : 0;
        $passPct = $q['pass_mark'];
    ?>
    <div class="quiz-card">
        <div class="quiz-card-head">
            <div style="flex:1;min-width:0">
                <div class="quiz-card-title"><?= h($q['title']) ?></div>
                <?php if($q['description']): ?>
                <div style="font-size:12px;color:var(--muted);margin-top:2px;line-height:1.5">
                    <?= h(mb_substr($q['description'],0,80)) ?><?= mb_strlen($q['description'])>80?'…':'' ?>
                </div>
                <?php endif; ?>
            </div>
            <span class="badge <?= $q['is_published'] ? 'bg-green' : 'bg-muted' ?>" style="flex-shrink:0;padding:6px 12px">
                <i class="fas fa-<?= $q['is_published'] ? 'globe' : 'lock' ?>"></i>
                <?= $q['is_published'] ? 'Published' : 'Draft' ?>
            </span>
        </div>

        <!-- Pills -->
        <div class="qc-pills">
            <span class="qc-pill qc-pill-q"><i class="fas fa-question"></i> <?= $q['n_questions'] ?> question<?= $q['n_questions']!=1?'s':'' ?></span>
            <span class="qc-pill qc-pill-m"><i class="fas fa-star"></i> <?= (int)$q['total_marks'] ?> marks total</span>
            <span class="qc-pill qc-pill-a"><i class="fas fa-users"></i> <?= $q['n_attempts'] ?> attempt<?= $q['n_attempts']!=1?'s':'' ?></span>
            <?php if($q['time_limit']): ?>
            <span class="qc-pill" style="background:rgba(255,255,255,.06);color:var(--muted)">
                <i class="fas fa-clock"></i> <?= $q['time_limit'] ?> min
            </span>
            <?php endif; ?>
            <?php if($q['allow_retake']): ?>
            <span class="qc-pill" style="background:rgba(39,174,96,.1);color:#4caf82">
                <i class="fas fa-redo"></i> Retakes allowed
            </span>
            <?php endif; ?>
        </div>

        <!-- Pass mark bar -->
        <div class="pass-bar-wrap">
            <div class="pass-bar-label">
                <span>Pass mark: <strong style="color:var(--white)"><?= $passRaw ?> / <?= (int)$q['total_marks'] ?> marks</strong></span>
                <span><?= $passPct ?>%</span>
            </div>
            <div class="pass-bar-track">
                <div class="pass-bar-fill" style="width:<?= $passPct ?>%"></div>
            </div>
        </div>

        <!-- Actions -->
        <div class="quiz-actions">
            <a href="quiz_manage.php?id=<?= $q['id'] ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-edit"></i> Edit Questions
            </a>
            <a href="quiz_create.php?course=<?= $cid ?>&toggle=<?= $q['id'] ?>"
               class="btn btn-secondary btn-sm"
               onclick="return <?= $q['is_published'] ? "confirm('Unpublish this quiz? Students will no longer see it.')" : 'true' ?>">
                <i class="fas fa-<?= $q['is_published'] ? 'eye-slash' : 'globe' ?>"></i>
                <?= $q['is_published'] ? 'Unpublish' : 'Publish' ?>
            </a>
            <a href="quiz_results.php?id=<?= $q['id'] ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-chart-bar"></i> Results
            </a>
            <a href="quiz_create.php?course=<?= $cid ?>&del=<?= $q['id'] ?>"
               class="btn btn-secondary btn-sm" style="color:#ff8a80;border-color:rgba(231,76,60,.3);margin-left:auto"
               onclick="return confirm('Delete this quiz and ALL student attempts? This cannot be undone.')">
                <i class="fas fa-trash"></i>
            </a>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<script>
function updateNewPassHint() {
    var v = parseInt(document.getElementById('new-pass').value) || 70;
    document.getElementById('new-pass-hint').textContent = 'Students need ' + v + '% to pass';
}
function syncRetake() {
    document.getElementById('new-retake-val').value =
        document.getElementById('new-retake-tog').classList.contains('on') ? '1' : '0';
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
