<?php
require_once '../includes/config.php';
requireRole('instructor');
require_once '../includes/mailer.php';

$uid = (int)$_SESSION['user_id'];
$cid = (int)($_GET['id'] ?? 0);

// Verify ownership (primary OR co-instructor)
$course = $pdo->prepare('SELECT * FROM courses WHERE id=? LIMIT 1');
$course->execute([$cid]);
$course = $course->fetch();
if (!$course) { header('Location: courses.php'); exit; }

// Check access: primary instructor or listed in course_instructors
$hasAccess = ($course['instructor_id'] === $uid);
if (!$hasAccess) {
    try {
        $chk = $pdo->prepare('SELECT 1 FROM course_instructors WHERE course_id=? AND instructor_id=? LIMIT 1');
        $chk->execute([$cid,$uid]);
        $hasAccess = (bool)$chk->fetch();
    } catch(Exception $e){}
}
if (!$hasAccess) { header('Location: courses.php'); exit; }

// Toggle publish
if (isset($_GET['pub'])) {
    $pdo->prepare('UPDATE courses SET is_published=NOT is_published WHERE id=?')->execute([$cid]);
    header('Location: manage.php?id='.$cid);
    exit;
}

// Delete lesson — only the uploader OR the primary instructor can delete
if (isset($_GET['del']) && is_numeric($_GET['del'])) {
    $lid = (int)$_GET['del'];
    $isPrimary = ($course['instructor_id'] === $uid);
    $row = $pdo->prepare('SELECT file_path,type,uploaded_by FROM lessons WHERE id=? AND course_id=?');
    $row->execute([$lid,$cid]);
    $row = $row->fetch();
    if ($row && ($isPrimary || (int)$row['uploaded_by'] === $uid)) {
        if ($row['type'] !== 'youtube' && $row['file_path']) {
            $fp = dirname(__DIR__).DIRECTORY_SEPARATOR.$row['file_path'];
            if (file_exists($fp)) unlink($fp);
        }
        $pdo->prepare('DELETE FROM lessons WHERE id=?')->execute([$lid]);
    }
    header('Location: manage.php?id='.$cid);
    exit;
}

// ── Helper: extract YouTube video ID from any URL format ──
function extractYouTubeId(string $url): string {
    // Standard: youtube.com/watch?v=ID
    // Short:    youtu.be/ID
    // Embed:    youtube.com/embed/ID
    $patterns = [
        '/(?:youtube\.com\/watch\?(?:.*&)?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $url, $m)) return $m[1];
    }
    return '';
}

// ── AJAX Edit Lesson ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_edit_lesson'])) {
    header('Content-Type: application/json');
    $lid   = (int)($_POST['lesson_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['desc']  ?? '');
    if (!$title) { echo json_encode(['ok'=>false,'msg'=>'Lesson title cannot be empty.']); exit; }
    $chk = $pdo->prepare('SELECT * FROM lessons WHERE id=? AND course_id=? LIMIT 1');
    $chk->execute([$lid, $cid]);
    $lesson = $chk->fetch();
    if (!$lesson) { echo json_encode(['ok'=>false,'msg'=>'Lesson not found.']); exit; }
    $ltype = effectiveType($lesson);
    $newFilePath = $lesson['file_path'];
    $newFileName = $lesson['file_name'];
    $newFileSize = $lesson['file_size'];
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedVideo = ['mp4','webm','mov','avi','mkv'];
        $allowedSlide = ['pdf','ppt','pptx','doc','docx'];
        if ($ltype==='video' && !in_array($ext,$allowedVideo)) { echo json_encode(['ok'=>false,'msg'=>'Invalid video format. Allowed: '.implode(', ',$allowedVideo)]); exit; }
        if ($ltype==='slide' && !in_array($ext,$allowedSlide)) { echo json_encode(['ok'=>false,'msg'=>'Invalid slide format. Allowed: '.implode(', ',$allowedSlide)]); exit; }
        $dir = UPLOAD_DIR . $ltype . 's' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $safe = uniqid($ltype.'_', true) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir.$safe)) { echo json_encode(['ok'=>false,'msg'=>'Could not save file. Check folder permissions.']); exit; }
        $oldPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $lesson['file_path'];
        if (file_exists($oldPath)) @unlink($oldPath);
        $newFilePath  = 'uploads/'.$ltype.'s/'.$safe;
        $newFileName  = htmlspecialchars($file['name']);
        $newFileSize  = $file['size'];
        // Convert to PDF if PPTX/DOCX
        if ($ltype === 'slide' && in_array($ext, ['ppt','pptx','doc','docx'])) {
            $fullSavePath = $dir . $safe;
            $pdfPath = convertToPdf($fullSavePath, $dir);
            if ($pdfPath !== false) {
                $newFilePath = 'uploads/slides/' . basename($pdfPath);
                $newFileSize = filesize($pdfPath);
            }
        }
    }
    if ($ltype==='youtube' && !empty($_POST['yt_url'])) {
        $ytUrl = trim($_POST['yt_url']);
        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $ytUrl, $m)) {
            $newFilePath = $m[1]; $newFileName = $ytUrl;
        } else { echo json_encode(['ok'=>false,'msg'=>'Invalid YouTube URL.']); exit; }
    }
    $pdo->prepare('UPDATE lessons SET title=?,description=?,file_path=?,file_name=?,file_size=? WHERE id=?')
        ->execute([$title,$desc,$newFilePath,$newFileName,$newFileSize,$lid]);
    echo json_encode(['ok'=>true,'msg'=>'Lesson updated successfully.']); exit;
}

