<?php
require_once '../includes/config.php';
requireRole('student');

$uid = (int)$_SESSION['user_id'];
$qid = (int)($_GET['id'] ?? 0);

// Load quiz
$quiz = $pdo->prepare('SELECT q.*, c.title AS course_title, c.id AS course_id FROM quizzes q JOIN courses c ON c.id=q.course_id WHERE q.id=? AND q.is_published=1 LIMIT 1');
$quiz->execute([$qid]);
$quiz = $quiz->fetch();
if (!$quiz) { header('Location: enrolled.php'); exit; }

$cid = $quiz['course_id'];

// Verify enrollment
$enr = $pdo->prepare('SELECT 1 FROM enrollments WHERE course_id=? AND student_id=? LIMIT 1');
$enr->execute([$cid, $uid]);
if (!$enr->fetch()) { header('Location: browse.php'); exit; }

// Check previous attempt
$prevAttempt = null;
$attemptQ = $pdo->prepare('SELECT * FROM quiz_attempts WHERE quiz_id=? AND student_id=? ORDER BY submitted_at DESC LIMIT 1');
$attemptQ->execute([$qid, $uid]);
$prevAttempt = $attemptQ->fetch();

// Block if already attempted and no retakes
if ($prevAttempt && !$quiz['allow_retake']) {
    header('Location: quiz_result.php?attempt=' . $prevAttempt['id']); exit;
}

// ── Submit Quiz ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_submit'])) {
    // Load questions
    $questions = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order');
    $questions->execute([$qid]);
    $questions = $questions->fetchAll();

    // Create attempt
    $pdo->prepare('INSERT INTO quiz_attempts (quiz_id, student_id, score, total_marks, passed) VALUES (?,?,0,0,0)')
        ->execute([$qid, $uid]);
    $attemptId = (int)$pdo->lastInsertId();

    $totalMarks  = 0;
    $earnedMarks = 0;

    foreach ($questions as $q) {
        $totalMarks += $q['marks'];
        $ans    = trim($_POST['q_' . $q['id']] ?? '');
        $correct = null;
        $marksGiven = 0;

        if ($q['type'] === 'short') {
            $correct = null; // pending manual review
        } elseif ($q['type'] === 'mcq' || $q['type'] === 'truefalse') {
            // Check if selected option is correct
            $optCheck = $pdo->prepare('SELECT is_correct FROM quiz_options WHERE id=? AND question_id=? LIMIT 1');
            $optCheck->execute([(int)$ans, $q['id']]);
            $optRow = $optCheck->fetch();
            if ($optRow) {
                $correct    = $optRow['is_correct'] ? 1 : 0;
                $marksGiven = $correct ? $q['marks'] : 0;
                $earnedMarks += $marksGiven;
            }
        }

        $pdo->prepare('INSERT INTO quiz_answers (attempt_id, question_id, answer_text, is_correct, marks_given) VALUES (?,?,?,?,?)')
            ->execute([$attemptId, $q['id'], $ans, $correct, $marksGiven]);
    }

    // Store earned marks (not percentage) — score = earned, total_marks = max
    // Pass/fail: earned >= pass_mark% of total
    $pct    = $totalMarks > 0 ? round(($earnedMarks / $totalMarks) * 100, 2) : 0;
    $passed = $pct >= $quiz['pass_mark'] ? 1 : 0;

    $pdo->prepare('UPDATE quiz_attempts SET score=?, total_marks=?, passed=? WHERE id=?')
        ->execute([$earnedMarks, $totalMarks, $passed, $attemptId]);

    header('Location: quiz_result.php?attempt=' . $attemptId); exit;
}

// Load questions + options
$questions = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order');
$questions->execute([$qid]);
$questions = $questions->fetchAll();

$optMap = [];
if ($questions) {
    $qids = implode(',', array_column($questions, 'id'));
    $opts = $pdo->query("SELECT * FROM quiz_options WHERE question_id IN ($qids) ORDER BY sort_order")->fetchAll();
    foreach ($opts as $o) { $optMap[$o['question_id']][] = $o; }
}

$pageTitle    = h($quiz['title']);
$pageSubtitle = 'Quiz — ' . h($quiz['course_title']);
$activePage   = 'enrolled';
$depth        = 1;
ob_start();
?>

