<?php
require_once '../includes/config.php';
requireRole('student');

$uid = (int)$_SESSION['user_id'];
$cid = (int)($_GET['id'] ?? 0);

// Verify enrollment
$enroll = $pdo->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND student_id=? LIMIT 1');
$enroll->execute([$cid,$uid]);
if (!$enroll->fetch()) { header('Location: browse.php'); exit; }

// Course
$course = $pdo->prepare('SELECT c.*,u.full_name AS instructor FROM courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=? LIMIT 1');
$course->execute([$cid]);
$course = $course->fetch();
if (!$course) { header('Location: enrolled.php'); exit; }

// AJAX mark complete
// Auto-create discussion table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lesson_discussion (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        lesson_id   INT NOT NULL,
        course_id   INT NOT NULL,
        user_id     INT NOT NULL,
        user_type   ENUM('student','instructor','admin') NOT NULL DEFAULT 'student',
        message     TEXT NOT NULL,
        parent_id   INT NULL DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lesson (lesson_id),
        INDEX idx_parent (parent_id)
    )");
    // Add missing columns to existing installs
    try { $pdo->exec("ALTER TABLE lesson_discussion ADD COLUMN user_type ENUM('student','instructor','admin') NOT NULL DEFAULT 'student'"); } catch(\Exception $e) {}
    try { $pdo->exec("ALTER TABLE lesson_discussion ADD COLUMN parent_id INT NULL DEFAULT NULL"); } catch(\Exception $e) {}
} catch(\Exception $e) {}

// AJAX — post discussion message
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_discuss'])) {
    header('Content-Type: application/json');
    $lid      = (int)($_POST['lesson_id'] ?? 0);
    $msg      = trim($_POST['message'] ?? '');
    $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
    if (!$lid || !$msg) { echo json_encode(['ok'=>false,'msg'=>'Message cannot be empty.']); exit; }

    // Students are in students table, instructors/admins in users
    $sStmt = $pdo->prepare('SELECT id, full_name, "student" AS role FROM students WHERE id=? LIMIT 1');
    $sStmt->execute([$uid]);
    $uRow = $sStmt->fetch();
    $userType = 'student';
    if (!$uRow) {
        $uStmt = $pdo->prepare('SELECT id, full_name, role FROM users WHERE id=? LIMIT 1');
        $uStmt->execute([$uid]);
        $uRow = $uStmt->fetch();
        $userType = $uRow['role'] ?? 'instructor';
    }

    $pdo->prepare('INSERT INTO lesson_discussion (lesson_id, course_id, user_id, user_type, message, parent_id) VALUES (?,?,?,?,?,?)')
        ->execute([$lid, $cid, $uid, $userType, $msg, $parentId]);
    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        'ok'         => true,
        'id'         => $newId,
        'message'    => htmlspecialchars($msg),
        'full_name'  => htmlspecialchars($uRow['full_name'] ?? 'User'),
        'role'       => $userType,
        'initial'    => strtoupper(mb_substr($uRow['full_name'] ?? 'U', 0, 1)),
        'created_at' => date('g:i A'),
        'mine'       => true,
        'parent_id'  => $parentId,
    ]);
    exit;
}

// AJAX — load discussion messages
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['load_discuss'])) {
    header('Content-Type: application/json');
    $lid = (int)($_GET['lesson_id'] ?? 0);
    if (!$lid) { echo json_encode([]); exit; }

    // Always try BOTH tables — user_type may be wrong for old records
    // COALESCE picks whichever table has the matching id
    $msgs = $pdo->prepare('
        SELECT d.id, d.message, d.created_at, d.user_id, d.user_type,
               COALESCE(
                   NULLIF(s.full_name, ""),
                   NULLIF(u.full_name, ""),
                   "Unknown"
               ) AS full_name,
               d.parent_id
        FROM lesson_discussion d
        LEFT JOIN students s ON s.id = d.user_id
        LEFT JOIN users    u ON u.id = d.user_id
        WHERE d.lesson_id = ?
        ORDER BY d.created_at ASC
        LIMIT 200
    ');
    $msgs->execute([$lid]);
    $rows = $msgs->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        // Determine actual role from which table matched
        $resolvedRole = $r['user_type'];
        $out[] = [
            'id'         => $r['id'],
            'message'    => htmlspecialchars($r['message']),
            'full_name'  => htmlspecialchars($r['full_name']),
            'role'       => $resolvedRole,
            'initial'    => strtoupper(mb_substr($r['full_name'], 0, 1)),
            'created_at' => date('g:i A', strtotime($r['created_at'])),
            'mine'       => (int)$r['user_id'] === $uid,
            'parent_id'  => $r['parent_id'],
        ];
    }
    echo json_encode($out); exit;
}



