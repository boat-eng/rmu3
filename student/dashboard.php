<?php
require_once '../includes/config.php';
requireRole('student');

$uid = (int)$_SESSION['user_id'];

// Enroll
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_enroll'])) {
    $cid = (int)$_POST['cid'];
    $pdo->prepare('INSERT IGNORE INTO enrollments (course_id,student_id) VALUES (?,?)')->execute([$cid,$uid]);
    header('Location: dashboard.php');
    exit;
}

// Unenroll
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_unenroll'])) {
    $cid = (int)$_POST['cid'];
    $pdo->prepare('DELETE lp FROM lesson_progress lp
                   JOIN lessons l ON l.id=lp.lesson_id
                   WHERE l.course_id=? AND lp.student_id=?')
        ->execute([$cid, $uid]);
    $pdo->prepare('DELETE FROM enrollments WHERE course_id=? AND student_id=?')
        ->execute([$cid, $uid]);
    header('Location: dashboard.php');
    exit;
}

// Stats
$nEnrolled = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id=?');
$nEnrolled->execute([$uid]); $nEnrolled = (int)$nEnrolled->fetchColumn();

$nDone = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress WHERE student_id=?');
$nDone->execute([$uid]); $nDone = (int)$nDone->fetchColumn();

// My courses
$stmt = $pdo->prepare('
    SELECT c.id, c.title, c.level, u.full_name AS instructor,
           COUNT(DISTINCT l.id)   AS total,
           COUNT(DISTINCT lp.id)  AS done,
           e.enrolled_at,
           ROUND(AVG(r.rating),1) AS avg_rating,
           COUNT(r.id)            AS n_ratings
    FROM enrollments e
    JOIN courses c ON c.id=e.course_id
    JOIN users u ON u.id=c.instructor_id
    LEFT JOIN lessons l  ON l.course_id=c.id
    LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.student_id=?
    LEFT JOIN course_ratings r ON r.course_id=c.id
    WHERE e.student_id=?
    GROUP BY c.id, e.enrolled_at
    ORDER BY e.enrolled_at DESC
    LIMIT 6
');
$stmt->execute([$uid,$uid]);
$enrolled = $stmt->fetchAll();

// Available to enroll
$avail = $pdo->prepare('
    SELECT c.id, c.title, c.level, u.full_name AS instructor,
           COUNT(DISTINCT l.id)   AS total,
           COUNT(DISTINCT e2.id)  AS n_enrolled,
           ROUND(AVG(r.rating),1) AS avg_rating,
           COUNT(r.id)            AS n_ratings
    FROM courses c
    JOIN users u ON u.id=c.instructor_id
    LEFT JOIN lessons l   ON l.course_id=c.id
    LEFT JOIN enrollments e2 ON e2.course_id=c.id
    LEFT JOIN course_ratings r ON r.course_id=c.id
    WHERE c.is_published=1
      AND c.id NOT IN (SELECT course_id FROM enrollments WHERE student_id=?)
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT 4
');
$avail->execute([$uid]);
$avail = $avail->fetchAll();

// Count completed courses (certificates earned)
$nCerts = $pdo->prepare('
    SELECT COUNT(*) FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    WHERE e.student_id = ?
    AND (SELECT COUNT(*) FROM lessons WHERE course_id = c.id) > 0
    AND (SELECT COUNT(*) FROM lessons WHERE course_id = c.id) =
        (SELECT COUNT(*) FROM lesson_progress lp
         JOIN lessons l ON l.id = lp.lesson_id
         WHERE l.course_id = c.id AND lp.student_id = e.student_id)
');
$nCerts->execute([$uid]); $nCerts = (int)$nCerts->fetchColumn();

// Last lesson watched (continue where you left off)
$lastLesson = null;
try {
    $llStmt = $pdo->prepare('
        SELECT l.id AS lesson_id, l.title AS lesson_title, l.type,
               c.id AS course_id, c.title AS course_title,
               u.full_name AS instructor,
               COUNT(DISTINCT l2.id) AS total_lessons,
               COUNT(DISTINCT lp2.id) AS done_lessons
        FROM lesson_progress lp
        JOIN lessons l ON l.id = lp.lesson_id
        JOIN courses c ON c.id = l.course_id
        JOIN users u ON u.id = c.instructor_id
        JOIN enrollments e ON e.course_id = c.id AND e.student_id = ?
        LEFT JOIN lessons l2 ON l2.course_id = c.id
        LEFT JOIN lesson_progress lp2 ON lp2.lesson_id = l2.id AND lp2.student_id = ?
        WHERE lp.student_id = ?
        GROUP BY l.id, c.id
        ORDER BY lp.id DESC
        LIMIT 1
    ');
    $llStmt->execute([$uid, $uid, $uid]);
    $lastLesson = $llStmt->fetch();
} catch(Exception $e) {}

// Quiz results for this student
$quizResults = [];
try {
    $qrStmt = $pdo->prepare('
        SELECT qa.id AS attempt_id, qa.score, qa.passed, qa.submitted_at, qa.total_marks,
               qz.title AS quiz_title, qz.pass_mark, qz.id AS quiz_id,
               c.title AS course_title, c.id AS course_id
        FROM quiz_attempts qa
        JOIN quizzes qz ON qz.id = qa.quiz_id
        JOIN courses c ON c.id = qz.course_id
        WHERE qa.student_id = ?
        ORDER BY qa.submitted_at DESC
        LIMIT 5
    ');
    $qrStmt->execute([$uid]);
    $quizResults = $qrStmt->fetchAll();
} catch(Exception $e) {}

// Count in-progress courses
$nInProgress = $pdo->prepare('
    SELECT COUNT(DISTINCT e.course_id)
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    WHERE e.student_id = ?
    AND (SELECT COUNT(*) FROM lessons WHERE course_id = c.id) > 0
    AND (SELECT COUNT(*) FROM lesson_progress lp
         JOIN lessons l ON l.id = lp.lesson_id
         WHERE l.course_id = c.id AND lp.student_id = e.student_id) > 0
    AND (SELECT COUNT(*) FROM lessons WHERE course_id = c.id) !=
        (SELECT COUNT(*) FROM lesson_progress lp
         JOIN lessons l ON l.id = lp.lesson_id
         WHERE l.course_id = c.id AND lp.student_id = e.student_id)
');
$nInProgress->execute([$uid]); $nInProgress = (int)$nInProgress->fetchColumn();

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Welcome back, ' . $_SESSION['full_name'];
$activePage   = 'dashboard';
$depth        = 1;

// Quiz pass rate
$quizPassRate = 0;
$totalAttempts = 0;
$passedAttempts = 0;
try {
    $qpStmt = $pdo->prepare('SELECT COUNT(*) AS tot, SUM(passed) AS passed FROM quiz_attempts WHERE student_id=?');
    $qpStmt->execute([$uid]);
    $qpRow = $qpStmt->fetch();
    $totalAttempts  = (int)$qpRow['tot'];
    $passedAttempts = (int)$qpRow['passed'];
    $quizPassRate   = $totalAttempts > 0 ? round($passedAttempts / $totalAttempts * 100) : 0;
} catch(Exception $e) {}

ob_start();

function getCourseThumb(string $title): array {
    $t = strtolower($title);
    $map = [
        ['keys'=>['operating system','os ','linux','unix','windows server','kernel'],           'icon'=>'fa-server',            'grad'=>'135deg,#1a237e,#283593'],
        ['keys'=>['network','cisco','routing','switching','tcp','ip','lan','wan','firewall'],    'icon'=>'fa-network-wired',     'grad'=>'135deg,#004d40,#00695c'],
        ['keys'=>['python','java','javascript','php','c++','c#','programming','coding','software development','flutter','kotlin','swift'], 'icon'=>'fa-code', 'grad'=>'135deg,#4a148c,#6a1b9a'],
        ['keys'=>['web','html','css','react','angular','vue','frontend','backend','fullstack'],  'icon'=>'fa-globe',             'grad'=>'135deg,#0d47a1,#1565c0'],
        ['keys'=>['database','sql','mysql','mongodb','oracle','data warehouse','nosql'],         'icon'=>'fa-database',          'grad'=>'135deg,#b71c1c,#c62828'],
        ['keys'=>['machine learning','artificial intelligence','deep learning','ai ','neural','data science','nlp'], 'icon'=>'fa-brain', 'grad'=>'135deg,#4a148c,#880e4f'],
        ['keys'=>['security','cyber','cryptography','ethical hacking','penetration','encryption'], 'icon'=>'fa-shield-alt',     'grad'=>'135deg,#1b5e20,#2e7d32'],
        ['keys'=>['cloud','aws','azure','devops','docker','kubernetes','microservice'],          'icon'=>'fa-cloud',             'grad'=>'135deg,#006064,#00838f'],
        ['keys'=>['math','calculus','algebra','statistics','discrete','probability','numerical'],'icon'=>'fa-square-root-alt',  'grad'=>'135deg,#33691e,#558b2f'],
        ['keys'=>['algorithm','data structure','complexity','sorting','graph'],                  'icon'=>'fa-project-diagram',   'grad'=>'135deg,#e65100,#ef6c00'],
        ['keys'=>['mobile','android','ios','app development'],                                   'icon'=>'fa-mobile-alt',        'grad'=>'135deg,#880e4f,#ad1457'],
        ['keys'=>['computer architecture','hardware','embedded','microprocessor','circuit'],     'icon'=>'fa-microchip',         'grad'=>'135deg,#37474f,#546e7a'],
        ['keys'=>['software engineering','agile','scrum','uml','system design','sdlc'],         'icon'=>'fa-drafting-compass',  'grad'=>'135deg,#4e342e,#6d4c41'],
        ['keys'=>['graphics','animation','3d','opengl','image processing','multimedia'],        'icon'=>'fa-paint-brush',       'grad'=>'135deg,#c62828,#d81b60'],
        ['keys'=>['information technology','it management','ict','computer fundamentals'],      'icon'=>'fa-laptop-code',       'grad'=>'135deg,#01579b,#0277bd'],
    ];
    foreach ($map as $entry) {
        foreach ($entry['keys'] as $kw) {
            if (str_contains($t, $kw)) {
                return ['icon' => $entry['icon'], 'grad' => $entry['grad']];
            }
        }
    }
    return ['icon' => 'fa-book-open', 'grad' => '135deg,#0d2a4e,#0a1a30'];
}
?>

<!-- Top bar with report button -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
  <span style="font-size:13px;color:var(--muted)">
    <i class="fas fa-calendar-alt" style="color:var(--gold)"></i>
    <?= date('l, F j, Y') ?>
  </span>
  <a href="report.php" class="btn btn-primary btn-sm">
    <i class="fas fa-file-alt"></i> Generate My Report
  </a>
</div>

<!-- Stats -->
<div class="stat-grid">
  <div class="stat-card" style="--sc:var(--gold)">
    <div class="stat-ico" style="background:rgba(200,168,75,.15);color:var(--gold)"><i class="fas fa-book-open"></i></div>
    <div><div class="stat-val"><?= $nEnrolled ?></div><div class="stat-lbl">Enrolled</div></div>
  </div>
  <div class="stat-card" style="--sc:#27ae60">
    <div class="stat-ico" style="background:rgba(39,174,96,.15);color:#4caf82"><i class="fas fa-graduation-cap"></i></div>
    <div><div class="stat-val"><?= $nCerts ?></div><div class="stat-lbl">Completed</div></div>
  </div>
  <div class="stat-card" style="--sc:#2980b9">
    <div class="stat-ico" style="background:rgba(41,128,185,.15);color:#64b5f6"><i class="fas fa-clock"></i></div>
    <div><div class="stat-val"><?= $nInProgress ?></div><div class="stat-lbl">In Progress</div></div>
  </div>
  <div class="stat-card" style="--sc:#8e44ad">
    <div class="stat-ico" style="background:rgba(142,68,173,.15);color:#ce93d8"><i class="fas fa-clipboard-check"></i></div>
    <div><div class="stat-val"><?= $quizPassRate ?>%</div><div class="stat-lbl">Quiz Pass Rate</div></div>
  </div>
</div>

<!-- Continue Where You Left Off -->
<?php if($lastLesson && $lastLesson['done_lessons'] < $lastLesson['total_lessons']): ?>
<?php
  $resumePct = $lastLesson['total_lessons'] > 0
    ? round($lastLesson['done_lessons'] / $lastLesson['total_lessons'] * 100) : 0;
?>
<div class="resume-banner mb3">
  <div class="resume-icon">
    <i class="fas fa-<?= $lastLesson['type']==='youtube'?'brands fa-youtube':($lastLesson['type']==='video'?'play-circle':'file-alt') ?>"></i>
  </div>
  <div class="resume-info">
    <div class="resume-label">Continue where you left off</div>
    <div class="resume-course"><?= h($lastLesson['course_title']) ?></div>
    <div class="resume-lesson">
      <i class="fas fa-bookmark" style="color:var(--gold);font-size:11px"></i>
      <?= h($lastLesson['lesson_title']) ?>
    </div>
    <div class="resume-progress">
      <div class="pbar" style="flex:1">
        <div class="pfill" style="width:<?= $resumePct ?>%"></div>
      </div>
      <span style="font-size:11px;color:var(--muted);white-space:nowrap">
        <?= $lastLesson['done_lessons'] ?>/<?= $lastLesson['total_lessons'] ?> lessons &nbsp; <?= $resumePct ?>%
      </span>
    </div>
  </div>
  <a href="watch.php?id=<?= $lastLesson['course_id'] ?>&lid=<?= $lastLesson['lesson_id'] ?>"
     class="btn btn-primary resume-btn">
    <i class="fas fa-play"></i> Resume
  </a>
</div>
<style>
.resume-banner {
    background: linear-gradient(135deg, rgba(200,168,75,0.08), rgba(200,168,75,0.03));
    border: 1px solid rgba(200,168,75,0.25);
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.resume-icon {
    width: 52px; height: 52px; border-radius: 12px;
    background: rgba(200,168,75,0.12);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: var(--gold); flex-shrink: 0;
}
.resume-info { flex: 1; min-width: 180px; }
.resume-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 3px; }
.resume-course { font-size: 14px; font-weight: 700; color: var(--white); margin-bottom: 3px; font-family: 'Cinzel', serif; }
.resume-lesson { font-size: 12px; color: var(--muted); margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.resume-progress { display: flex; align-items: center; gap: 10px; }
.resume-btn { white-space: nowrap; flex-shrink: 0; }
@media(max-width:600px){ .resume-btn { width:100%; justify-content:center; } }
</style>
<?php endif; ?>

<!-- Continue Learning -->
<div class="flex-between mb2">
  <span class="card-title">Continue Learning</span>
  <a href="enrolled.php" class="btn btn-secondary btn-sm">View All</a>
</div>

<?php if(empty($enrolled)): ?>
<div class="card mb3" style="text-align:center;padding:44px">
  <i class="fas fa-compass" style="font-size:40px;color:var(--muted);display:block;margin-bottom:12px"></i>
  <p style="color:var(--muted);margin-bottom:16px">No courses yet. Browse below and enroll!</p>
</div>
<?php else: ?>
<div class="course-grid mb3">
<?php foreach($enrolled as $c):
  $pct = $c['total']>0 ? round($c['done']/$c['total']*100) : 0;
  $thumb = getCourseThumb($c['title']);
?>
  <div class="c-card">
    <div class="c-thumb" onclick="location.href='watch.php?id=<?= $c['id'] ?>'" style="cursor:pointer;background:linear-gradient(<?= $thumb['grad'] ?>)">
      <i class="fas <?= $thumb['icon'] ?>" style="font-size:48px;color:rgba(255,255,255,0.85);filter:drop-shadow(0 2px 8px rgba(0,0,0,0.4))"></i>
    </div>
    <div class="c-body" onclick="location.href='watch.php?id=<?= $c['id'] ?>'" style="cursor:pointer">
      <div class="c-title"><?= h($c['title']) ?></div>
      <div class="c-meta"><i class="fas fa-chalkboard-teacher"></i> <?= h($c['instructor']) ?><br>
        <i class="fas fa-film"></i> <?= $c['done'] ?>/<?= $c['total'] ?> lessons
      </div>
      <?php if($c['avg_rating']): ?>
      <div style="display:flex;align-items:center;gap:4px;margin-top:5px">
        <i class="fas fa-star" style="color:var(--gold);font-size:12px"></i>
        <span style="font-size:12px;font-weight:700;color:var(--gold)"><?= number_format($c['avg_rating'],1) ?></span>
        <span style="font-size:11px;color:var(--muted)">(<?= $c['n_ratings'] ?> review<?= $c['n_ratings']!=1?'s':'' ?>)</span>
      </div>
      <?php endif; ?>
      <div class="pbar"><div class="pfill" style="width:<?= $pct ?>%"></div></div>
      <div style="font-size:11px;color:var(--muted);margin-top:4px"><?= $pct ?>% complete</div>
    </div>
    <div class="c-foot">
      <span class="badge <?= $pct===100?'bg-green':($pct>0?'bg-blue':'bg-muted') ?>">
        <?= $pct===100?'Done':($pct>0?'In Progress':'Not Started') ?>
      </span>
      <div class="flex gap">
        <a href="watch.php?id=<?= $c['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-play"></i> Open</a>
        <button class="btn btn-secondary btn-sm" style="color:#ff8a80;border-color:rgba(231,76,60,.3)"
                onclick="confirmUnenroll(<?= $c['id'] ?>, '<?= h(addslashes($c['title'])) ?>')"
                title="Unenroll">
          <i class="fas fa-sign-out-alt"></i>
        </button>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Available -->
<?php if(!empty($avail)): ?>
<div class="flex-between mb2">
  <span class="card-title">Available Courses</span>
  <a href="browse.php" class="btn btn-secondary btn-sm">Browse All</a>
</div>
<div class="course-grid">
<?php foreach($avail as $c):
  $thumb = getCourseThumb($c['title']);
?>
  <div class="c-card">
    <div class="c-thumb" style="background:linear-gradient(<?= $thumb['grad'] ?>)">
      <i class="fas <?= $thumb['icon'] ?>" style="font-size:48px;color:rgba(255,255,255,0.85);filter:drop-shadow(0 2px 8px rgba(0,0,0,0.4))"></i>
    </div>
    <div class="c-body">
      <div class="c-title"><?= h($c['title']) ?></div>
      <div class="c-meta">
        <i class="fas fa-chalkboard-teacher"></i> <?= h($c['instructor']) ?><br>
        <i class="fas fa-film"></i> <?= $c['total'] ?> lessons
      </div>
      <?php if($c['avg_rating']): ?>
      <div style="display:flex;align-items:center;gap:4px;margin-top:5px">
        <i class="fas fa-star" style="color:var(--gold);font-size:12px"></i>
        <span style="font-size:12px;font-weight:700;color:var(--gold)"><?= number_format($c['avg_rating'],1) ?></span>
        <span style="font-size:11px;color:var(--muted)">(<?= $c['n_ratings'] ?> review<?= $c['n_ratings']!=1?'s':'' ?>)</span>
      </div>
      <?php endif; ?>
    </div>
    <div class="c-foot">
      <span class="badge bg-gold">Level <?= h($c['level']) ?></span>
      <form method="POST" style="display:inline" onsubmit="this.querySelector('button').disabled=true">
        <input type="hidden" name="cid" value="<?= $c['id'] ?>">
        <button class="btn btn-primary btn-sm" type="submit" name="do_enroll"><i class="fas fa-plus"></i> Enroll</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Quiz Results -->
<?php if(!empty($quizResults)): ?>
<div class="flex-between mb2" style="margin-top:28px">
  <span class="card-title"><i class="fas fa-question-circle" style="color:var(--gold)"></i> My Quiz Results</span>
</div>
<div class="card">
  <div class="tbl-wrap">
  <table>
    <thead>
      <tr>
        <th>Quiz</th>
        <th>Course</th>
        <th>Score</th>
        <th>Status</th>
        <th>Date</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($quizResults as $qr): ?>
    <tr>
      <td><strong style="color:var(--white)"><?= h($qr['quiz_title']) ?></strong></td>
      <td><span class="badge bg-blue" style="font-size:10px"><?= h($qr['course_title']) ?></span></td>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <?php
            $earned = (float)$qr['score'];
            $total  = (int)$qr['total_marks'];
            $pctBar = $total > 0 ? min(100, round($earned / $total * 100)) : 0;
          ?>
          <div style="width:60px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;height:6px">
            <div style="height:100%;width:<?= $pctBar ?>%;background:<?= $qr['passed']?'#27ae60':'#e74c3c' ?>;border-radius:4px"></div>
          </div>
          <span style="font-size:13px;font-weight:700;color:<?= $qr['passed']?'#4caf82':'#ff8a80' ?>">
            <?= (int)$earned ?> / <?= $total ?>
          </span>
        </div>
      </td>
      <td>
        <span class="badge <?= $qr['passed']?'bg-green':'bg-red' ?>">
          <i class="fas fa-<?= $qr['passed']?'check':'times' ?>-circle"></i>
          <?= $qr['passed']?'Passed':'Failed' ?>
        </span>
        <?php
        // Check if short answers pending
        $pending = $pdo->prepare('SELECT COUNT(*) FROM quiz_answers qa JOIN quiz_attempts att ON att.id=qa.attempt_id WHERE att.id=? AND qa.is_correct IS NULL');
        $pending->execute([$qr['attempt_id']]);
        if((int)$pending->fetchColumn() > 0):
        ?>
        <span class="badge bg-gold" style="font-size:9px;margin-left:4px"><i class="fas fa-clock"></i> Pending review</span>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:var(--muted)"><?= date('M j, Y', strtotime($qr['submitted_at'])) ?></td>
      <td>
        <a href="quiz_result.php?attempt=<?= $qr['attempt_id'] ?>" class="btn btn-secondary btn-sm">
          <i class="fas fa-eye"></i> View
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- Hidden unenroll form -->
<form id="unenroll-form" method="POST" style="display:none">
  <input type="hidden" name="do_unenroll" value="1">
  <input type="hidden" name="cid" id="unenroll-cid" value="">
</form>

<!-- Unenroll confirmation modal -->
<div class="modal-wrap" id="m-unenroll">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-head">
      <h3><i class="fas fa-sign-out-alt" style="color:#ff8a80"></i> Unenroll from Course</h3>
      <button class="modal-close" onclick="closeModal('m-unenroll')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="alert alert-err" style="margin-bottom:18px">
        <i class="fas fa-exclamation-triangle"></i>
        <div><strong>Warning:</strong> This will unenroll you from <strong id="unenroll-title"></strong> and permanently delete all your progress for this course.</div>
      </div>
      <p style="color:var(--muted);font-size:13px;margin-bottom:20px">Are you sure you want to unenroll?</p>
      <div class="flex gap" style="justify-content:flex-end">
        <button class="btn btn-secondary" onclick="closeModal('m-unenroll')">Cancel</button>
        <button class="btn btn-danger" onclick="document.getElementById('unenroll-form').submit()">
          <i class="fas fa-sign-out-alt"></i> Yes, Unenroll
        </button>
      </div>
    </div>
  </div>
</div>

<style>
.btn-danger { background:rgba(231,76,60,.15); border:1px solid rgba(231,76,60,.4); color:#ff8a80; }
.btn-danger:hover { background:rgba(231,76,60,.3); }
</style>

<script>
function confirmUnenroll(cid, title) {
  document.getElementById('unenroll-cid').value = cid;
  document.getElementById('unenroll-title').textContent = title;
  openModal('m-unenroll');
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
