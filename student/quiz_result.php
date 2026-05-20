<?php
require_once '../includes/config.php';
requireRole('student');

$uid       = (int)$_SESSION['user_id'];
$attemptId = (int)($_GET['attempt'] ?? 0);

// Load attempt
$attempt = $pdo->prepare('
    SELECT qa.*, q.title AS quiz_title, q.pass_mark, q.course_id, q.allow_retake, q.id AS quiz_id,
           c.title AS course_title
    FROM quiz_attempts qa
    JOIN quizzes q ON q.id=qa.quiz_id
    JOIN courses c ON c.id=q.course_id
    WHERE qa.id=? AND qa.student_id=? LIMIT 1
');
$attempt->execute([$attemptId, $uid]);
$attempt = $attempt->fetch();
if (!$attempt) { header('Location: enrolled.php'); exit; }

// Load answers with questions
$answers = $pdo->prepare('
    SELECT qan.*, qq.question, qq.type, qq.marks
    FROM quiz_answers qan
    JOIN quiz_questions qq ON qq.id=qan.question_id
    WHERE qan.attempt_id=?
    ORDER BY qq.sort_order
');
$answers->execute([$attemptId]);
$answers = $answers->fetchAll();

// Load options for MCQ/TF answers
$optMap = [];
foreach ($answers as $a) {
    if (in_array($a['type'], ['mcq','truefalse'])) {
        $opts = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id=? ORDER BY sort_order');
        $opts->execute([$a['question_id']]);
        $optMap[$a['question_id']] = $opts->fetchAll();
    }
}

$cid         = $attempt['course_id'];
$passed      = $attempt['passed'];
$earnedMarks = (float)$attempt['score'];      // raw earned marks e.g. 15
$totalMarks  = (int)$attempt['total_marks'];  // total possible e.g. 20
$passmark    = $attempt['pass_mark'];         // % threshold e.g. 70
$passMarkRaw = $totalMarks > 0 ? ceil($totalMarks * $passmark / 100) : 0; // e.g. 14

// Check pending short answers
$pendingCount = 0;
foreach ($answers as $a) {
    if ($a['type'] === 'short' && $a['is_correct'] === null) $pendingCount++;
}
$hasPending = $pendingCount > 0;

$pageTitle    = $hasPending ? 'Quiz Submitted' : ($passed ? 'Quiz Passed!' : 'Quiz Results');
$pageSubtitle = h($attempt['quiz_title']);
$activePage   = 'enrolled';
$depth        = 1;
ob_start();
?>

<style>
.result-wrap { max-width: 760px; margin: 0 auto; }
.result-hero {
    border-radius:14px; padding:36px 28px; text-align:center; margin-bottom:24px;
    background: <?= $hasPending ? 'linear-gradient(135deg,rgba(200,168,75,.12),rgba(200,168,75,.04))' : ($passed ? 'linear-gradient(135deg,rgba(39,174,96,.15),rgba(39,174,96,.05))' : 'linear-gradient(135deg,rgba(231,76,60,.15),rgba(231,76,60,.05))') ?>;
    border: 1px solid <?= $hasPending ? 'rgba(200,168,75,.35)' : ($passed ? 'rgba(39,174,96,.3)' : 'rgba(231,76,60,.3)') ?>;
}
.result-icon  { font-size:52px; margin-bottom:12px; color:<?= $hasPending?'var(--gold)':($passed?'#4caf82':'#ff8a80') ?>; }
.result-title { font-family:'Cinzel',serif; font-size:24px; color:var(--white); margin-bottom:8px; }
.result-score { font-size:52px; font-weight:700; color:<?= $hasPending?'var(--gold)':($passed?'#4caf82':'#ff8a80') ?>; font-family:'Cinzel',serif; line-height:1.1; }
.result-score sup { font-size:22px; vertical-align:super; opacity:.7; }
.result-score-lbl { font-size:13px; color:var(--muted); margin-top:4px; letter-spacing:.04em; text-transform:uppercase; }
.result-sub   { font-size:14px; color:var(--muted); margin-top:10px; line-height:1.7; }
.ans-card { background:var(--surface); border-radius:10px; padding:16px; margin-bottom:12px; }
.ans-correct { border-left:4px solid #27ae60; }
.ans-wrong   { border-left:4px solid #e74c3c; }
.ans-pending { border-left:4px solid #c8a84b; }
</style>

<div class="result-wrap">

    <div class="result-hero">
        <div class="result-icon">
            <i class="fas fa-<?= $hasPending ? 'hourglass-half' : ($passed ? 'trophy' : 'times-circle') ?>"></i>
        </div>
        <div class="result-title">
            <?= $hasPending ? 'Quiz Submitted — Awaiting Review' : ($passed ? 'Congratulations! You Passed!' : 'Not Quite There') ?>
        </div>

        <?php if($hasPending): ?>
        <div style="font-size:15px;color:var(--gold);font-weight:700;margin:10px 0">
            <?= $pendingCount ?> short answer<?= $pendingCount>1?'s':'' ?> pending instructor review
        </div>
        <div class="result-sub">
            Your score will be finalised once your instructor marks your short answer<?= $pendingCount>1?'s':''?>.<br>
            You will see your result here once the review is complete.
        </div>
        <?php else: ?>
        <!-- Score as earned / total (e.g. 15 / 20) -->
        <div class="result-score"><?= (int)$earnedMarks ?> <sup>/ <?= $totalMarks ?></sup></div>
        <div class="result-score-lbl">marks scored</div>
        <div class="result-sub">
            Pass mark: <strong style="color:var(--white)"><?= $passMarkRaw ?> / <?= $totalMarks ?></strong>
            &nbsp;(<?= $passmark ?>% of total)
            <br>
            <?= $passed
                ? '<span style="color:#4caf82"><i class="fas fa-check-circle"></i> You have passed this quiz.</span>'
                : '<span style="color:#ff8a80"><i class="fas fa-times-circle"></i> You did not reach the pass mark.</span>' ?>
        </div>
        <?php if(!$passed && $attempt['allow_retake']): ?>
        <a href="quiz.php?id=<?= $attempt['quiz_id'] ?>" class="btn btn-primary" style="margin-top:16px;display:inline-flex">
            <i class="fas fa-redo"></i> &nbsp; Retake Quiz
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Answer review -->
    <div class="card">
        <div class="card-hd">
            <span class="card-title"><i class="fas fa-list-check" style="color:var(--gold)"></i> Answer Review</span>
            <?php if(!$hasPending): ?>
            <span style="font-size:13px;font-weight:700;color:<?= $passed?'#4caf82':'#ff8a80' ?>">
                <?= (int)$earnedMarks ?> / <?= $totalMarks ?> marks
            </span>
            <?php endif; ?>
        </div>

        <?php foreach($answers as $i => $a): ?>
        <?php
        $cardClass = $a['type']==='short'
            ? ($a['is_correct']===null ? 'ans-pending' : ($a['is_correct'] ? 'ans-correct' : 'ans-wrong'))
            : ($a['is_correct'] ? 'ans-correct' : 'ans-wrong');
        ?>
        <div class="ans-card <?= $cardClass ?>">
            <div style="font-size:11px;color:var(--muted);margin-bottom:6px">
                Q<?= $i+1 ?> &nbsp;·&nbsp;
                <?= $a['type']==='mcq'?'Multiple Choice':($a['type']==='truefalse'?'True/False':'Short Answer') ?>
                &nbsp;·&nbsp;
                <span style="font-weight:700;color:<?= $a['is_correct']===null?'var(--gold)':($a['is_correct']?'#4caf82':'#ff8a80') ?>">
                    <?= (float)$a['marks_given'] ?> / <?= $a['marks'] ?> marks
                </span>
                <?php if($a['type']==='short' && $a['is_correct']===null): ?>
                <span class="badge bg-gold" style="font-size:9px"><i class="fas fa-hourglass-half"></i> Pending Review</span>
                <?php endif; ?>
            </div>
            <div style="font-size:13px;color:var(--white);margin-bottom:10px;font-weight:600"><?= h($a['question']) ?></div>

            <?php if($a['type']==='short'): ?>
            <div style="font-size:13px;color:var(--text);background:var(--surf2);padding:10px;border-radius:6px">
                <?= h($a['answer_text'] ?? '(no answer given)') ?>
            </div>
            <?php if($a['is_correct']===null): ?>
            <div style="font-size:12px;color:var(--gold);margin-top:8px"><i class="fas fa-clock"></i> Awaiting instructor review — marks will be updated once reviewed.</div>
            <?php elseif($a['is_correct']): ?>
            <div style="font-size:12px;color:#4caf82;margin-top:8px"><i class="fas fa-check-circle"></i> Marked correct — <?= (float)$a['marks_given'] ?> / <?= $a['marks'] ?> mark<?= $a['marks']!=1?'s':'' ?> awarded.</div>
            <?php else: ?>
            <div style="font-size:12px;color:#ff8a80;margin-top:8px"><i class="fas fa-times-circle"></i> Marked incorrect — 0 / <?= $a['marks'] ?> marks.</div>
            <?php endif; ?>

            <?php else: ?>
            <?php
            $studentOptId = (int)$a['answer_text'];
            $correctOptId = null;
            if (!empty($optMap[$a['question_id']])) {
                foreach ($optMap[$a['question_id']] as $opt) {
                    if ($opt['is_correct']) { $correctOptId = $opt['id']; break; }
                }
            }
            ?>
            <?php foreach(($optMap[$a['question_id']] ?? []) as $opt): ?>
            <div style="display:flex;align-items:center;gap:8px;font-size:13px;padding:6px 10px;border-radius:6px;margin-bottom:4px;
                background:<?= $opt['id']===$correctOptId?'rgba(39,174,96,.1)':($opt['id']===$studentOptId && !$opt['is_correct']?'rgba(231,76,60,.1)':'transparent') ?>;
                color:<?= $opt['id']===$correctOptId?'#4caf82':($opt['id']===$studentOptId && !$opt['is_correct']?'#ff8a80':'var(--muted)') ?>">
                <i class="fas fa-<?= $opt['id']===$correctOptId?'check-circle':($opt['id']===$studentOptId && !$opt['is_correct']?'times-circle':'circle') ?>" style="font-size:12px"></i>
                <?= h($opt['option_text']) ?>
                <?php if($opt['id']===$studentOptId): ?>
                <span style="font-size:10px;opacity:.7">(your answer)</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:12px;justify-content:center;margin-top:20px">
        <a href="watch.php?id=<?= $cid ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Course
        </a>
    </div>

</div>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
