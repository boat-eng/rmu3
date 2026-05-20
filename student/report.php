<?php
require_once '../includes/config.php';
requireRole('student');

$uid  = (int)$_SESSION['user_id'];
$name = $_SESSION['full_name'] ?? 'Student';

// ── All enrolled courses with full progress ──
$courses = $pdo->prepare('
    SELECT c.id, c.title, c.level, c.semester, u.full_name AS instructor,
           e.enrolled_at,
           COUNT(DISTINCT l.id)   AS total_lessons,
           COUNT(DISTINCT lp.id)  AS done_lessons,
           ROUND(AVG(r.rating),1) AS my_rating
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    JOIN users u   ON u.id = c.instructor_id
    LEFT JOIN lessons l           ON l.course_id = c.id
    LEFT JOIN lesson_progress lp  ON lp.lesson_id = l.id AND lp.student_id = ?
    LEFT JOIN course_ratings r    ON r.course_id = c.id  AND r.student_id  = ?
    WHERE e.student_id = ?
    GROUP BY c.id, e.enrolled_at
    ORDER BY c.level ASC, c.semester ASC, e.enrolled_at DESC
');
$courses->execute([$uid, $uid, $uid]);
$courses = $courses->fetchAll();

// ── Quiz attempts ──
$quizzes = [];
try {
    $qzStmt = $pdo->prepare('
        SELECT qa.id AS attempt_id, qa.score, qa.total_marks, qa.passed, qa.submitted_at,
               qz.title AS quiz_title, qz.pass_mark, qz.id AS quiz_id,
               c.title AS course_title, c.id AS course_id, c.level
        FROM quiz_attempts qa
        JOIN quizzes qz ON qz.id = qa.quiz_id
        JOIN courses c  ON c.id  = qz.course_id
        WHERE qa.student_id = ?
        ORDER BY qa.submitted_at DESC
    ');
    $qzStmt->execute([$uid]);
    $quizzes = $qzStmt->fetchAll();
} catch(Exception $e) {}

// ── Summary numbers ──
$totalEnrolled  = count($courses);
$totalCompleted = 0;
$totalInProgress= 0;
$totalNotStarted= 0;
foreach ($courses as $c) {
    if ($c['total_lessons'] > 0 && $c['done_lessons'] >= $c['total_lessons']) $totalCompleted++;
    elseif ($c['done_lessons'] > 0) $totalInProgress++;
    else $totalNotStarted++;
}

$totalLessonsDone = array_sum(array_column($courses, 'done_lessons'));
$totalLessons     = array_sum(array_column($courses, 'total_lessons'));
$totalQuizzes     = count($quizzes);
$totalPassed      = count(array_filter($quizzes, fn($q) => $q['passed'] == 1));
$totalFailed      = $totalQuizzes - $totalPassed;
$quizPassRate     = $totalQuizzes > 0 ? round($totalPassed / $totalQuizzes * 100) : 0;

// Average quiz score (earned/total where total > 0)
$avgScore = 0;
if ($totalQuizzes > 0) {
    $totalEarned = array_sum(array_column($quizzes, 'score'));
    $totalMax    = array_sum(array_column($quizzes, 'total_marks'));
    $avgScore    = $totalMax > 0 ? round($totalEarned / $totalMax * 100) : 0;
}

// Student level — derived from the highest level course they are enrolled in
// (avoids dependency on a non-existent current_level column in users)
$levelValues = array_column($courses, 'level');
$studentLevel = !empty($levelValues) ? (int)max($levelValues) : 100;

$generatedAt = date('F j, Y \a\t g:i A');

$pageTitle    = 'My Academic Report';
$pageSubtitle = 'Performance summary — generated ' . date('M j, Y');
$activePage   = 'dashboard';
$depth        = 1;
ob_start();
?>

<style>
/* ── Print styles ── */
@media print {
    .no-print { display:none !important; }
    .rpt-wrap { max-width:100% !important; }
    .rpt-section { break-inside:avoid; }
    body { background:#fff !important; }
}

/* ── Layout ── */
.rpt-wrap { max-width:900px; margin:0 auto; }

/* ── Report header ── */
.rpt-header { background:linear-gradient(135deg,#001a3e,#003580);
    border-radius:14px; padding:28px 32px; margin-bottom:20px;
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:16px; border:1px solid rgba(200,168,75,.2); }
.rpt-header-left h1 { font-family:'Cinzel',serif; font-size:22px;
    color:#fff; margin-bottom:4px; }
.rpt-header-left p  { font-size:13px; color:rgba(255,255,255,.6); margin:2px 0; }
.rpt-logo { font-family:'Cinzel',serif; font-size:28px; font-weight:700;
    color:var(--gold); text-align:right; line-height:1.1; }
.rpt-logo small { display:block; font-size:10px; color:rgba(255,255,255,.4);
    letter-spacing:2px; text-transform:uppercase; font-family:sans-serif; font-weight:400; }

/* ── Summary stat cards ── */
.rpt-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr));
    gap:10px; margin-bottom:20px; }
.rpt-stat { background:var(--surface); border:1px solid var(--border);
    border-radius:12px; padding:16px 14px; text-align:center; }
.rpt-stat-val { font-size:28px; font-weight:700; font-family:'Cinzel',serif;
    color:var(--white); line-height:1.1; }
.rpt-stat-lbl { font-size:11px; color:var(--muted); margin-top:5px;
    text-transform:uppercase; letter-spacing:.05em; }
.rpt-stat-icon { font-size:18px; margin-bottom:6px; }

/* ── Section cards ── */
.rpt-section { background:var(--surface); border:1px solid var(--border);
    border-radius:12px; margin-bottom:16px; overflow:hidden; }
.rpt-section-head { padding:14px 20px; border-bottom:1px solid var(--border);
    background:rgba(200,168,75,.04);
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.rpt-section-title { font-family:'Cinzel',serif; font-size:14px; color:var(--white);
    display:flex; align-items:center; gap:8px; }
.rpt-section-body { padding:0; }

/* ── Course rows ── */
.rpt-course-row { display:flex; align-items:center; gap:14px; flex-wrap:wrap;
    padding:13px 20px; border-bottom:1px solid var(--border); }
.rpt-course-row:last-child { border-bottom:none; }
.rpt-course-name { flex:1; min-width:160px; }
.rpt-course-name strong { font-size:13px; color:var(--white); display:block; margin-bottom:2px; }
.rpt-course-name span   { font-size:11px; color:var(--muted); }
.rpt-pbar-wrap { flex:1; min-width:100px; }
.rpt-pbar      { height:6px; background:var(--surf2); border-radius:3px; overflow:hidden; }
.rpt-pbar-fill { height:100%; border-radius:3px; transition:width .4s; }
.rpt-pbar-lbl  { font-size:11px; color:var(--muted); margin-top:4px; }
.rpt-status    { flex-shrink:0; }

/* ── Quiz rows ── */
.rpt-quiz-row { display:flex; align-items:center; gap:14px; flex-wrap:wrap;
    padding:12px 20px; border-bottom:1px solid var(--border); }
.rpt-quiz-row:last-child { border-bottom:none; }
.rpt-quiz-name { flex:1; min-width:160px; }
.rpt-quiz-name strong { font-size:13px; color:var(--white); display:block; margin-bottom:2px; }
.rpt-quiz-name span   { font-size:11px; color:var(--muted); }
.rpt-score-pill { font-size:13px; font-weight:700; padding:4px 12px;
    border-radius:20px; flex-shrink:0; }
.rpt-score-pass { background:rgba(39,174,96,.15); color:#4caf82; }
.rpt-score-fail { background:rgba(231,76,60,.12); color:#ff8a80; }

/* ── Performance ring (CSS only) ── */
.rpt-perf-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;
    padding:16px 20px; }
@media(max-width:600px){ .rpt-perf-grid { grid-template-columns:1fr 1fr; } }
.rpt-perf-item { text-align:center; }
.rpt-ring-wrap { position:relative; width:80px; height:80px; margin:0 auto 8px; }
.rpt-ring-svg  { transform:rotate(-90deg); }
.rpt-ring-bg   { fill:none; stroke:var(--surf2); stroke-width:8; }
.rpt-ring-fill { fill:none; stroke-width:8; stroke-linecap:round; transition:stroke-dashoffset .6s; }
.rpt-ring-label { position:absolute; inset:0; display:flex; align-items:center;
    justify-content:center; font-size:14px; font-weight:700;
    font-family:'Cinzel',serif; color:var(--white); }
.rpt-perf-name  { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }

/* ── Level badge ── */
.rpt-level { display:inline-flex; align-items:center; gap:6px; padding:5px 14px;
    background:rgba(200,168,75,.12); border:1px solid rgba(200,168,75,.3);
    border-radius:20px; font-size:12px; font-weight:700; color:var(--gold); }

/* ── Print button ── */
.print-btn { background:rgba(200,168,75,.12); border:1px solid rgba(200,168,75,.3);
    color:var(--gold); padding:9px 20px; border-radius:9px; font-size:13px;
    font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
.print-btn:hover { background:rgba(200,168,75,.22); }
</style>

<div class="rpt-wrap">

  <!-- Action bar -->
  <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px">
    <a href="dashboard.php" class="btn btn-secondary btn-sm">
      <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
    <button class="print-btn" onclick="window.print()">
      <i class="fas fa-print"></i> Print / Save as PDF
    </button>
  </div>

  <!-- Report header -->
  <div class="rpt-header">
    <div class="rpt-header-left">
      <h1><?= h($name) ?></h1>
      <p><i class="fas fa-layer-group" style="color:var(--gold);margin-right:5px"></i> Current Level: <?= $studentLevel ?></p>
      <p><i class="fas fa-envelope" style="color:var(--gold);margin-right:5px"></i> <?= h($_SESSION['email'] ?? '') ?></p>
      <p style="margin-top:8px;font-size:11px;color:rgba(255,255,255,.35)">
        Report generated: <?= $generatedAt ?>
      </p>
    </div>
    <div class="rpt-logo">
      RMU
      <small>E-Learning Platform</small>
      <div style="margin-top:10px">
        <span class="rpt-level"><i class="fas fa-graduation-cap"></i> Level <?= $studentLevel ?> Student</span>
      </div>
    </div>
  </div>

  <!-- Summary stats -->
  <div class="rpt-stats">
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:var(--gold)"><i class="fas fa-book-open"></i></div>
      <div class="rpt-stat-val"><?= $totalEnrolled ?></div>
      <div class="rpt-stat-lbl">Enrolled</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:#4caf82"><i class="fas fa-graduation-cap"></i></div>
      <div class="rpt-stat-val"><?= $totalCompleted ?></div>
      <div class="rpt-stat-lbl">Completed</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:#64b5f6"><i class="fas fa-spinner"></i></div>
      <div class="rpt-stat-val"><?= $totalInProgress ?></div>
      <div class="rpt-stat-lbl">In Progress</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:var(--muted)"><i class="fas fa-minus-circle"></i></div>
      <div class="rpt-stat-val"><?= $totalNotStarted ?></div>
      <div class="rpt-stat-lbl">Not Started</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:#ce93d8"><i class="fas fa-film"></i></div>
      <div class="rpt-stat-val"><?= $totalLessonsDone ?></div>
      <div class="rpt-stat-lbl">Lessons Done</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:var(--gold)"><i class="fas fa-clipboard-list"></i></div>
      <div class="rpt-stat-val"><?= $totalQuizzes ?></div>
      <div class="rpt-stat-lbl">Quiz Attempts</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:#4caf82"><i class="fas fa-check-double"></i></div>
      <div class="rpt-stat-val"><?= $totalPassed ?></div>
      <div class="rpt-stat-lbl">Quizzes Passed</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:#ff8a80"><i class="fas fa-times-circle"></i></div>
      <div class="rpt-stat-val"><?= $totalFailed ?></div>
      <div class="rpt-stat-lbl">Quizzes Failed</div>
    </div>
  </div>

  <!-- Performance overview -->
  <div class="rpt-section" style="margin-bottom:16px">
    <div class="rpt-section-head">
      <div class="rpt-section-title">
        <i class="fas fa-chart-pie" style="color:var(--gold)"></i> Performance Overview
      </div>
    </div>
    <div class="rpt-perf-grid">

      <?php
      $rings = [
          ['label'=>'Course Completion','val'=>$totalEnrolled>0?round($totalCompleted/$totalEnrolled*100):0,'color'=>'#4caf82'],
          ['label'=>'Lesson Progress',  'val'=>$totalLessons>0?round($totalLessonsDone/$totalLessons*100):0,'color'=>'#64b5f6'],
          ['label'=>'Quiz Pass Rate',   'val'=>$quizPassRate,'color'=>'#c8a84b'],
      ];
      $r = 34; $circ = round(2 * M_PI * $r, 2);
      foreach ($rings as $ring):
          $offset = round($circ - ($ring['val'] / 100) * $circ, 2);
      ?>
      <div class="rpt-perf-item">
        <div class="rpt-ring-wrap">
          <svg class="rpt-ring-svg" width="80" height="80" viewBox="0 0 80 80">
            <circle class="rpt-ring-bg"   cx="40" cy="40" r="<?= $r ?>"/>
            <circle class="rpt-ring-fill" cx="40" cy="40" r="<?= $r ?>"
              stroke="<?= $ring['color'] ?>"
              stroke-dasharray="<?= $circ ?>"
              stroke-dashoffset="<?= $offset ?>"/>
          </svg>
          <div class="rpt-ring-label"><?= $ring['val'] ?>%</div>
        </div>
        <div class="rpt-perf-name"><?= $ring['label'] ?></div>
      </div>
      <?php endforeach; ?>

    </div>
  </div>

  <!-- Course breakdown -->
  <div class="rpt-section">
    <div class="rpt-section-head">
      <div class="rpt-section-title">
        <i class="fas fa-book" style="color:var(--gold)"></i>
        Course Breakdown
        <span style="font-size:11px;color:var(--muted);font-family:sans-serif;font-weight:400">(<?= $totalEnrolled ?> courses)</span>
      </div>
      <div style="display:flex;gap:12px;font-size:11px;flex-wrap:wrap">
        <span style="color:#4caf82"><i class="fas fa-circle" style="font-size:8px"></i> Completed (<?= $totalCompleted ?>)</span>
        <span style="color:#64b5f6"><i class="fas fa-circle" style="font-size:8px"></i> In Progress (<?= $totalInProgress ?>)</span>
        <span style="color:var(--muted)"><i class="fas fa-circle" style="font-size:8px"></i> Not Started (<?= $totalNotStarted ?>)</span>
      </div>
    </div>
    <div class="rpt-section-body">
      <?php if(empty($courses)): ?>
      <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px">
        No courses enrolled yet.
      </div>
      <?php else: ?>
      <?php foreach($courses as $c):
        $pct = $c['total_lessons'] > 0 ? round($c['done_lessons'] / $c['total_lessons'] * 100) : 0;
        $isDone = $c['total_lessons'] > 0 && $c['done_lessons'] >= $c['total_lessons'];
        $isStarted = $c['done_lessons'] > 0;
        $barColor = $isDone ? '#4caf82' : ($isStarted ? '#64b5f6' : 'var(--muted)');
        $statusBadge = $isDone
            ? '<span class="badge bg-green" style="font-size:10px"><i class="fas fa-check-circle"></i> Completed</span>'
            : ($isStarted
                ? '<span class="badge bg-blue" style="font-size:10px"><i class="fas fa-spinner"></i> In Progress</span>'
                : '<span class="badge bg-muted" style="font-size:10px">Not Started</span>');
      ?>
      <div class="rpt-course-row">
        <div style="flex-shrink:0">
          <span class="badge bg-gold" style="font-size:9px">L<?= $c['level'] ?> · S<?= $c['semester'] ?? 1 ?></span>
        </div>
        <div class="rpt-course-name">
          <strong><?= h($c['title']) ?></strong>
          <span><?= h($c['instructor']) ?> &nbsp;·&nbsp; Enrolled <?= date('M j, Y', strtotime($c['enrolled_at'])) ?></span>
        </div>
        <div class="rpt-pbar-wrap">
          <div class="rpt-pbar">
            <div class="rpt-pbar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
          </div>
          <div class="rpt-pbar-lbl"><?= $c['done_lessons'] ?> / <?= $c['total_lessons'] ?> lessons &nbsp;·&nbsp; <?= $pct ?>%</div>
        </div>
        <div class="rpt-status"><?= $statusBadge ?></div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Quiz results -->
  <div class="rpt-section">
    <div class="rpt-section-head">
      <div class="rpt-section-title">
        <i class="fas fa-clipboard-list" style="color:var(--gold)"></i>
        Quiz Results
        <span style="font-size:11px;color:var(--muted);font-family:sans-serif;font-weight:400">(<?= $totalQuizzes ?> attempt<?= $totalQuizzes!=1?'s':'' ?>)</span>
      </div>
      <?php if($totalQuizzes > 0): ?>
      <div style="font-size:12px;color:var(--muted)">
        Avg score: <strong style="color:var(--white)"><?= $avgScore ?>%</strong>
        &nbsp;·&nbsp; Pass rate: <strong style="color:<?= $quizPassRate >= 50 ? '#4caf82' : '#ff8a80' ?>"><?= $quizPassRate ?>%</strong>
      </div>
      <?php endif; ?>
    </div>
    <div class="rpt-section-body">
      <?php if(empty($quizzes)): ?>
      <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px">
        No quiz attempts yet.
      </div>
      <?php else: ?>
      <?php foreach($quizzes as $qz):
        $earned  = (float)$qz['score'];
        $total   = (int)$qz['total_marks'];
        $pctBar  = $total > 0 ? min(100, round($earned / $total * 100)) : 0;
        $passRaw = $total > 0 ? ceil($total * $qz['pass_mark'] / 100) : 0;
      ?>
      <div class="rpt-quiz-row">
        <div style="flex-shrink:0">
          <span class="badge bg-blue" style="font-size:9px">L<?= $qz['level'] ?></span>
        </div>
        <div class="rpt-quiz-name">
          <strong><?= h($qz['quiz_title']) ?></strong>
          <span><?= h($qz['course_title']) ?> &nbsp;·&nbsp; <?= date('M j, Y', strtotime($qz['submitted_at'])) ?></span>
        </div>
        <div style="flex:1;min-width:100px">
          <div class="rpt-pbar">
            <div class="rpt-pbar-fill" style="width:<?= $pctBar ?>%;background:<?= $qz['passed']?'#4caf82':'#e74c3c' ?>"></div>
          </div>
          <div class="rpt-pbar-lbl">
            <?= (int)$earned ?> / <?= $total ?> marks
            &nbsp;·&nbsp; pass mark: <?= $passRaw ?> / <?= $total ?>
          </div>
        </div>
        <div>
          <span class="rpt-score-pill <?= $qz['passed']?'rpt-score-pass':'rpt-score-fail' ?>">
            <i class="fas fa-<?= $qz['passed']?'check':'times' ?>-circle"></i>
            <?= (int)$earned ?> / <?= $total ?>
          </span>
        </div>
        <div>
          <span class="badge <?= $qz['passed']?'bg-green':'bg-red' ?>" style="font-size:10px">
            <?= $qz['passed'] ? 'Passed' : 'Failed' ?>
          </span>
        </div>
        <a href="quiz_result.php?attempt=<?= $qz['attempt_id'] ?>" class="btn btn-secondary btn-sm no-print">
          <i class="fas fa-eye"></i>
        </a>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Footer -->
  <div style="text-align:center;padding:16px 0 8px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);margin-top:8px">
    <i class="fas fa-shield-alt" style="color:var(--gold)"></i>
    This report was generated automatically from your RMU E-Learning account on <?= $generatedAt ?>.
  </div>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