// ── AJAX: Add YouTube lesson ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_youtube'])) {
    header('Content-Type: application/json');
    $title    = trim($_POST['title'] ?? '');
    $ytUrl    = trim($_POST['yt_url'] ?? '');
    $desc     = trim($_POST['desc']  ?? '');
    $ytId     = extractYouTubeId($ytUrl);

    if (!$title)  { echo json_encode(['ok'=>false,'msg'=>'Lesson title is required.']); exit; }
    if (!$ytId)   { echo json_encode(['ok'=>false,'msg'=>'Could not find a valid YouTube video ID in that URL. Please paste a standard youtube.com/watch?v=... or youtu.be/... link.']); exit; }

    $ord = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM lessons WHERE course_id=?');
    $ord->execute([$cid]); $ord = (int)$ord->fetchColumn();

    // Store youtube ID in file_path, file_name; type='youtube'
    $pdo->prepare('INSERT INTO lessons (course_id,uploaded_by,title,description,type,file_path,file_name,file_size,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cid,$uid,$title,$desc,'youtube',$ytId,$ytId,0,$ord]);

    $pdo->prepare('UPDATE courses SET is_published=1 WHERE id=?')->execute([$cid]);

    // ── Notify enrolled students (co-instructor aware) ──
    try {
        $courseRow = $pdo->prepare('SELECT c.title, u.full_name AS primary_name, u.email AS primary_email FROM courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=? LIMIT 1');
        $courseRow->execute([$cid]); $courseRow = $courseRow->fetch();
        $courseTitle       = $courseRow['title'] ?? 'your course';

        // Fetch uploader name from DB — reliable for co-instructors
        $uploaderQ = $pdo->prepare('SELECT full_name FROM users WHERE id=? LIMIT 1');
        $uploaderQ->execute([$uid]); $instructorName = $uploaderQ->fetchColumn() ?: 'Your instructor';

        $isCoInstructor = $courseRow && (strtolower($courseRow['primary_name']) !== strtolower($instructorName));

        $lessonCard = '
            <div style="background:#0d2a4e;border:1px solid #1e3a5f;border-left:4px solid #c8a84b;border-radius:8px;padding:16px 20px;margin-bottom:24px">
              <div style="font-size:11px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">New Lesson</div>
              <div style="font-size:15px;font-weight:700;color:#fff;margin-bottom:4px">' . htmlspecialchars($title) . '</div>
              <div style="font-size:12px;color:rgba(255,255,255,.4)">YouTube Video'
              . ($isCoInstructor ? ' &nbsp;·&nbsp; Uploaded by <strong style="color:rgba(255,255,255,.6)">' . htmlspecialchars($instructorName) . '</strong>' : '') .
              '</div>
            </div>
            <a href="' . BASE_URL . '/student/watch.php?id=' . $cid . '"
               style="display:inline-block;background:#c8a84b;color:#001a3e;font-weight:700;font-size:14px;
                      padding:12px 28px;border-radius:8px;text-decoration:none;letter-spacing:.5px">
              ▶ Watch Now
            </a>';

        // Email all enrolled students
        $subject     = '📹 New Lesson Added: ' . $title . ' — ' . $courseTitle;
        $studentBody = buildEmailTemplate(
            'A new lesson has been added to ' . $courseTitle,
            '<p style="font-size:16px;color:rgba(255,255,255,.9);margin:0 0 8px">Hi {name},</p>
             <p style="font-size:14px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 0 20px">
               A new YouTube lesson has been added to <strong style="color:#c8a84b">' . htmlspecialchars($courseTitle) . '</strong>
               by ' . htmlspecialchars($instructorName) . '. Log in to watch it now!
             </p>' . $lessonCard
        );
        notifyEnrolledStudents($pdo, $cid, $subject, $studentBody);

        // Also notify primary instructor if a co-instructor uploaded
        if ($isCoInstructor) {
            notifyPrimaryInstructor($pdo, $cid, $instructorName, 'lesson', $title, 'youtube');
        }
    } catch(Exception $e){ error_log('[RMU] Lesson email error: ' . $e->getMessage()); }

    echo json_encode(['ok'=>true,'msg'=>'YouTube lesson added successfully!']); exit;
}

