<?php
require_once '../includes/config.php';
requireRole('student');

$uid = (int)$_SESSION['user_id'];
$cid = (int)($_GET['id'] ?? 0);

// Enroll handler
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_enroll'])) {
    $pdo->prepare('INSERT IGNORE INTO enrollments (course_id,student_id) VALUES (?,?)')->execute([$cid,$uid]);
    header('Location: watch.php?id=' . $cid);
    exit;
}

// Fetch course
$cStmt = $pdo->prepare('
    SELECT c.*, u.full_name AS instructor, u.bio AS instructor_bio, u.profile_pic AS instructor_pic,
           COUNT(DISTINCT l.id) AS total_lessons,
           COUNT(DISTINCT e.student_id) AS n_enrolled
    FROM courses c
    JOIN users u ON u.id=c.instructor_id
    LEFT JOIN lessons l ON l.course_id=c.id
    LEFT JOIN enrollments e ON e.course_id=c.id
    WHERE c.id=? AND c.is_published=1
    GROUP BY c.id
    LIMIT 1
');
$cStmt->execute([$cid]);
$course = $cStmt->fetch();
if (!$course) { header('Location: browse.php'); exit; }

// Is student enrolled?
$enrolled = $pdo->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND student_id=? LIMIT 1');
$enrolled->execute([$cid,$uid]);
$isEnrolled = (bool)$enrolled->fetch();

// Progress if enrolled
$pct = 0;
if ($isEnrolled) {
    $doneQ = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id WHERE l.course_id=? AND lp.student_id=?');
    $doneQ->execute([$cid,$uid]); $doneCount = (int)$doneQ->fetchColumn();
    $pct = $course['total_lessons']>0 ? round($doneCount/$course['total_lessons']*100) : 0;
}

// Lessons list (include type and file_path for YouTube)
$lessons = $pdo->prepare('SELECT id,title,type,sort_order,file_path FROM lessons WHERE course_id=? ORDER BY sort_order,id');
$lessons->execute([$cid]);
$lessons = $lessons->fetchAll();

// Ratings summary
$ratingData = ['avg'=>0,'count'=>0,'dist'=>[5=>0,4=>0,3=>0,2=>0,1=>0]];
try {
    $rq = $pdo->prepare('SELECT rating, COUNT(*) as cnt FROM course_ratings WHERE course_id=? GROUP BY rating');
    $rq->execute([$cid]);
    $rows = $rq->fetchAll();
    $total = 0; $sum = 0;
    foreach ($rows as $r) {
        $ratingData['dist'][$r['rating']] = (int)$r['cnt'];
        $sum += $r['rating'] * $r['cnt'];
        $total += $r['cnt'];
    }
    $ratingData['count'] = $total;
    $ratingData['avg']   = $total>0 ? round($sum/$total,1) : 0;
} catch(Exception $e){}

// Recent reviews
$reviews = [];
try {
    $rvq = $pdo->prepare('SELECT cr.rating,cr.review,cr.created_at,u.full_name FROM course_ratings cr JOIN users u ON u.id=cr.student_id WHERE cr.course_id=? AND cr.review IS NOT NULL AND cr.review != "" ORDER BY cr.created_at DESC LIMIT 4');
    $rvq->execute([$cid]);
    $reviews = $rvq->fetchAll();
} catch(Exception $e){}

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
            if (str_contains($t, $kw)) return ['icon'=>$entry['icon'],'grad'=>$entry['grad']];
        }
    }
    return ['icon'=>'fa-book-open','grad'=>'135deg,#0d2a4e,#0a1a30'];
}

$thumb = getCourseThumb($course['title']);

// Count youtube lessons
$ytCount = 0;
foreach ($lessons as $l) { if($l['type']==='youtube') $ytCount++; }

$pageTitle    = h($course['title']);
$pageSubtitle = 'Course Details';
$activePage   = 'browse';
$depth        = 1;

