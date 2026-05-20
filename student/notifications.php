<?php
require_once '../includes/config.php';
requireRole('student');

$uid = (int)$_SESSION['user_id'];

// Mark all as read
if (isset($_GET['mark_all'])) {
    try {
        $pdo->prepare('UPDATE notifications SET is_read=1 WHERE student_id=?')->execute([$uid]);
    } catch(Exception $e) {}
    header('Location: notifications.php'); exit;
}

// Mark one as read
if (isset($_GET['read']) && is_numeric($_GET['read'])) {
    try {
        $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND student_id=?')->execute([(int)$_GET['read'], $uid]);
    } catch(Exception $e) {}
    $link = $_GET['link'] ?? '';
    if ($link) { header('Location: ' . $link); exit; }
    header('Location: notifications.php'); exit;
}

// Load all notifications
$notifs = [];
try {
    $ns = $pdo->prepare('SELECT * FROM notifications WHERE student_id=? ORDER BY created_at DESC LIMIT 50');
    $ns->execute([$uid]);
    $notifs = $ns->fetchAll();
} catch(Exception $e) {}

$unread = count(array_filter($notifs, fn($n) => !$n['is_read']));

$pageTitle    = 'Notifications';
$pageSubtitle = $unread > 0 ? $unread . ' unread' : 'All caught up';
$activePage   = 'dashboard';
$depth        = 1;
ob_start();

function notifIcon(string $type): array {
    return match($type) {
        'new_lesson'      => ['fa-film',        '#64b5f6', 'rgba(41,128,185,.12)'],
        'course_complete' => ['fa-trophy',       '#f0cc6a', 'rgba(200,168,75,.12)'],
        'quiz_graded'     => ['fa-check-circle', '#4caf82', 'rgba(39,174,96,.12)'],
        'quiz_failed'     => ['fa-times-circle', '#ff8a80', 'rgba(231,76,60,.12)'],
        default           => ['fa-bell',         '#ce93d8', 'rgba(142,68,173,.12)'],
    };
}
?>

<style>
.notif-item { display:flex; align-items:flex-start; gap:14px; padding:14px 18px; border-bottom:1px solid var(--border); transition:background .15s; cursor:pointer; }
.notif-item:last-child { border-bottom:none; }
.notif-item:hover { background:rgba(255,255,255,.03); }
.notif-item.unread { background:rgba(200,168,75,.04); border-left:3px solid var(--gold); }
.notif-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
.notif-body { flex:1; min-width:0; }
.notif-msg  { font-size:13px; color:var(--white); line-height:1.5; margin-bottom:4px; }
.notif-time { font-size:11px; color:var(--muted); }
.notif-dot  { width:8px; height:8px; background:var(--gold); border-radius:50%; flex-shrink:0; margin-top:6px; }
</style>

<div class="flex-between mb2">
    <span class="card-title"><i class="fas fa-bell" style="color:var(--gold)"></i> All Notifications</span>
    <?php if($unread > 0): ?>
    <a href="notifications.php?mark_all=1" class="btn btn-secondary btn-sm">
        <i class="fas fa-check-double"></i> Mark All Read
    </a>
    <?php endif; ?>
</div>

<?php if(empty($notifs)): ?>
<div class="card" style="text-align:center;padding:60px;color:var(--muted)">
    <i class="fas fa-bell-slash" style="font-size:44px;display:block;margin-bottom:14px;opacity:.3"></i>
    <p>No notifications yet.</p>
</div>
<?php else: ?>
<div class="card" style="padding:0;overflow:hidden">
    <?php foreach($notifs as $n):
        [$icon, $color, $bg] = notifIcon($n['type']);
        $link = $n['link'] ?? '';
        $timeAgo = '';
        $diff = time() - strtotime($n['created_at']);
        if ($diff < 60) $timeAgo = 'Just now';
        elseif ($diff < 3600) $timeAgo = floor($diff/60) . ' min ago';
        elseif ($diff < 86400) $timeAgo = floor($diff/3600) . ' hr ago';
        else $timeAgo = date('M j, Y', strtotime($n['created_at']));
    ?>
    <div class="notif-item <?= !$n['is_read']?'unread':'' ?>"
         onclick="window.location='notifications.php?read=<?= $n['id'] ?><?= $link?'&link='.urlencode($link):'' ?>'">
        <div class="notif-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
            <i class="fas <?= $icon ?>"></i>
        </div>
        <div class="notif-body">
            <div class="notif-msg"><?= h($n['message']) ?></div>
            <div class="notif-time"><i class="fas fa-clock" style="font-size:10px"></i> <?= $timeAgo ?></div>
        </div>
        <?php if(!$n['is_read']): ?>
        <div class="notif-dot"></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