<style>
.quiz-wrap { max-width: 760px; margin: 0 auto; }
.quiz-header { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:20px 24px; margin-bottom:20px; }
.quiz-q { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:20px 24px; margin-bottom:16px; }
.quiz-q-num { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:6px; }
.quiz-q-text { font-size:15px; color:var(--white); font-weight:600; margin-bottom:14px; line-height:1.5; }
.opt-label {
    display:flex; align-items:center; gap:12px; padding:10px 14px;
    border:1px solid var(--border); border-radius:8px; cursor:pointer;
    font-size:13px; color:var(--text); transition:all .15s; margin-bottom:8px;
}
.opt-label:hover { border-color:var(--gold); background:rgba(200,168,75,.05); }
.opt-label input { accent-color:var(--gold); flex-shrink:0; }
.opt-label:has(input:checked) { border-color:var(--gold); background:rgba(200,168,75,.08); color:var(--white); }
.timer-bar { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:10px 16px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
</style>

<div class="quiz-wrap">

    <!-- Timer (if set) -->
    <?php if($quiz['time_limit']): ?>
    <div class="timer-bar">
        <i class="fas fa-clock" style="color:var(--gold)"></i>
        <span style="font-size:13px;color:var(--text)">Time remaining:</span>
        <span id="timer" style="font-size:16px;font-weight:700;color:var(--gold);font-family:'Cinzel',serif"></span>
    </div>
    <?php endif; ?>

    <!-- Quiz header -->
    <div class="quiz-header">
        <div style="font-family:'Cinzel',serif;font-size:18px;color:var(--white);margin-bottom:6px"><?= h($quiz['title']) ?></div>
        <?php if($quiz['description']): ?>
        <p style="font-size:13px;color:var(--muted);margin-bottom:10px"><?= h($quiz['description']) ?></p>
        <?php endif; ?>
        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--muted)">
            <span><i class="fas fa-question-circle" style="color:var(--gold)"></i> <?= count($questions) ?> questions</span>
            <span><i class="fas fa-percentage" style="color:var(--gold)"></i> Pass mark: <?= $quiz['pass_mark'] ?>%</span>
            <?php if($quiz['time_limit']): ?>
            <span><i class="fas fa-clock" style="color:var(--gold)"></i> <?= $quiz['time_limit'] ?> minutes</span>
            <?php endif; ?>
            <?php if($quiz['allow_retake']): ?>
            <span><i class="fas fa-redo" style="color:#64b5f6"></i> Retakes allowed</span>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" id="quiz-form">
        <?php foreach($questions as $i => $q): ?>
        <div class="quiz-q">
            <div class="quiz-q-num">
                Question <?= $i+1 ?> of <?= count($questions) ?>
                &nbsp;·&nbsp; <?= $q['marks'] ?> mark<?= $q['marks']!=1?'s':'' ?>
                &nbsp;·&nbsp;
                <span style="color:<?= $q['type']==='mcq'?'#64b5f6':($q['type']==='truefalse'?'#4caf82':'var(--gold)') ?>">
                    <?= $q['type']==='mcq'?'Multiple Choice':($q['type']==='truefalse'?'True / False':'Short Answer') ?>
                </span>
            </div>
            <div class="quiz-q-text"><?= h($q['question']) ?></div>

            <?php if($q['type']==='mcq' && !empty($optMap[$q['id']])): ?>
                <?php foreach($optMap[$q['id']] as $opt): ?>
                <label class="opt-label">
                    <input type="radio" name="q_<?= $q['id'] ?>" value="<?= $opt['id'] ?>" required>
                    <?= h($opt['option_text']) ?>
                </label>
                <?php endforeach; ?>

            <?php elseif($q['type']==='truefalse' && !empty($optMap[$q['id']])): ?>
                <?php foreach($optMap[$q['id']] as $opt): ?>
                <label class="opt-label">
                    <input type="radio" name="q_<?= $q['id'] ?>" value="<?= $opt['id'] ?>" required>
                    <?= h($opt['option_text']) ?>
                </label>
                <?php endforeach; ?>

            <?php elseif($q['type']==='short'): ?>
                <textarea class="fc" name="q_<?= $q['id'] ?>" rows="3"
                          placeholder="Type your answer here…" style="resize:vertical"></textarea>
                <small style="color:var(--muted);font-size:11px"><i class="fas fa-info-circle"></i> This answer will be reviewed and graded manually by your instructor.</small>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:8px">
            <a href="watch.php?id=<?= $cid ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Course
            </a>
            <button class="btn btn-primary" type="submit" name="do_submit"
                    onclick="return confirm('Submit your quiz? You cannot change answers after submitting.')">
                <i class="fas fa-paper-plane"></i> Submit Quiz
            </button>
        </div>
    </form>

</div>

<?php if($quiz['time_limit']): ?>
<script>
var seconds = <?= $quiz['time_limit'] * 60 ?>;
function tick() {
    var m = Math.floor(seconds / 60);
    var s = seconds % 60;
    document.getElementById('timer').textContent = m + ':' + (s < 10 ? '0' : '') + s;
    if (seconds <= 0) {
        document.getElementById('quiz-form').submit();
        return;
    }
    if (seconds <= 60) {
        document.getElementById('timer').style.color = '#ff8a80';
    }
    seconds--;
    setTimeout(tick, 1000);
}
tick();
</script>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
