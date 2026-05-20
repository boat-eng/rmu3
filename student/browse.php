<?php
require_once '../includes/config.php';
requireRole('student');

$uid = (int)$_SESSION['user_id'];

// Enroll
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_enroll'])) {
    $cid = (int)$_POST['cid'];
    $pdo->prepare('INSERT IGNORE INTO enrollments (course_id,student_id) VALUES (?,?)')->execute([$cid,$uid]);
    header('Location: browse.php?ok=1');
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
    header('Location: browse.php?unenrolled=1');
    exit;
}

$q = trim($_GET['q'] ?? '');

// Ensure programme column exists (idempotent)
try { $pdo->exec("ALTER TABLE courses ADD COLUMN programme VARCHAR(50) DEFAULT 'bsc_it'"); } catch(Exception $e){}

// Fetch ALL published courses — no level restriction
$sql = "SELECT c.*, u.full_name AS instructor,
        COUNT(DISTINCT l.id)  AS total,
        COUNT(DISTINCT e2.id) AS n_enrolled,
        (SELECT 1 FROM enrollments WHERE course_id=c.id AND student_id=?) AS mine,
        ROUND(AVG(r.rating),1) AS avg_rating,
        COUNT(r.id) AS n_ratings
        FROM courses c
        JOIN users u ON u.id=c.instructor_id
        LEFT JOIN lessons l        ON l.course_id=c.id
        LEFT JOIN enrollments e2   ON e2.course_id=c.id
        LEFT JOIN course_ratings r ON r.course_id=c.id
        WHERE c.is_published=1";
$params = [$uid];

if ($q) { $sql .= ' AND (c.title LIKE ? OR c.description LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }

$sql .= ' GROUP BY c.id ORDER BY c.programme ASC, c.level ASC, c.semester ASC, n_enrolled DESC, c.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$courses = $stmt->fetchAll();

$programmeLabels = [
    'diploma_it'           => 'Diploma in Information Technology',
    'bsc_it'               => 'BSc. Information Technology',
    'computer_engineering' => 'Computer Engineering',
    'computer_science'     => 'BSc. Computer Science',
];

$programmeIcons = [
    'diploma_it'           => 'fa-certificate',
    'bsc_it'               => 'fa-laptop-code',
    'computer_engineering' => 'fa-microchip',
    'computer_science'     => 'fa-brain',
];

$programmeColors = [
    'diploma_it'           => '#ffb74d',
    'bsc_it'               => '#64b5f6',
    'computer_engineering' => '#81c784',
    'computer_science'     => '#ce93d8',
];

$pageTitle    = 'Browse Courses';
$pageSubtitle = count($courses).' course(s) available';
$activePage   = 'browse';
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
            if (str_contains($t, $kw)) return ['icon' => $entry['icon'], 'grad' => $entry['grad']];
        }
    }
    return ['icon' => 'fa-book-open', 'grad' => '135deg,#0d2a4e,#0a1a30'];
}
?>


<style>


/* ── Course cards ── */
.browse-search { position:relative; margin-bottom:18px; }
.browse-search i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:13px; pointer-events:none; }
.browse-search input {
    width:100%; padding:10px 14px 10px 40px;
    background:var(--surf2); border:1px solid var(--border);
    border-radius:10px; color:var(--text);
    font-family:'Raleway',sans-serif; font-size:13px; outline:none;
    transition:border-color .2s; box-sizing:border-box;
}
.browse-search input:focus { border-color:var(--gold); }
.browse-search input::placeholder { color:var(--muted); }

.no-results { text-align:center; padding:50px 20px; color:var(--muted); }
.no-results i { font-size:40px; display:block; margin-bottom:12px; opacity:.4; }