// AJAX upload handler (video / slide)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_upload'])) {
    header('Content-Type: application/json');

    $type  = $_POST['type'] ?? '';
    $title = trim($_POST['title'] ?? '');

    if (!in_array($type,['video','slide']) || !$title) {
        echo json_encode(['ok'=>false,'msg'=>'Missing required fields.']); exit;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $codes = [0=>'OK',1=>'Too large (php.ini)',2=>'Too large (form)',3=>'Partial',4=>'No file',6=>'No tmp',7=>'Write fail',8=>'Extension stopped'];
        $code = $_FILES['file']['error'] ?? 4;
        echo json_encode(['ok'=>false,'msg'=>'Upload error: '.($codes[$code] ?? $code)]); exit;
    }

    $file = $_FILES['file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowedVideo = ['mp4','webm','mov','avi','mkv'];
    $allowedSlide = ['pdf','ppt','pptx','doc','docx'];

    if ($type==='video' && !in_array($ext,$allowedVideo)) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid video format. Use: '.implode(', ',$allowedVideo)]); exit;
    }
    if ($type==='slide' && !in_array($ext,$allowedSlide)) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid slide format. Use: '.implode(', ',$allowedSlide)]); exit;
    }

    $maxBytes = ($type==='video' ? MAX_VIDEO_MB : MAX_SLIDE_MB) * 1048576;
    if ($file['size'] > $maxBytes) {
        echo json_encode(['ok'=>false,'msg'=>'File too large. Max '.($type==='video'?MAX_VIDEO_MB:MAX_SLIDE_MB).'MB.']); exit;
    }

    $dir = UPLOAD_DIR . $type . 's' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        echo json_encode(['ok'=>false,'msg'=>'Cannot create upload directory.']); exit;
    }

    $safe = uniqid($type.'_', true) . '.' . $ext;
    $dest = $dir . $safe;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['ok'=>false,'msg'=>'Failed to save file. Check folder permissions.']); exit;
    }

    $relPath   = 'uploads/'.$type.'s/'.$safe;
    $savedSize = $file['size'];
    // Convert PPTX/DOCX to PDF
    if ($type === 'slide' && in_array($ext, ['ppt','pptx','doc','docx'])) {
        $fullSavePath = $dest;
        $pdfPath = convertToPdf($fullSavePath, $dir);
        if ($pdfPath !== false) {
            $relPath   = 'uploads/slides/' . basename($pdfPath);
            $savedSize = filesize($pdfPath);
        }
    }
    $ord = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM lessons WHERE course_id=?');
    $ord->execute([$cid]); $ord = (int)$ord->fetchColumn();

    $desc = trim($_POST['desc'] ?? '');
    $pdo->prepare('INSERT INTO lessons (course_id,uploaded_by,title,description,type,file_path,file_name,file_size,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cid,$uid,$title,$desc,$type,$relPath,h($file['name']),$savedSize,$ord]);

    $pdo->prepare('UPDATE courses SET is_published=1 WHERE id=?')->execute([$cid]);

    // ── Notify enrolled students (co-instructor aware) ──
    try {
        $courseRow = $pdo->prepare('SELECT c.title, u.full_name AS primary_name, u.email AS primary_email FROM courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=? LIMIT 1');
        $courseRow->execute([$cid]); $courseRow = $courseRow->fetch();
        $courseTitle = $courseRow['title'] ?? 'your course';

        // Fetch uploader name from DB — reliable for co-instructors
        $uploaderQ = $pdo->prepare('SELECT full_name FROM users WHERE id=? LIMIT 1');
        $uploaderQ->execute([$uid]); $instructorName = $uploaderQ->fetchColumn() ?: 'Your instructor';

        $isCoInstructor = $courseRow && (strtolower($courseRow['primary_name']) !== strtolower($instructorName));
        $typeLabel       = $type === 'video' ? '🎬 Video' : '📄 Document';
        $lessonTitle     = trim($_POST['title'] ?? '');

        $lessonCard = '
            <div style="background:#0d2a4e;border:1px solid #1e3a5f;border-left:4px solid #c8a84b;border-radius:8px;padding:16px 20px;margin-bottom:24px">
              <div style="font-size:11px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">New Lesson</div>
              <div style="font-size:15px;font-weight:700;color:#fff;margin-bottom:4px">' . htmlspecialchars($lessonTitle) . '</div>
              <div style="font-size:12px;color:rgba(255,255,255,.4)">' . ucfirst($type) . ' &nbsp;·&nbsp; ' . formatSize($file['size'])
              . ($isCoInstructor ? ' &nbsp;·&nbsp; Uploaded by <strong style="color:rgba(255,255,255,.6)">' . htmlspecialchars($instructorName) . '</strong>' : '') .
              '</div>
            </div>
            <a href="' . BASE_URL . '/student/watch.php?id=' . $cid . '"
               style="display:inline-block;background:#c8a84b;color:#001a3e;font-weight:700;font-size:14px;
                      padding:12px 28px;border-radius:8px;text-decoration:none;letter-spacing:.5px">
              ▶ Go to Course
            </a>';

        // Email all enrolled students
        $subject     = $typeLabel . ' Lesson Added: ' . $lessonTitle . ' — ' . $courseTitle;
        $studentBody = buildEmailTemplate(
            'A new lesson has been added to ' . $courseTitle,
            '<p style="font-size:16px;color:rgba(255,255,255,.9);margin:0 0 8px">Hi {name},</p>
             <p style="font-size:14px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 0 20px">
               A new lesson has been added to <strong style="color:#c8a84b">' . htmlspecialchars($courseTitle) . '</strong>
               by ' . htmlspecialchars($instructorName) . '. Log in to watch it now!
             </p>' . $lessonCard
        );
        notifyEnrolledStudents($pdo, $cid, $subject, $studentBody);

        // Also notify primary instructor if a co-instructor uploaded
        if ($isCoInstructor) {
            notifyPrimaryInstructor($pdo, $cid, $instructorName, 'lesson', $lessonTitle, $type);
        }
    } catch(Exception $e){ error_log('[RMU] Lesson email error: ' . $e->getMessage()); }

    echo json_encode(['ok'=>true,'msg'=>ucfirst($type).' uploaded successfully!']); exit;
}

