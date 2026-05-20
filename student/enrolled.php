<?php
require_once '../includes/config.php';
requireRole('student');

$uid = (int)$_SESSION['user_id'];

// Handle unenroll
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_unenroll'])) {
    $cid = (int)$_POST['cid'];
    $pdo->prepare('DELETE lp FROM lesson_progress lp
                   JOIN lessons l ON l.id=lp.lesson_id
                   WHERE l.course_id=? AND lp.student_id=?')
        ->execute([$cid, $uid]);
    $pdo->prepare('DELETE FROM enrollments WHERE course_id=? AND student_id=?')
        ->execute([$cid, $uid]);
    header('Location: enrolled.php?unenrolled=1');
    exit;
}

// Fetch enrolled courses — all levels, ordered by level then semester
$stmt = $pdo->prepare("
    SELECT c.id, c.title, c.level, c.semester, u.full_name AS instructor,
           COUNT(DISTINCT l.id)  AS total,
           COUNT(DISTINCT lp.id) AS done,
           e.enrolled_at,
           ROUND(AVG(r.rating),1) AS avg_rating,
           COUNT(r.id)            AS n_ratings
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    JOIN users u   ON u.id = c.instructor_id
    LEFT JOIN lessons l         ON l.course_id = c.id
    LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = ?
    LEFT JOIN course_ratings r  ON r.course_id = c.id
    WHERE e.student_id = ?
    GROUP BY c.id, e.enrolled_at
    ORDER BY c.level ASC, c.semester ASC, e.enrolled_at DESC
");
$stmt->execute([$uid, $uid]);
$courses = $stmt->fetchAll();

// Group courses by level → semester
$grouped = [];
foreach ($courses as $c) {
    $grouped[$c['level']][$c['semester']][] = $c;
}

$pageTitle    = 'My Courses';
$pageSubtitle = count($courses) . ' enrolled';
$activePage   = 'enrolled';
$depth        = 1;

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

<?php if(isset($_GET['unenrolled'])): ?>
<div class="alert alert-ok"><i class="fas fa-check-circle"></i> You have been unenrolled and your progress for that course has been cleared.</div>
<?php endif; ?>


<?php if(empty($courses)): ?>
<div class="card" style="text-align:center;padding:50px">
  <i class="fas fa-book-open" style="font-size:44px;color:var(--muted);display:block;margin-bottom:14px"></i>
  <p style="color:var(--muted);margin-bottom:16px">No courses yet.</p>
  <a href="browse.php" class="btn btn-primary"><i class="fas fa-compass"></i> Browse Courses</a>
</div>
<?php else: ?>

<?php foreach([100,200,300,400] as $lvl):
  if (!isset($grouped[$lvl])) continue;
?>

<!-- ══ LEVEL <?= $lvl ?> ══ -->
<div style="margin-bottom:32px">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid var(--border)">
    <div style="width:38px;height:38px;border-radius:10px;background:rgba(200,168,75,.12);display:flex;align-items:center;justify-content:center;border:1px solid rgba(200,168,75,.25)">
      <i class="fas fa-layer-group" style="color:var(--gold);font-size:15px"></i>
    </div>
    <div>
      <div style="font-family:'Cinzel',serif;font-size:16px;font-weight:700;color:var(--white)">Level <?= $lvl ?></div>
      <div style="font-size:11px;color:var(--muted)"><?= array_sum(array_map('count', $grouped[$lvl])) ?> course(s)</div>
    </div>
  </div>

  <?php foreach([1,2] as $sem):
    if (!isset($grouped[$lvl][$sem])) continue;
  ?>
  <!-- Semester <?= $sem ?> -->
  <div style="margin-bottom:20px">
    <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;display:flex;align-items:center;gap:8px">
      <i class="fas fa-calendar-alt" style="color:rgba(200,168,75,.5)"></i> Semester <?= $sem ?>
    </div>
    <div class="course-grid">
    <?php foreach($grouped[$lvl][$sem] as $c):
      $pct = $c['total']>0 ? round($c['done']/$c['total']*100) : 0;
      $thumb = getCourseThumb($c['title']);
    ?>
      <div class="c-card">
        <div class="c-thumb" onclick="location.href='watch.php?id=<?= $c['id'] ?>'" style="cursor:pointer;background:linear-gradient(<?= $thumb['grad'] ?>)">
          <i class="fas <?= $thumb['icon'] ?>" style="font-size:48px;color:rgba(255,255,255,0.85);filter:drop-shadow(0 2px 8px rgba(0,0,0,0.4))"></i>
        </div>
        <div class="c-body" onclick="location.href='watch.php?id=<?= $c['id'] ?>'" style="cursor:pointer">
          <div class="c-title"><?= h($c['title']) ?></div>
          <div class="c-meta">
            <i class="fas fa-chalkboard-teacher"></i> <?= h($c['instructor']) ?><br>
            <i class="fas fa-film"></i> <?= $c['done'] ?>/<?= $c['total'] ?> lessons
            &nbsp;<span class="badge bg-blue" style="font-size:9px">L<?= $c['level'] ?> · S<?= $c['semester'] ?></span>
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
            <a href="course.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> View</a>
            <a href="watch.php?id=<?= $c['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-play"></i> Open</a>
            <button class="btn btn-secondary btn-sm" style="color:#ff8a80;border-color:rgba(231,76,60,.3)"
                    onclick="confirmUnenroll(<?= $c['id'] ?>, '<?= h(addslashes($c['title'])) ?>')"
                    title="Unenroll from this course">
              <i class="fas fa-sign-out-alt"></i>
            </button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

</div>
<?php endforeach; ?>

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
        <div>
          <strong>Warning:</strong> This will remove you from <strong id="unenroll-title"></strong> and permanently delete all your progress for this course.
        </div>
      </div>
      <p style="color:var(--muted);font-size:13px;margin-bottom:20px">This action cannot be undone. Are you sure you want to unenroll?</p>
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
