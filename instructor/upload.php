<?php
require_once '../includes/config.php';
requireRole('instructor');
require_once '../includes/mailer.php';

$uid = (int)$_SESSION['user_id'];

// ── Get all courses this instructor can upload to ────────────────────────────
$stmt = $pdo->prepare('
    SELECT DISTINCT c.id, c.title,
           CASE WHEN c.instructor_id = :uid1 THEN 1 ELSE 0 END AS is_primary
    FROM courses c
    WHERE c.instructor_id = :uid2
       OR c.id IN (SELECT course_id FROM course_instructors WHERE instructor_id = :uid3)
    ORDER BY c.title
');
$stmt->execute([':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid]);
$myCourses = $stmt->fetchAll();

// ── AJAX: Upload a single file (video or slide) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_upload'])) {
    header('Content-Type: application/json');

    $cid   = (int)($_POST['course_id'] ?? 0);
    $type  = $_POST['type'] ?? '';
    $title = trim($_POST['title'] ?? '');

    $chk = $pdo->prepare('SELECT 1 FROM courses WHERE id=? AND (instructor_id=? OR id IN (SELECT course_id FROM course_instructors WHERE instructor_id=?)) LIMIT 1');
    $chk->execute([$cid, $uid, $uid]);
    if (!$chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Course not found or access denied.']); exit; }

    if (!in_array($type,['video','slide']) || !$title || !$cid) {
        echo json_encode(['ok'=>false,'msg'=>'Missing required fields.']); exit;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $codes=[1=>'File too large (php.ini)',2=>'File too large (form)',3=>'Partial upload',4=>'No file received',6=>'No temp dir',7=>'Write failed'];
        $code = $_FILES['file']['error'] ?? 4;
        echo json_encode(['ok'=>false,'msg'=>'Upload error: '.($codes[$code] ?? 'Code '.$code)]); exit;
    }

    $file = $_FILES['file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedVideo = ['mp4','webm','mov','avi','mkv'];
    $allowedSlide = ['pdf','docx','pptx'];

    if ($type==='video' && !in_array($ext,$allowedVideo)) { echo json_encode(['ok'=>false,'msg'=>'Invalid video format.']); exit; }
    if ($type==='slide' && !in_array($ext,$allowedSlide)) { echo json_encode(['ok'=>false,'msg'=>'Invalid slide format.']); exit; }

    $maxBytes = ($type==='video' ? MAX_VIDEO_MB : MAX_SLIDE_MB) * 1048576;
    if ($file['size'] > $maxBytes) { echo json_encode(['ok'=>false,'msg'=>'File too large. Max '.($type==='video'?MAX_VIDEO_MB:MAX_SLIDE_MB).'MB.']); exit; }

    $dir = UPLOAD_DIR . $type . 's' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) { echo json_encode(['ok'=>false,'msg'=>'Cannot create upload folder.']); exit; }

    $safe = uniqid($type.'_', true).'.'.$ext;
    if (!move_uploaded_file($file['tmp_name'], $dir.$safe)) { echo json_encode(['ok'=>false,'msg'=>'Could not save file. Check permissions.']); exit; }

    $relPath = 'uploads/'.$type.'s/'.$safe;
    $ord = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM lessons WHERE course_id=?');
    $ord->execute([$cid]); $ord = (int)$ord->fetchColumn();

    $desc = trim($_POST['desc'] ?? '');
    $pdo->prepare('INSERT INTO lessons (course_id,uploaded_by,title,description,type,file_path,file_name,file_size,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cid,$uid,$title,$desc,$type,$relPath,htmlspecialchars($file['name']),$file['size'],$ord]);

    // NOTE: do NOT auto-publish here — publish happens only on Publish All
    $newLessonId = (int)$pdo->lastInsertId();

    echo json_encode(['ok'=>true,'lesson_id'=>$newLessonId,'msg'=>ucfirst($type).' uploaded.']); exit;
}

// ── AJAX: Add YouTube lesson ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_youtube'])) {
    header('Content-Type: application/json');

    $cid   = (int)($_POST['course_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $ytUrl = trim($_POST['yt_url'] ?? '');
    $desc  = trim($_POST['desc'] ?? '');

    $chk = $pdo->prepare('SELECT 1 FROM courses WHERE id=? AND (instructor_id=? OR id IN (SELECT course_id FROM course_instructors WHERE instructor_id=?)) LIMIT 1');
    $chk->execute([$cid, $uid, $uid]);
    if (!$chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Course not found or access denied.']); exit; }

    if (!$title || !$ytUrl || !$cid) { echo json_encode(['ok'=>false,'msg'=>'All fields required.']); exit; }

    $ytId = '';
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $ytUrl, $m)) { $ytId = $m[1]; }
    if (!$ytId) { echo json_encode(['ok'=>false,'msg'=>'Invalid YouTube URL.']); exit; }

    $ord = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM lessons WHERE course_id=?');
    $ord->execute([$cid]); $ord = (int)$ord->fetchColumn();

    $pdo->prepare('INSERT INTO lessons (course_id,uploaded_by,title,description,type,file_path,file_name,file_size,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cid,$uid,$title,$desc,'youtube',$ytId,$ytUrl,0,$ord]);

    $newLessonId = (int)$pdo->lastInsertId();

    echo json_encode(['ok'=>true,'lesson_id'=>$newLessonId,'msg'=>'YouTube lesson added.']); exit;
}

// ── AJAX: PUBLISH ALL ────────────────────────────────────────────────────────
// Publishes the course and sends notifications + emails to all enrolled students
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_publish_all'])) {
    header('Content-Type: application/json');

    $cid        = (int)($_POST['course_id'] ?? 0);
    $lessonIds  = json_decode($_POST['lesson_ids'] ?? '[]', true);

    if (!$cid || empty($lessonIds)) {
        echo json_encode(['ok'=>false,'msg'=>'Nothing to publish.']); exit;
    }

    $chk = $pdo->prepare('SELECT 1 FROM courses WHERE id=? AND (instructor_id=? OR id IN (SELECT course_id FROM course_instructors WHERE instructor_id=?)) LIMIT 1');
    $chk->execute([$cid, $uid, $uid]);
    if (!$chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Access denied.']); exit; }

    // 1. Publish the course
    $pdo->prepare('UPDATE courses SET is_published=1 WHERE id=?')->execute([$cid]);

    // 2. Get course and uploader info
    $cnStmt = $pdo->prepare('SELECT c.title, u.full_name AS instructor FROM courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=? LIMIT 1');
    $cnStmt->execute([$cid]); $courseRow = $cnStmt->fetch();
    $courseName = $courseRow['title'] ?? '';

    $uploaderQ = $pdo->prepare('SELECT full_name FROM users WHERE id=? LIMIT 1');
    $uploaderQ->execute([$uid]);
    $uploaderName = $uploaderQ->fetchColumn() ?: 'Your instructor';

    // 3. Get uploaded lesson titles for the notification message
    $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
    $lessonsStmt  = $pdo->prepare("SELECT id, title, type FROM lessons WHERE id IN ($placeholders)");
    $lessonsStmt->execute($lessonIds); $newLessons = $lessonsStmt->fetchAll();

    $lessonCount = count($newLessons);
    $summary = $lessonCount === 1
        ? '"' . $newLessons[0]['title'] . '"'
        : $lessonCount . ' new lessons';

    // 4. Notify enrolled students (in-app + email)
    $enrolled = $pdo->prepare('SELECT student_id FROM enrollments WHERE course_id=?');
    $enrolled->execute([$cid]); $students = $enrolled->fetchAll();

    if (!empty($students)) {
        $notifMsg  = $uploaderName . ' published ' . $summary . ' to "' . $courseName . '"';
        $notifLink = BASE_URL . '/student/watch.php?id=' . $cid;
        $notifStmt = $pdo->prepare('INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)');
        foreach ($students as $row) {
            $notifStmt->execute([(int)$row['student_id'], 'new_lesson', $notifMsg, $notifLink]);
        }
    }

    // 5. Send email notifications
    try {
        $primaryQ = $pdo->prepare('SELECT u.full_name, u.email FROM courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=? LIMIT 1');
        $primaryQ->execute([$cid]); $primaryInstructor = $primaryQ->fetch();
        $isCoInstructor = $primaryInstructor && strtolower($primaryInstructor['full_name']) !== strtolower($uploaderName);

        // Build lesson list for email
        $lessonListHtml = '';
        foreach ($newLessons as $l) {
            $icon = $l['type'] === 'video' ? '🎬' : ($l['type'] === 'youtube' ? '▶' : '📄');
            $lessonListHtml .= '<div style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,.07);font-size:13px;color:rgba(255,255,255,.75)">'
                . $icon . ' ' . htmlspecialchars($l['title']) . '</div>';
        }

        $subject = '🚀 New content published in "' . $courseName . '"';
        $body = buildEmailTemplate(
            $lessonCount . ' new lesson' . ($lessonCount > 1 ? 's' : '') . ' added to ' . $courseName,
            '<p style="font-size:15px;color:rgba(255,255,255,.9);margin:0 0 8px">Hi {name},</p>
             <p style="font-size:13px;color:rgba(255,255,255,.65);line-height:1.7;margin:0 0 16px">
               <strong style="color:#c8a84b">' . htmlspecialchars($uploaderName) . '</strong> just published
               new content to <strong style="color:#c8a84b">' . htmlspecialchars($courseName) . '</strong>:
             </p>
             <div style="background:#0d2a4e;border:1px solid #1e3a5f;border-left:4px solid #c8a84b;border-radius:8px;padding:12px 16px;margin-bottom:20px">'
             . $lessonListHtml . '</div>
             <a href="' . BASE_URL . '/student/watch.php?id=' . $cid . '"
                style="display:inline-block;background:#c8a84b;color:#001a3e;font-weight:700;font-size:14px;padding:12px 28px;border-radius:8px;text-decoration:none">
               ▶ Go to Course
             </a>'
        );
        notifyEnrolledStudents($pdo, $cid, $subject, $body);

        if ($isCoInstructor && !empty($primaryInstructor['email'])) {
            $pBody = buildEmailTemplate(
                $uploaderName . ' published new content to ' . $courseName,
                '<p style="font-size:15px;color:rgba(255,255,255,.9);margin:0 0 8px">Hi ' . htmlspecialchars($primaryInstructor['full_name']) . ',</p>
                 <p style="font-size:13px;color:rgba(255,255,255,.65);line-height:1.7;margin:0 0 16px">
                   Your co-instructor <strong style="color:#c8a84b">' . htmlspecialchars($uploaderName) . '</strong>
                   published ' . $lessonCount . ' lesson' . ($lessonCount > 1 ? 's' : '') . ' to
                   <strong style="color:#c8a84b">' . htmlspecialchars($courseName) . '</strong>.
                 </p>' . $lessonListHtml
            );
            sendMail($primaryInstructor['email'], $primaryInstructor['full_name'],
                '👨‍🏫 Co-instructor published to ' . $courseName, $pBody);
        }
    } catch(\Exception $e) { error_log('[RMU] publish_all email error: ' . $e->getMessage()); }

    echo json_encode([
        'ok'    => true,
        'msg'   => 'Your content is now live.',
        'count' => $lessonCount,
        'url'   => BASE_URL . '/student/watch.php?id=' . $cid
    ]);
    exit;
}

// ── Recent uploads ───────────────────────────────────────────────────────────
$recent = $pdo->prepare('
    SELECT l.*, c.title AS c_title,
           uploader.full_name AS uploaded_by_name,
           CASE WHEN l.uploaded_by = :uid1 THEN 1 ELSE 0 END AS is_mine
    FROM lessons l
    JOIN courses c ON c.id = l.course_id
    LEFT JOIN users uploader ON uploader.id = l.uploaded_by
    WHERE c.instructor_id = :uid2
       OR c.id IN (SELECT course_id FROM course_instructors WHERE instructor_id = :uid3)
    ORDER BY l.created_at DESC
    LIMIT 20
');
$recent->execute([':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid]);
$recent = $recent->fetchAll();

$pageTitle    = 'Upload & Publish';
$pageSubtitle = 'Add content, review, then publish in one click';
$activePage   = 'upload';
$depth        = 1;

ob_start();
?>

<style>
/* ── Workspace layout ────────────────────────────────────────────────────── */
.workspace {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 1.5rem;
    align-items: start;
}
@media(max-width:900px){ .workspace{grid-template-columns:1fr;} }

/* ── Drop zones ──────────────────────────────────────────────────────────── */
.dz-panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 1.5rem;
    margin-bottom: 1.25rem;
}
.dz-panel-head {
    display: flex; align-items: center; gap: .6rem;
    font-size: .95rem; font-weight: 700; color: var(--white);
    margin-bottom: 1rem;
}
.drop-zone {
    border: 2px dashed var(--border);
    border-radius: 10px;
    padding: 1.75rem 1rem;
    text-align: center;
    cursor: pointer;
    transition: border-color .18s, background .18s;
    background: rgba(255,255,255,.02);
    display: flex; flex-direction: column; align-items: center; gap: .3rem;
}
.drop-zone:hover, .drop-zone.over {
    border-color: var(--gold);
    background: rgba(200,168,75,.05);
}
.drop-zone .dz-icon { font-size: 1.8rem; color: var(--gold); }
.drop-zone h4 { color: var(--white); font-size: .9rem; margin: .3rem 0 0; }
.drop-zone p  { color: var(--muted); font-size: .75rem; margin: 0; }
.drop-zone .dz-multi-hint { color: var(--gold); font-size: .72rem; margin-top: .2rem; }

/* ── Queue items ─────────────────────────────────────────────────────────── */
.queue-list { display: flex; flex-direction: column; gap: .5rem; margin-top: .85rem; }
.q-item {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: .6rem .85rem;
    animation: qIn .18s ease;
}
@keyframes qIn { from{opacity:0;transform:translateY(-5px)} to{opacity:1;transform:translateY(0)} }
.q-item.done  { border-color: rgba(79,193,120,.4); background: rgba(79,193,120,.04); }
.q-item.error { border-color: rgba(239,83,80,.4);  background: rgba(239,83,80,.04); }
.q-item.uploading { border-color: rgba(200,168,75,.4); }
.q-item-top {
    display: flex; align-items: center; gap: .5rem;
    margin-bottom: .35rem;
}
.q-item-name {
    flex: 1; font-size: .8rem; color: var(--muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.q-item-size { font-size: .72rem; color: var(--muted); flex-shrink: 0; }
.q-remove {
    background: none; border: none; color: var(--muted);
    cursor: pointer; font-size: .9rem; padding: 0 2px; line-height: 1;
    flex-shrink: 0;
}
.q-remove:hover { color: #ef5350; }
.q-title-input {
    width: 100%; background: var(--surface);
    border: 1px solid var(--border); border-radius: 6px;
    padding: .35rem .65rem; color: var(--white);
    font-size: .82rem;
}
.q-title-input:focus { outline: none; border-color: var(--gold); }
.q-prog-wrap { margin-top: .4rem; }
.q-prog-track { height: 4px; background: var(--border); border-radius: 99px; overflow: hidden; }
.q-prog-fill  { height: 100%; border-radius: 99px; background: var(--gold); width: 0; transition: width .2s; }
.q-status { font-size: .72rem; margin-top: .25rem; color: var(--muted); }
.q-status.ok  { color: #4fc178; }
.q-status.err { color: #ef5350; }

/* ── YouTube quick-add ───────────────────────────────────────────────────── */
.yt-quick {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.25rem;
}
.yt-quick-head {
    display: flex; align-items: center; gap: .6rem;
    font-size: .95rem; font-weight: 700; color: var(--white);
    margin-bottom: .85rem;
}
.yt-input-row { display: flex; gap: .5rem; margin-bottom: .6rem; }
.yt-input-row input { flex: 1; }
.yt-input-row button { flex-shrink: 0; white-space: nowrap; }
.yt-title-input { width: 100%; margin-bottom: .5rem; }

/* ── Publish sidebar ─────────────────────────────────────────────────────── */
.publish-sidebar {
    position: sticky;
    top: 76px;
}
.pub-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 1.5rem;
    margin-bottom: 1.25rem;
}
.pub-box-title {
    font-size: .78rem; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .08em;
    margin-bottom: 1rem;
}

/* Course selector */
.course-sel-label { font-size: .8rem; color: var(--muted); margin-bottom: .4rem; font-weight: 600; }

/* File browser */
.browser-list { margin-top: .75rem; display: flex; flex-direction: column; gap: .35rem; max-height: 340px; overflow-y: auto; }
.browser-list::-webkit-scrollbar { width: 3px; }
.browser-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
.browser-item {
    display: flex; align-items: center; gap: .6rem;
    padding: .5rem .65rem;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 7px; font-size: .8rem; color: var(--white);
}
.browser-item .bi-icon { font-size: .85rem; flex-shrink: 0; }
.browser-item .bi-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.browser-item .bi-type { font-size: .68rem; color: var(--muted); flex-shrink: 0; }
.browser-empty { text-align: center; padding: 1.5rem; color: var(--muted); font-size: .82rem; }

/* ── THE Publish All button ──────────────────────────────────────────────── */
.publish-all-btn {
    width: 100%;
    padding: 1rem;
    font-size: 1rem;
    font-weight: 800;
    letter-spacing: .04em;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    transition: opacity .15s, transform .12s, box-shadow .15s;
    background: linear-gradient(135deg, #c8a84b 0%, #e8c86c 50%, #c8a84b 100%);
    color: #07111f;
    display: flex; align-items: center; justify-content: center; gap: .6rem;
    box-shadow: 0 4px 20px rgba(200,168,75,.35);
    position: relative;
    overflow: hidden;
}
.publish-all-btn::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(135deg,rgba(255,255,255,.15),transparent);
    border-radius: 10px;
    pointer-events: none;
}
.publish-all-btn:disabled {
    opacity: .35; cursor: not-allowed;
    box-shadow: none;
    background: var(--border);
    color: var(--muted);
}
.publish-all-btn:not(:disabled):hover {
    opacity: .9; transform: translateY(-1px);
    box-shadow: 0 6px 28px rgba(200,168,75,.45);
}
.publish-all-btn:not(:disabled):active { transform: translateY(0) scale(.99); }
.pub-count-hint {
    font-size: .75rem; color: var(--muted); text-align: center;
    margin-top: .5rem;
}

/* ── Success state ───────────────────────────────────────────────────────── */
.success-banner {
    display: none;
    background: rgba(79,193,120,.1);
    border: 1px solid rgba(79,193,120,.3);
    border-radius: 12px;
    padding: 1.25rem;
    text-align: center;
    margin-bottom: 1rem;
}
.success-banner.show { display: block; }
.success-banner .sb-icon { font-size: 2.2rem; color: #4fc178; display: block; margin-bottom: .6rem; }
.success-banner .sb-title { font-size: 1rem; font-weight: 700; color: #4fc178; margin-bottom: .3rem; }
.success-banner .sb-sub { font-size: .8rem; color: var(--muted); margin-bottom: .75rem; }

/* ── Overall progress bar (Publish All) ──────────────────────────────────── */
.pub-progress { display: none; margin-bottom: .85rem; }
.pub-progress.show { display: block; }
.pub-prog-track { height: 6px; background: var(--border); border-radius: 99px; overflow: hidden; margin-bottom: .4rem; }
.pub-prog-fill { height: 100%; background: linear-gradient(90deg,var(--gold),#ffd700); border-radius: 99px; width: 0; transition: width .3s; }
.pub-prog-label { font-size: .75rem; color: var(--muted); text-align: center; }

/* ── Recent uploads table ────────────────────────────────────────────────── */
.recent-card { margin-top: 2rem; }
</style>

<?php if (empty($myCourses)): ?>
<div class="alert alert-err">
    <i class="fas fa-exclamation-circle"></i>
    You have no courses yet. <a href="courses.php" style="color:var(--gold)">Create a course first</a>, then come back to upload.
</div>
<?php else: ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     MAIN WORKSPACE
══════════════════════════════════════════════════════════════════════════════ -->
<div class="workspace">

  <!-- ── LEFT COLUMN: Upload zones ─────────────────────────────────────── -->
  <div>

    <!-- VIDEO drop zone -->
    <div class="dz-panel">
      <div class="dz-panel-head">
        <i class="fas fa-film" style="color:var(--gold)"></i>
        Video Lectures
        <span class="badge bg-blue" style="font-size:.68rem">MP4 · WEBM · MOV · AVI · MKV</span>
        <span class="badge bg-muted" id="v-badge" style="font-size:.68rem;margin-left:auto">0 queued</span>
      </div>
      <div class="drop-zone" id="v-dz" onclick="document.getElementById('v-inp').click()">
        <div class="dz-icon"><i class="fas fa-film"></i></div>
        <h4>Drop videos here or click to browse</h4>
        <p>MP4, WEBM, MOV, AVI, MKV — max <?= MAX_VIDEO_MB >= 1024 ? round(MAX_VIDEO_MB/1024).' GB' : MAX_VIDEO_MB.' MB' ?> each</p>
        <div class="dz-multi-hint"><i class="fas fa-layer-group"></i> Multiple files at once</div>
      </div>
      <input type="file" id="v-inp" accept="video/*" multiple style="display:none">
      <div class="queue-list" id="v-queue"></div>
    </div>

    <!-- SLIDE drop zone -->
    <div class="dz-panel">
      <div class="dz-panel-head">
        <i class="fas fa-file-powerpoint" style="color:var(--gold)"></i>
        Slides & Documents
        <span class="badge bg-gold" style="font-size:.68rem">PDF · DOCX · PPTX</span>
        <span class="badge bg-muted" id="s-badge" style="font-size:.68rem;margin-left:auto">0 queued</span>
      </div>
      <div class="drop-zone" id="s-dz" onclick="document.getElementById('s-inp').click()">
        <div class="dz-icon"><i class="fas fa-file-upload"></i></div>
        <h4>Drop document here or click to browse</h4>
        <p>PDF, DOCX, PPTX — max <?= MAX_SLIDE_MB >= 1024 ? round(MAX_SLIDE_MB/1024).' GB' : MAX_SLIDE_MB.' MB' ?> each</p>
        <div class="dz-multi-hint"><i class="fas fa-layer-group"></i> Multiple files at once</div>
      </div>
      <input type="file" id="s-inp" accept=".pdf,.docx,.pptx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.presentationml.presentation" multiple style="display:none">
      <div class="queue-list" id="s-queue"></div>
    </div>

    <!-- YOUTUBE quick-add -->
    <div class="yt-quick">
      <div class="yt-quick-head">
        <i class="fab fa-youtube" style="color:#ff0000"></i>
        YouTube Links
        <span class="badge bg-muted" id="yt-badge" style="font-size:.68rem;margin-left:auto">0 added</span>
      </div>
      <div id="yt-err" style="margin-bottom:.5rem"></div>
      <input class="fc yt-title-input" type="text" id="yt-title" placeholder="Lesson title e.g. Week 3 — Routing Protocols">
      <div class="yt-input-row">
        <input class="fc" type="url" id="yt-url" placeholder="https://www.youtube.com/watch?v=...">
        <button class="btn btn-secondary" onclick="addYouTube()"><i class="fab fa-youtube" style="color:#ff0000"></i> Add</button>
      </div>
      <div id="yt-preview" style="display:none;margin-bottom:.6rem">
        <div style="position:relative;padding-bottom:40%;height:0;overflow:hidden;border-radius:8px;background:#000">
          <iframe id="yt-frame" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allowfullscreen></iframe>
        </div>
        <div style="font-size:.72rem;color:var(--gold);text-align:center;margin-top:.3rem">
          <i class="fas fa-check-circle"></i> Valid YouTube URL
        </div>
      </div>
      <div class="queue-list" id="yt-queue"></div>
    </div>

  </div><!-- /left column -->

  <!-- ── RIGHT COLUMN: Publish sidebar ─────────────────────────────────── -->
  <div class="publish-sidebar">

    <div class="pub-box">
      <div class="pub-box-title">Publishing settings</div>

      <!-- Course selector -->
      <div class="course-sel-label">Publish to course *</div>
      <select class="fc" id="pub-course" style="margin-bottom:1.25rem">
        <option value="">— Select course —</option>
        <?php foreach($myCourses as $c): ?>
        <option value="<?= $c['id'] ?>"><?= h($c['title']) ?><?= !$c['is_primary'] ? ' (co-instructor)' : '' ?></option>
        <?php endforeach; ?>
      </select>

      <!-- File browser -->
      <div class="pub-box-title">Content to publish</div>
      <div id="browser-list" class="browser-list">
        <div class="browser-empty" id="browser-empty">
          <i class="fas fa-inbox" style="font-size:1.5rem;display:block;margin-bottom:.4rem"></i>
          No files yet — add videos, slides, or YouTube links on the left.
        </div>
      </div>
    </div>

    <!-- Success banner (shown after publish) -->
    <div class="success-banner" id="success-banner">
      <i class="fas fa-check-circle sb-icon"></i>
      <div class="sb-title">Your content is now live.</div>
      <div class="sb-sub" id="success-sub"></div>
      <a id="success-link" href="#" class="btn btn-primary btn-sm" target="_blank">
        <i class="fas fa-eye"></i> View course page
      </a>
    </div>

    <!-- Overall publish progress -->
    <div class="pub-progress" id="pub-progress">
      <div class="pub-prog-track"><div class="pub-prog-fill" id="pub-prog-fill"></div></div>
      <div class="pub-prog-label" id="pub-prog-label">Uploading…</div>
    </div>

    <!-- THE BUTTON -->
    <button class="publish-all-btn" id="publish-btn" disabled onclick="publishAll()">
      <i class="fas fa-globe"></i> Publish All
    </button>
    <div class="pub-count-hint" id="pub-count-hint">Add content and select a course to publish</div>

  </div><!-- /right column -->

</div><!-- /workspace -->

<?php endif; ?>

<!-- Recent Uploads -->
<div class="card recent-card">
  <div class="card-hd">
    <span class="card-title"><i class="fas fa-history" style="color:var(--gold)"></i> Recent Uploads</span>
  </div>
  <?php if (empty($recent)): ?>
    <p style="color:var(--muted);padding:20px 0;text-align:center">No uploads yet.</p>
  <?php else: ?>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Title</th><th>Course</th><th>Type</th><th>Uploaded By</th><th>Size</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach($recent as $r): ?>
      <tr>
        <td><strong><?= h($r['title'] ?? '(untitled)') ?></strong></td>
        <td><span class="badge bg-blue"><?= h($r['c_title'] ?? '—') ?></span></td>
        <td><span class="badge <?= $r['type']==='video'?'bg-blue':($r['type']==='youtube'?'bg-red':'bg-gold') ?>"><?= strtoupper($r['type']) ?></span></td>
        <td>
          <?php if($r['is_mine']): ?>
            <span style="color:var(--gold);font-size:11px;font-weight:600"><i class="fas fa-user"></i> You</span>
          <?php else: ?>
            <span style="color:var(--muted);font-size:11px"><?= h($r['uploaded_by_name'] ?? '—') ?></span>
          <?php endif; ?>
        </td>
        <td><?= formatSize($r['file_size']) ?></td>
        <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
        <td>
          <?php if($r['type']==='youtube'): ?>
            <a href="https://youtube.com/watch?v=<?= h($r['file_path'] ?? '') ?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i></a>
          <?php elseif(!empty($r['file_path'])): ?>
            <?php
              $fExt  = strtolower(pathinfo($r['file_path'], PATHINFO_EXTENSION));
              $fUrl  = BASE_URL . '/' . $r['file_path'];
            ?>
            <button class="btn btn-secondary btn-sm" title="Preview"
                    onclick="openPreview(<?= json_encode($fUrl) ?>, <?= json_encode($fExt) ?>, <?= json_encode($r['title'] ?? '') ?>)">
              <i class="fas fa-eye"></i>
            </button>
          <?php else: ?>
            <span class="btn btn-secondary btn-sm" style="opacity:.4;cursor:not-allowed" title="File missing"><i class="fas fa-eye-slash"></i></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
// ════════════════════════════════════════════════════════════════════════════
// STATE
// ════════════════════════════════════════════════════════════════════════════
var workspace = [];   // [{id, type, name, file|url, title, lessonId, status}]
var nextId    = 0;

function fmtBytes(b){
  if(b>=1073741824) return (b/1073741824).toFixed(2)+' GB';
  if(b>=1048576)    return (b/1048576).toFixed(1)+' MB';
  if(b>=1024)       return (b/1024).toFixed(0)+' KB';
  return b+' B';
}
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }

// ════════════════════════════════════════════════════════════════════════════
// DROP ZONE SETUP
// ════════════════════════════════════════════════════════════════════════════
function setupDZ(dzId, inputId, type) {
  var dz = document.getElementById(dzId);
  var fi = document.getElementById(inputId);
  dz.addEventListener('dragover',  function(e){ e.preventDefault(); dz.classList.add('over'); });
  dz.addEventListener('dragleave', function(){ dz.classList.remove('over'); });
  dz.addEventListener('drop', function(e){
    e.preventDefault(); dz.classList.remove('over');
    enqueueFiles(e.dataTransfer.files, type);
  });
  fi.addEventListener('change', function(){
    enqueueFiles(fi.files, type);
    fi.value = '';
  });
}
setupDZ('v-dz', 'v-inp', 'video');
setupDZ('s-dz', 's-inp', 'slide');

// ════════════════════════════════════════════════════════════════════════════
// ENQUEUE FILES
// ════════════════════════════════════════════════════════════════════════════
function enqueueFiles(fileList, type) {
  var queueEl = document.getElementById(type === 'video' ? 'v-queue' : 's-queue');
  Array.from(fileList).forEach(function(file){
    var id       = nextId++;
    var baseName = file.name.replace(/\.[^.]+$/,'').replace(/[_-]/g,' ');
    var item     = { id:id, type:type, name:file.name, file:file, title:baseName, lessonId:null, status:'queued' };
    workspace.push(item);

    var el = document.createElement('div');
    el.className = 'q-item';
    el.id = 'qi-'+id;
    el.innerHTML =
      '<div class="q-item-top">'
      + '<span style="font-size:.8rem;color:var(--gold)"><i class="fas fa-'+(type==='video'?'film':'file-alt')+'"></i></span>'
      + '<span class="q-item-name" title="'+esc(file.name)+'">'+esc(file.name)+'</span>'
      + '<span class="q-item-size">'+fmtBytes(file.size)+'</span>'
      + '<button class="q-remove" onclick="removeItem('+id+')" title="Remove"><i class="fas fa-times"></i></button>'
      + '</div>'
      + '<input class="q-title-input fc" type="text" id="qt-'+id+'" value="'+esc(baseName)+'" placeholder="Lesson title…" oninput="updateTitle('+id+',this.value)">'
      + '<div class="q-prog-wrap" id="qp-'+id+'" style="display:none">'
      +   '<div class="q-prog-track"><div class="q-prog-fill" id="qf-'+id+'"></div></div>'
      +   '<div class="q-status busy" id="qs-'+id+'">Waiting…</div>'
      + '</div>';
    queueEl.appendChild(el);
  });
  refreshSidebar();
}

// ════════════════════════════════════════════════════════════════════════════
// YOUTUBE ADD
// ════════════════════════════════════════════════════════════════════════════
document.getElementById('yt-url').addEventListener('input', function(){
  var id = extractYtId(this.value);
  var frame = document.getElementById('yt-frame');
  var prev  = document.getElementById('yt-preview');
  if(id){ frame.src='https://www.youtube.com/embed/'+id; prev.style.display='block'; }
  else  { frame.src=''; prev.style.display='none'; }
});

function extractYtId(url){
  var m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/);
  return m ? m[1] : null;
}

function addYouTube(){
  var urlEl   = document.getElementById('yt-url');
  var titleEl = document.getElementById('yt-title');
  var errEl   = document.getElementById('yt-err');
  var url     = urlEl.value.trim();
  var title   = titleEl.value.trim();
  var ytId    = extractYtId(url);
  errEl.innerHTML = '';

  if (!title) { errEl.innerHTML='<div class="alert alert-err" style="padding:.4rem .8rem;font-size:.82rem"><i class="fas fa-exclamation-circle"></i> Enter a lesson title.</div>'; return; }
  if (!ytId)  { errEl.innerHTML='<div class="alert alert-err" style="padding:.4rem .8rem;font-size:.82rem"><i class="fas fa-exclamation-circle"></i> Invalid YouTube URL.</div>'; return; }

  var id   = nextId++;
  var name = 'youtube.com/watch?v='+ytId;
  var item = { id:id, type:'youtube', name:name, ytId:ytId, url:url, title:title, lessonId:null, status:'queued' };
  workspace.push(item);

  var queueEl = document.getElementById('yt-queue');
  var el = document.createElement('div');
  el.className = 'q-item';
  el.id = 'qi-'+id;
  el.innerHTML =
    '<div class="q-item-top">'
    + '<span style="font-size:.8rem;color:#ff4444"><i class="fab fa-youtube"></i></span>'
    + '<span class="q-item-name">'+esc(title)+'</span>'
    + '<span class="q-item-size" style="font-size:.68rem;color:var(--muted)">'+esc(ytId)+'</span>'
    + '<button class="q-remove" onclick="removeItem('+id+')" title="Remove"><i class="fas fa-times"></i></button>'
    + '</div>';
  queueEl.appendChild(el);

  urlEl.value   = '';
  titleEl.value = '';
  document.getElementById('yt-frame').src='';
  document.getElementById('yt-preview').style.display='none';
  refreshSidebar();
}

// ════════════════════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════════════════════
function removeItem(id){
  workspace = workspace.filter(function(w){ return w.id !== id; });
  var el = document.getElementById('qi-'+id);
  if(el) el.remove();
  refreshSidebar();
}

function updateTitle(id, val){
  var item = workspace.find(function(w){ return w.id===id; });
  if(item) item.title = val;
}

function refreshSidebar(){
  // Badges
  var vCount  = workspace.filter(function(w){ return w.type==='video'; }).length;
  var sCount  = workspace.filter(function(w){ return w.type==='slide'; }).length;
  var ytCount = workspace.filter(function(w){ return w.type==='youtube'; }).length;
  var total   = workspace.length;

  document.getElementById('v-badge').textContent  = vCount  + ' queued';
  document.getElementById('s-badge').textContent  = sCount  + ' queued';
  document.getElementById('yt-badge').textContent = ytCount + ' added';

  // File browser
  var browserEl = document.getElementById('browser-list');
  var emptyEl   = document.getElementById('browser-empty');
  var parts = [];
  if(vCount)  parts.push(vCount  + ' video'+(vCount>1?'s':''));
  if(sCount)  parts.push(sCount  + ' slide'+(sCount>1?'s':''));
  if(ytCount) parts.push(ytCount + ' YouTube link'+(ytCount>1?'s':''));

  if(total === 0){
    emptyEl.style.display = '';
    // Remove old browser items
    browserEl.querySelectorAll('.browser-item').forEach(function(el){ el.remove(); });
  } else {
    emptyEl.style.display = 'none';
    // Sync browser items
    workspace.forEach(function(item){
      var existing = document.getElementById('bi-'+item.id);
      if(!existing){
        var icon = item.type==='video' ? 'fa-film' : item.type==='youtube' ? 'fa-youtube fab' : 'fa-file-alt';
        var iconColor = item.type==='video' ? 'var(--gold)' : item.type==='youtube' ? '#ff4444' : 'var(--gold)';
        var typeLabel = item.type==='video' ? 'VIDEO' : item.type==='youtube' ? 'YOUTUBE' : 'SLIDE';
        var bi = document.createElement('div');
        bi.className = 'browser-item';
        bi.id = 'bi-'+item.id;
        bi.innerHTML =
          '<i class="fas '+icon+' bi-icon" style="color:'+iconColor+'"></i>'
          + '<span class="bi-name" title="'+esc(item.title)+'">'+esc(item.title)+'</span>'
          + '<span class="bi-type">'+typeLabel+'</span>';
        browserEl.appendChild(bi);
      }
    });
    // Remove browser items for deleted workspace items
    browserEl.querySelectorAll('.browser-item').forEach(function(el){
      var id = parseInt(el.id.replace('bi-',''));
      if(!workspace.find(function(w){ return w.id===id; })) el.remove();
    });
  }

  // Publish button
  var course   = document.getElementById('pub-course').value;
  var btn      = document.getElementById('publish-btn');
  var hintEl   = document.getElementById('pub-count-hint');
  var canPub   = total > 0 && course !== '';
  btn.disabled = !canPub;
  if(total === 0){
    hintEl.textContent = 'Add content and select a course to publish';
  } else if(!course){
    hintEl.textContent = total + ' item'+(total>1?'s':'')+' ready — select a course above';
  } else {
    hintEl.textContent = 'Ready to publish ' + parts.join(', ');
  }
}

document.getElementById('pub-course').addEventListener('change', refreshSidebar);

// ════════════════════════════════════════════════════════════════════════════
// PUBLISH ALL — the one-click action
// ════════════════════════════════════════════════════════════════════════════
async function publishAll(){
  var courseId = document.getElementById('pub-course').value;
  if(!courseId || workspace.length === 0) return;

  // Validate titles
  var missingTitle = false;
  workspace.forEach(function(item){
    if(!item.title.trim()){
      var inp = document.getElementById('qt-'+item.id);
      if(inp){ inp.style.borderColor='#ef5350'; missingTitle=true; }
    }
  });
  if(missingTitle){
    alert('Please fill in a title for every file before publishing.');
    return;
  }

  var btn      = document.getElementById('publish-btn');
  var progWrap = document.getElementById('pub-progress');
  var progFill = document.getElementById('pub-prog-fill');
  var progLbl  = document.getElementById('pub-prog-label');

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publishing…';
  progWrap.classList.add('show');

  var total    = workspace.length;
  var done     = 0;
  var failed   = 0;
  var lessonIds = [];

  // ── Step 1: Upload every file / register every YouTube link ──────────────
  for(var i=0; i<workspace.length; i++){
    var item = workspace[i];

    // Update progress
    progFill.style.width = Math.round((done/total)*100)+'%';
    progLbl.textContent  = 'Uploading '+(done+1)+' of '+total+': '+item.title+'…';

    // Show per-item progress bar (file items only)
    var qpEl = document.getElementById('qp-'+item.id);
    var qiEl = document.getElementById('qi-'+item.id);
    if(qpEl){ qpEl.style.display='block'; }
    if(qiEl){ qiEl.classList.add('uploading'); }

    var result = await uploadItem(item, courseId);

    if(result.ok){
      lessonIds.push(result.lessonId);
      item.lessonId = result.lessonId;
      item.status   = 'done';
      if(qiEl){ qiEl.classList.remove('uploading'); qiEl.classList.add('done'); }
      var qfEl = document.getElementById('qf-'+item.id);
      var qsEl = document.getElementById('qs-'+item.id);
      if(qfEl){ qfEl.style.width='100%'; qfEl.style.background='#4fc178'; }
      if(qsEl){ qsEl.className='q-status ok'; qsEl.innerHTML='<i class="fas fa-check-circle"></i> Uploaded'; }
    } else {
      failed++;
      item.status = 'error';
      if(qiEl){ qiEl.classList.remove('uploading'); qiEl.classList.add('error'); }
      var qsEl = document.getElementById('qs-'+item.id);
      if(qsEl){ qsEl.className='q-status err'; qsEl.innerHTML='<i class="fas fa-times-circle"></i> '+(result.msg||'Failed'); }
    }
    done++;
  }

  progFill.style.width = '100%';

  if(failed > 0){
    progLbl.textContent = (done-failed)+' uploaded, '+failed+' failed.';
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-globe"></i> Publish All';
    return;
  }

  // ── Step 2: One call to publish everything ────────────────────────────────
  progLbl.textContent = 'Publishing to course page…';
  var fd = new FormData();
  fd.append('ajax_publish_all', '1');
  fd.append('course_id', courseId);
  fd.append('lesson_ids', JSON.stringify(lessonIds));

  var pubRes = await fetch('upload.php', { method:'POST', body:fd }).then(function(r){ return r.json(); });

  progWrap.classList.remove('show');

  if(pubRes.ok){
    // Show success banner
    var banner  = document.getElementById('success-banner');
    var sub     = document.getElementById('success-sub');
    var link    = document.getElementById('success-link');
    banner.classList.add('show');
    sub.textContent = lessonIds.length + ' item'+(lessonIds.length>1?'s':'')+' published. Students have been notified.';
    if(pubRes.url) link.href = pubRes.url;
    btn.innerHTML = '<i class="fas fa-check-circle"></i> Published!';
    // Scroll to button
    btn.scrollIntoView({ behavior:'smooth', block:'center' });
  } else {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-globe"></i> Publish All';
    alert('Publishing failed: '+(pubRes.msg||'Unknown error'));
  }
}

// ── Upload a single item (returns {ok, lessonId, msg}) ───────────────────────
function uploadItem(item, courseId){
  return new Promise(function(resolve){
    var fd = new FormData();

    if(item.type === 'youtube'){
      fd.append('ajax_youtube', '1');
      fd.append('course_id', courseId);
      fd.append('title', item.title);
      fd.append('yt_url', item.url);
      fd.append('desc', '');
    } else {
      fd.append('ajax_upload', '1');
      fd.append('course_id', courseId);
      fd.append('type', item.type);
      fd.append('title', item.title);
      fd.append('desc', '');
      fd.append('file', item.file, item.file.name);
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'upload.php');

    // Per-file progress bar
    var fillEl = document.getElementById('qf-'+item.id);
    var statEl = document.getElementById('qs-'+item.id);
    if(statEl) statEl.textContent = 'Uploading…';

    xhr.upload.onprogress = function(ev){
      if(ev.lengthComputable && fillEl){
        fillEl.style.width = Math.round(ev.loaded/ev.total*100)+'%';
      }
    };
    xhr.onload = function(){
      var res;
      try{ res=JSON.parse(xhr.responseText); } catch(e){ res={ok:false,msg:'Server error'}; }
      resolve({ ok: res.ok, lessonId: res.lesson_id, msg: res.msg });
    };
    xhr.onerror = function(){ resolve({ ok:false, msg:'Network error' }); };
    xhr.send(fd);
  });
}
</script>

<!-- ══ FILE PREVIEW MODAL ══ -->
<div class="modal-wrap" id="m-preview" style="z-index:9999">
  <div class="modal-box" style="max-width:900px;width:95vw;height:90vh;display:flex;flex-direction:column;padding:0">
    <div class="modal-head" style="padding:12px 18px;flex-shrink:0">
      <h3 id="preview-title" style="font-size:14px"><i class="fas fa-eye" style="color:var(--gold)"></i> Preview</h3>
      <div style="display:flex;gap:8px;align-items:center">
        <a id="preview-dl" href="#" download class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Download</a>
        <button class="modal-close" onclick="closeModal('m-preview')"><i class="fas fa-times"></i></button>
      </div>
    </div>
    <div id="preview-body" style="flex:1;overflow:hidden;background:#1a1a2e;position:relative">
      <!-- Content injected by openPreview() -->
    </div>
  </div>
</div>

<script>
function openPreview(url, ext, title) {
    document.getElementById('preview-title').innerHTML = '<i class="fas fa-eye" style="color:var(--gold)"></i> ' + (title || 'Preview');
    document.getElementById('preview-dl').href = url;

    var body = document.getElementById('preview-body');
    body.innerHTML = ''; // clear previous

    if (ext === 'pdf') {
        // PDF — embed directly in iframe (works on localhost perfectly)
        var iframe = document.createElement('iframe');
        iframe.src  = url + '#toolbar=1&navpanes=1&scrollbar=1';
        iframe.style.cssText = 'width:100%;height:100%;border:none;display:block';
        body.appendChild(iframe);

    } else if (ext === 'docx' || ext === 'pptx') {
        // DOCX/PPTX — show a clear offline message with download option
        // (Office Online / Google Docs viewer can't access localhost)
        var icon  = ext === 'pptx' ? 'fa-file-powerpoint' : 'fa-file-word';
        var color = ext === 'pptx' ? '#d04423' : '#2b5797';
        var app   = ext === 'pptx' ? 'PowerPoint' : 'Word';
        body.innerHTML =
            '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:20px;padding:40px;text-align:center">' +
              '<i class="fas ' + icon + '" style="font-size:72px;color:' + color + ';opacity:.85"></i>' +
              '<div>' +
                '<div style="font-size:17px;font-weight:700;color:#fff;margin-bottom:10px">' + (title || ext.toUpperCase() + ' File') + '</div>' +
                '<div style="font-size:13px;color:rgba(255,255,255,.5);line-height:1.8;max-width:400px">' +
                  ext.toUpperCase() + ' files cannot be previewed on a local server.<br>' +
                  'Download the file to open it in Microsoft ' + app + '.<br>' +
                  '<strong style="color:rgba(255,255,255,.7)">Tip:</strong> Upload as PDF for instant in-browser preview.' +
                '</div>' +
              '</div>' +
              '<a href="' + url + '" download class="btn btn-primary" style="font-size:14px;padding:12px 32px">' +
                '<i class="fas fa-download"></i> Download ' + ext.toUpperCase() +
              '</a>' +
            '</div>';
    } else {
        body.innerHTML = '<div style="padding:40px;text-align:center;color:rgba(255,255,255,.5)">Cannot preview this file type.</div>';
    }

    openModal('m-preview');
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
