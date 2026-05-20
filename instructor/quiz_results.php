<?php
require_once '../includes/config.php';
requireRole('instructor');

$uid = (int)$_SESSION['user_id'];
$qid = (int)($_GET['id'] ?? 0);

// Load quiz (must belong to this instructor)
$quiz = $pdo->prepare('
    SELECT q.*, c.title AS course_title, c.id AS course_id
    FROM quizzes q
    JOIN courses c ON c.id = q.course_id
    WHERE q.id = ? AND q.created_by = ?
    LIMIT 1
');
$quiz->execute([$qid, $uid]);
$quiz = $quiz->fetch();
if (!$quiz) { header('Location: courses.php'); exit; }

$cid = $quiz['course_id'];

// ── AJAX: save short answer marks ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_mark'])) {
    header('Content-Type: application/json');

    $answerId  = (int)$_POST['answer_id'];
    $attemptId = (int)$_POST['attempt_id'];
    $correct   = (int)$_POST['is_correct'];   // 1 = correct, 0 = wrong
    $maxMarks  = (float)$_POST['max_marks'];
    $marksGiven = $correct ? $maxMarks : 0;

    // Verify this answer belongs to an attempt on this instructor's quiz
    $chk = $pdo->prepare('
        SELECT qa.id FROM quiz_answers qa
        JOIN quiz_attempts qat ON qat.id = qa.attempt_id
        JOIN quizzes qz ON qz.id = qat.quiz_id
        WHERE qa.id = ? AND qat.id = ? AND qz.created_by = ?
        LIMIT 1
    ');
    $chk->execute([$answerId, $attemptId, $uid]);
    if (!$chk->fetch()) {
        echo json_encode(['ok' => false, 'msg' => 'Unauthorized']); exit;
    }

    // Update this answer
    $pdo->prepare('UPDATE quiz_answers SET is_correct=?, marks_given=? WHERE id=?')
        ->execute([$correct, $marksGiven, $answerId]);

    // Recalculate total score for this attempt
    $totQ = $pdo->prepare('
        SELECT SUM(qq.marks) AS total_marks,
               SUM(COALESCE(qa.marks_given, 0)) AS earned_marks,
               SUM(CASE WHEN qa.is_correct IS NULL THEN 1 ELSE 0 END) AS still_pending
        FROM quiz_answers qa
        JOIN quiz_questions qq ON qq.id = qa.question_id
        WHERE qa.attempt_id = ?
    ');
    $totQ->execute([$attemptId]);
    $totRow = $totQ->fetch();

    $totalMarks   = (float)$totRow['total_marks'];
    $earnedMarks  = (float)$totRow['earned_marks'];
    $stillPending = (int)$totRow['still_pending'];

    // Only finalise pass/fail when ALL short answers are marked
    if ($stillPending === 0) {
        $pct    = $totalMarks > 0 ? round(($earnedMarks / $totalMarks) * 100, 2) : 0;
        $passed = $pct >= $quiz['pass_mark'] ? 1 : 0;
        // Store earned marks in score column (not percentage)
        $pdo->prepare('UPDATE quiz_attempts SET score=?, total_marks=?, passed=? WHERE id=?')
            ->execute([$earnedMarks, $totalMarks, $passed, $attemptId]);

        // Notify student their result is ready
        try {
            $stuQ = $pdo->prepare('SELECT student_id FROM quiz_attempts WHERE id=? LIMIT 1');
            $stuQ->execute([$attemptId]);
            $stuId = (int)$stuQ->fetchColumn();
            if ($stuId) {
                $pdo->prepare('INSERT IGNORE INTO notifications (user_id, type, message, link) VALUES (?,?,?,?)')
                    ->execute([
                        $stuId, 'quiz_marked',
                        'Your quiz "' . $quiz['title'] . '" has been marked. Check your result!',
                        '../student/quiz_result.php?attempt=' . $attemptId
                    ]);
            }
        } catch(Exception $e) {}

        echo json_encode(['ok'=>true,'finalised'=>true,'score'=>$pct,'passed'=>$passed,'stillPending'=>0]);
    } else {
        echo json_encode(['ok'=>true,'finalised'=>false,'stillPending'=>$stillPending]);
    }
    exit;
}

// ── Load all attempts ──
$attempts = $pdo->prepare('
    SELECT qa.*, s.full_name AS student_name, s.student_id AS student_sid, s.email AS student_email,
           (SELECT COUNT(*) FROM quiz_answers qan
            JOIN quiz_questions qq ON qq.id = qan.question_id
            WHERE qan.attempt_id = qa.id AND qq.type = "short" AND qan.is_correct IS NULL) AS pending_short
    FROM quiz_attempts qa
    JOIN students s ON s.id = qa.student_id
    WHERE qa.quiz_id = ?
    ORDER BY qa.submitted_at DESC
');
$attempts->execute([$qid]);
$attempts = $attempts->fetchAll();

// Current attempt to mark (if ?attempt= is set)
$markAttemptId = (int)($_GET['attempt'] ?? 0);
$markAttempt   = null;
$markAnswers   = [];

if ($markAttemptId) {
    // Verify it's for this quiz
    foreach ($attempts as $a) {
        if ($a['id'] === $markAttemptId) { $markAttempt = $a; break; }
    }
    if ($markAttempt) {
        $ansQ = $pdo->prepare('
            SELECT qa.*, qq.question, qq.type, qq.marks, qq.sort_order
            FROM quiz_answers qa
            JOIN quiz_questions qq ON qq.id = qa.question_id
            WHERE qa.attempt_id = ?
            ORDER BY qq.sort_order
        ');
        $ansQ->execute([$markAttemptId]);
        $markAnswers = $ansQ->fetchAll();
    }
}

// Stats
$totalAttempts  = count($attempts);
$passedCount    = count(array_filter($attempts, fn($a) => $a['passed'] == 1));
$pendingCount   = count(array_filter($attempts, fn($a) => $a['pending_short'] > 0));
$avgScore       = $totalAttempts > 0 ? round(array_sum(array_column($attempts,'score')) / $totalAttempts, 1) : 0;

$pageTitle    = 'Quiz Results — ' . h($quiz['title']);
$pageSubtitle = h($quiz['course_title']);
$activePage   = 'courses';
$depth        = 1;
ob_start();
?>

<style>
.mark-ans-card {
    background:var(--surf2); border:1px solid var(--border); border-radius:10px;
    padding:18px; margin-bottom:14px;
}
.mark-ans-card.is-short  { border-left:4px solid var(--gold); }
.mark-ans-card.is-correct-done { border-left:4px solid #27ae60; }
.mark-ans-card.is-wrong-done   { border-left:4px solid #e74c3c; }
.mark-btn { padding:8px 20px; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; border:2px solid transparent; transition:all .15s; }
.mark-btn.correct { background:rgba(39,174,96,.12); border-color:rgba(39,174,96,.4); color:#4caf82; }
.mark-btn.correct:hover, .mark-btn.correct.active { background:rgba(39,174,96,.25); border-color:#4caf82; }
.mark-btn.wrong   { background:rgba(231,76,60,.12); border-color:rgba(231,76,60,.35); color:#ff8a80; }
.mark-btn.wrong:hover, .mark-btn.wrong.active   { background:rgba(231,76,60,.25); border-color:#ff8a80; }
.attempt-row { background:var(--surf2); border:1px solid var(--border); border-radius:10px; padding:14px 18px; margin-bottom:10px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.attempt-row.has-pending { border-color:rgba(200,168,75,.4); }
</style>

<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px">
    <a href="quiz_create.php?course=<?= $cid ?>" class="btn btn-secondary btn-sm">
        <i class="fas fa-arrow-left"></i> Back to Quizzes
    </a>
    <?php if($markAttempt): ?>
    <a href="quiz_results.php?id=<?= $qid ?>" class="btn btn-secondary btn-sm">
        <i class="fas fa-list"></i> All Attempts
    </a>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="stat-grid" style="margin-bottom:22px">
    <div class="stat-card" style="--sc:var(--gold)">
        <div class="stat-ico" style="background:rgba(200,168,75,.15);color:var(--gold)"><i class="fas fa-users"></i></div>
        <div><div class="stat-val"><?= $totalAttempts ?></div><div class="stat-lbl">Total Attempts</div></div>
    </div>
    <div class="stat-card" style="--sc:#27ae60">
        <div class="stat-ico" style="background:rgba(39,174,96,.15);color:#4caf82"><i class="fas fa-check-circle"></i></div>
        <div><div class="stat-val"><?= $passedCount ?></div><div class="stat-lbl">Passed</div></div>
    </div>
    <div class="stat-card" style="--sc:#c8a84b">
        <div class="stat-ico" style="background:rgba(200,168,75,.12);color:var(--gold)"><i class="fas fa-hourglass-half"></i></div>
        <div><div class="stat-val"><?= $pendingCount ?></div><div class="stat-lbl">Awaiting Review</div></div>
    </div>
    <div class="stat-card" style="--sc:#2980b9">
        <div class="stat-ico" style="background:rgba(41,128,185,.15);color:#64b5f6"><i class="fas fa-percentage"></i></div>
        <div><div class="stat-val"><?= $avgScore ?>%</div><div class="stat-lbl">Avg Score</div></div>
    </div>
</div>

<?php if($markAttempt): ?>
<!-- ══ MARKING INTERFACE ══ -->
<div class="card">
    <div class="card-hd" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
        <span class="card-title">
            <i class="fas fa-pen" style="color:var(--gold)"></i>
            Marking: <?= h($markAttempt['student_name']) ?>
            <?php if($markAttempt['student_sid']): ?>
            <span style="font-size:11px;color:var(--muted);font-family:monospace">(<?= h($markAttempt['student_sid']) ?>)</span>
            <?php endif; ?>
        </span>
        <?php if($markAttempt['pending_short'] > 0): ?>
        <span class="badge bg-gold"><i class="fas fa-hourglass-half"></i> <?= $markAttempt['pending_short'] ?> answer<?= $markAttempt['pending_short']>1?'s':'' ?> to mark</span>
        <?php else: ?>
        <span class="badge bg-green"><i class="fas fa-check-circle"></i> All marked — <?= (int)$markAttempt['score'] ?> / <?= $markAttempt['total_marks'] ?> marks</span>
        <?php endif; ?>
    </div>

    <div id="mark-msg" style="display:none;margin-bottom:14px;padding:10px 14px;border-radius:8px;font-size:13px"></div>

    <?php foreach($markAnswers as $i => $a): ?>
    <?php
    $isShort    = $a['type'] === 'short';
    $isDone     = $isShort && $a['is_correct'] !== null;
    $cardExtra  = $isShort ? ($isDone ? ($a['is_correct'] ? 'is-correct-done' : 'is-wrong-done') : 'is-short') : '';
    ?>
    <div class="mark-ans-card <?= $cardExtra ?>" id="ans-card-<?= $a['id'] ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px">
            <div style="font-size:11px;color:var(--muted)">
                Q<?= $i+1 ?> &nbsp;·&nbsp;
                <?php if($a['type']==='mcq'): ?>
                    <span style="color:#64b5f6">Multiple Choice</span>
                <?php elseif($a['type']==='truefalse'): ?>
                    <span style="color:#4caf82">True/False</span>
                <?php else: ?>
                    <span style="color:var(--gold)">Short Answer</span>
                <?php endif; ?>
                &nbsp;·&nbsp; <?= $a['marks'] ?> mark<?= $a['marks']!=1?'s':'' ?>
                <?php if($isShort && !$isDone): ?>
                <span class="badge bg-gold" style="font-size:9px">Needs Marking</span>
                <?php elseif($isShort && $isDone): ?>
                <span id="badge-<?= $a['id'] ?>" class="badge <?= $a['is_correct']?'bg-green':'bg-red' ?>" style="font-size:9px">
                    <?= $a['is_correct'] ? 'Correct — ' . $a['marks_given'] . ' mark(s)' : 'Incorrect — 0 marks' ?>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <div style="font-size:14px;color:var(--white);font-weight:600;margin-bottom:10px;line-height:1.5">
            <?= h($a['question']) ?>
        </div>

        <!-- Student's answer -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px;font-size:13px;color:var(--text);line-height:1.6;margin-bottom:<?= $isShort && !$isDone ? '14px' : '0' ?>">
            <?= $a['type']==='short'
                ? nl2br(h($a['answer_text'] ?? '(no answer given)'))
                : h($a['answer_text'] ?? '—') ?>
        </div>

        <?php if($isShort && !$isDone): ?>
        <!-- Mark buttons — only shown for ungraded short answers -->
        <div style="display:flex;gap:10px;align-items:center;margin-top:14px">
            <span style="font-size:12px;color:var(--muted)">Mark as:</span>
            <button class="mark-btn correct" onclick="markAnswer(<?= $a['id'] ?>, <?= $markAttemptId ?>, 1, <?= $a['marks'] ?>)">
                <i class="fas fa-check"></i> Correct (<?= $a['marks'] ?> mark<?= $a['marks']!=1?'s':'' ?>)
            </button>
            <button class="mark-btn wrong" onclick="markAnswer(<?= $a['id'] ?>, <?= $markAttemptId ?>, 0, <?= $a['marks'] ?>)">
                <i class="fas fa-times"></i> Incorrect (0 marks)
            </button>
        </div>
        <?php elseif($isShort && $isDone): ?>
        <!-- Already marked — show undo option -->
        <div style="display:flex;gap:10px;align-items:center;margin-top:12px">
            <span id="mark-status-<?= $a['id'] ?>" style="font-size:12px;color:<?= $a['is_correct']?'#4caf82':'#ff8a80' ?>">
                <i class="fas fa-<?= $a['is_correct']?'check':'times' ?>-circle"></i>
                <?= $a['is_correct'] ? 'Marked correct' : 'Marked incorrect' ?>
            </span>
            <button class="mark-btn <?= $a['is_correct']?'wrong':'correct' ?>" style="padding:5px 14px;font-size:12px"
                    onclick="markAnswer(<?= $a['id'] ?>, <?= $markAttemptId ?>, <?= $a['is_correct']?0:1 ?>, <?= $a['marks'] ?>)">
                <i class="fas fa-undo"></i> Change to <?= $a['is_correct']?'Incorrect':'Correct' ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div style="padding-top:4px;text-align:right">
        <a href="quiz_results.php?id=<?= $qid ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to All Attempts
        </a>
    </div>
</div>

<?php else: ?>
<!-- ══ ATTEMPTS LIST ══ -->
<?php if(empty($attempts)): ?>
<div class="card" style="text-align:center;padding:50px;color:var(--muted)">
    <i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:12px;opacity:.4"></i>
    No students have attempted this quiz yet.
</div>
<?php else: ?>
<div class="card">
    <div class="card-hd">
        <span class="card-title"><i class="fas fa-list-check" style="color:var(--gold)"></i> All Attempts</span>
        <?php if($pendingCount > 0): ?>
        <span class="badge bg-gold"><i class="fas fa-exclamation-circle"></i> <?= $pendingCount ?> need<?= $pendingCount===1?'s':'' ?> marking</span>
        <?php endif; ?>
    </div>

    <?php foreach($attempts as $att): ?>
    <div class="attempt-row <?= $att['pending_short']>0?'has-pending':'' ?>">
        <!-- Student info -->
        <div style="flex:1;min-width:160px">
            <div style="font-weight:700;color:var(--white);font-size:14px"><?= h($att['student_name']) ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px">
                <?php if($att['student_sid']): ?>
                <span style="font-family:monospace"><?= h($att['student_sid']) ?></span> &nbsp;·&nbsp;
                <?php endif; ?>
                <?= date('M d, Y H:i', strtotime($att['submitted_at'])) ?>
            </div>
        </div>

        <!-- Score / Status -->
        <?php if($att['pending_short'] > 0): ?>
        <span class="badge bg-gold">
            <i class="fas fa-hourglass-half"></i>
            <?= $att['pending_short'] ?> answer<?= $att['pending_short']>1?'s':'' ?> pending
        </span>
        <?php else: ?>
        <div style="text-align:center;min-width:80px">
            <div style="font-size:18px;font-weight:700;font-family:'Cinzel',serif;color:<?= $att['passed']?'#4caf82':'#ff8a80' ?>">
                <?= (int)$att['score'] ?><span style="font-size:13px;opacity:.7"> / <?= $att['total_marks'] ?></span>
            </div>
            <span class="badge <?= $att['passed']?'bg-green':'bg-red' ?>" style="font-size:10px">
                <?= $att['passed']?'Passed':'Failed' ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="flex gap">
            <?php if($att['pending_short'] > 0): ?>
            <a href="quiz_results.php?id=<?= $qid ?>&attempt=<?= $att['id'] ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-pen"></i> Mark Now
            </a>
            <?php else: ?>
            <a href="quiz_results.php?id=<?= $qid ?>&attempt=<?= $att['id'] ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-eye"></i> Review
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
function markAnswer(answerId, attemptId, isCorrect, maxMarks) {
    var card = document.getElementById('ans-card-' + answerId);
    if (card) { card.style.opacity = '0.6'; card.style.pointerEvents = 'none'; }

    fetch('quiz_results.php?id=<?= $qid ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'do_mark=1&answer_id=' + answerId +
              '&attempt_id=' + attemptId +
              '&is_correct=' + isCorrect +
              '&max_marks=' + maxMarks
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            // Reload the page to reflect updated state
            location.reload();
        } else {
            if (card) { card.style.opacity = '1'; card.style.pointerEvents = ''; }
            alert('Error saving mark. Please try again.');
        }
    })
    .catch(() => {
        if (card) { card.style.opacity = '1'; card.style.pointerEvents = ''; }
        alert('Network error. Please try again.');
    });
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