if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['mark_done'])) {
    header('Content-Type: application/json');
    $lid = (int)$_POST['lid'];
    $chk = $pdo->prepare('SELECT id FROM lessons WHERE id=? AND course_id=?');
    $chk->execute([$lid,$cid]);
    if ($chk->fetch()) {
        $pdo->prepare('INSERT IGNORE INTO lesson_progress (lesson_id,student_id) VALUES (?,?)')->execute([$lid,$uid]);
    }
    // Check if course is now 100% complete
    $totQ = $pdo->prepare('SELECT COUNT(*) FROM lessons WHERE course_id=?');
    $totQ->execute([$cid]); $tot = (int)$totQ->fetchColumn();
    $doneQ = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id WHERE l.course_id=? AND lp.student_id=?');
    $doneQ->execute([$cid,$uid]); $doneNow = (int)$doneQ->fetchColumn();
    $justCompleted = ($tot > 0 && $doneNow >= $tot);
    if ($justCompleted) {
        $instrQ = $pdo->prepare('SELECT instructor_id, title FROM courses WHERE id=?');
        $instrQ->execute([$cid]); $cRow = $instrQ->fetch();
        if ($cRow) {
            try {
                $pdo->prepare('INSERT IGNORE INTO notifications (user_id,type,message,link) VALUES (?,?,?,?)')
                    ->execute([$cRow['instructor_id'], 'course_complete',
                        $_SESSION['full_name'] . ' completed your course "' . $cRow['title'] . '"',
                        '../instructor/students.php']);
            } catch(Exception $e){}
        }
    }
    // Check if quiz is required — if so, don't show cert modal yet
    $hasQuiz = false;
    $quizPassed = false;
    try {
        $qzChk = $pdo->prepare('SELECT id FROM quizzes WHERE course_id=? AND is_published=1 LIMIT 1');
        $qzChk->execute([$cid]); $qzRow = $qzChk->fetch();
        if ($qzRow) {
            $hasQuiz = true;
            $qzPass = $pdo->prepare('SELECT passed FROM quiz_attempts WHERE quiz_id=? AND student_id=? AND passed=1 LIMIT 1');
            $qzPass->execute([$qzRow['id'], $uid]);
            $quizPassed = (bool)$qzPass->fetch();
        }
    } catch(Exception $e) {}
    $showCert = $justCompleted && (!$hasQuiz || $quizPassed);
    echo json_encode(['ok'=>true,'completed'=>$showCert,'needsQuiz'=>($justCompleted && $hasQuiz && !$quizPassed)]); exit;
}

// AJAX submit rating
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_rate'])) {
    $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
    if ($rating > 0) {
        try {
            $pdo->prepare('INSERT INTO course_ratings (course_id, student_id, rating) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE rating=VALUES(rating)')
                ->execute([$cid, $uid, $rating]);
        } catch(Exception $e) {}
    }
    header('Location: watch.php?id=' . $cid); exit;
}