// ── Auto-fix: any lesson whose file_path is an 11-char YouTube ID but type is wrong ──
try {
    $pdo->prepare("
        UPDATE lessons
        SET type = 'youtube'
        WHERE course_id = ?
          AND type != 'youtube'
          AND file_size = 0
          AND file_path REGEXP '^[a-zA-Z0-9_-]{11}$'
    ")->execute([$cid]);
} catch(Exception $e){
    // Fallback for DBs without REGEXP support
    try {
        $fixRows = $pdo->prepare("SELECT id, file_path FROM lessons WHERE course_id=? AND type != 'youtube' AND file_size = 0");
        $fixRows->execute([$cid]);
        foreach ($fixRows->fetchAll() as $fr) {
            if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $fr['file_path'])) {
                $pdo->prepare("UPDATE lessons SET type='youtube' WHERE id=?")->execute([$fr['id']]);
            }
        }
    } catch(Exception $e2){}
}

// ── Helper: resolve effective type (guard against old bad data) ──
function effectiveType(array $lesson): string {
    if ($lesson['type'] === 'youtube') return 'youtube';
    // Detect by file_path: if it looks like a YouTube ID (11 alphanumeric/dash/underscore chars, no slashes)
    if ($lesson['file_size'] == 0 && preg_match('/^[a-zA-Z0-9_-]{11}$/', $lesson['file_path'] ?? '')) return 'youtube';
    return $lesson['type'];
}

