<?php
require_once '../includes/config.php';
requireRole('instructor');

$uid  = (int)$_SESSION['user_id'];
$name = $_SESSION['full_name'] ?? 'Instructor';

// ── All courses this instructor teaches (primary + co-instructor) ──
$courseStmt = $pdo->prepare('
    SELECT DISTINCT c.*,
           COUNT(DISTINCT e.student_id) AS n_students,
           COUNT(DISTINCT l.id)         AS n_lessons,
           CASE WHEN c.instructor_id = :uid1 THEN 1 ELSE 0 END AS is_primary
    FROM courses c
    LEFT JOIN enrollments e ON e.course_id = c.id
    LEFT JOIN lessons l     ON l.course_id = c.id
    WHERE c.instructor_id = :uid2
       OR c.id IN (SELECT course_id FROM course_instructors WHERE instructor_id = :uid3)
    GROUP BY c.id
    ORDER BY c.level ASC, c.semester ASC
');
$courseStmt->execute([':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid]);
$courses = $courseStmt->fetchAll();

$courseIds = array_column($courses, 'id');

// ── Per-course student progress ──
$progressMap = [];
if (!empty($courseIds)) {
    $inList = implode(',', array_fill(0, count($courseIds), '?'));
    try {
        $progStmt = $pdo->prepare("
            SELECT e.course_id,
                   COUNT(DISTINCT e.student_id) AS total_students,
                   SUM(CASE WHEN done.done_count >= lc.lesson_count AND lc.lesson_count > 0 THEN 1 ELSE 0 END) AS completed,
                   SUM(CASE WHEN done.done_count > 0 AND (done.done_count < lc.lesson_count OR lc.lesson_count = 0) THEN 1 ELSE 0 END) AS in_progress,
                   SUM(CASE WHEN done.done_count IS NULL OR done.done_count = 0 THEN 1 ELSE 0 END) AS not_started
            FROM enrollments e
            LEFT JOIN (
                SELECT lp.student_id, l.course_id, COUNT(DISTINCT lp.lesson_id) AS done_count
                FROM lesson_progress lp
                JOIN lessons l ON l.id = lp.lesson_id
                WHERE l.course_id IN ($inList)
                GROUP BY lp.student_id, l.course_id
            ) done ON done.student_id = e.student_id AND done.course_id = e.course_id
            LEFT JOIN (
                SELECT course_id, COUNT(*) AS lesson_count FROM lessons WHERE course_id IN ($inList) GROUP BY course_id
            ) lc ON lc.course_id = e.course_id
            WHERE e.course_id IN ($inList)
            GROUP BY e.course_id
        ");
        $progStmt->execute(array_merge($courseIds, $courseIds, $courseIds));
        foreach ($progStmt->fetchAll() as $row) {
            $progressMap[$row['course_id']] = $row;
        }
    } catch(Exception $e) {}
}

// ── Quiz attempts across all instructor's courses ──
$quizMap        = [];
$allQuizAttempts = [];
if (!empty($courseIds)) {
    $inList = implode(',', array_fill(0, count($courseIds), '?'));
    try {
        $qzStmt = $pdo->prepare("
            SELECT qa.id AS attempt_id, qa.score, qa.total_marks, qa.passed, qa.submitted_at,
                   qz.title AS quiz_title, qz.pass_mark, qz.id AS quiz_id,
                   c.title AS course_title, c.id AS course_id, c.level,
                   u.full_name AS student_name
            FROM quiz_attempts qa
            JOIN quizzes qz ON qz.id  = qa.quiz_id
            JOIN courses c  ON c.id   = qz.course_id
            JOIN users u    ON u.id   = qa.student_id
            WHERE c.id IN ($inList)
            ORDER BY qa.submitted_at DESC
        ");
        $qzStmt->execute($courseIds);
        $allQuizAttempts = $qzStmt->fetchAll();
        foreach ($allQuizAttempts as $a) {
            $cid = $a['course_id'];
            if (!isset($quizMap[$cid])) {
                $quizMap[$cid] = ['total'=>0,'passed'=>0,'score_sum'=>0,'mark_sum'=>0];
            }
            $quizMap[$cid]['total']++;
            if ($a['passed']) $quizMap[$cid]['passed']++;
            $quizMap[$cid]['score_sum'] += $a['score'];
            $quizMap[$cid]['mark_sum']  += $a['total_marks'];
        }
    } catch(Exception $e) {}
}

// ── Top-level summary numbers ──
$totalCourses   = count($courses);
$totalPublished = count(array_filter($courses, fn($c) => $c['is_published']));
$totalLessons   = array_sum(array_column($courses, 'n_lessons'));
$totalStudents  = 0;
foreach ($progressMap as $p) $totalStudents += $p['total_students'];
$totalAttempts  = count($allQuizAttempts);
$totalPassed    = count(array_filter($allQuizAttempts, fn($a) => $a['passed'] == 1));
$overallPassRate = $totalAttempts > 0 ? round($totalPassed / $totalAttempts * 100) : 0;

// Overall lesson completion rate
$lessonCompletionRate = 0;
if (!empty($courseIds)) {
    $inList = implode(',', array_fill(0, count($courseIds), '?'));
    try {
        $doneStmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT CONCAT(lp.student_id,'-',lp.lesson_id)) AS done,
                COUNT(DISTINCT CONCAT(e.student_id,'-',l.id))          AS possible
            FROM enrollments e
            JOIN lessons l ON l.course_id = e.course_id
            LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = e.student_id
            WHERE e.course_id IN ($inList)
        ");
        $doneStmt->execute($courseIds);
        $doneRow = $doneStmt->fetch();
        $possible = (int)($doneRow['possible'] ?? 0);
        $done     = (int)($doneRow['done']     ?? 0);
        $lessonCompletionRate = $possible > 0 ? round($done / $possible * 100) : 0;
    } catch(Exception $e) {}
}

$generatedAt = date('F j, Y \a\t g:i A');

$pageTitle    = 'Teaching Report';
$pageSubtitle = 'Performance summary — generated ' . date('M j, Y');
$activePage   = 'report';
$depth        = 1;
ob_start();
?>

<style>
@media print {
    .no-print { display:none !important; }
    .rpt-wrap { max-width:100% !important; }
    .rpt-section { break-inside:avoid; }
    body { background:#fff !important; }
}

.rpt-wrap { max-width:960px; margin:0 auto; position:relative; z-index:1; }

.rpt-header {
    background:linear-gradient(135deg,#001a3e,#003580);
    border-radius:14px; padding:28px 32px; margin-bottom:20px;
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:16px; border:1px solid rgba(200,168,75,.25);
}
.rpt-header-left h1 { font-family:'Cinzel',serif; font-size:22px; color:#fff; margin-bottom:4px; }
.rpt-header-left p  { font-size:13px; color:rgba(255,255,255,.6); margin:2px 0; }
.rpt-logo { font-family:'Cinzel',serif; font-size:28px; font-weight:700;
    color:var(--gold); text-align:right; line-height:1.1; }
.rpt-logo small { display:block; font-size:10px; color:rgba(255,255,255,.4);
    letter-spacing:2px; text-transform:uppercase; font-family:sans-serif; font-weight:400; }

.rpt-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr));
    gap:10px; margin-bottom:20px; }
.rpt-stat { background:var(--surface); border:1px solid var(--border);
    border-radius:12px; padding:16px 14px; text-align:center; }
.rpt-stat-val  { font-size:28px; font-weight:700; font-family:'Cinzel',serif; color:var(--white); line-height:1.1; }
.rpt-stat-lbl  { font-size:11px; color:var(--muted); margin-top:5px; text-transform:uppercase; letter-spacing:.05em; }
.rpt-stat-icon { font-size:18px; margin-bottom:6px; }

.rpt-section { background:var(--surface); border:1px solid var(--border);
    border-radius:12px; margin-bottom:16px; overflow:hidden; }
.rpt-section-head { padding:14px 20px; border-bottom:1px solid var(--border);
    background:rgba(200,168,75,.04);
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.rpt-section-title { font-family:'Cinzel',serif; font-size:14px; color:var(--white);
    display:flex; align-items:center; gap:8px; }

.rpt-col-head {
    display:grid; grid-template-columns:1fr 80px 80px 1fr 110px;
    gap:14px; padding:8px 20px;
    font-size:10px; text-transform:uppercase; letter-spacing:.07em;
    color:var(--muted); border-bottom:1px solid var(--border); background:rgba(0,0,0,.15);
}
.rpt-course-row {
    display:grid; grid-template-columns:1fr 80px 80px 1fr 110px;
    align-items:center; gap:14px; padding:13px 20px;
    border-bottom:1px solid var(--border);
}
.rpt-course-row:last-child { border-bottom:none; }
.rpt-course-row:hover { background:rgba(200,168,75,.025); }

.rpt-pbar     { height:6px; background:var(--surf2); border-radius:3px; overflow:hidden; }
.rpt-pbar-fill{ height:100%; border-radius:3px; }
.rpt-pbar-lbl { font-size:10px; color:var(--muted); margin-top:3px; }

.rpt-perf-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr));
    gap:10px; padding:20px; }
.rpt-perf-item { text-align:center; }
.rpt-ring-wrap { position:relative; width:80px; height:80px; margin:0 auto 8px; }
.rpt-ring-svg  { transform:rotate(-90deg); }
.rpt-ring-bg   { fill:none; stroke:var(--surf2); stroke-width:8; }
.rpt-ring-fill { fill:none; stroke-width:8; stroke-linecap:round; }
.rpt-ring-label{ position:absolute; inset:0; display:flex; align-items:center;
    justify-content:center; font-size:14px; font-weight:700;
    font-family:'Cinzel',serif; color:var(--white); }
.rpt-perf-name { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }

.rpt-quiz-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap;
    padding:11px 20px; border-bottom:1px solid var(--border); }
.rpt-quiz-row:last-child { border-bottom:none; }
.rpt-score-pill { font-size:12px; font-weight:700; padding:3px 10px; border-radius:20px; flex-shrink:0; }
.rpt-score-pass { background:rgba(39,174,96,.15); color:#4caf82; }
.rpt-score-fail { background:rgba(231,76,60,.12); color:#ff8a80; }

.print-btn { background:rgba(200,168,75,.12); border:1px solid rgba(200,168,75,.3);
    color:var(--gold); padding:9px 20px; border-radius:9px; font-size:13px;
    font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
.print-btn:hover { background:rgba(200,168,75,.2); }

/* ── Watermark ── */
.rpt-watermark {
    position:fixed; inset:0; pointer-events:none; z-index:0;
    display:flex; align-items:center; justify-content:center; overflow:hidden;
}
.rpt-watermark svg { opacity:.045; width:680px; height:680px;
    transform:rotate(-35deg); user-select:none; }
@media print { .rpt-watermark svg { opacity:.07; } }

/* ── Signature block ── */
.rpt-signature {
    display:grid; grid-template-columns:1fr 1fr;
    gap:32px; padding:28px 32px 20px;
    border-top:1px solid var(--border); margin-top:8px;
}
@media(max-width:560px){ .rpt-signature { grid-template-columns:1fr; } }
.sig-label {
    font-size:10px; text-transform:uppercase; letter-spacing:.1em;
    color:var(--muted); margin-bottom:10px;
}
.sig-line {
    border-bottom:1.5px solid rgba(200,168,75,.5);
    min-height:48px; display:flex; align-items:flex-end; padding-bottom:7px;
}
.sig-name {
    font-family:'Cinzel',serif; font-size:16px; font-weight:700;
    color:var(--gold); font-style:italic; letter-spacing:.02em;
}
.sig-meta { font-size:11px; color:var(--muted); margin-top:6px; line-height:1.6; }

.sig-stamp {
    width:92px; height:92px; border-radius:50%;
    border:2.5px solid rgba(200,168,75,.45);
    outline:1px dashed rgba(200,168,75,.2); outline-offset:4px;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    background:rgba(200,168,75,.05); text-align:center;
    font-size:8px; font-weight:700; color:var(--gold);
    text-transform:uppercase; letter-spacing:.08em; line-height:1.6;
    flex-shrink:0;
}

.rpt-seal-bar {
    margin:0 32px 24px;
    padding:10px 18px;
    border:1px dashed rgba(200,168,75,.3);
    border-radius:8px;
    display:flex; align-items:center; gap:10px;
    font-size:11px; color:var(--muted);
    background:rgba(200,168,75,.025);
}
.rpt-seal-bar i { color:var(--gold); font-size:15px; flex-shrink:0; }
</style>

<div class="rpt-wrap">

  <!-- ════ WATERMARK (shows on screen + print) ════ -->
  <div class="rpt-watermark" aria-hidden="true">
    <svg viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" fill="none">
      <!-- Outer ring -->
      <circle cx="200" cy="200" r="190" stroke="#c8a84b" stroke-width="3"/>
      <circle cx="200" cy="200" r="178" stroke="#c8a84b" stroke-width="1" stroke-dasharray="6 4"/>
      <!-- Inner ring -->
      <circle cx="200" cy="200" r="130" stroke="#c8a84b" stroke-width="2"/>
      <!-- Star / laurel motif -->
      <circle cx="200" cy="200" r="60"  stroke="#c8a84b" stroke-width="1.5"/>
      <!-- RMU text large -->
      <text x="200" y="185" text-anchor="middle" font-family="Georgia,serif"
            font-size="64" font-weight="700" fill="#c8a84b" letter-spacing="4">RMU</text>
      <text x="200" y="220" text-anchor="middle" font-family="Georgia,serif"
            font-size="16" fill="#c8a84b" letter-spacing="6">E-LEARNING</text>
      <!-- Arc text top -->
      <path id="arcTop" d="M 40,200 A 160,160 0 0,1 360,200" fill="none"/>
      <text font-family="Georgia,serif" font-size="13" fill="#c8a84b" letter-spacing="3">
        <textPath href="#arcTop" startOffset="10%">OFFICIAL TEACHING REPORT · CONFIDENTIAL</textPath>
      </text>
      <!-- Arc text bottom -->
      <path id="arcBot" d="M 60,210 A 145,145 0 0,0 340,210" fill="none"/>
      <text font-family="Georgia,serif" font-size="11" fill="#c8a84b" letter-spacing="2">
        <textPath href="#arcBot" startOffset="12%">REGENT MARITIME UNIVERSITY · GHANA</textPath>
      </text>
      <!-- Decorative dots on ring -->
      <?php for($deg=0;$deg<360;$deg+=30): $rad=deg2rad($deg); ?>
      <circle cx="<?= round(200+190*cos($rad),1) ?>" cy="<?= round(200+190*sin($rad),1) ?>" r="3" fill="#c8a84b"/>
      <?php endfor; ?>
    </svg>
  </div>
  <div class="rpt-header">
    <div class="rpt-header-left">
      <h1><i class="fas fa-chart-bar" style="color:var(--gold);margin-right:10px"></i>Instructor Teaching Report</h1>
      <p><i class="fas fa-user-tie" style="margin-right:5px"></i><?= h($name) ?></p>
      <p><i class="fas fa-calendar-alt" style="margin-right:5px"></i>Generated: <?= $generatedAt ?></p>
      <div style="margin-top:12px">
        <span style="background:rgba(200,168,75,.15);border:1px solid rgba(200,168,75,.3);border-radius:20px;padding:4px 14px;font-size:12px;font-weight:700;color:var(--gold)">
          <i class="fas fa-chalkboard-teacher"></i>
          &nbsp;<?= $totalCourses ?> Course<?= $totalCourses!=1?'s':'' ?>
          &nbsp;·&nbsp; <?= $totalPublished ?> Published
        </span>
      </div>
    </div>
    <div style="text-align:right">
      <div class="rpt-logo">RMU<small>E-Learning</small></div>
      <button class="print-btn no-print" style="margin-top:14px" onclick="window.print()">
        <i class="fas fa-print"></i> Print / Save PDF
      </button>
    </div>
  </div>

  <!-- Summary stats -->
  <div class="rpt-stats">
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:var(--gold)"><i class="fas fa-book"></i></div>
      <div class="rpt-stat-val"><?= $totalCourses ?></div>
      <div class="rpt-stat-lbl">Courses</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:#64b5f6"><i class="fas fa-users"></i></div>
      <div class="rpt-stat-val"><?= $totalStudents ?></div>
      <div class="rpt-stat-lbl">Total Students</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:#ce93d8"><i class="fas fa-film"></i></div>
      <div class="rpt-stat-val"><?= $totalLessons ?></div>
      <div class="rpt-stat-lbl">Total Lessons</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:#80cbc4"><i class="fas fa-graduation-cap"></i></div>
      <div class="rpt-stat-val"><?= $lessonCompletionRate ?>%</div>
      <div class="rpt-stat-lbl">Lesson Completion</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:#ffb74d"><i class="fas fa-clipboard-list"></i></div>
      <div class="rpt-stat-val"><?= $totalAttempts ?></div>
      <div class="rpt-stat-lbl">Quiz Attempts</div>
    </div>
    <div class="rpt-stat">
      <div class="rpt-stat-icon" style="color:#4caf82"><i class="fas fa-check-double"></i></div>
      <div class="rpt-stat-val"><?= $overallPassRate ?>%</div>
      <div class="rpt-stat-lbl">Quiz Pass Rate</div>
    </div>
  </div>

  <!-- Performance rings -->
  <div class="rpt-section" style="margin-bottom:16px">
    <div class="rpt-section-head">
      <div class="rpt-section-title">
        <i class="fas fa-chart-pie" style="color:var(--gold)"></i> Performance Overview
      </div>
    </div>
    <div class="rpt-perf-grid">
      <?php
      $rings = [
          ['label'=>'Published Courses',  'val'=>$totalCourses>0?round($totalPublished/$totalCourses*100):0, 'color'=>'#c8a84b'],
          ['label'=>'Lesson Completion',  'val'=>$lessonCompletionRate, 'color'=>'#64b5f6'],
          ['label'=>'Quiz Pass Rate',     'val'=>$overallPassRate,      'color'=>'#4caf82'],
          ['label'=>'Quizzes Passed',     'val'=>$totalAttempts>0?round($totalPassed/$totalAttempts*100):0, 'color'=>'#ce93d8'],
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

  <!-- Course-by-course breakdown -->
  <div class="rpt-section">
    <div class="rpt-section-head">
      <div class="rpt-section-title">
        <i class="fas fa-book-open" style="color:var(--gold)"></i>
        Course Breakdown
        <span style="font-size:11px;color:var(--muted);font-family:sans-serif;font-weight:400">(<?= $totalCourses ?> courses)</span>
      </div>
      <div style="display:flex;gap:14px;font-size:11px;flex-wrap:wrap">
        <span style="color:#4caf82"><i class="fas fa-circle" style="font-size:8px"></i> Completed</span>
        <span style="color:#64b5f6"><i class="fas fa-circle" style="font-size:8px"></i> In Progress</span>
        <span style="color:var(--muted)"><i class="fas fa-circle" style="font-size:8px"></i> Not Started</span>
      </div>
    </div>

    <?php if(empty($courses)): ?>
    <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px">No courses found.</div>
    <?php else: ?>

    <div class="rpt-col-head">
      <div>Course</div>
      <div style="text-align:center">Students</div>
      <div style="text-align:center">Lessons</div>
      <div>Student Progress</div>
      <div style="text-align:center">Quiz Stats</div>
    </div>

    <?php foreach($courses as $c):
      $p  = $progressMap[$c['id']] ?? ['total_students'=>0,'completed'=>0,'in_progress'=>0,'not_started'=>0];
      $qz = $quizMap[$c['id']]     ?? ['total'=>0,'passed'=>0,'score_sum'=>0,'mark_sum'=>0];
      $compPct   = $p['total_students'] > 0 ? round($p['completed']   / $p['total_students'] * 100) : 0;
      $inpPct    = $p['total_students'] > 0 ? round($p['in_progress'] / $p['total_students'] * 100) : 0;
      $qzPassPct = $qz['total'] > 0 ? round($qz['passed'] / $qz['total'] * 100) : 0;
      $qzAvgPct  = ($qz['total'] > 0 && $qz['mark_sum'] > 0) ? round($qz['score_sum'] / $qz['mark_sum'] * 100) : 0;
    ?>
    <div class="rpt-course-row">

      <div>
        <strong style="font-size:13px;color:var(--white);display:block;margin-bottom:4px"><?= h($c['title']) ?></strong>
        <span>
          <span class="badge bg-gold" style="font-size:9px">L<?= $c['level'] ?> · S<?= $c['semester'] ?? 1 ?></span>
          &nbsp;
          <?php if($c['is_primary']): ?>
            <span class="badge bg-gold" style="font-size:9px"><i class="fas fa-star"></i> Primary</span>
          <?php else: ?>
            <span class="badge bg-blue" style="font-size:9px">Co-Instructor</span>
          <?php endif; ?>
          &nbsp;
          <span class="badge <?= $c['is_published'] ? 'bg-green' : 'bg-muted' ?>" style="font-size:9px">
            <?= $c['is_published'] ? 'Published' : 'Draft' ?>
          </span>
        </span>
      </div>

      <div style="text-align:center">
        <div style="font-size:20px;font-weight:700;font-family:'Cinzel',serif;color:var(--white)"><?= $p['total_students'] ?></div>
        <div style="font-size:10px;color:var(--muted)">enrolled</div>
      </div>

      <div style="text-align:center">
        <div style="font-size:20px;font-weight:700;font-family:'Cinzel',serif;color:var(--white)"><?= $c['n_lessons'] ?></div>
        <div style="font-size:10px;color:var(--muted)">lessons</div>
      </div>

      <div>
        <?php if($p['total_students'] > 0): ?>
        <div style="display:flex;height:8px;border-radius:4px;overflow:hidden;gap:1px;margin-bottom:5px">
          <?php if($compPct > 0): ?>
          <div style="width:<?= $compPct ?>%;background:#4caf82"></div>
          <?php endif; ?>
          <?php if($inpPct > 0): ?>
          <div style="width:<?= $inpPct ?>%;background:#64b5f6"></div>
          <?php endif; ?>
          <?php $notPct = max(0,100-$compPct-$inpPct); if($notPct>0): ?>
          <div style="width:<?= $notPct ?>%;background:var(--surf2)"></div>
          <?php endif; ?>
        </div>
        <div style="font-size:10px;color:var(--muted);display:flex;gap:10px;flex-wrap:wrap">
          <span style="color:#4caf82"><?= $p['completed'] ?> done</span>
          <span style="color:#64b5f6"><?= $p['in_progress'] ?> active</span>
          <span><?= $p['not_started'] ?> not started</span>
        </div>
        <?php else: ?>
        <span style="font-size:11px;color:var(--muted)">No students enrolled</span>
        <?php endif; ?>
      </div>

      <div style="text-align:center">
        <?php if($qz['total'] > 0): ?>
        <div style="font-size:14px;font-weight:700;color:<?= $qzPassPct>=50?'#4caf82':'#ff8a80' ?>"><?= $qzPassPct ?>% pass</div>
        <div style="font-size:10px;color:var(--muted)"><?= $qz['total'] ?> attempt<?= $qz['total']!=1?'s':'' ?> · avg <?= $qzAvgPct ?>%</div>
        <?php else: ?>
        <span style="font-size:11px;color:var(--muted)">No attempts</span>
        <?php endif; ?>
      </div>

    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Recent quiz attempts -->
  <div class="rpt-section">
    <div class="rpt-section-head">
      <div class="rpt-section-title">
        <i class="fas fa-clipboard-list" style="color:var(--gold)"></i>
        Student Quiz Attempts
        <span style="font-size:11px;color:var(--muted);font-family:sans-serif;font-weight:400">(<?= $totalAttempts ?> total)</span>
      </div>
      <?php if($totalAttempts > 0): ?>
      <div style="font-size:12px;color:var(--muted)">
        Pass rate: <strong style="color:<?= $overallPassRate>=50?'#4caf82':'#ff8a80' ?>"><?= $overallPassRate ?>%</strong>
        &nbsp;·&nbsp; Passed: <strong style="color:#4caf82"><?= $totalPassed ?></strong>
        &nbsp;·&nbsp; Failed: <strong style="color:#ff8a80"><?= $totalAttempts-$totalPassed ?></strong>
      </div>
      <?php endif; ?>
    </div>

    <?php if(empty($allQuizAttempts)): ?>
    <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px">No quiz attempts yet across your courses.</div>
    <?php else: ?>

    <?php $displayAttempts = array_slice($allQuizAttempts, 0, 50); ?>
    <?php foreach($displayAttempts as $qz):
      $earned  = (float)$qz['score'];
      $total   = (int)$qz['total_marks'];
      $pctBar  = $total > 0 ? min(100, round($earned / $total * 100)) : 0;
      $passRaw = $total > 0 ? ceil($total * $qz['pass_mark'] / 100) : 0;
    ?>
    <div class="rpt-quiz-row">
      <div style="flex-shrink:0">
        <span class="badge bg-blue" style="font-size:9px">L<?= $qz['level'] ?></span>
      </div>
      <div style="flex:1;min-width:160px">
        <strong style="font-size:13px;color:var(--white);display:block;margin-bottom:2px"><?= h($qz['quiz_title']) ?></strong>
        <span style="font-size:11px;color:var(--muted)">
          <?= h($qz['course_title']) ?> &nbsp;·&nbsp;
          <i class="fas fa-user" style="font-size:10px"></i> <?= h($qz['student_name']) ?> &nbsp;·&nbsp;
          <?= date('M j, Y', strtotime($qz['submitted_at'])) ?>
        </span>
      </div>
      <div style="flex:1;min-width:100px">
        <div class="rpt-pbar">
          <div class="rpt-pbar-fill" style="width:<?= $pctBar ?>%;background:<?= $qz['passed']?'#4caf82':'#e74c3c' ?>"></div>
        </div>
        <div class="rpt-pbar-lbl"><?= (int)$earned ?> / <?= $total ?> marks &nbsp;·&nbsp; pass mark: <?= $passRaw ?></div>
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
    </div>
    <?php endforeach; ?>

    <?php if(count($allQuizAttempts) > 50): ?>
    <div style="text-align:center;padding:12px;font-size:12px;color:var(--muted);border-top:1px solid var(--border)">
      Showing 50 most recent of <?= count($allQuizAttempts) ?> total attempts.
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ════ SIGNATURE BLOCK ════ -->
  <div class="rpt-section" style="margin-top:24px">
    <div class="rpt-section-head">
      <div class="rpt-section-title">
        <i class="fas fa-signature" style="color:var(--gold)"></i> Authorization &amp; Signature
      </div>
      <span style="font-size:11px;color:var(--muted)">Generated: <?= $generatedAt ?></span>
    </div>

    <!-- Seal bar -->
    <div class="rpt-seal-bar">
      <i class="fas fa-shield-alt"></i>
      <span>
        This report is an official academic document generated from the RMU E-Learning Management System.
        It reflects data as at <strong style="color:var(--white)"><?= $generatedAt ?></strong> and is
        intended for internal academic and administrative use only.
      </span>
    </div>

    <!-- Signatures -->
    <div class="rpt-signature">

      <!-- Instructor signature -->
      <div>
        <div class="sig-label"><i class="fas fa-user-tie" style="margin-right:4px"></i> Instructor Signature</div>
        <div class="sig-line">
          <span class="sig-name"><?= h($name) ?></span>
        </div>
        <div class="sig-meta">
          <strong style="color:var(--white)"><?= h($name) ?></strong><br>
          Instructor — RMU E-Learning<br>
          Date: <?= date('F j, Y') ?>
        </div>
      </div>

      <!-- Department / Authority signature -->
      <div>
        <div class="sig-label"><i class="fas fa-university" style="margin-right:4px"></i> Authorized By</div>
        <div class="sig-line" style="min-height:48px"></div>
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-top:6px">
          <div class="sig-meta">
            Head of Department<br>
            Faculty of Engneering<br>
            Regional Maritime University
          </div>
          <!-- Circular stamp -->
          <div class="sig-stamp">
            RMU<br>E-Learning<br>──────<br>OFFICIAL<br>SEAL
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- Footer -->
  <div style="text-align:center;padding:16px 0 8px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);margin-top:8px">
    <i class="fas fa-shield-alt" style="color:var(--gold)"></i>
    Generated automatically from RMU E-Learning on <?= $generatedAt ?> &nbsp;·&nbsp; Instructor: <?= h($name) ?>
  </div>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
