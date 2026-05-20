<?php
require_once '../includes/config.php';
requireRole('student');

$uid = (int)$_SESSION['user_id'];

// All enrolled courses with progress
$stmt = $pdo->prepare('
    SELECT c.id, c.title, c.level, u.full_name AS instructor, e.enrolled_at,
           COUNT(DISTINCT l.id)  AS total,
           COUNT(DISTINCT lp.id) AS done
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    JOIN users u ON u.id = c.instructor_id
    LEFT JOIN lessons l  ON l.course_id = c.id
    LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = ?
    WHERE e.student_id = ?
    GROUP BY c.id, e.enrolled_at
    ORDER BY e.enrolled_at DESC
');
$stmt->execute([$uid, $uid]);
$courses = $stmt->fetchAll();

// Quiz results
$quizResults = [];
try {
    $qr = $pdo->prepare('
        SELECT qa.id AS attempt_id, qa.score, qa.passed, qa.submitted_at, qa.total_marks,
               qz.title AS quiz_title, qz.pass_mark,
               c.title AS course_title, c.id AS course_id
        FROM quiz_attempts qa
        JOIN quizzes qz ON qz.id = qa.quiz_id
        JOIN courses c ON c.id = qz.course_id
        WHERE qa.student_id = ?
        ORDER BY qa.submitted_at DESC
    ');
    $qr->execute([$uid]);
    $quizResults = $qr->fetchAll();
} catch(Exception $e) {}

// Summary stats
$nTotal     = count($courses);
$nCompleted = count(array_filter($courses, fn($c) => $c['total'] > 0 && $c['done'] >= $c['total']));
$nProgress  = count(array_filter($courses, fn($c) => $c['done'] > 0 && $c['done'] < $c['total']));
$totalLessons = array_sum(array_column($courses, 'total'));
$doneLessons  = array_sum(array_column($courses, 'done'));
$overallPct   = $totalLessons > 0 ? round($doneLessons / $totalLessons * 100) : 0;
$quizPassed   = count(array_filter($quizResults, fn($q) => $q['passed'] == 1));

$pageTitle    = 'My Progress';
$pageSubtitle = 'Learning overview';
$activePage   = 'progress';
$depth        = 1;
ob_start();
?>

<style>
.prog-stat-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:24px; }
.prog-stat { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:16px 18px; }
.prog-stat-val { font-size:28px; font-weight:700; color:var(--white); font-family:'Cinzel',serif; }
.prog-stat-lbl { font-size:12px; color:var(--muted); margin-top:3px; }
.overall-bar-wrap { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:20px 24px; margin-bottom:20px; }
.big-bar { height:14px; background:rgba(255,255,255,.07); border-radius:8px; overflow:hidden; }
.big-bar-fill { height:100%; background:linear-gradient(90deg,#c8a84b,#f0cc6a); border-radius:8px; }
.c-prog-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:16px 18px; margin-bottom:12px; }
.c-prog-title { font-size:14px; font-weight:700; color:var(--white); margin-bottom:4px; }
.c-prog-meta  { font-size:12px; color:var(--muted); margin-bottom:10px; display:flex; gap:14px; flex-wrap:wrap; }
.quiz-row { display:flex; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid var(--border); flex-wrap:wrap; }
.quiz-row:last-child { border-bottom:none; }
@media(max-width:600px){ .prog-stat-grid{ grid-template-columns:1fr 1fr; } }
</style>

<!-- Summary stats -->
<div class="prog-stat-grid">
    <div class="prog-stat">
        <div class="prog-stat-val"><?= $nTotal ?></div>
        <div class="prog-stat-lbl"><i class="fas fa-book-open" style="color:var(--gold)"></i> Enrolled</div>
    </div>
    <div class="prog-stat">
        <div class="prog-stat-val" style="color:#4caf82"><?= $nCompleted ?></div>
        <div class="prog-stat-lbl"><i class="fas fa-check-circle" style="color:#4caf82"></i> Completed</div>
    </div>
    <div class="prog-stat">
        <div class="prog-stat-val" style="color:#64b5f6"><?= $nProgress ?></div>
        <div class="prog-stat-lbl"><i class="fas fa-spinner" style="color:#64b5f6"></i> In Progress</div>
    </div>
    <div class="prog-stat">
        <div class="prog-stat-val" style="color:#ce93d8"><?= $quizPassed ?></div>
        <div class="prog-stat-lbl"><i class="fas fa-trophy" style="color:#ce93d8"></i> Quizzes Passed</div>
    </div>
</div>

<!-- Overall progress bar -->
<div class="overall-bar-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <span style="font-size:14px;font-weight:700;color:var(--white)">Overall Learning Progress</span>
        <span style="font-size:20px;font-weight:700;color:var(--gold);font-family:'Cinzel',serif"><?= $overallPct ?>%</span>
    </div>
    <div class="big-bar"><div class="big-bar-fill" style="width:<?= $overallPct ?>%"></div></div>
    <div style="font-size:12px;color:var(--muted);margin-top:8px">
        <?= $doneLessons ?> of <?= $totalLessons ?> total lessons completed across all courses
    </div>
</div>

<!-- Course breakdown -->
<div class="flex-between mb2">
    <span class="card-title"><i class="fas fa-book" style="color:var(--gold)"></i> Course Breakdown</span>
</div>

<?php if(empty($courses)): ?>
<div class="card" style="text-align:center;padding:50px;color:var(--muted)">
    <i class="fas fa-chart-line" style="font-size:44px;display:block;margin-bottom:14px;opacity:.4"></i>
    <p>Enroll in courses to track your progress here.</p>
    <a href="browse.php" class="btn btn-primary" style="margin-top:16px"><i class="fas fa-compass"></i> Browse Courses</a>
</div>
<?php else: ?>
<?php foreach($courses as $c):
    $pct = $c['total'] > 0 ? round($c['done'] / $c['total'] * 100) : 0;
    $statusClass = $pct===100 ? 'bg-green' : ($pct>0 ? 'bg-blue' : 'bg-muted');
    $statusText  = $pct===100 ? 'Completed' : ($pct>0 ? 'In Progress' : 'Not Started');
?>
<div class="c-prog-card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:6px">
        <div class="c-prog-title"><?= h($c['title']) ?></div>
        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
    </div>
    <div class="c-prog-meta">
        <span><i class="fas fa-chalkboard-teacher"></i> <?= h($c['instructor']) ?></span>
        <span><i class="fas fa-film"></i> <?= $c['done'] ?>/<?= $c['total'] ?> lessons</span>
        <span><i class="fas fa-calendar-alt"></i> Enrolled <?= date('M j, Y', strtotime($c['enrolled_at'])) ?></span>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
        <div class="pbar" style="flex:1"><div class="pfill" style="width:<?= $pct ?>%"></div></div>
        <span style="font-size:13px;font-weight:700;color:var(--gold);min-width:38px;text-align:right"><?= $pct ?>%</span>
        <a href="watch.php?id=<?= $c['id'] ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-<?= $pct===100?'eye':'play' ?>"></i> <?= $pct===100?'Review':'Continue' ?>
        </a>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Quiz performance -->
<?php if(!empty($quizResults)): ?>
<div class="flex-between mb2" style="margin-top:24px">
    <span class="card-title"><i class="fas fa-question-circle" style="color:var(--gold)"></i> Quiz Performance</span>
</div>
<div class="card">
    <?php foreach($quizResults as $qr):
        $pending = false;
        try {
            $pc = $pdo->prepare('SELECT COUNT(*) FROM quiz_answers WHERE attempt_id=? AND is_correct IS NULL');
            $pc->execute([$qr['attempt_id']]);
            $pending = (int)$pc->fetchColumn() > 0;
        } catch(Exception $e) {}
    ?>
    <div class="quiz-row">
        <div style="flex:1;min-width:140px">
            <div style="font-size:13px;font-weight:700;color:var(--white)"><?= h($qr['quiz_title']) ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= h($qr['course_title']) ?></div>
        </div>
        <?php if($pending): ?>
        <span class="badge bg-gold"><i class="fas fa-hourglass-half"></i> Awaiting Review</span>
        <?php else: ?>
        <div style="display:flex;align-items:center;gap:8px">
            <div style="width:70px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;height:6px">
                <div style="height:100%;width:<?= min(100,$qr['score']) ?>%;background:<?= $qr['passed']?'#27ae60':'#e74c3c' ?>;border-radius:4px"></div>
            </div>
            <span style="font-size:13px;font-weight:700;color:<?= $qr['passed']?'#4caf82':'#ff8a80' ?>"><?= $qr['score'] ?>%</span>
        </div>
        <span class="badge <?= $qr['passed']?'bg-green':'bg-red' ?>">
            <?= $qr['passed']?'Passed':'Failed' ?>
        </span>
        <?php endif; ?>
        <span style="font-size:11px;color:var(--muted);white-space:nowrap"><?= date('M j, Y', strtotime($qr['submitted_at'])) ?></span>
        <a href="quiz_result.php?attempt=<?= $qr['attempt_id'] ?>" class="btn btn-secondary btn-sm">
            <i class="fas fa-eye"></i>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