// Load lessons with uploader name
$lessons = $pdo->prepare('
    SELECT l.*, u.full_name AS uploaded_by_name
    FROM lessons l
    LEFT JOIN users u ON u.id = l.uploaded_by
    WHERE l.course_id = ?
    ORDER BY l.sort_order, l.id
');
$lessons->execute([$cid]);
$lessons = $lessons->fetchAll();

$nEnrolled = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id=?');
$nEnrolled->execute([$cid]); $nEnrolled = (int)$nEnrolled->fetchColumn();

$pageTitle    = h($course['title']);
$pageSubtitle = 'Course Management';
$activePage   = 'courses';
$depth        = 1;

ob_start();
?>

<style>
/* ── YouTube preview modal ── */
.yt-modal-wrap {
    display:none; position:fixed; inset:0; z-index:9999;
    background:rgba(0,0,0,.85); align-items:center; justify-content:center;
}
.yt-modal-wrap.open { display:flex; }
.yt-modal-box {
    background:#0a0a0a; border-radius:14px; overflow:hidden;
    width:min(860px,95vw); border:1px solid rgba(204,0,0,.3);
    box-shadow:0 24px 80px rgba(0,0,0,.8);
}
.yt-modal-head {
    display:flex; align-items:center; gap:10px; padding:12px 16px;
    background:rgba(204,0,0,.1); border-bottom:1px solid rgba(204,0,0,.2);
}
.yt-modal-title { flex:1; font-size:13px; font-weight:700; color:#fff; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.yt-modal-close {
    background:rgba(255,255,255,.1); border:none; color:#fff;
    width:28px; height:28px; border-radius:6px; cursor:pointer; font-size:14px;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.yt-modal-close:hover { background:rgba(255,255,255,.2); }
.yt-modal-player { position:relative; padding-bottom:56.25%; height:0; background:#000; }
.yt-modal-player iframe { position:absolute; top:0; left:0; width:100%; height:100%; border:0; }
.yt-modal-foot {
    padding:8px 16px; font-size:11px; color:rgba(255,255,255,.4); text-align:center;
    background:rgba(0,0,0,.4); border-top:1px solid rgba(255,255,255,.05);
}
.yt-modal-foot a { color:#c00; text-decoration:none; }
.yt-modal-foot a:hover { text-decoration:underline; }

/* ── Lesson type tabs (upload form) ── */
.add-tab-bar { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:18px; }
.add-tab-btn {
    padding:9px 18px; font-size:12px; font-weight:700; cursor:pointer;
    background:transparent; border:none; color:var(--muted);
    border-bottom:3px solid transparent; margin-bottom:-2px;
    display:flex; align-items:center; gap:6px; transition:color .15s;
}
.add-tab-btn.active { color:var(--gold); border-bottom-color:var(--gold); }
.add-tab-btn:hover:not(.active){ color:var(--white); }
.add-tab-pane { display:none; }
.add-tab-pane.active { display:block; }

/* ── YouTube lesson item badge ── */
.yt-badge {
    display:inline-flex; align-items:center; gap:4px;
    background:#c00; color:#fff; font-size:9px; font-weight:700;
    border-radius:4px; padding:2px 6px; text-transform:uppercase; letter-spacing:.5px;
}
</style>

<?php if (isset($_GET['new'])): ?>
<div class="alert alert-ok"><i class="fas fa-check-circle"></i> Course created! Add lessons below.</div>
<?php endif; ?>

<?php
// Load all instructors on this course for the team panel
$teamMembers = [];
try {
    $teamStmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.email, ci.is_primary
        FROM course_instructors ci
        JOIN users u ON u.id = ci.instructor_id
        WHERE ci.course_id = ?
        ORDER BY ci.is_primary DESC, u.full_name
    ");
    $teamStmt->execute([$cid]);
    $teamMembers = $teamStmt->fetchAll();
} catch(Exception $e){}
?>

<?php if(count($teamMembers) > 1): ?>
<div class="card" style="margin-bottom:18px;padding:14px 18px">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px">Instructor Team</span>
    <?php foreach($teamMembers as $tm): ?>
    <span style="display:inline-flex;align-items:center;gap:6px;background:<?= $tm['is_primary']?'rgba(200,168,75,.12)':'rgba(41,128,185,.10)' ?>;border:1px solid <?= $tm['is_primary']?'rgba(200,168,75,.3)':'rgba(41,128,185,.25)' ?>;border-radius:20px;padding:4px 12px;font-size:11px;font-weight:600;color:<?= $tm['is_primary']?'var(--gold)':'#64b5f6' ?>">
      <i class="fas fa-<?= $tm['is_primary']?'star':'chalkboard-teacher' ?>" style="font-size:9px"></i>
      <?= h($tm['full_name']) ?>
      <?php if($tm['id'] == $uid): ?><span style="opacity:.6">(you)</span><?php endif; ?>
    </span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="flex-between mb2">
  <div class="flex gap">
    <a href="courses.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
    <span class="badge <?= $course['is_published'] ? 'bg-green' : 'bg-muted' ?>">
      <?= $course['is_published'] ? '● Published' : '○ Draft' ?>
    </span>
    <span class="badge bg-blue"><?= $nEnrolled ?> enrolled</span>
  </div>
  <div class="flex gap">
    <a href="quiz_create.php?course=<?= $cid ?>" class="btn btn-secondary btn-sm" style="color:#ce93d8;border-color:rgba(142,68,173,.3)">
      <i class="fas fa-question-circle"></i> Quizzes
    </a>
    <a href="manage.php?id=<?= $cid ?>&pub=1" class="btn btn-secondary btn-sm">
      <i class="fas fa-<?= $course['is_published'] ? 'eye-slash' : 'eye' ?>"></i>
      <?= $course['is_published'] ? 'Unpublish' : 'Publish' ?>
    </a>
  </div>
</div>

<div class="g2" style="align-items:start">

  <!-- Lesson list -->
  <div class="card">
    <div class="card-hd">
      <span class="card-title"><i class="fas fa-list" style="color:var(--gold)"></i> Lessons (<?= count($lessons) ?>)</span>
    </div>
    <?php if (empty($lessons)): ?>
      <p style="color:var(--muted);text-align:center;padding:30px 0">
        <i class="fas fa-inbox" style="font-size:30px;display:block;margin-bottom:10px"></i>
        No lessons yet. Use the form on the right to add.
      </p>
    <?php else: ?>
      <?php foreach ($lessons as $l): ?>
      <?php
        $ltype = effectiveType($l);
        $isPrimaryInstructor = ($course['instructor_id'] === $uid);
        $isMyLesson = ((int)$l['uploaded_by'] === $uid);
        $canDelete = $isPrimaryInstructor || $isMyLesson;
      ?>
      <div class="l-item">
        <div class="l-ico" style="<?= $ltype==='youtube' ? 'background:rgba(204,0,0,.15);' : '' ?>">
          <?php if($ltype==='youtube'): ?>
            <i class="fab fa-youtube" style="color:#c00;font-size:18px"></i>
          <?php elseif($ltype==='video'): ?>
            <i class="fas fa-play" style="color:var(--gold)"></i>
          <?php else: ?>
            <i class="fas fa-file-alt"></i>
          <?php endif; ?>
        </div>
        <div style="flex:1;min-width:0">
          <div class="l-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($l['title']) ?></div>
          <div class="l-sub">
            <?php if($ltype==='youtube'): ?>
              <span class="yt-badge"><i class="fab fa-youtube"></i> YouTube</span>
              &nbsp;<span style="color:var(--muted);font-size:10px;font-family:monospace"><?= h($l['file_path']) ?></span>
            <?php else: ?>
              <span class="badge <?= $ltype==='video' ? 'bg-blue' : 'bg-gold' ?>" style="font-size:9px"><?= strtoupper($ltype) ?></span>
              &nbsp;<?= formatSize($l['file_size']) ?>
            <?php endif; ?>
            &nbsp;<?= date('M d', strtotime($l['created_at'])) ?>
            &nbsp;·&nbsp;
            <?php if($isMyLesson): ?>
              <span style="color:var(--gold);font-size:10px"><i class="fas fa-user"></i> You</span>
            <?php else: ?>
              <span style="color:var(--muted);font-size:10px"><i class="fas fa-user"></i> <?= h($l['uploaded_by_name'] ?? 'Unknown') ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="flex gap">
          <?php if($ltype==='youtube'): ?>
            <button class="btn btn-secondary btn-sm" title="Preview video"
              onclick="openYTModal('<?= h($l['file_path']) ?>','<?= h(addslashes($l['title'])) ?>')">
              <i class="fab fa-youtube" style="color:#c00"></i>
            </button>
          <?php else: ?>
            <a href="<?= BASE_URL . '/' . h($l['file_path']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="Preview"><i class="fas fa-eye"></i></a>
          <?php endif; ?>
          <button class="btn btn-secondary btn-sm" title="Edit lesson"
            onclick="openEditModal(<?= $l['id'] ?>,'<?= h(addslashes($l['title'])) ?>','<?= h(addslashes($l['description'] ?? '')) ?>','<?= $ltype ?>','<?= h(addslashes($l['file_name'] ?? '')) ?>','<?= $ltype==='youtube' ? h($l['file_path']) : '' ?>')">
            <i class="fas fa-pen"></i> Edit
          </button>
          <?php if($canDelete): ?>
          <a href="manage.php?id=<?= $cid ?>&del=<?= $l['id'] ?>" class="btn btn-danger btn-sm"
             onclick="return confirm('Delete this lesson permanently?')" title="Delete">
            <i class="fas fa-trash"></i>
          </a>
          <?php else: ?>
          <button class="btn btn-secondary btn-sm" disabled title="Only the uploader or primary instructor can delete this lesson" style="opacity:.4;cursor:not-allowed">
            <i class="fas fa-trash"></i>
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Course Info & Quick Actions -->
  <div>
    <div class="card mb2">
      <div class="card-hd">
        <span class="card-title"><i class="fas fa-info-circle" style="color:var(--gold)"></i> Course Info</span>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px">
        <div style="font-size:13px;color:var(--muted)">
          <i class="fas fa-book" style="color:var(--gold);width:16px"></i>
          <strong style="color:var(--white)"><?= h($course['title']) ?></strong>
        </div>
        <div style="font-size:12px;color:var(--muted)">
          <i class="fas fa-layer-group" style="width:16px"></i> Level <?= h($course['level']) ?>
        </div>
        <div style="font-size:12px;color:var(--muted)">
          <i class="fas fa-users" style="width:16px"></i> <?= $nEnrolled ?> student<?= $nEnrolled!=1?'s':'' ?> enrolled
        </div>
        <div style="font-size:12px;color:var(--muted)">
          <i class="fas fa-film" style="width:16px"></i> <?= count($lessons) ?> lesson<?= count($lessons)!=1?'s':'' ?>
        </div>
        <?php if($course['description']): ?>
        <div style="font-size:12px;color:var(--muted);line-height:1.6;border-top:1px solid var(--border);padding-top:10px;margin-top:4px">
          <?= h($course['description']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-hd">
        <span class="card-title"><i class="fas fa-bolt" style="color:var(--gold)"></i> Quick Actions</span>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px">
        <a href="upload.php" class="btn btn-primary" style="justify-content:center">
          <i class="fas fa-upload"></i> Add New Lessons
        </a>
        <a href="quiz_create.php?course=<?= $cid ?>" class="btn btn-secondary" style="justify-content:center;color:#ce93d8;border-color:rgba(142,68,173,.3)">
          <i class="fas fa-question-circle"></i> Manage Quizzes
        </a>
        <a href="<?= BASE_URL ?>/student/watch.php?id=<?= $cid ?>" target="_blank" class="btn btn-secondary" style="justify-content:center">
          <i class="fas fa-eye"></i> Preview as Student
        </a>
        <a href="manage.php?id=<?= $cid ?>&pub=1" class="btn btn-secondary" style="justify-content:center">
          <i class="fas fa-<?= $course['is_published']?'eye-slash':'eye' ?>"></i>
          <?= $course['is_published']?'Unpublish Course':'Publish Course' ?>
        </a>
      </div>
    </div>
  </div>

<!-- ── YouTube Preview Modal ── -->
<div class="yt-modal-wrap" id="yt-preview-modal" onclick="closeYTModal(event)">
  <div class="yt-modal-box">
    <div class="yt-modal-head">
      <i class="fab fa-youtube" style="color:#c00;font-size:18px;flex-shrink:0"></i>
      <span class="yt-modal-title" id="yt-modal-title">Video Preview</span>
      <button class="yt-modal-close" onclick="closeYTModal(null,true)"><i class="fas fa-times"></i></button>
    </div>
    <div class="yt-modal-player">
      <iframe id="yt-modal-frame"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowfullscreen></iframe>
    </div>
    <div class="yt-modal-foot">
      <i class="fab fa-youtube" style="color:#c00"></i>
      Video hosted on YouTube — views count on YouTube &nbsp;·&nbsp;
      <a id="yt-modal-ext-link" href="#" target="_blank" rel="noopener">Open on YouTube <i class="fas fa-external-link-alt" style="font-size:9px"></i></a>
    </div>
  </div>
</div>

<script>
function openYTModal(ytId, title) {
  document.getElementById('yt-modal-title').textContent = title || 'Video Preview';
  document.getElementById('yt-modal-frame').src = 'https://www.youtube.com/embed/' + ytId + '?autoplay=1&rel=0&modestbranding=1';
  document.getElementById('yt-modal-ext-link').href = 'https://www.youtube.com/watch?v=' + ytId;
  document.getElementById('yt-preview-modal').classList.add('open');
}
function closeYTModal(e, force) {
  if (force || (e && e.target === document.getElementById('yt-preview-modal'))) {
    document.getElementById('yt-preview-modal').classList.remove('open');
    document.getElementById('yt-modal-frame').src = ''; // stop video
  }
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeYTModal(null,true); });
</script>

<script>
// ── Add-lesson tab switcher ──
function switchAddTab(tab) {
  document.querySelectorAll('.add-tab-pane').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.add-tab-btn').forEach(function(b){ b.classList.remove('active'); });
  document.getElementById('atab-'+tab).classList.add('active');
  document.querySelectorAll('.add-tab-btn[data-atab="'+tab+'"]').forEach(function(b){ b.classList.add('active'); });
}

// ── YouTube live preview ──
document.getElementById('yt-url').addEventListener('input', function(){
  var url = this.value.trim();
  var match = url.match(/(?:youtube\.com\/watch\?(?:.*&)?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/);
  var wrap = document.getElementById('yt-preview-wrap');
  var frame = document.getElementById('yt-preview-frame');
  if(match){
    frame.src = 'https://www.youtube.com/embed/' + match[1];
    wrap.style.display = 'block';
  } else {
    frame.src = '';
    wrap.style.display = 'none';
  }
});

// ── YouTube form submit ──
document.getElementById('yt-form').addEventListener('submit', function(e){
  e.preventDefault();
  var alertEl = document.getElementById('add-alert');
  alertEl.innerHTML = '';
  var fd = new FormData(this);
  fd.append('ajax_youtube','1');
  fetch('manage.php?id=<?= $cid ?>', { method:'POST', body:fd })
    .then(function(r){ return r.json(); })
    .then(function(res){
      if(res.ok){
        alertEl.innerHTML = '<div class="alert alert-ok"><i class="fas fa-check-circle"></i> ' + res.msg + '</div>';
        document.getElementById('yt-form').reset();
        document.getElementById('yt-preview-wrap').style.display='none';
        document.getElementById('yt-preview-frame').src='';
        setTimeout(function(){ location.reload(); }, 1200);
      } else {
        alertEl.innerHTML = '<div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> ' + res.msg + '</div>';
      }
    })
    .catch(function(){ alertEl.innerHTML = '<div class="alert alert-err">Network error. Please try again.</div>'; });
});

// ── Upload form ──
function updateType(){
  var isVideo = document.getElementById('t-video').checked;
  document.getElementById('file-inp').accept = isVideo ? 'video/*' : '.pdf,.ppt,.pptx,.doc,.docx';
  document.getElementById('dz-hint').textContent = isVideo
    ? 'MP4, WEBM, MOV, AVI, MKV — max 20 GB'
    : 'PDF, PPT, PPTX, DOC, DOCX — max 20 GB';
  var ovStyle = document.getElementById('opt-video').style;
  var osStyle = document.getElementById('opt-slide').style;
  ovStyle.borderColor  = isVideo ? 'var(--gold)'   : 'var(--border)';
  ovStyle.background   = isVideo ? 'rgba(200,168,75,.08)' : 'transparent';
  osStyle.borderColor  = !isVideo ? 'var(--gold)'  : 'var(--border)';
  osStyle.background   = !isVideo ? 'rgba(200,168,75,.08)' : 'transparent';
  document.getElementById('opt-video').querySelector('i').style.color = isVideo  ? 'var(--gold)' : 'var(--muted)';
  document.getElementById('opt-slide').querySelector('i').style.color = !isVideo ? 'var(--gold)' : 'var(--muted)';
  document.getElementById('opt-video').querySelector('div').style.color = isVideo  ? 'var(--white)' : 'var(--muted)';
  document.getElementById('opt-slide').querySelector('div').style.color = !isVideo ? 'var(--white)' : 'var(--muted)';
}

var dz = document.getElementById('drop-zone');
var fi = document.getElementById('file-inp');
dz.addEventListener('dragover',function(e){e.preventDefault();dz.classList.add('over');});
dz.addEventListener('dragleave',function(){dz.classList.remove('over');});
dz.addEventListener('drop',function(e){
  e.preventDefault(); dz.classList.remove('over');
  if(e.dataTransfer.files.length){ fi.files=e.dataTransfer.files; document.getElementById('dz-name').textContent=fi.files[0].name; }
});

document.getElementById('upload-form').addEventListener('submit',function(e){
  e.preventDefault();
  var alertEl = document.getElementById('add-alert');
  alertEl.innerHTML='';
  var title = document.getElementById('up-title').value.trim();
  if(!title){ alertEl.innerHTML='<div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> Please enter a lesson title.</div>'; return; }
  if(!fi.files.length){ alertEl.innerHTML='<div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> Please choose a file.</div>'; return; }
  var fd = new FormData(this);
  var prog=document.getElementById('prog'), fill=document.getElementById('prog-fill'), txt=document.getElementById('prog-text');
  prog.classList.add('show');
  var xhr=new XMLHttpRequest();
  xhr.open('POST','manage.php?id=<?= $cid ?>');
  xhr.upload.onprogress=function(ev){
    if(ev.lengthComputable){var p=Math.round(ev.loaded/ev.total*100);fill.style.width=p+'%';txt.textContent='Uploading… '+p+'% ('+fmtBytes(ev.loaded)+' / '+fmtBytes(ev.total)+')';}
  };
  xhr.onload=function(){
    prog.classList.remove('show'); fill.style.width='0';
    var res; try{res=JSON.parse(xhr.responseText);}catch(er){alertEl.innerHTML='<div class="alert alert-err">Server error.</div>';return;}
    if(res.ok){alertEl.innerHTML='<div class="alert alert-ok"><i class="fas fa-check-circle"></i> '+res.msg+'</div>';document.getElementById('upload-form').reset();document.getElementById('dz-name').textContent='';setTimeout(function(){location.reload();},1200);}
    else{alertEl.innerHTML='<div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> '+res.msg+'</div>';}
  };
  xhr.onerror=function(){prog.classList.remove('show');alertEl.innerHTML='<div class="alert alert-err">Network error.</div>';};
  xhr.send(fd);
});
</script>

<!-- ── Edit Lesson Modal ── -->
<div class="modal-wrap" id="edit-modal">
  <div class="modal-box" style="max-width:500px">
    <div class="modal-head">
      <h3><i class="fas fa-pen" style="color:var(--gold)"></i> Edit Lesson</h3>
      <button class="modal-close" onclick="closeModal('edit-modal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="edit-alert" style="margin-bottom:12px"></div>
      <form id="edit-form" enctype="multipart/form-data">
        <input type="hidden" name="ajax_edit_lesson" value="1">
        <input type="hidden" name="lesson_id" id="edit-lid">

        <div class="fg">
          <label class="lbl2">Lesson Title *</label>
          <input class="fc" type="text" name="title" id="edit-title" required placeholder="Lesson title">
        </div>

        <div class="fg">
          <label class="lbl2">Description</label>
          <textarea class="fc" name="desc" id="edit-desc" rows="2" placeholder="Brief description…"></textarea>
        </div>

        <!-- File replacement (video/slide) -->
        <div id="edit-file-section" style="display:none">
          <div class="fg">
            <label class="lbl2">Replace File <span style="color:var(--muted);font-weight:400;font-size:11px">(optional — leave blank to keep current)</span></label>
            <div style="font-size:11px;color:var(--muted);margin-bottom:6px">
              <i class="fas fa-file"></i> Current: <span id="edit-current-file" style="color:var(--gold)"></span>
            </div>
            <input class="fc" type="file" name="file" id="edit-file" style="padding:8px">
            <small id="edit-file-hint" style="color:var(--muted);font-size:11px;margin-top:4px;display:block"></small>
          </div>
        </div>

        <!-- YouTube URL replacement -->
        <div id="edit-yt-section" style="display:none">
          <div class="fg">
            <label class="lbl2">YouTube URL <span style="color:var(--muted);font-weight:400;font-size:11px">(optional — leave blank to keep current)</span></label>
            <input class="fc" type="url" name="yt_url" id="edit-yt-url" placeholder="https://www.youtube.com/watch?v=...">
            <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block">
              <i class="fas fa-info-circle"></i> Paste a new YouTube link to replace the current video
            </small>
          </div>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
          <button type="button" class="btn btn-secondary" onclick="closeModal('edit-modal')">
            <i class="fas fa-times"></i> Cancel
          </button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditModal(lid, title, desc, ltype, fileName, ytId) {
  document.getElementById('edit-lid').value   = lid;
  document.getElementById('edit-title').value = title;
  document.getElementById('edit-desc').value  = desc;
  document.getElementById('edit-alert').innerHTML = '';
  document.getElementById('edit-file').value  = '';

  var fileSection = document.getElementById('edit-file-section');
  var ytSection   = document.getElementById('edit-yt-section');
  var fileHint    = document.getElementById('edit-file-hint');
  var currentFile = document.getElementById('edit-current-file');
  var fileInput   = document.getElementById('edit-file');

  fileSection.style.display = 'none';
  ytSection.style.display   = 'none';

  if (ltype === 'youtube') {
    ytSection.style.display = 'block';
    document.getElementById('edit-yt-url').value = ytId ? 'https://www.youtube.com/watch?v=' + ytId : '';
  } else if (ltype === 'video') {
    fileSection.style.display = 'block';
    fileInput.accept = 'video/*';
    fileHint.textContent = 'Allowed formats: MP4, WEBM, MOV, AVI, MKV';
    currentFile.textContent = fileName || 'Unknown';
  } else {
    fileSection.style.display = 'block';
    fileInput.accept = '.pdf,.ppt,.pptx,.doc,.docx';
    fileHint.textContent = 'Allowed formats: PDF, PPT, PPTX, DOC, DOCX';
    currentFile.textContent = fileName || 'Unknown';
  }

  openModal('edit-modal');
}

document.getElementById('edit-form').addEventListener('submit', function(e) {
  e.preventDefault();
  var alertEl = document.getElementById('edit-alert');
  var title   = document.getElementById('edit-title').value.trim();
  if (!title) {
    alertEl.innerHTML = '<div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> Lesson title cannot be empty.</div>';
    return;
  }
  alertEl.innerHTML = '<div class="alert" style="background:rgba(200,168,75,.08);border:1px solid rgba(200,168,75,.2);color:var(--gold)"><i class="fas fa-spinner fa-spin"></i> Saving changes…</div>';
  var fd  = new FormData(this);
  var xhr = new XMLHttpRequest();
  xhr.open('POST', 'manage.php?id=<?= $cid ?>');
  xhr.onload = function() {
    var res;
    try { res = JSON.parse(xhr.responseText); } catch(er) {
      alertEl.innerHTML = '<div class="alert alert-err">Server error. Please try again.</div>'; return;
    }
    if (res.ok) {
      alertEl.innerHTML = '<div class="alert alert-ok"><i class="fas fa-check-circle"></i> ' + res.msg + '</div>';
      setTimeout(function(){ closeModal('edit-modal'); location.reload(); }, 1000);
    } else {
      alertEl.innerHTML = '<div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> ' + res.msg + '</div>';
    }
  };
  xhr.onerror = function() {
    alertEl.innerHTML = '<div class="alert alert-err">Network error. Please try again.</div>';
  };
  xhr.send(fd);
});
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