.btn-danger { background:rgba(231,76,60,.15); border:1px solid rgba(231,76,60,.4); color:#ff8a80; }
.btn-danger:hover { background:rgba(231,76,60,.3); }


</style>

<?php if(isset($_GET['ok'])): ?>
<div class="alert alert-ok"><i class="fas fa-check-circle"></i> Enrolled! Go to <a href="enrolled.php" style="color:var(--gold)">My Courses</a> to start learning.</div>
<?php endif; ?>
<?php if(isset($_GET['unenrolled'])): ?>
<div class="alert alert-ok"><i class="fas fa-check-circle"></i> You have been unenrolled and your progress was cleared.</div>
<?php endif; ?>

<!-- ══ SEARCH BAR ══ -->
<?php if(count($courses) > 3): ?>
<div class="browse-search" style="margin-bottom:22px">
  <i class="fas fa-search"></i>
  <input type="text" id="browse-search" placeholder="Search courses…" oninput="filterCards()">
</div>
<?php endif; ?>

<?php if(empty($courses)): ?>
<div class="no-results">
  <i class="fas fa-folder-open"></i>
  <p>No published courses available yet. Check back later.</p>
</div>
<?php else: ?>

<div class="course-grid" id="results-grid">
<?php foreach($courses as $c):
  $thumb = getCourseThumb($c['title']);
?>
  <div class="c-card"
       data-title="<?= strtolower(h($c['title'])) ?>"
       data-desc="<?= strtolower(h($c['description'] ?? '')) ?>">
    <a href="course.php?id=<?= $c['id'] ?>" style="text-decoration:none">
      <div class="c-thumb" style="background:linear-gradient(<?= $thumb['grad'] ?>)">
        <i class="fas <?= $thumb['icon'] ?>" style="font-size:48px;color:rgba(255,255,255,0.85);filter:drop-shadow(0 2px 8px rgba(0,0,0,0.4))"></i>
      </div>
    </a>
    <div class="c-body">
      <div class="c-title"><?= h($c['title']) ?></div>
      <?php if($c['description']): ?>
      <p style="font-size:12px;color:var(--muted);margin:5px 0;line-height:1.5;overflow:hidden;max-height:36px"><?= h($c['description']) ?></p>
      <?php endif; ?>
      <div class="c-meta">
        <i class="fas fa-chalkboard-teacher"></i> <?= h($c['instructor']) ?><br>
        <i class="fas fa-film"></i> <?= $c['total'] ?> lessons &nbsp;
        <i class="fas fa-users"></i> <?= $c['n_enrolled'] ?> enrolled
        &nbsp;<span class="badge bg-blue" style="font-size:9px">L<?= $c['level'] ?> · S<?= $c['semester'] ?></span>
      </div>
      <?php if($c['avg_rating']): ?>
      <div style="display:flex;align-items:center;gap:4px;margin-top:5px">
        <i class="fas fa-star" style="color:var(--gold);font-size:12px"></i>
        <span style="font-size:12px;font-weight:700;color:var(--gold)"><?= number_format($c['avg_rating'],1) ?></span>
        <span style="font-size:11px;color:var(--muted)">(<?= $c['n_ratings'] ?> review<?= $c['n_ratings']!=1?'s':'' ?>)</span>
      </div>
      <?php else: ?>
      <div style="display:flex;align-items:center;gap:4px;margin-top:5px">
        <i class="fas fa-star" style="color:var(--muted);font-size:12px"></i>
        <span style="font-size:11px;color:var(--muted)">No ratings yet</span>
      </div>
      <?php endif; ?>
    </div>
    <div class="c-foot">
      <span class="badge bg-gold" style="font-size:10px">L<?= $c['level'] ?> · S<?= $c['semester'] ?></span>
      <?php if($c['mine']): ?>
        <div class="flex gap">
          <a href="course.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> View</a>
          <a href="watch.php?id=<?= $c['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-play"></i> Continue</a>
          <button class="btn btn-secondary btn-sm" style="color:#ff8a80;border-color:rgba(231,76,60,.3)"
                  onclick="confirmUnenroll(<?= $c['id'] ?>, '<?= h(addslashes($c['title'])) ?>')"
                  title="Unenroll"><i class="fas fa-sign-out-alt"></i></button>
        </div>
      <?php else: ?>
        <div class="flex gap">
          <a href="course.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> View</a>
          <form method="POST" style="display:inline">
            <input type="hidden" name="cid" value="<?= $c['id'] ?>">
            <button class="btn btn-primary btn-sm" type="submit" name="do_enroll"><i class="fas fa-plus"></i> Enroll</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div id="no-inline" style="display:none" class="no-results">
  <i class="fas fa-search"></i>
  <p>No courses match your search.</p>
</div>

<?php endif; /* courses */ ?>

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

<script>
function filterCards() {
    var q = (document.getElementById('browse-search') ? document.getElementById('browse-search').value : '').toLowerCase().trim();
    var cards = document.querySelectorAll('#results-grid .c-card');
    var visible = 0;
    cards.forEach(function(card) {
        var show = !q || card.getAttribute('data-title').indexOf(q) !== -1 || card.getAttribute('data-desc').indexOf(q) !== -1;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var noInline = document.getElementById('no-inline');
    if (noInline) noInline.style.display = visible === 0 ? 'block' : 'none';
}

function confirmUnenroll(cid, title) {
    document.getElementById('unenroll-cid').value = cid;
    document.getElementById('unenroll-title').textContent = title;
    openModal('m-unenroll');
}
</script>


<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
