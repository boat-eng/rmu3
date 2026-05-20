<?php
/**
 * yt_player.php — Standalone YouTube lesson player
 * 
 * Usage from watch.php: when a lesson type === 'youtube', redirect here instead of
 * trying to serve the file.
 * 
 *   header('Location: yt_player.php?vid=VIDEO_ID&title=LESSON_TITLE&cid=COURSE_ID');
 * 
 * Or link directly from any lesson list:
 *   <a href="yt_player.php?vid=dQw4w9WgXcQ&title=My+Lesson&cid=5">Watch</a>
 */
require_once '../includes/config.php';
requireRole('student');

$uid   = (int)$_SESSION['user_id'];
$ytId  = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['vid'] ?? '');
$cid   = (int)($_GET['cid'] ?? 0);
$lid   = (int)($_GET['lid'] ?? 0);  // optional lesson id for progress tracking
$title = trim($_GET['title'] ?? 'Video Lesson');

if (!$ytId) { header('Location: browse.php'); exit; }

// Verify the student is enrolled in this course (security)
if ($cid) {
    $enr = $pdo->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND student_id=? LIMIT 1');
    $enr->execute([$cid, $uid]);
    if (!$enr->fetch()) { header('Location: browse.php'); exit; }
}

// Mark lesson as completed when this page is loaded (optional — remove if you track via JS)
if ($lid && $cid) {
    try {
        $pdo->prepare('INSERT IGNORE INTO lesson_progress (lesson_id,student_id) VALUES (?,?)')->execute([$lid,$uid]);
    } catch(Exception $e){}
}

// Fetch course name for breadcrumb
$courseName = '';
if ($cid) {
    $cn = $pdo->prepare('SELECT title FROM courses WHERE id=? LIMIT 1');
    $cn->execute([$cid]);
    $row = $cn->fetch();
    $courseName = $row ? $row['title'] : '';
}

$pageTitle    = h($title);
$pageSubtitle = 'YouTube Lesson';
$activePage   = 'browse';
$depth        = 1;

ob_start();
?>

<style>
.ytp-page {
    max-width:960px; margin:0 auto;
}
.ytp-breadcrumb {
    display:flex; align-items:center; gap:8px; margin-bottom:18px;
    font-size:12px; color:var(--muted);
}
.ytp-breadcrumb a { color:var(--muted); text-decoration:none; }
.ytp-breadcrumb a:hover { color:var(--gold); }
.ytp-breadcrumb i { font-size:9px; }

.ytp-header {
    display:flex; align-items:center; gap:12px; margin-bottom:16px;
}
.ytp-yt-icon {
    width:44px; height:44px; border-radius:10px; flex-shrink:0;
    background:rgba(204,0,0,.15); border:1px solid rgba(204,0,0,.3);
    display:flex; align-items:center; justify-content:center;
}
.ytp-yt-icon i { color:#c00; font-size:22px; }
.ytp-title {
    font-family:'Cinzel',serif; font-size:20px; font-weight:700; color:var(--white);
    line-height:1.3; flex:1;
}
.ytp-sub { font-size:12px; color:var(--muted); margin-top:3px; }

/* 16:9 video container */
.ytp-frame-wrap {
    position:relative; padding-bottom:56.25%; height:0;
    background:#000; border-radius:12px; overflow:hidden;
    border:1px solid rgba(204,0,0,.25);
    box-shadow:0 16px 60px rgba(0,0,0,.6);
    margin-bottom:14px;
}
.ytp-frame-wrap iframe {
    position:absolute; top:0; left:0; width:100%; height:100%; border:0;
}

.ytp-note {
    display:flex; align-items:center; gap:10px;
    background:rgba(204,0,0,.07); border:1px solid rgba(204,0,0,.2);
    border-radius:9px; padding:11px 16px; font-size:12px; color:var(--muted);
    margin-bottom:20px;
}
.ytp-note i { color:#c00; font-size:16px; flex-shrink:0; }
.ytp-note a { color:var(--gold); text-decoration:none; }
.ytp-note a:hover { text-decoration:underline; }

.ytp-actions { display:flex; gap:10px; flex-wrap:wrap; }
</style>

<div class="ytp-page">

  <!-- Breadcrumb -->
  <div class="ytp-breadcrumb">
    <a href="browse.php"><i class="fas fa-home"></i> Browse</a>
    <?php if($cid && $courseName): ?>
    <i class="fas fa-chevron-right"></i>
    <a href="course.php?id=<?= $cid ?>"><?= h(mb_substr($courseName,0,40)) ?></a>
    <i class="fas fa-chevron-right"></i>
    <a href="watch.php?id=<?= $cid ?>">Lessons</a>
    <?php endif; ?>
    <i class="fas fa-chevron-right"></i>
    <span style="color:var(--text)"><?= h(mb_substr($title,0,50)) ?></span>
  </div>

  <!-- Header -->
  <div class="ytp-header">
    <div class="ytp-yt-icon"><i class="fab fa-youtube"></i></div>
    <div>
      <div class="ytp-title"><?= h($title) ?></div>
      <?php if($courseName): ?>
      <div class="ytp-sub"><i class="fas fa-book" style="color:var(--gold)"></i> <?= h($courseName) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Video Player -->
  <div class="ytp-frame-wrap">
    <iframe
      src="https://www.youtube.com/embed/<?= $ytId ?>?autoplay=1&rel=0&modestbranding=1"
      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
      allowfullscreen
      title="<?= h($title) ?>">
    </iframe>
  </div>

  <!-- Note -->
  <div class="ytp-note">
    <i class="fab fa-youtube"></i>
    <div>
      This video is hosted on YouTube. Your view counts on YouTube's platform. &nbsp;
      <a href="https://www.youtube.com/watch?v=<?= $ytId ?>" target="_blank" rel="noopener">
        Open directly on YouTube <i class="fas fa-external-link-alt" style="font-size:9px"></i>
      </a>
    </div>
  </div>

  <!-- Navigation -->
  <div class="ytp-actions">
    <?php if($cid): ?>
    <a href="watch.php?id=<?= $cid ?>" class="btn btn-secondary">
      <i class="fas fa-list"></i> Back to Lessons
    </a>
    <a href="course.php?id=<?= $cid ?>" class="btn btn-secondary">
      <i class="fas fa-info-circle"></i> Course Details
    </a>
    <?php else: ?>
    <a href="browse.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Browse Courses</a>
    <?php endif; ?>
  </div>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