// Lessons with done status
$lStmt = $pdo->prepare('
    SELECT l.*,
           IF(lp.id IS NOT NULL,1,0) AS is_done
    FROM lessons l
    LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.student_id=?
    WHERE l.course_id=?
    ORDER BY l.sort_order, l.id
');
$lStmt->execute([$uid,$cid]);
$lessons = $lStmt->fetchAll();

$total = count($lessons);
$done  = array_sum(array_column($lessons,'is_done'));
$pct   = $total>0 ? round($done/$total*100) : 0;

// Quiz for this course (published)
$quizData     = null;
$studentPassed = false;
try {
    $qzStmt = $pdo->prepare('SELECT * FROM quizzes WHERE course_id=? AND is_published=1 ORDER BY created_at DESC LIMIT 1');
    $qzStmt->execute([$cid]); $quizData = $qzStmt->fetch();
    if ($quizData) {
        $qzAttempt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE quiz_id=? AND student_id=? ORDER BY submitted_at DESC LIMIT 1');
        $qzAttempt->execute([$quizData['id'],$uid]);
        $latestAttempt = $qzAttempt->fetch();
        $studentPassed = $latestAttempt && $latestAttempt['passed'];
    }
} catch(Exception $e) {}

// Existing rating for this student
$existingRating = null;
try {
    $rq = $pdo->prepare('SELECT * FROM course_ratings WHERE course_id=? AND student_id=? LIMIT 1');
    $rq->execute([$cid,$uid]);
    $existingRating = $rq->fetch();
} catch(Exception $e){}

// Current lesson
$curId = (int)($_GET['lid'] ?? ($lessons[0]['id'] ?? 0));
$cur   = null;
$curIdx = 0;
foreach ($lessons as $i=>$l) {
    if ($l['id']===$curId) { $cur=$l; $curIdx=$i; break; }
}
if (!$cur && !empty($lessons)) { $cur=$lessons[0]; $curIdx=0; }

$prev = $curIdx>0            ? $lessons[$curIdx-1] : null;
$next = $curIdx<$total-1     ? $lessons[$curIdx+1] : null;

$ext = ($cur && !empty($cur['file_path'])) ? strtolower(pathinfo($cur['file_path'], PATHINFO_EXTENSION)) : '';

// ── Resolve the true lesson type (guards against bad DB data) ──
function effectiveType(array $lesson): string {
    if ($lesson['type'] === 'youtube') return 'youtube';
    // file_path is an 11-char YouTube ID with no dots or slashes → it's YouTube
    if (
        isset($lesson['file_path']) &&
        preg_match('/^[a-zA-Z0-9_-]{11}$/', $lesson['file_path']) &&
        (int)$lesson['file_size'] === 0
    ) return 'youtube';
    return $lesson['type'];
}

$pageTitle    = h($course['title']);
$pageSubtitle = 'By ' . h($course['instructor']);
$activePage   = 'enrolled';
$depth        = 1;

ob_start();
?>

<style>
/* ── Star Rating ── */
.rating-box {
    background: var(--surf2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 16px;
    transition: border-color .2s;
}
.rating-box:hover { border-color: rgba(30,144,255,.3); }
.rating-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--white);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.rating-label i { color: #f0cc6a; }
.rating-form {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.rating-stars {
    display: flex;
    gap: 4px;
}
.rating-star {
    font-size: 28px;
    cursor: pointer;
    color: rgba(255,255,255,.15);
    transition: color .15s, transform .1s;
    line-height: 1;
    display: block;
}
.rating-star input { display: none; }
.rating-star.lit  { color: #f0cc6a; }
.rating-star:hover { transform: scale(1.15); }
.rating-hint {
    font-size: 12px;
    color: var(--muted);
}
/* ── Watch Grid ── */
.watch-grid { display:grid; grid-template-columns:1fr 300px; gap:20px; align-items:start; }
.lesson-panel { background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; position:sticky; top:76px; }
.lp-head { padding:14px 18px; border-bottom:1px solid var(--border); background:rgba(200,168,75,.04); }
.lp-head-title { font-family:'Cinzel',serif; font-size:13px; color:var(--white); margin-bottom:8px; }
.lp-scroll { max-height:460px; overflow-y:auto; padding:8px; }
.lp-scroll::-webkit-scrollbar{width:4px;} .lp-scroll::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px;}
@media(max-width:860px){.watch-grid{grid-template-columns:1fr;}.lesson-panel{position:static;}}
</style>

<a href="enrolled.php" class="btn btn-secondary btn-sm mb2"><i class="fas fa-arrow-left"></i> My Courses</a>
<?php if(!empty($quizData) && $pct===100 && !$studentPassed): ?>
<a href="quiz.php?id=<?= $quizData['id'] ?>" class="btn btn-primary btn-sm mb2" style="background:rgba(200,168,75,.15);border-color:var(--gold);color:var(--gold)">
  <i class="fas fa-clipboard-list"></i> Take Quiz
</a>
<?php elseif(!empty($quizData) && $studentPassed): ?>
<span class="badge bg-green mb2" style="padding:9px 14px"><i class="fas fa-check-circle"></i> Quiz Passed</span>
<?php endif; ?>

<div class="watch-grid">

  <!-- Player area -->
  <div>
    <?php if ($cur): ?>

      <?php
        $ltype = effectiveType($cur);
      ?>

      <?php if ($ltype === 'video'): ?>
      <!-- ══ VIDEO PLAYER ══ -->
      <div class="vid-wrap mb2" style="background:#000;border-radius:11px;overflow:hidden">
        <video id="main-vid" controls controlsList="nodownload"
               src="<?= BASE_URL . '/' . h($cur['file_path'] ?? '') ?>"
               style="width:100%;max-height:520px;display:block;border-radius:11px"></video>
      </div>

      <?php elseif ($ltype === 'youtube'): ?>
      <!-- ══ YOUTUBE PLAYER ══ -->
      <div class="mb2" style="border-radius:12px;overflow:hidden;border:1px solid rgba(204,0,0,.3);background:#000;box-shadow:0 8px 32px rgba(0,0,0,.5)">
        <!-- Header bar -->
        <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;background:rgba(204,0,0,.1);border-bottom:1px solid rgba(204,0,0,.2)">
          <i class="fab fa-youtube" style="color:#c00;font-size:20px"></i>
          <span style="color:#fff;font-size:13px;font-weight:700;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($cur['title']) ?></span>
          <a href="https://www.youtube.com/watch?v=<?= h($cur['file_path'] ?? '') ?>" target="_blank" rel="noopener"
             class="btn btn-secondary btn-sm" style="font-size:11px;padding:4px 10px">
            <i class="fas fa-external-link-alt"></i> YouTube
          </a>
        </div>
        <!-- 16:9 iframe embed -->
        <div style="position:relative;padding-bottom:56.25%;height:0;background:#000">
          <iframe
            id="yt-player"
            src="https://www.youtube.com/embed/<?= h($cur['file_path'] ?? '') ?>?rel=0&modestbranding=1&autoplay=0&enablejsapi=1"
            style="position:absolute;top:0;left:0;width:100%;height:100%;border:0"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen
            title="<?= h($cur['title']) ?>">
          </iframe>
        </div>
        <!-- Footer note -->
        <div style="padding:8px 16px;font-size:11px;color:rgba(255,255,255,.4);text-align:center;background:rgba(0,0,0,.4);border-top:1px solid rgba(255,255,255,.05)">
          <i class="fab fa-youtube" style="color:#c00"></i>
          Video hosted on YouTube — views &amp; watch time count on YouTube
        </div>
      </div>

      <?php else: ?>
      <!-- ══ SLIDE / DOCUMENT VIEWER ══ -->
      <div class="card mb2" style="padding:0;overflow:hidden">
        <?php
          $fileUrl     = BASE_URL . '/' . h($cur['file_path'] ?? '');
          $absoluteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
                         . '://' . $_SERVER['HTTP_HOST'] . '/' . ($cur['file_path'] ?? '');
          $isOfficeDoc = in_array($ext, ['docx','doc','pptx','ppt']);
          $isPdf       = ($ext === 'pdf');
          $officeUrl   = 'https://view.officeapps.live.com/op/embed.aspx?src=' . urlencode($absoluteUrl);
          $googleUrl   = 'https://docs.google.com/viewer?embedded=true&url=' . urlencode($absoluteUrl);
          // Icon and label per format
          $fileIcon  = $isPdf ? 'fa-file-pdf' : (in_array($ext,['pptx','ppt']) ? 'fa-file-powerpoint' : 'fa-file-word');
          $fileColor = $isPdf ? '#e74c3c' : (in_array($ext,['pptx','ppt']) ? '#d04423' : '#2b5797');
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.04)">
          <div style="display:flex;align-items:center;gap:10px">
            <?php if($isPdf): ?>
              <i class="fas fa-file-pdf" style="color:#e74c3c;font-size:20px"></i>
            <?php elseif(in_array($ext,['pptx','ppt'])): ?>
              <i class="fas fa-file-powerpoint" style="color:#d04423;font-size:20px"></i>
            <?php else: ?>
              <i class="fas fa-file-word" style="color:#2b5797;font-size:20px"></i>
            <?php endif; ?>
            <div>
              <div style="color:var(--white);font-size:13px;font-weight:600"><?= h($cur['title']) ?></div>
              <div style="color:var(--muted);font-size:11px"><?= strtoupper($ext) ?> &bull; <?= formatSize($cur['file_size']) ?></div>
            </div>
          </div>
          <div style="display:flex;gap:8px">
            <a href="<?= $fileUrl ?>" target="_blank" class="btn btn-secondary btn-sm">
              <i class="fas fa-external-link-alt"></i> Open
            </a>
            <a href="<?= $fileUrl ?>" download class="btn btn-primary btn-sm">
              <i class="fas fa-download"></i> Download
            </a>
          </div>
        </div>
        <div style="height:640px;position:relative;background:#1a1a2e">
          <?php if ($isPdf): ?>
            <!-- PDF — direct iframe embed (works perfectly on localhost) -->
            <iframe src="<?= $fileUrl ?>#toolbar=1&navpanes=1&scrollbar=1&zoom=page-fit"
                    style="width:100%;height:100%;border:none;display:block"
                    title="<?= h($cur['title'] ?? '') ?>">
            </iframe>

          <?php elseif ($isOfficeDoc): ?>
            <!-- DOCX / PPTX — cannot preview on localhost, show clear download card -->
            <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                        height:100%;gap:20px;padding:40px;text-align:center">
              <?php if(in_array($ext,['pptx','ppt'])): ?>
                <i class="fas fa-file-powerpoint" style="font-size:80px;color:#d04423;opacity:.85"></i>
              <?php else: ?>
                <i class="fas fa-file-word" style="font-size:80px;color:#2b5797;opacity:.85"></i>
              <?php endif; ?>
              <div>
                <div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:10px">
                  <?= h($cur['title'] ?? '') ?>
                </div>
                <div style="font-size:13px;color:rgba(255,255,255,.5);line-height:1.8;max-width:420px">
                  <?= strtoupper($ext) ?> files cannot be previewed in the browser.<br>
                  Download the file to open it in
                  Microsoft <?= in_array($ext,['pptx','ppt']) ? 'PowerPoint' : 'Word' ?>.
                </div>
              </div>
              <a href="<?= $fileUrl ?>" download class="btn btn-primary" style="font-size:14px;padding:13px 36px">
                <i class="fas fa-download"></i> Download <?= strtoupper($ext) ?>
              </a>
            </div>

          <?php else: ?>
            <!-- Unknown format fallback -->
            <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                        height:100%;gap:16px;padding:40px;text-align:center">
              <i class="fas fa-file" style="font-size:56px;color:rgba(255,255,255,.3)"></i>
              <div style="font-size:13px;color:rgba(255,255,255,.4)">This file type cannot be previewed.</div>
              <a href="<?= $fileUrl ?>" download class="btn btn-primary"><i class="fas fa-download"></i> Download</a>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Lesson info card -->
      <div class="card">
        <div class="flex-between mb2">
          <div>
            <h2 style="font-family:'Cinzel',serif;font-size:17px;color:var(--white);margin-bottom:6px"><?= h($cur['title']) ?></h2>
            <div class="flex gap">
              <span class="badge <?= $ltype==='video' ? 'bg-blue' : ($ltype==='youtube' ? 'bg-red' : 'bg-gold') ?>"
                  style="<?= $ltype==='youtube' ? 'background:rgba(204,0,0,.15);color:#ff6b6b;border-color:rgba(204,0,0,.3);' : '' ?>">
              <?php if($ltype==='youtube'): ?>
                <i class="fab fa-youtube"></i> YouTube
              <?php elseif($ltype==='video'): ?>
                <i class="fas fa-video"></i> Video
              <?php else: ?>
                <i class="fas fa-file-alt"></i> <?= ucfirst($ltype) ?>
              <?php endif; ?>
            </span>
              <?php if($cur['is_done']): ?>
              <span class="badge bg-green"><i class="fas fa-check"></i> Completed</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if(!$cur['is_done']): ?>
          <?php if($ltype === 'youtube'): ?>
          <button class="btn btn-secondary" id="done-btn" onclick="markDone(<?= $cur['id'] ?>)" disabled
                  style="opacity:.45;cursor:not-allowed" title="Watch at least 5 minutes to unlock">
            <i class="fas fa-lock"></i> <span id="done-btn-txt">Watch 5:00 more to unlock</span>
          </button>
          <?php else: ?>
          <button class="btn btn-primary" id="done-btn" onclick="markDone(<?= $cur['id'] ?>)">
            <i class="fas fa-check-circle"></i> Mark Complete
          </button>
          <?php endif; ?>
          <?php else: ?>
          <span class="badge bg-green" style="padding:9px 14px;font-size:12px"><i class="fas fa-check-circle"></i> Lesson Completed</span>
          <?php endif; ?>
        </div>

        <?php if($cur['description']): ?>
        <p style="color:var(--muted);line-height:1.7;margin-bottom:16px"><?= h($cur['description'] ?? '') ?></p>
        <?php endif; ?>

        <!-- Star Rating -->
        <?php
        $myRating = 0;
        try {
            $rr = $pdo->prepare('SELECT rating FROM course_ratings WHERE course_id=? AND student_id=? LIMIT 1');
            $rr->execute([$cid, $uid]);
            $myRating = (int)($rr->fetchColumn() ?: 0);
        } catch(Exception $e) {}
        ?>
        <div class="rating-box">
          <div class="rating-label">
            <i class="fas fa-star"></i>
            <?= $myRating ? 'Your Rating' : 'Rate This Course' ?>
          </div>
          <form method="POST" id="rate-form" class="rating-form">
            <input type="hidden" name="do_rate" value="1">
            <div class="rating-stars" id="star-row">
              <?php for($s=1;$s<=5;$s++): ?>
              <label class="rating-star <?= $s<=$myRating?'lit':'' ?>" id="star-<?= $s ?>">
                <input type="radio" name="rating" value="<?= $s ?>" <?= $s===$myRating?'checked':'' ?> onchange="submitRating(<?= $s ?>)">
                &#9733;
              </label>
              <?php endfor; ?>
            </div>
            <span class="rating-hint">
              <?= $myRating ? 'You rated '.$myRating.' star'.($myRating!=1?'s':'') : 'Click a star to rate this course' ?>
            </span>
          </form>
        </div>

        <!-- Prev / Next -->
        <div style="display:flex;justify-content:space-between;padding-top:16px;border-top:1px solid var(--border)">
          <?php if($prev): ?>
          <a href="watch.php?id=<?= $cid ?>&lid=<?= $prev['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-chevron-left"></i> Previous</a>
          <?php else: ?><span></span><?php endif; ?>
          <?php if($next): ?>
          <a href="watch.php?id=<?= $cid ?>&lid=<?= $next['id'] ?>" class="btn btn-primary btn-sm">Next <i class="fas fa-chevron-right"></i></a>
          <?php endif; ?>
        </div>
      </div>

      <?php if($pct===100 && $quizData): ?>
      <!-- Quiz banner — shown when all lessons complete -->
      <div style="background:<?= $studentPassed?'rgba(39,174,96,.1)':'rgba(200,168,75,.08)' ?>;
                  border:1px solid <?= $studentPassed?'rgba(39,174,96,.3)':'rgba(200,168,75,.25)' ?>;
                  border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <div style="width:44px;height:44px;border-radius:10px;
                    background:<?= $studentPassed?'rgba(39,174,96,.15)':'rgba(200,168,75,.12)' ?>;
                    display:flex;align-items:center;justify-content:center;font-size:20px;
                    color:<?= $studentPassed?'#4caf82':'var(--gold)' ?>;flex-shrink:0">
          <i class="fas fa-<?= $studentPassed?'check-circle':'question-circle' ?>"></i>
        </div>
        <div style="flex:1;min-width:160px">
          <div style="font-size:14px;font-weight:700;color:var(--white)"><?= h($quizData['title']) ?></div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">
            Pass mark: <?= $quizData['pass_mark'] ?>% &nbsp;·&nbsp;
            <?php if($quizData['time_limit']): ?><i class="fas fa-clock"></i> <?= $quizData['time_limit'] ?> min &nbsp;·&nbsp;<?php endif; ?>
            <?= $studentPassed ? '<span style="color:#4caf82"><i class="fas fa-check"></i> Passed</span>' : 'Required to pass this course' ?>
          </div>
        </div>
        <?php if(!$studentPassed): ?>
        <a href="quiz.php?id=<?= $quizData['id'] ?>" class="btn btn-primary btn-sm">
          <i class="fas fa-pen-to-square"></i> Take Quiz
        </a>
        <?php endif; ?>
      </div>
      <?php elseif($pct===100 && !$quizData): ?>
      <?php /* No quiz — cert shown via modal */ ?>
      <?php endif; ?>

    <?php else: ?>
    <div class="card" style="text-align:center;padding:60px;color:var(--muted)">
      <i class="fas fa-play-circle" style="font-size:54px;display:block;margin-bottom:14px"></i>
      No lessons available yet.
    </div>
    <?php endif; ?>
  </div>

  <!-- Lesson sidebar -->
  <div class="lesson-panel">
    <div class="lp-head">
      <div class="lp-head-title">Course Content</div>
      <div style="font-size:12px;color:var(--muted);margin-bottom:8px"><?= $done ?>/<?= $total ?> completed</div>
      <div class="pbar"><div class="pfill" id="lpbar" style="width:<?= $pct ?>%"></div></div>
      <div style="font-size:11px;color:var(--gold);margin-top:5px;font-weight:700"><?= $pct ?>%</div>
    </div>
    <div class="lp-scroll">
      <?php foreach($lessons as $i=>$l):
        $lItemType = effectiveType($l);
      ?>
      <a href="watch.php?id=<?= $cid ?>&lid=<?= $l['id'] ?>" style="text-decoration:none">
        <div class="l-item <?= $l['id']===$curId?'act':'' ?>">
          <div class="l-ico <?= $l['is_done']?'done':'' ?>" style="<?= !$l['is_done'] && $lItemType==='youtube' ? 'background:rgba(204,0,0,.15);' : '' ?>">
            <?php if($l['is_done']): ?>
              <i class="fas fa-check"></i>
            <?php elseif($lItemType==='youtube'): ?>
              <i class="fab fa-youtube" style="color:#c00"></i>
            <?php elseif($lItemType==='video'): ?>
              <i class="fas fa-play"></i>
            <?php else: ?>
              <i class="fas fa-file-alt"></i>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:0">
            <div class="l-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= ($i+1) ?>. <?= h($l['title']) ?>
            </div>
            <div class="l-sub">
              <?php if($lItemType==='youtube'): ?>
                <i class="fab fa-youtube" style="color:#c00"></i> YouTube
              <?php elseif($lItemType==='video'): ?>
                <i class="fas fa-video"></i> Video
              <?php else: ?>
                <i class="fas fa-file-alt"></i> <?= ucfirst($lItemType) ?>
              <?php endif; ?>
            </div>
          </div>
          <?php if($l['is_done']): ?>
          <i class="fas fa-check-circle" style="color:#4caf82;font-size:14px;flex-shrink:0"></i>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

</div>





<script>
// ===== MARK DONE =====
function markDone(lid) {
  fetch('watch.php?id=<?= $cid ?>', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'mark_done=1&lid='+lid
  }).then(function(r){return r.json();}).then(function(d){
    if(d.ok){
      var btn=document.getElementById('done-btn');
      if(btn) btn.outerHTML='<span class="badge bg-green" style="padding:9px 14px;font-size:12px"><i class="fas fa-check-circle"></i> Lesson Completed</span>';
      setTimeout(function(){ location.reload(); }, 700);
    }
  });
}

// Slide viewer fallback
(function(){
  var iframe   = document.getElementById('slide-viewer');
  var fallback = document.getElementById('viewer-fallback');
  if (!iframe || !fallback) return;
  var timer = setTimeout(function(){
    fallback.style.display = 'flex';
    iframe.style.display   = 'none';
  }, 12000);
  iframe.addEventListener('load', function(){ clearTimeout(timer); });
})();

// 5-minute watch gate for uploaded videos
<?php if($cur && effectiveType($cur) === 'video' && !$cur['is_done']): ?>
var vid = document.getElementById('main-vid');
if (vid) {
    var VID_REQUIRED = 300; // 5 minutes in seconds
    var vidWatched   = 0;
    var vidLastTime  = 0;
    var vidUnlocked  = false;
    var vidTicker    = null;

    // Use setInterval (fires every second reliably) instead of timeupdate
    vid.addEventListener('play', function() {
        if (vidUnlocked) return;
        vidLastTime = vid.currentTime;
        vidTicker = setInterval(function() {
            if (vidUnlocked) { clearInterval(vidTicker); return; }
            var pos = vid.currentTime;
            // Only count genuine forward playback — ignore seeks/jumps > 3s
            if (pos > vidLastTime && pos - vidLastTime < 3) {
                vidWatched += (pos - vidLastTime);
            }
            vidLastTime = pos;

            // Update countdown on button
            var remaining = Math.max(0, Math.ceil(VID_REQUIRED - vidWatched));
            var mins = Math.floor(remaining / 60);
            var secs = remaining % 60;
            var txt = document.getElementById('done-btn-txt');
            if (txt && remaining > 0) {
                txt.textContent = 'Watch ' + mins + ':' + (secs < 10 ? '0' : '') + secs + ' more to unlock';
            }

            // Unlock at 5 minutes
            if (vidWatched >= VID_REQUIRED) {
                vidUnlocked = true;
                clearInterval(vidTicker);
                var btn = document.getElementById('done-btn');
                if (btn) {
                    btn.disabled      = false;
                    btn.className     = 'btn btn-primary';
                    btn.style.opacity = '1';
                    btn.style.cursor  = 'pointer';
                    btn.title         = '';
                    btn.querySelector('i').className = 'fas fa-check-circle';
                    var t = document.getElementById('done-btn-txt');
                    if (t) t.textContent = 'Mark Complete';
                }
            }
        }, 1000);
    });

    // Stop counting when paused or ended
    vid.addEventListener('pause', function() { clearInterval(vidTicker); });
    vid.addEventListener('ended', function() {
        clearInterval(vidTicker);
        markDone(<?= $cur['id'] ?>);
    });
}
<?php elseif($cur && effectiveType($cur) === 'video' && $cur['is_done']): ?>
var vid = document.getElementById('main-vid');
if(vid){
    vid.addEventListener('ended', function(){ markDone(<?= $cur['id'] ?>); });
}
<?php endif; ?>

// ===== YOUTUBE 10-MINUTE WATCH GATE =====
<?php if($cur && effectiveType($cur) === 'youtube' && !$cur['is_done']): ?>
var YT_REQUIRED = 300; // 5 minutes in seconds
var ytWatched   = 0;
var ytLastPos   = 0;
var ytUnlocked  = false;
var ytTicker    = null;
var ytPlayer    = null;

var tag = document.createElement('script');
tag.src = 'https://www.youtube.com/iframe_api';
document.head.appendChild(tag);

function onYouTubeIframeAPIReady() {
    ytPlayer = new YT.Player('yt-player', {
        events: { onStateChange: function(e) {
            if (e.data === YT.PlayerState.PLAYING) {
                ytTicker = setInterval(ytTick, 1000);
            } else {
                clearInterval(ytTicker);
            }
        }}
    });
}

function ytTick() {
    if (!ytPlayer || ytUnlocked) return;
    var pos = ytPlayer.getCurrentTime();
    // Only count real forward playback — ignore scrubbing
    if (pos > ytLastPos && pos - ytLastPos < 3) ytWatched += (pos - ytLastPos);
    ytLastPos = pos;

    var remaining = Math.max(0, Math.ceil(YT_REQUIRED - ytWatched));
    var mins = Math.floor(remaining / 60);
    var secs = remaining % 60;
    var txt = document.getElementById('done-btn-txt');
    if (txt && remaining > 0) txt.textContent = 'Watch ' + mins + ':' + (secs < 10 ? '0' : '') + secs + ' more to unlock';

    if (ytWatched >= YT_REQUIRED) {
        ytUnlocked = true;
        clearInterval(ytTicker);
        var btn = document.getElementById('done-btn');
        if (btn) {
            btn.disabled = false;
            btn.className = 'btn btn-primary';
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
            btn.title = '';
            btn.querySelector('i').className = 'fas fa-check-circle';
            if (txt) txt.textContent = 'Mark Complete';
        }
    }
}
<?php endif; ?>



// ===== STAR RATING =====
</script>

<script>
function submitRating(val) {
    // Highlight selected stars
    document.querySelectorAll('.rating-star').forEach(function(s, i){
        s.classList.toggle('lit', i < val);
    });
    // Update hint text
    var hint = document.querySelector('.rating-hint');
    if(hint) hint.textContent = 'You rated ' + val + ' star' + (val !== 1 ? 's' : '');
    setTimeout(function(){ document.getElementById('rate-form').submit(); }, 300);
}

// Hover effects for stars
document.addEventListener('DOMContentLoaded', function(){
    var stars = document.querySelectorAll('.rating-star');
    stars.forEach(function(s, i){
        s.addEventListener('mouseover', function(){
            stars.forEach(function(st, j){ st.classList.toggle('lit', j <= i); });
        });
        s.addEventListener('mouseout', function(){
            var chk = document.querySelector('.rating-star input:checked');
            var val = chk ? parseInt(chk.value) : 0;
            stars.forEach(function(st, j){ st.classList.toggle('lit', j < val); });
        });
    });
});
</script>

<!-- ── Lesson Discussion ── -->
<div class="card" style="margin-top:20px" id="discussion-section">
  <div class="card-hd" style="margin-bottom:0;padding-bottom:14px;border-bottom:1px solid var(--border)">
    <span class="card-title">
      <i class="fas fa-comments" style="color:var(--gold)"></i>
      Lesson Discussion
    </span>
    <span class="badge bg-muted" id="disc-count">Loading…</span>
  </div>

  <!-- Chat messages area -->
  <div id="disc-messages"
       style="display:flex;flex-direction:column;gap:10px;padding:16px 0;min-height:80px;max-height:420px;overflow-y:auto">
    <div id="disc-loading" style="text-align:center;color:var(--muted);font-size:13px;padding:20px 0">
      <i class="fas fa-spinner fa-spin"></i> Loading messages…
    </div>
  </div>

  <!-- Compose box -->
  <div style="border-top:1px solid var(--border);padding-top:14px;display:flex;gap:10px;align-items:flex-end">
    <div style="flex:1">
      <textarea id="disc-input"
        style="width:100%;background:var(--surf2);border:1px solid var(--border);border-radius:20px;
               padding:10px 16px;color:var(--text);font-family:'Raleway',sans-serif;font-size:13px;
               resize:none;outline:none;line-height:1.5;min-height:42px;max-height:120px;
               transition:border-color .2s;display:block"
        placeholder="Ask a question or share a thought…"
        rows="1"
        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();discSend();}"
        oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px';
                 this.style.borderColor=this.value.trim()?'var(--gold)':'var(--border)'">
      </textarea>
    </div>
    <button onclick="discSend()"
            style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#1e6fcc,#1e90ff);
                   border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;
                   flex-shrink:0;transition:opacity .2s"
            onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="22" y1="2" x2="11" y2="13"></line>
        <polygon points="22 2 15 22 11 13 2 9 22 2" fill="#fff" stroke="none"></polygon>
      </svg>
    </button>
  </div>
  <div style="font-size:11px;color:var(--muted);margin-top:6px">
    <i class="fas fa-info-circle"></i> Press Enter to send · Shift+Enter for new line
  </div>