ob_start();
?>

<style>
.cl-hero {
    border-radius:14px; overflow:hidden;
    background:linear-gradient(135deg,#001a3e 0%,#003580 50%,#0057b8 100%);
    margin-bottom:24px; position:relative;
}
.cl-hero-inner {
    display:grid; grid-template-columns:1fr 320px; gap:0; align-items:stretch;
}
.cl-hero-left { padding:36px 40px; }
.cl-hero-right {
    background:rgba(0,0,0,.25);
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    padding:36px 28px; gap:16px;
    border-left:1px solid rgba(255,255,255,.08);
}
.cl-breadcrumb {
    font-size:12px; color:rgba(255,255,255,.5); margin-bottom:14px;
    display:flex; align-items:center; gap:6px;
}
.cl-breadcrumb a { color:rgba(255,255,255,.5); text-decoration:none; }
.cl-breadcrumb a:hover { color:var(--gold); }
.cl-title {
    font-family:'Cinzel',serif; font-size:26px; font-weight:700;
    color:#fff; line-height:1.3; margin-bottom:12px;
}
.cl-desc {
    font-size:14px; color:rgba(255,255,255,.75); line-height:1.7;
    margin-bottom:20px; max-width:560px;
}
.cl-meta-row { display:flex; flex-wrap:wrap; gap:18px; margin-bottom:20px; }
.cl-meta-item { display:flex; align-items:center; gap:7px; font-size:13px; color:rgba(255,255,255,.7); }
.cl-meta-item i { color:var(--gold); font-size:13px; }
.cl-stars-inline { display:flex; align-items:center; gap:4px; }
.cl-stars-inline i { color:var(--gold); font-size:13px; }
.cl-stars-inline span { color:rgba(255,255,255,.7); font-size:12px; margin-left:4px; }

.cl-thumb-hero {
    width:160px; height:160px; border-radius:16px;
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 12px 40px rgba(0,0,0,.4);
    background:linear-gradient(<?= $thumb['grad'] ?>);
}
.cl-thumb-hero i { font-size:64px; color:rgba(255,255,255,.9); filter:drop-shadow(0 4px 12px rgba(0,0,0,.4)); }
.cl-cta { width:100%; }
.cl-cta .btn { width:100%; justify-content:center; padding:13px; font-size:14px; }
.cl-price-note { font-size:11px; color:rgba(255,255,255,.4); text-align:center; margin-top:6px; }

.cl-prog-wrap { width:100%; }
.cl-prog-label { display:flex; justify-content:space-between; font-size:12px; color:rgba(255,255,255,.6); margin-bottom:6px; }
.cl-prog-bar { height:8px; background:rgba(255,255,255,.12); border-radius:4px; overflow:hidden; }
.cl-prog-fill { height:100%; background:linear-gradient(90deg,var(--gold),var(--gold-lt)); border-radius:4px; }

.cl-body { display:grid; grid-template-columns:1fr 320px; gap:24px; align-items:start; }
.cl-section { margin-bottom:24px; }
.cl-section-title {
    font-family:'Cinzel',serif; font-size:14px; color:var(--white);
    margin-bottom:14px; padding-bottom:10px;
    border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:8px;
}
.cl-section-title i { color:var(--gold); }

/* Lesson list */
.cl-lesson {
    display:flex; align-items:center; gap:14px;
    padding:11px 14px; border-radius:9px;
    border:1px solid var(--border); margin-bottom:6px;
    background:var(--surface); transition:border-color .15s;
}
.cl-lesson:hover { border-color:rgba(200,168,75,.3); }
.cl-lesson-num {
    width:28px; height:28px; border-radius:7px; flex-shrink:0;
    background:rgba(200,168,75,.1); color:var(--gold);
    display:flex; align-items:center; justify-content:center;
    font-size:11px; font-weight:700;
}
.cl-lesson-name { font-size:13px; color:var(--text); font-weight:600; flex:1; }
.cl-lesson-type { font-size:11px; color:var(--muted); display:flex; align-items:center; gap:5px; margin-top:2px; }

/* YouTube lesson: embedded preview panel */
.yt-lesson-preview {
    margin-bottom:6px; border-radius:9px; overflow:hidden;
    border:1px solid rgba(204,0,0,.3); background:#0a0a0a;
}
.yt-lesson-preview-header {
    display:flex; align-items:center; gap:10px; padding:10px 14px;
    background:rgba(204,0,0,.08); border-bottom:1px solid rgba(204,0,0,.2);
    cursor:pointer; user-select:none;
}
.yt-lesson-preview-header:hover { background:rgba(204,0,0,.14); }
.yt-lesson-preview-num {
    width:28px; height:28px; border-radius:7px; flex-shrink:0;
    background:rgba(204,0,0,.2); color:#c00;
    display:flex; align-items:center; justify-content:center;
    font-size:11px; font-weight:700;
}
.yt-lesson-title { font-size:13px; color:var(--text); font-weight:600; flex:1; }
.yt-badge-sm {
    display:inline-flex; align-items:center; gap:4px;
    background:#c00; color:#fff; font-size:9px; font-weight:700;
    border-radius:4px; padding:2px 6px; text-transform:uppercase; letter-spacing:.5px;
}
.yt-expand-icon { color:var(--muted); font-size:11px; transition:transform .2s; }
.yt-expand-icon.open { transform:rotate(180deg); }
.yt-embed-wrap {
    display:none; padding:0;
}
.yt-embed-wrap.open { display:block; }
.yt-iframe-container {
    position:relative; padding-bottom:56.25%; height:0; background:#000;
}
.yt-iframe-container iframe {
    position:absolute; top:0; left:0; width:100%; height:100%; border:0;
}
.yt-watch-note {
    padding:8px 14px; font-size:11px; color:var(--muted); text-align:center;
    background:rgba(0,0,0,.3); border-top:1px solid rgba(255,255,255,.05);
}
.yt-watch-note a { color:var(--gold); text-decoration:none; }
.yt-watch-note a:hover { text-decoration:underline; }

/* Rating bars */
.rating-bar-row { display:flex; align-items:center; gap:10px; margin-bottom:7px; }
.rating-bar-row span { font-size:12px; color:var(--muted); width:10px; text-align:right; }
.rating-bar-track { flex:1; height:7px; background:rgba(255,255,255,.08); border-radius:4px; overflow:hidden; }
.rating-bar-fill { height:100%; background:var(--gold); border-radius:4px; }
.rating-bar-count { font-size:11px; color:var(--muted); width:24px; }

.rating-big { text-align:center; padding:20px; }
.rating-big-num { font-family:'Cinzel',serif; font-size:52px; font-weight:700; color:var(--gold); line-height:1; }
.rating-big-stars { display:flex; justify-content:center; gap:4px; margin:8px 0; }
.rating-big-stars i { color:var(--gold); font-size:18px; }
.rating-big-count { font-size:12px; color:var(--muted); }

.review-card {
    background:var(--surf2); border:1px solid var(--border);
    border-radius:10px; padding:14px; margin-bottom:10px;
}
.review-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
.review-name { font-size:13px; font-weight:700; color:var(--white); }
.review-stars i { color:var(--gold); font-size:11px; }
.review-text { font-size:13px; color:var(--muted); line-height:1.6; }

.instructor-card {
    background:var(--surface); border:1px solid var(--border);
    border-radius:12px; padding:20px; text-align:center;
}
.instructor-avatar {
    width:64px; height:64px; border-radius:50%; margin:0 auto 12px;
    background:linear-gradient(135deg,var(--gold),var(--surf2));
    display:flex; align-items:center; justify-content:center;
    font-family:'Cinzel',serif; font-size:24px; font-weight:700; color:var(--navy);
    border:3px solid var(--border);
}
.instructor-name { font-family:'Cinzel',serif; font-size:14px; color:var(--white); margin-bottom:4px; }
.instructor-role { font-size:11px; color:var(--gold); letter-spacing:1px; text-transform:uppercase; margin-bottom:10px; }
.instructor-bio  { font-size:12px; color:var(--muted); line-height:1.6; }

@media(max-width:860px){
    .cl-hero-inner { grid-template-columns:1fr; }
    .cl-hero-right { border-left:none; border-top:1px solid rgba(255,255,255,.08); }
    .cl-body { grid-template-columns:1fr; }
}
</style>

<a href="browse.php" class="btn btn-secondary btn-sm mb2"><i class="fas fa-arrow-left"></i> Back to Browse</a>

<!-- HERO -->
<div class="cl-hero">
    <div class="cl-hero-inner">
        <div class="cl-hero-left">
            <div class="cl-breadcrumb">
                <a href="browse.php">Browse</a>
                <i class="fas fa-chevron-right" style="font-size:9px"></i>
                <span><?= h($course['title']) ?></span>
            </div>
            <div class="cl-title"><?= h($course['title']) ?></div>
            <?php if($course['description']): ?>
            <div class="cl-desc"><?= h($course['description']) ?></div>
            <?php endif; ?>
            <div class="cl-meta-row">
                <div class="cl-meta-item">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <?= h($course['instructor']) ?>
                </div>
                <div class="cl-meta-item">
                    <i class="fas fa-film"></i>
                    <?= $course['total_lessons'] ?> lessons
                </div>
                <?php if($ytCount>0): ?>
                <div class="cl-meta-item">
                    <i class="fab fa-youtube" style="color:#c00"></i>
                    <?= $ytCount ?> YouTube video<?= $ytCount>1?'s':'' ?>
                </div>
                <?php endif; ?>
                <div class="cl-meta-item">
                    <i class="fas fa-users"></i>
                    <?= $course['n_enrolled'] ?> students
                </div>
                <div class="cl-meta-item">
                    <i class="fas fa-layer-group"></i>
                    Level <?= h($course['level']) ?>
                </div>
                <?php if($ratingData['count']>0): ?>
                <div class="cl-meta-item">
                    <div class="cl-stars-inline">
                        <?php for($s=1;$s<=5;$s++): ?>
                        <i class="fas fa-star" style="color:<?= $s<=$ratingData['avg']?'var(--gold)':'rgba(255,255,255,.2)' ?>"></i>
                        <?php endfor; ?>
                        <span><?= $ratingData['avg'] ?> (<?= $ratingData['count'] ?> reviews)</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php if($isEnrolled): ?>
            <div style="max-width:400px">
                <div class="cl-prog-label">
                    <span>Your Progress</span>
                    <span><?= $pct ?>%</span>
                </div>
                <div class="cl-prog-bar">
                    <div class="cl-prog-fill" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="cl-hero-right">
            <div class="cl-thumb-hero">
                <i class="fas <?= $thumb['icon'] ?>"></i>
            </div>
            <div class="cl-cta" style="width:100%">
                <?php if($isEnrolled): ?>
                    <a href="watch.php?id=<?= $cid ?>" class="btn btn-primary">
                        <i class="fas fa-play"></i>
                        <?= $pct>0 ? 'Continue Learning' : 'Start Course' ?>
                    </a>
                    <?php if($pct===100): ?>
                    <a href="certificate.php?id=<?= $cid ?>" target="_blank" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:8px">
                        <i class="fas fa-certificate"></i> Download Certificate
                    </a>
                    <?php endif; ?>
                <?php else: ?>
                    <form method="POST">
                        <button class="btn btn-primary" type="submit" name="do_enroll" style="width:100%;justify-content:center;padding:13px;font-size:14px">
                            <i class="fas fa-plus"></i> Enroll Now — It's Free
                        </button>
                    </form>
                <?php endif; ?>
                <div class="cl-price-note"><i class="fas fa-lock"></i> Secure &nbsp;·&nbsp; Free Access &nbsp;·&nbsp; RMU Students Only</div>
            </div>
        </div>
    </div>
</div>

<!-- BODY -->
<div class="cl-body">
    <!-- LEFT: Lessons + Reviews -->
    <div>
        <!-- Course Content -->
        <div class="cl-section">
            <div class="cl-section-title">
                <i class="fas fa-list-ul"></i> Course Content
                <span style="font-family:'Raleway',sans-serif;font-size:12px;color:var(--muted);font-weight:400;margin-left:auto">
                    <?= count($lessons) ?> lessons
                    <?php if($ytCount>0): ?>
                    &nbsp;·&nbsp; <i class="fab fa-youtube" style="color:#c00"></i> <?= $ytCount ?> on YouTube
                    <?php endif; ?>
                </span>
            </div>

            <?php if(empty($lessons)): ?>
            <p style="color:var(--muted);font-size:13px">No lessons added yet.</p>
            <?php else: ?>

            <?php foreach($lessons as $i=>$l): ?>

              <?php if($l['type']==='youtube'): ?>
              <!-- ── YouTube Lesson ── -->
              <div class="yt-lesson-preview">
                <?php if($isEnrolled): ?>
                <!-- ENROLLED: expandable inline + full player button -->
                <div class="yt-lesson-preview-header" onclick="toggleYT('yt-<?= $l['id'] ?>')">
                  <div class="yt-lesson-preview-num"><?= $i+1 ?></div>
                  <div style="flex:1;min-width:0">
                    <div class="yt-lesson-title"><?= h($l['title']) ?></div>
                    <div class="cl-lesson-type">
                      <i class="fab fa-youtube" style="color:#c00"></i>
                      <span class="yt-badge-sm"><i class="fab fa-youtube"></i> YouTube</span>
                      <span style="margin-left:4px;color:var(--muted)">Click to expand &amp; watch</span>
                    </div>
                  </div>
                  <div style="display:flex;align-items:center;gap:8px" onclick="event.stopPropagation()">
                    <a href="yt_player.php?vid=<?= h($l['file_path']) ?>&title=<?= urlencode($l['title']) ?>&cid=<?= $cid ?>&lid=<?= $l['id'] ?>"
                       class="btn btn-secondary btn-sm" style="font-size:10px;padding:4px 9px" title="Full screen player">
                      <i class="fas fa-expand"></i> Full Player
                    </a>
                    <i class="fas fa-chevron-down yt-expand-icon" id="yt-icon-<?= $l['id'] ?>"></i>
                  </div>
                </div>
                <div class="yt-embed-wrap" id="yt-<?= $l['id'] ?>">
                  <div class="yt-iframe-container">
                    <iframe
                      data-src="https://www.youtube.com/embed/<?= h($l['file_path']) ?>?rel=0&modestbranding=1"
                      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                      allowfullscreen
                      title="<?= h($l['title']) ?>">
                    </iframe>
                  </div>
                  <div class="yt-watch-note">
                    <i class="fab fa-youtube" style="color:#c00"></i>
                    Video hosted on YouTube — views count on YouTube. &nbsp;
                    <a href="https://www.youtube.com/watch?v=<?= h($l['file_path']) ?>" target="_blank" rel="noopener">
                      Open on YouTube <i class="fas fa-external-link-alt" style="font-size:9px"></i>
                    </a>
                  </div>
                </div>
                <?php else: ?>
                <!-- NOT ENROLLED: locked state -->
                <div class="yt-lesson-preview-header" style="cursor:default">
                  <div class="yt-lesson-preview-num"><?= $i+1 ?></div>
                  <div style="flex:1;min-width:0">
                    <div class="yt-lesson-title"><?= h($l['title']) ?></div>
                    <div class="cl-lesson-type">
                      <i class="fab fa-youtube" style="color:#c00"></i>
                      <span>YouTube Video · Enroll to watch</span>
                    </div>
                  </div>
                  <i class="fas fa-lock" style="color:var(--muted);font-size:12px"></i>
                </div>
                <?php endif; ?>
              </div>

              <?php else: ?>
              <!-- ── Regular lesson (video/slide) ── -->
              <div class="cl-lesson">
                <div class="cl-lesson-num"><?= $i+1 ?></div>
                <div style="flex:1;min-width:0">
                    <div class="cl-lesson-name"><?= h($l['title']) ?></div>
                    <div class="cl-lesson-type">
                        <i class="fas fa-<?= $l['type']==='video'?'video':'file-alt' ?>"></i>
                        <?= ucfirst($l['type']) ?>
                    </div>
                </div>
                <?php if($isEnrolled): ?>
                <i class="fas fa-lock-open" style="color:var(--gold);font-size:12px" title="Unlocked"></i>
                <?php else: ?>
                <i class="fas fa-lock" style="color:var(--muted);font-size:12px" title="Enroll to unlock"></i>
                <?php endif; ?>
              </div>
              <?php endif; ?>

            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Reviews -->
        <?php if($ratingData['count']>0): ?>
        <div class="cl-section">
            <div class="cl-section-title"><i class="fas fa-star"></i> Student Reviews</div>
            <div style="display:grid;grid-template-columns:180px 1fr;gap:24px;align-items:center;margin-bottom:20px">
                <div class="rating-big">
                    <div class="rating-big-num"><?= $ratingData['avg'] ?></div>
                    <div class="rating-big-stars">
                        <?php for($s=1;$s<=5;$s++): ?>
                        <i class="fas fa-star" style="color:<?= $s<=$ratingData['avg']?'var(--gold)':'var(--muted)' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <div class="rating-big-count"><?= $ratingData['count'] ?> <?= $ratingData['count']===1?'review':'reviews' ?></div>
                </div>
                <div>
                    <?php for($s=5;$s>=1;$s--):
                        $cnt = $ratingData['dist'][$s];
                        $pctBar = $ratingData['count']>0 ? round($cnt/$ratingData['count']*100) : 0;
                    ?>
                    <div class="rating-bar-row">
                        <span><?= $s ?></span>
                        <i class="fas fa-star" style="color:var(--gold);font-size:11px"></i>
                        <div class="rating-bar-track">
                            <div class="rating-bar-fill" style="width:<?= $pctBar ?>%"></div>
                        </div>
                        <span class="rating-bar-count"><?= $cnt ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php foreach($reviews as $rv): ?>
            <div class="review-card">
                <div class="review-header">
                    <div class="review-name"><?= h($rv['full_name']) ?></div>
                    <div class="review-stars">
                        <?php for($s=1;$s<=5;$s++): ?>
                        <i class="fas fa-star" style="color:<?= $s<=$rv['rating']?'var(--gold)':'var(--muted)' ?>"></i>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="review-text"><?= h($rv['review']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT: Instructor + quick stats -->
    <div>
        <div class="instructor-card mb2">
            <div class="cl-section-title" style="justify-content:center;border:none;margin-bottom:16px">
                <i class="fas fa-chalkboard-teacher"></i> Your Instructor
            </div>
            <?php if(!empty($course['instructor_pic'])): ?>
            <div style="width:64px;height:64px;border-radius:50%;margin:0 auto 12px;overflow:hidden;border:3px solid var(--border)">
                <img src="../uploads/avatars/<?= h($course['instructor_pic']) ?>" style="width:100%;height:100%;object-fit:cover">
            </div>
            <?php else: ?>
            <div class="instructor-avatar"><?= strtoupper(mb_substr($course['instructor'],0,1)) ?></div>
            <?php endif; ?>
            <div class="instructor-name"><?= h($course['instructor']) ?></div>
            <div class="instructor-role">Course Instructor</div>
            <?php if($course['instructor_bio']): ?>
            <div class="instructor-bio"><?= h($course['instructor_bio']) ?></div>
            <?php else: ?>
            <div class="instructor-bio" style="font-style:italic">No bio provided.</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="cl-section-title"><i class="fas fa-info-circle"></i> Course Info</div>
            <div style="display:flex;flex-direction:column;gap:12px">
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span style="color:var(--muted)"><i class="fas fa-layer-group"></i> Level</span>
                    <span class="badge bg-gold">Level <?= h($course['level']) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span style="color:var(--muted)"><i class="fas fa-calendar-alt"></i> Semester</span>
                    <span class="badge bg-blue">Semester <?= h($course['semester'] ?? 1) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span style="color:var(--muted)"><i class="fas fa-film"></i> Lessons</span>
                    <strong style="color:var(--white)"><?= $course['total_lessons'] ?></strong>
                </div>
                <?php if($ytCount>0): ?>
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span style="color:var(--muted)"><i class="fab fa-youtube" style="color:#c00"></i> YouTube</span>
                    <strong style="color:var(--white)"><?= $ytCount ?> video<?= $ytCount>1?'s':'' ?></strong>
                </div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span style="color:var(--muted)"><i class="fas fa-users"></i> Enrolled</span>
                    <strong style="color:var(--white)"><?= $course['n_enrolled'] ?></strong>
                </div>
                <?php if($ratingData['count']>0): ?>
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span style="color:var(--muted)"><i class="fas fa-star"></i> Rating</span>
                    <strong style="color:var(--gold)"><?= $ratingData['avg'] ?> / 5.0</strong>
                </div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;font-size:13px">
                    <span style="color:var(--muted)"><i class="fas fa-infinity"></i> Access</span>
                    <span style="color:var(--white)">Lifetime</span>
                </div>
            </div>
            <?php if(!$isEnrolled): ?>
            <div style="margin-top:18px">
                <form method="POST">
                    <button class="btn btn-primary" type="submit" name="do_enroll" style="width:100%;justify-content:center">
                        <i class="fas fa-plus"></i> Enroll Now
                    </button>
                </form>
            </div>
            <?php else: ?>
            <div style="margin-top:18px">
                <a href="watch.php?id=<?= $cid ?>" class="btn btn-primary" style="width:100%;justify-content:center">
                    <i class="fas fa-play"></i> <?= $pct>0?'Continue':'Start Course' ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ── Toggle YouTube embed open/close ──
function toggleYT(panelId) {
    var panel = document.getElementById(panelId);
    var lessonId = panelId.replace('yt-','');
    var icon = document.getElementById('yt-icon-'+lessonId);
    if (!panel) return;

    var isOpen = panel.classList.contains('open');

    // Close all first
    document.querySelectorAll('.yt-embed-wrap.open').forEach(function(el){
        el.classList.remove('open');
    });
    document.querySelectorAll('.yt-expand-icon.open').forEach(function(el){
        el.classList.remove('open');
        // Pause iframe by clearing src
        var iframe = el.closest('.yt-lesson-preview')?.querySelector('iframe');
    });
    // Reset all iframes to data-src to pause them
    document.querySelectorAll('.yt-embed-wrap iframe').forEach(function(iframe){
        iframe.src = '';
    });

    if (!isOpen) {
        panel.classList.add('open');
        if (icon) icon.classList.add('open');
        // Load iframe
        var iframe = panel.querySelector('iframe');
        if (iframe) {
            var dataSrc = iframe.getAttribute('data-src');
            if (dataSrc && iframe.src !== dataSrc) iframe.src = dataSrc;
        }
        // Scroll to it
        setTimeout(function(){ panel.scrollIntoView({behavior:'smooth',block:'nearest'}); }, 100);
    }
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
