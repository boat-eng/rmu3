<?php
require_once '../includes/config.php';
requireRole('instructor');
require_once '../includes/mailer.php';

$uid     = (int)$_SESSION['user_id'];
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_post'])) {
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body']  ?? '');
    $type  = $_POST['type'] ?? 'info';
    if (!in_array($type, ['info','warning','success','danger'])) $type = 'info';
    if (!$title) {
        $error = 'Title is required.';
    } else {
        $pdo->prepare('UPDATE instructor_announcements SET is_active=0 WHERE instructor_id=?')->execute([$uid]);
        $pdo->prepare('INSERT INTO instructor_announcements (instructor_id,title,body,type,is_active) VALUES (?,?,?,?,1)')
            ->execute([$uid, $title, $body, $type]);

        // Fetch instructor name
        $instrRow = $pdo->prepare('SELECT full_name FROM users WHERE id=? LIMIT 1');
        $instrRow->execute([$uid]);
        $instrName = $instrRow->fetchColumn() ?: 'Your instructor';

        // Email only students enrolled in THIS instructor's courses
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.full_name, s.email
            FROM enrollments e
            JOIN courses c ON c.id = e.course_id
            JOIN students s ON s.id = e.student_id
            WHERE c.instructor_id = ?
              AND s.email IS NOT NULL AND s.email != ''
        ");
        $stmt->execute([$uid]);
        $enrolled = $stmt->fetchAll();

        $typeColors = ['info'=>'#64b5f6','warning'=>'#f5c842','success'=>'#6fcf97','danger'=>'#ff8a80'];
        $typeIcons  = ['info'=>'ℹ️','warning'=>'⚠️','success'=>'✅','danger'=>'🚨'];
        $accent     = $typeColors[$type] ?? '#c8a84b';
        $icon       = $typeIcons[$type]  ?? 'ℹ️';

        $tpl = buildEmailTemplate(
            $icon . ' ' . $title,
            '<p style="font-size:16px;color:rgba(255,255,255,.9);margin:0 0 10px">Hi {name},</p>
             <p style="font-size:14px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 0 20px">
               Your instructor <strong style="color:#c8a84b">' . htmlspecialchars($instrName) . '</strong> has posted a new announcement:
             </p>
             <div style="background:#0d2a4e;border:1px solid #1e3a5f;border-left:4px solid ' . $accent . ';border-radius:8px;padding:16px 20px;margin-bottom:24px">
               <div style="font-size:15px;font-weight:700;color:' . $accent . ';margin-bottom:' . ($body?'8px':'0') . '">' . htmlspecialchars($title) . '</div>
               ' . ($body ? '<div style="font-size:13px;color:rgba(255,255,255,.7);line-height:1.7">' . nl2br(htmlspecialchars($body)) . '</div>' : '') . '
             </div>
             <a href="' . BASE_URL . '/student/dashboard.php"
                style="display:inline-block;background:#c8a84b;color:#001a3e;font-weight:700;font-size:14px;padding:12px 28px;border-radius:8px;text-decoration:none">
               Go to Dashboard
             </a>'
        );

        foreach ($enrolled as $s) {
            sendMail($s['email'], $s['full_name'], $icon . ' Notice from ' . $instrName . ': ' . $title,
                str_replace('{name}', htmlspecialchars($s['full_name']), $tpl));
        }

        $success = 'Announcement posted and emailed to ' . count($enrolled) . ' enrolled student(s).';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_dismiss'])) {
    $pdo->prepare('UPDATE instructor_announcements SET is_active=0 WHERE instructor_id=?')->execute([$uid]);
    $success = 'Announcement dismissed.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_delete'])) {
    $pdo->prepare('DELETE FROM instructor_announcements WHERE id=? AND instructor_id=?')
        ->execute([(int)$_POST['ann_id'], $uid]);
    $success = 'Announcement deleted.';
}

$nStudents = (int)$pdo->prepare('SELECT COUNT(DISTINCT e.student_id) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE c.instructor_id=?')
    ->execute([$uid]) ? $pdo->prepare('SELECT COUNT(DISTINCT e.student_id) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE c.instructor_id=?')->execute([$uid]) : 0;
$nStmt = $pdo->prepare('SELECT COUNT(DISTINCT e.student_id) FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE c.instructor_id=?');
$nStmt->execute([$uid]);
$nStudents = (int)$nStmt->fetchColumn();

$all = $pdo->prepare('SELECT * FROM instructor_announcements WHERE instructor_id=? ORDER BY created_at DESC');
$all->execute([$uid]);
$all = $all->fetchAll();

$pageTitle    = 'Announcements';
$pageSubtitle = 'Post notices to your enrolled students';
$activePage   = 'announcements';
$depth        = 1;
ob_start();
?>
<?php if($success): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> <?= h($success) ?></div><?php endif; ?>
<?php if($error):   ?><div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> <?= h($error) ?></div><?php endif; ?>

<div class="alert" style="background:rgba(200,168,75,.08);border:1px solid rgba(200,168,75,.25);color:var(--gold);margin-bottom:20px">
    <i class="fas fa-users"></i>
    Your announcement will be emailed to <strong><?= $nStudents ?> student<?= $nStudents!==1?'s':'' ?></strong> enrolled in your courses.