</div>

<style>
.disc-bubble-wrap { display:flex; align-items:flex-end; gap:10px; }
.disc-bubble-wrap.mine { flex-direction:row-reverse; }
.disc-av {
    width:32px; height:32px; border-radius:50%; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:12px; font-weight:700; font-family:'Cinzel',serif;
}
.disc-bubble {
    max-width:72%; padding:9px 13px; border-radius:16px;
    font-size:13px; line-height:1.55; word-break:break-word;
}
.disc-bubble-wrap:not(.mine) .disc-bubble {
    background:var(--surf2); border:1px solid var(--border);
    border-bottom-left-radius:4px; color:var(--text);
}
.disc-bubble-wrap.mine .disc-bubble {
    background:linear-gradient(135deg,#1e6fcc,#1e90ff);
    color:#fff; border-bottom-right-radius:4px;
}
.disc-name { font-size:11px; font-weight:700; margin-bottom:3px; }
.disc-bubble-wrap:not(.mine) .disc-name { color:var(--muted); }
.disc-bubble-wrap.mine .disc-name { color:rgba(255,255,255,.75); text-align:right; }
.disc-time { font-size:10px; margin-top:3px; text-align:right; }
.disc-bubble-wrap:not(.mine) .disc-time { color:var(--muted); }
.disc-bubble-wrap.mine .disc-time { color:rgba(255,255,255,.65); }
.instr-badge {
    display:inline-block; font-size:9px; font-weight:700;
    background:rgba(39,174,96,.15); color:#4caf82;
    border:1px solid rgba(39,174,96,.3);
    padding:1px 6px; border-radius:99px; margin-left:4px; vertical-align:middle;
}
</style>

<script>
var DISC_LESSON_ID = <?= $cur ? (int)$cur['id'] : 0 ?>;
var DISC_MY_ID     = <?= $uid ?>;
var DISC_MY_NAME   = <?= json_encode($_SESSION['full_name'] ?? 'You') ?>;
var DISC_MY_ROLE   = <?= json_encode($_SESSION['role'] ?? 'student') ?>;
var DISC_MY_INIT   = <?= json_encode(strtoupper(mb_substr($_SESSION['full_name'] ?? 'U', 0, 1))) ?>;

var avatarColors = ['#1e6fcc','#0F6E56','#993C1D','#3C3489','#185FA5','#639922','#BA7517'];
function avatarColor(name) {
    var n = 0; for(var i=0;i<name.length;i++) n += name.charCodeAt(i);
    return avatarColors[n % avatarColors.length];
}

function buildBubble(m) {
    var wrap = document.createElement('div');
    wrap.className = 'disc-bubble-wrap' + (m.mine ? ' mine' : '');
    wrap.id = 'disc-msg-' + m.id;

    var avColor = avatarColor(m.full_name);
    var av = '<div class="disc-av" style="background:' + (m.mine ? 'rgba(30,144,255,.15)' : 'rgba(255,255,255,.07)') + ';color:' + (m.mine ? '#63b3ff' : avColor) + '">' + m.initial + '</div>';

    var instrBadge = (m.role === 'instructor') ? '<span class="instr-badge">Instructor</span>' : '';
    var nameAlign  = m.mine ? 'text-align:right' : '';
    var bubble = '<div class="disc-bubble">'
        + '<div class="disc-name" style="' + nameAlign + '">' + m.full_name + instrBadge + '</div>'
        + m.message
        + '<div class="disc-time">' + m.created_at + (m.mine ? ' &#10003;&#10003;' : '') + '</div>'
        + '</div>';

    wrap.innerHTML = m.mine ? bubble + av : av + bubble;
    return wrap;
}

function discLoad() {
    if (!DISC_LESSON_ID) return;
    fetch('watch.php?id=<?= $cid ?>&load_discuss=1&lesson_id=' + DISC_LESSON_ID)
        .then(function(r){ return r.json(); })
        .then(function(msgs) {
            var box = document.getElementById('disc-messages');
            var loading = document.getElementById('disc-loading');
            if(loading) loading.remove();
            box.innerHTML = '';
            if (msgs.length === 0) {
                box.innerHTML = '<div style="text-align:center;color:var(--muted);font-size:13px;padding:24px 0">'
                    + '<i class="fas fa-comments" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3"></i>'
                    + 'No messages yet. Be the first to ask a question!</div>';
            } else {
                msgs.forEach(function(m){ box.appendChild(buildBubble(m)); });
            }
            document.getElementById('disc-count').textContent = msgs.length + ' message' + (msgs.length !== 1 ? 's' : '');
            box.scrollTop = box.scrollHeight;
        })
        .catch(function(){ });
}

function discSend() {
    var inp = document.getElementById('disc-input');
    var msg = inp.value.trim();
    if (!msg) return;
    inp.value = ''; inp.style.height = 'auto';
    inp.style.borderColor = 'var(--border)';

    var fd = new FormData();
    fd.append('ajax_discuss', '1');
    fd.append('lesson_id', DISC_LESSON_ID);
    fd.append('message', msg);

    fetch('watch.php?id=<?= $cid ?>', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.ok) {
                var box = document.getElementById('disc-messages');
                var empty = box.querySelector('[style*="No messages"]');
                if (empty) empty.remove();
                box.appendChild(buildBubble(res));
                box.scrollTop = box.scrollHeight;
                var cnt = box.querySelectorAll('.disc-bubble-wrap').length;
                document.getElementById('disc-count').textContent = cnt + ' message' + (cnt !== 1 ? 's' : '');
            }
        })
        .catch(function(){ });
}

// Load on page ready
document.addEventListener('DOMContentLoaded', function(){ discLoad(); });
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
