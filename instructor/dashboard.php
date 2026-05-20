<?php
require_once '../includes/config.php';
requireRole('instructor');

$uid = (int)$_SESSION['user_id'];

// Stats
$nCourses  = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE instructor_id=?');
$nCourses->execute([$uid]); $nCourses = (int)$nCourses->fetchColumn();

$nStudents = $pdo->prepare('SELECT COUNT(DISTINCT e.student_id) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE c.instructor_id=?');
$nStudents->execute([$uid]); $nStudents = (int)$nStudents->fetchColumn();

$nLessons  = $pdo->prepare('SELECT COUNT(l.id) FROM lessons l JOIN courses c ON c.id=l.course_id WHERE c.instructor_id=?');
$nLessons->execute([$uid]); $nLessons = (int)$nLessons->fetchColumn();

// Avg rating
$avgRating = null;
try {
    $rStmt = $pdo->prepare('SELECT ROUND(AVG(r.rating),1) FROM course_ratings r JOIN courses c ON c.id=r.course_id WHERE c.instructor_id=?');
    $rStmt->execute([$uid]); $avgRating = $rStmt->fetchColumn();
} catch(Exception $e) {}

// Pending short-answer quiz marks
$pendingMarks = [];
try {
    $pmStmt = $pdo->prepare('
        SELECT qa.id AS attempt_id, qa.quiz_id, qa.student_id,
               u.full_name AS student_name, u.student_id AS student_sid,
               qz.title AS quiz_title, c.title AS course_title, c.id AS course_id,
               COUNT(qan.id) AS pending_count
        FROM quiz_attempts qa
        JOIN quizzes qz ON qz.id = qa.quiz_id
        JOIN courses c ON c.id = qz.course_id
        JOIN users u ON u.id = qa.student_id
        JOIN quiz_answers qan ON qan.attempt_id = qa.id AND qan.is_correct IS NULL
        WHERE qz.created_by = ?
        GROUP BY qa.id
        ORDER BY qa.submitted_at DESC
        LIMIT 10
    ');
    $pmStmt->execute([$uid]);
    $pendingMarks = $pmStmt->fetchAll();
} catch(Exception $e) {}

// Recent courses
$stmt = $pdo->prepare('
    SELECT c.*,
           COUNT(DISTINCT e.student_id) AS n_students,
           COUNT(DISTINCT l.id)         AS n_lessons
    FROM courses c
    LEFT JOIN enrollments e ON e.course_id=c.id
    LEFT JOIN lessons l     ON l.course_id=c.id
    WHERE c.instructor_id=?
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT 6
');
$stmt->execute([$uid]);
$courses = $stmt->fetchAll();

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Welcome back, ' . $_SESSION['full_name'];
$activePage   = 'dashboard';
$depth        = 1;

ob_start();

function getCourseThumb(string $title): array {
    $t = strtolower($title);
    $map = [
        ['keys'=>['operating system','os ','linux','unix','windows server','kernel'],          'icon'=>'fa-server',           'grad'=>'135deg,#1a237e,#283593'],
        ['keys'=>['network','cisco','routing','switching','tcp','ip','lan','wan','firewall'],   'icon'=>'fa-network-wired',    'grad'=>'135deg,#004d40,#00695c'],
        ['keys'=>['python','java','javascript','php','c++','c#','programming','coding','software development','flutter','kotlin','swift'], 'icon'=>'fa-code', 'grad'=>'135deg,#4a148c,#6a1b9a'],
        ['keys'=>['web','html','css','react','angular','vue','frontend','backend','fullstack'], 'icon'=>'fa-globe',            'grad'=>'135deg,#0d47a1,#1565c0'],
        ['keys'=>['database','sql','mysql','mongodb','oracle','data warehouse','nosql'],        'icon'=>'fa-database',         'grad'=>'135deg,#b71c1c,#c62828'],
        ['keys'=>['machine learning','artificial intelligence','deep learning','ai ','neural'], 'icon'=>'fa-brain',            'grad'=>'135deg,#4a148c,#880e4f'],
        ['keys'=>['security','cyber','cryptography','ethical hacking','penetration'],          'icon'=>'fa-shield-alt',       'grad'=>'135deg,#1b5e20,#2e7d32'],
        ['keys'=>['cloud','aws','azure','devops','docker','kubernetes'],                        'icon'=>'fa-cloud',            'grad'=>'135deg,#006064,#00838f'],
        ['keys'=>['math','calculus','algebra','statistics','discrete','probability'],           'icon'=>'fa-square-root-alt',  'grad'=>'135deg,#33691e,#558b2f'],
        ['keys'=>['algorithm','data structure','complexity','sorting','graph'],                 'icon'=>'fa-project-diagram',  'grad'=>'135deg,#e65100,#ef6c00'],
        ['keys'=>['mobile','android','ios','app development'],                                  'icon'=>'fa-mobile-alt',       'grad'=>'135deg,#880e4f,#ad1457'],
        ['keys'=>['computer architecture','hardware','embedded','microprocessor'],             'icon'=>'fa-microchip',        'grad'=>'135deg,#37474f,#546e7a'],
        ['keys'=>['software engineering','agile','scrum','uml','system design'],               'icon'=>'fa-drafting-compass', 'grad'=>'135deg,#4e342e,#6d4c41'],
        ['keys'=>['graphics','animation','3d','opengl','image processing'],                    'icon'=>'fa-paint-brush',      'grad'=>'135deg,#c62828,#d81b60'],
        ['keys'=>['information technology','it management','ict','computer fundamentals'],     'icon'=>'fa-laptop-code',      'grad'=>'135deg,#01579b,#0277bd'],
    ];
    foreach ($map as $entry) {
        foreach ($entry['keys'] as $kw) {
            if (str_contains($t, $kw)) return ['icon'=>$entry['icon'],'grad'=>$entry['grad']];
        }
    }
    return ['icon'=>'fa-book-open','grad'=>'135deg,#0d2a4e,#0a1a30'];
}
?>

<!-- Stats -->
<div class="stat-grid">
  <div class="stat-card" style="--sc:var(--gold)">
    <div class="stat-ico" style="background:rgba(200,168,75,.15);color:var(--gold)"><i class="fas fa-book"></i></div>
    <div><div class="stat-val"><?= $nCourses ?></div><div class="stat-lbl">Courses</div></div>
  </div>
  <div class="stat-card" style="--sc:#2980b9">
    <div class="stat-ico" style="background:rgba(41,128,185,.15);color:#64b5f6"><i class="fas fa-user-graduate"></i></div>
    <div><div class="stat-val"><?= $nStudents ?></div><div class="stat-lbl">Students</div></div>
  </div>
  <div class="stat-card" style="--sc:#27ae60">
    <div class="stat-ico" style="background:rgba(39,174,96,.15);color:#4caf82"><i class="fas fa-play-circle"></i></div>
    <div><div class="stat-val"><?= $nLessons ?></div><div class="stat-lbl">Lessons</div></div>
  </div>
  <div class="stat-card" style="--sc:#8e44ad">
    <div class="stat-ico" style="background:rgba(142,68,173,.15);color:#ce93d8"><i class="fas fa-star"></i></div>
    <div><div class="stat-val"><?= $avgRating ?? '—' ?></div><div class="stat-lbl">Avg Rating</div></div>
  </div>
</div>

<!-- Pending Quiz Marks -->
<?php if(!empty($pendingMarks)): ?>
<div class="flex-between mb2" style="margin-top:8px">
  <span class="card-title"><i class="fas fa-pen" style="color:#ff8a80"></i> Quizzes Awaiting Your Marking</span>
  <span class="badge bg-red"><?= count($pendingMarks) ?> pending</span>
</div>
<div class="card mb3">
  <div class="tbl-wrap">
  <table>
    <thead><tr><th>Student</th><th>ID</th><th>Quiz</th><th>Course</th><th>Pending Answers</th><th></th></tr></thead>
    <tbody>
    <?php foreach($pendingMarks as $pm): ?>
    <tr>
      <td><strong style="color:var(--white)"><?= h($pm['student_name']) ?></strong></td>
      <td style="font-family:monospace;font-size:12px;color:var(--muted)"><?= h($pm['student_sid'] ?? '—') ?></td>
      <td style="font-size:13px"><?= h($pm['quiz_title']) ?></td>
      <td><span class="badge bg-blue" style="font-size:10px"><?= h($pm['course_title']) ?></span></td>
      <td>
        <span class="badge bg-gold">
          <i class="fas fa-clock"></i> <?= $pm['pending_count'] ?> answer<?= $pm['pending_count']!=1?'s':'' ?> to mark
        </span>
      </td>
      <td>
        <a href="quiz_results.php?id=<?= $pm['quiz_id'] ?>" class="btn btn-primary btn-sm">
          <i class="fas fa-pen"></i> Mark Now
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- Courses -->
<div class="flex-between mb2">
  <span class="card-title">My Courses</span>
  <a href="upload.php" class="btn btn-primary btn-sm"><i class="fas fa-upload"></i> Upload Content</a>
</div>

<?php if (empty($courses)): ?>
<div class="card" style="text-align:center;padding:50px">
  <i class="fas fa-ship" style="font-size:44px;color:var(--muted);display:block;margin-bottom:14px"></i>
  <p style="color:var(--muted);margin-bottom:18px">No courses assigned yet. Contact the administrator.</p>
</div>
<?php else: ?>
<div class="course-grid">
<?php foreach($courses as $c):
    $thumb = getCourseThumb($c['title']);
?>
  <div class="c-card" onclick="location.href='manage.php?id=<?= $c['id'] ?>'">
    <div class="c-thumb" style="background:linear-gradient(<?= $thumb['grad'] ?>)">
        <i class="fas <?= $thumb['icon'] ?>" style="font-size:48px;color:rgba(255,255,255,0.85);filter:drop-shadow(0 2px 8px rgba(0,0,0,0.4))"></i>
    </div>
    <div class="c-body">
      <div class="c-title"><?= h($c['title']) ?></div>
      <div class="c-meta">
        <i class="fas fa-users"></i> <?= $c['n_students'] ?> students&nbsp;&nbsp;
        <i class="fas fa-film"></i> <?= $c['n_lessons'] ?> lessons
      </div>
    </div>
    <div class="c-foot">
      <span class="badge <?= $c['is_published'] ? 'bg-green' : 'bg-muted' ?>">
        <?= $c['is_published'] ? 'Published' : 'Draft' ?>
      </span>
      <div class="flex gap">
        <a href="quiz_create.php?course=<?= $c['id'] ?>" class="btn btn-secondary btn-sm" style="color:#ce93d8;border-color:rgba(142,68,173,.3)" onclick="event.stopPropagation()">
          <i class="fas fa-question-circle"></i>
        </a>
        <a href="manage.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm" onclick="event.stopPropagation()">
          <i class="fas fa-cog"></i> Manage
        </a>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