</div>

<div class="g2" style="align-items:start">
    <div class="card">
        <div class="card-hd"><span class="card-title"><i class="fas fa-bullhorn" style="color:var(--gold)"></i> Post Announcement</span></div>
        <form method="POST">
            <div class="fg"><label class="lbl2">Title *</label>
                <input class="fc" type="text" name="title" placeholder="e.g. Assignment due this Friday" required></div>
            <div class="fg"><label class="lbl2">Message</label>
                <textarea class="fc" name="body" rows="4" placeholder="Write your message to students..."></textarea></div>
            <div class="fg"><label class="lbl2">Type</label>
                <select class="fc" name="type">
                    <option value="info">ℹ️ Info (blue)</option>
                    <option value="warning">⚠️ Warning (yellow)</option>
                    <option value="success">✅ Success (green)</option>
                    <option value="danger">🚨 Urgent (red)</option>
                </select></div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="btn btn-primary" type="submit" name="do_post"><i class="fas fa-bullhorn"></i> Post &amp; Email Students</button>
                <button class="btn btn-secondary" type="submit" name="do_dismiss"
                        style="color:#ff8a80;border-color:rgba(231,76,60,.3)"
                        onclick="return confirm('Dismiss the current active announcement?')">
                    <i class="fas fa-times"></i> Dismiss Active
                </button>
            </div>
        </form>
    </div>

    <div>
        <div class="card mb2">
            <div class="card-hd"><span class="card-title"><i class="fas fa-eye" style="color:var(--gold)"></i> Live Preview</span></div>
            <div id="preview-bar" class="ann-preview type-info">
                <i class="fas fa-info-circle" id="preview-icon"></i>
                <div>
                    <strong id="preview-title">Your announcement title</strong>
                    <div id="preview-body" style="font-size:12px;margin-top:2px;opacity:.8"></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-hd"><span class="card-title"><i class="fas fa-history" style="color:var(--gold)"></i> History</span></div>
            <?php if(empty($all)): ?>
            <p style="color:var(--muted);font-size:13px;text-align:center;padding:20px 0">
                <i class="fas fa-bullhorn" style="display:block;font-size:28px;margin-bottom:8px;opacity:.3"></i>No announcements yet.
            </p>
            <?php else: ?>
            <div class="tbl-wrap"><table>
                <thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach($all as $a): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;color:var(--white)"><?= h($a['title']) ?></div>
                        <?php if($a['body']): ?><div style="font-size:11px;color:var(--muted)"><?= h(mb_substr($a['body'],0,60)) ?><?= mb_strlen($a['body'])>60?'…':'' ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge <?= $a['type']==='warning'?'bg-gold':($a['type']==='danger'?'bg-red':($a['type']==='success'?'bg-green':'bg-blue')) ?>"><?= ucfirst($a['type']) ?></span></td>
                    <td><span class="badge <?= $a['is_active']?'bg-green':'bg-muted' ?>"><?= $a['is_active']?'Active':'Inactive' ?></span></td>
                    <td style="font-size:12px;color:var(--muted)"><?= date('M j, Y', strtotime($a['created_at'])) ?></td>
                    <td>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this announcement?')">
                            <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                            <button class="btn btn-secondary btn-sm" name="do_delete" style="color:#ff8a80;border-color:rgba(231,76,60,.3)"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.ann-preview{padding:12px 16px;border-radius:8px;font-size:13px;display:flex;align-items:flex-start;gap:10px;margin-top:4px}
.ann-preview.type-info   {background:rgba(41,128,185,.12);color:#64b5f6;border:1px solid rgba(41,128,185,.25)}
.ann-preview.type-warning{background:rgba(243,156,18,.12);color:#f5c842;border:1px solid rgba(243,156,18,.25)}
.ann-preview.type-success{background:rgba(39,174,96,.12);color:#6fcf97;border:1px solid rgba(39,174,96,.25)}
.ann-preview.type-danger {background:rgba(231,76,60,.12);color:#ff8a80;border:1px solid rgba(231,76,60,.25)}
</style>
<script>
var iconMap={info:'fa-info-circle',warning:'fa-exclamation-triangle',success:'fa-check-circle',danger:'fa-exclamation-circle'};
function updatePreview(){
    var title=document.querySelector('[name="title"]').value||'Your announcement title';
    var body=document.querySelector('[name="body"]').value;
    var type=document.querySelector('[name="type"]').value;
    document.getElementById('preview-bar').className='ann-preview type-'+type;
    document.getElementById('preview-icon').className='fas '+(iconMap[type]||'fa-info-circle');
    document.getElementById('preview-title').textContent=title;
    document.getElementById('preview-body').textContent=body;
}
document.querySelector('[name="title"]').addEventListener('input',updatePreview);
document.querySelector('[name="body"]').addEventListener('input',updatePreview);
document.querySelector('[name="type"]').addEventListener('change',updatePreview);
</script>
<?php $pageContent=ob_get_clean(); require_once '../includes/layout.php';
