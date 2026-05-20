<?php
require_once '../includes/config.php';
requireRole('student');

$uid = (int)$_SESSION['user_id'];

// Fetch all published quizzes for courses the student is enrolled in
$stmt = $pdo->prepare("
    SELECT
        qz.id          AS quiz_id,
        qz.title       AS quiz_title,
        qz.description AS quiz_desc,
        qz.pass_mark,
        qz.time_limit,
        qz.allow_retake,
        c.id           AS course_id,
        c.title        AS course_title,
        c.description  AS course_desc,
        c.level,
        c.semester,
        u.full_name    AS instructor_name,
        u.bio          AS instructor_bio,
        u.profile_pic  AS instructor_pic,
        COUNT(DISTINCT qq.id) AS n_questions,
        qa.score       AS my_score,
        qa.passed      AS my_passed,
        qa.submitted_at AS my_submitted_at,
        qa.id          AS my_attempt_id
    FROM enrollments e
    JOIN courses c   ON c.id = e.course_id
    JOIN users u     ON u.id = c.instructor_id
    JOIN quizzes qz  ON qz.course_id = c.id AND qz.is_published = 1
    LEFT JOIN quiz_questions qq ON qq.quiz_id = qz.id
    LEFT JOIN quiz_attempts qa  ON qa.quiz_id = qz.id
                                AND qa.student_id = ?
                                AND qa.id = (
                                    SELECT MAX(id) FROM quiz_attempts
                                    WHERE quiz_id = qz.id AND student_id = ?
                                )
    WHERE e.student_id = ?
    GROUP BY qz.id, qa.id
    ORDER BY c.level ASC, c.semester ASC, qz.id ASC
");
$stmt->execute([$uid, $uid, $uid]);
$quizzes = $stmt->fetchAll();

// Group by course
$grouped = [];
foreach ($quizzes as $q) {
    $grouped[$q['course_id']][] = $q;
}

$totalQuizzes = count($quizzes);
$totalPassed  = count(array_filter($quizzes, fn($q) => $q['my_passed']));
$totalFailed  = count(array_filter($quizzes, fn($q) => $q['my_attempt_id'] && !$q['my_passed']));
$totalPending = count(array_filter($quizzes, fn($q) => !$q['my_attempt_id']));

$pageTitle    = 'Quizzes';
$pageSubtitle = $totalQuizzes . ' quiz' . ($totalQuizzes !== 1 ? 'zes' : '') . ' available';
$activePage   = 'quizzes';
$depth        = 1;

ob_start();
?>

<style>
.quiz-stats {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr));
    gap:14px; margin-bottom:28px;
}
.quiz-stat-card {
    background:var(--surface); border:1px solid var(--border);
    border-radius:12px; padding:18px 16px;
    display:flex; align-items:center; gap:14px;
}
.quiz-stat-ico {
    width:42px; height:42px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:16px; flex-shrink:0;
}
.quiz-stat-val { font-family:'Cinzel',serif; font-size:22px; font-weight:700; color:var(--white); }
.quiz-stat-lbl { font-size:11px; color:var(--muted); margin-top:2px; }

.course-block { margin-bottom:36px; }
.course-block-header {
    background:var(--surface); border:1px solid var(--border);
    border-radius:14px; padding:20px 24px; margin-bottom:16px;
    display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap;
    justify-content:space-between;
}
.course-block-info { display:flex; gap:16px; align-items:flex-start; flex:1; min-width:0; }
.course-icon {
    width:50px; height:50px; border-radius:12px; flex-shrink:0;
    background:rgba(200,168,75,.12); border:1px solid rgba(200,168,75,.25);
    display:flex; align-items:center; justify-content:center;
}
.course-title { font-family:'Cinzel',serif; font-size:16px; font-weight:700; color:var(--white); margin-bottom:5px; }
.course-desc  { font-size:12px; color:var(--muted); line-height:1.6; margin-bottom:8px; max-width:500px; }
.course-meta  { display:flex; flex-wrap:wrap; gap:10px; }
.course-meta-item { font-size:11px; color:var(--muted); display:flex; align-items:center; gap:5px; }
.course-meta-item i { color:rgba(200,168,75,.6); }

.instructor-pill {
    display:flex; align-items:center; gap:10px;
    background:var(--surf2); border:1px solid var(--border);
    border-radius:14px; padding:12px 16px; flex-shrink:0;
    max-width:240px;
}
.instr-avatar {
    width:40px; height:40px; border-radius:50%; flex-shrink:0;
    background:rgba(200,168,75,.15); border:2px solid rgba(200,168,75,.3);
    display:flex; align-items:center; justify-content:center;
    font-size:15px; font-weight:700; color:var(--gold); overflow:hidden;
}
.instr-avatar img { width:100%; height:100%; object-fit:cover; }
.instr-name { font-size:13px; font-weight:700; color:var(--white); }
.instr-role { font-size:10px; color:var(--muted); margin-top:1px; }
.instr-bio  { font-size:11px; color:var(--muted); margin-top:4px; line-height:1.4; }

.quiz-card {
    background:var(--surface); border:1px solid var(--border);
    border-radius:12px; padding:20px 22px;
    display:flex; align-items:flex-start; justify-content:space-between;
    gap:16px; margin-bottom:12px; transition:border-color .2s;
}
.quiz-card:hover { border-color:rgba(200,168,75,.35); }
.quiz-card-left { flex:1; min-width:0; }
.quiz-title { font-size:15px; font-weight:700; color:var(--white); margin-bottom:5px; }
.quiz-desc  { font-size:12px; color:var(--muted); line-height:1.5; margin-bottom:10px; }
.quiz-meta  { display:flex; flex-wrap:wrap; gap:10px; }
.quiz-meta-item { font-size:11px; color:var(--muted); display:flex; align-items:center; gap:4px; }
.quiz-meta-item i { color:rgba(200,168,75,.6); font-size:10px; }
.quiz-card-right { display:flex; flex-direction:column; align-items:flex-end; gap:10px; flex-shrink:0; }

.score-ring {
    width:60px; height:60px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    flex-direction:column; font-size:12px; font-weight:700;
    border:3px solid; font-family:'Cinzel',serif;
}
.score-ring.passed  { border-color:#27ae60; color:#4caf82; background:rgba(39,174,96,.08); }
.score-ring.failed  { border-color:#e74c3c; color:#ff8a80; background:rgba(231,76,60,.08); }
.score-ring.pending { border-color:rgba(200,168,75,.4); color:var(--gold); background:rgba(200,168,75,.06); }
.score-ring-label   { font-size:8px; color:var(--muted); margin-top:1px; font-family:'Raleway',sans-serif; }

.empty-quizzes { text-align:center; padding:60px 20px; color:var(--muted); }
.empty-quizzes i { font-size:48px; display:block; margin-bottom:14px; opacity:.35; }
.empty-quizzes h3 { font-family:'Cinzel',serif; font-size:16px; color:var(--white); margin-bottom:8px; }
</style>

<!-- Stats -->
<div class="quiz-stats">
    <div class="quiz-stat-card">
        <div class="quiz-stat-ico" style="background:rgba(200,168,75,.12);color:var(--gold)">
            <i class="fas fa-clipboard-list"></i>
        </div>
        <div><div class="quiz-stat-val"><?= $totalQuizzes ?></div><div class="quiz-stat-lbl">Total Quizzes</div></div>
    </div>
    <div class="quiz-stat-card">
        <div class="quiz-stat-ico" style="background:rgba(39,174,96,.12);color:#4caf82">
            <i class="fas fa-check-circle"></i>
        </div>
        <div><div class="quiz-stat-val"><?= $totalPassed ?></div><div class="quiz-stat-lbl">Passed</div></div>
    </div>
    <div class="quiz-stat-card">
        <div class="quiz-stat-ico" style="background:rgba(231,76,60,.12);color:#ff8a80">
            <i class="fas fa-times-circle"></i>
        </div>
        <div><div class="quiz-stat-val"><?= $totalFailed ?></div><div class="quiz-stat-lbl">Failed</div></div>
    </div>
    <div class="quiz-stat-card">
        <div class="quiz-stat-ico" style="background:rgba(41,128,185,.12);color:#64b5f6">
            <i class="fas fa-hourglass-half"></i>
        </div>
        <div><div class="quiz-stat-val"><?= $totalPending ?></div><div class="quiz-stat-lbl">Not Taken</div></div>
    </div>
</div>

<?php if(empty($quizzes)): ?>
<div class="empty-quizzes">
    <i class="fas fa-clipboard-list"></i>
    <h3>No Quizzes Yet</h3>
    <p>Your instructors haven't published any quizzes for your enrolled courses yet.<br>Check back soon or enroll in more courses.</p>
    <a href="browse.php" class="btn btn-primary" style="margin-top:18px">
        <i class="fas fa-compass"></i> Browse Courses
    </a>
</div>
<?php else: ?>

<?php foreach($grouped as $courseId => $courseQuizzes):
    $first = $courseQuizzes[0];
?>
<div class="course-block">

    <!-- Course + Instructor header -->
    <div class="course-block-header">
        <div class="course-block-info">
            <div class="course-icon">
                <i class="fas fa-book-open" style="color:var(--gold);font-size:20px"></i>
            </div>
            <div style="min-width:0">
                <div class="course-title"><?= h($first['course_title']) ?></div>
                <?php if($first['course_desc']): ?>
                <div class="course-desc"><?= h(mb_substr($first['course_desc'], 0, 180)) ?><?= mb_strlen($first['course_desc']) > 180 ? '…' : '' ?></div>
                <?php endif; ?>
                <div class="course-meta">
                    <div class="course-meta-item">
                        <i class="fas fa-layer-group"></i> Level <?= h($first['level']) ?>
                    </div>
                    <div class="course-meta-item">
                        <i class="fas fa-calendar-alt"></i> Semester <?= h($first['semester']) ?>
                    </div>
                    <div class="course-meta-item">
                        <i class="fas fa-clipboard-list"></i>
                        <?= count($courseQuizzes) ?> quiz<?= count($courseQuizzes) !== 1 ? 'zes' : '' ?>
                    </div>
                    <a href="course.php?id=<?= $courseId ?>" class="course-meta-item" style="color:var(--gold);text-decoration:none">
                        <i class="fas fa-external-link-alt"></i> View Course
                    </a>
                </div>
            </div>
        </div>

        <!-- Instructor info -->
        <div class="instructor-pill">
            <div class="instr-avatar">
                <?php if($first['instructor_pic']): ?>
                <img src="../uploads/avatars/<?= h($first['instructor_pic']) ?>" alt="">
                <?php else: ?>
                <?= strtoupper(mb_substr($first['instructor_name'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div style="min-width:0">
                <div class="instr-name"><?= h($first['instructor_name']) ?></div>
                <div class="instr-role"><i class="fas fa-chalkboard-teacher" style="font-size:9px"></i> Instructor</div>
                <?php if($first['instructor_bio']): ?>
                <div class="instr-bio"><?= h(mb_substr($first['instructor_bio'], 0, 90)) ?><?= mb_strlen($first['instructor_bio'] ?? '') > 90 ? '…' : '' ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quiz cards -->
    <?php foreach($courseQuizzes as $q): ?>
    <div class="quiz-card">
        <div class="quiz-card-left">
            <div class="quiz-title">
                <i class="fas fa-clipboard-list" style="color:var(--gold);margin-right:7px;font-size:13px"></i>
                <?= h($q['quiz_title']) ?>
            </div>
            <?php if($q['quiz_desc']): ?>
            <div class="quiz-desc"><?= h($q['quiz_desc']) ?></div>
            <?php endif; ?>
            <div class="quiz-meta">
                <div class="quiz-meta-item">
                    <i class="fas fa-question-circle"></i>
                    <?= $q['n_questions'] ?> question<?= $q['n_questions'] != 1 ? 's' : '' ?>
                </div>
                <div class="quiz-meta-item">
                    <i class="fas fa-percentage"></i> Pass mark: <?= $q['pass_mark'] ?>%
                </div>
                <?php if($q['time_limit']): ?>
                <div class="quiz-meta-item">
                    <i class="fas fa-clock"></i> <?= $q['time_limit'] ?> min
                </div>
                <?php else: ?>
                <div class="quiz-meta-item">
                    <i class="fas fa-infinity"></i> No time limit
                </div>
                <?php endif; ?>
                <?php if($q['allow_retake']): ?>
                <div class="quiz-meta-item">
                    <i class="fas fa-redo"></i> Retakes allowed
                </div>
                <?php endif; ?>
                <?php if($q['my_submitted_at']): ?>
                <div class="quiz-meta-item">
                    <i class="fas fa-calendar-check"></i>
                    Taken <?= date('M j, Y', strtotime($q['my_submitted_at'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="quiz-card-right">
            <?php if($q['my_attempt_id']): ?>
                <div class="score-ring <?= $q['my_passed'] ? 'passed' : 'failed' ?>">
                    <?= $q['my_score'] ?>%
                    <div class="score-ring-label"><?= $q['my_passed'] ? 'PASSED' : 'FAILED' ?></div>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
                    <a href="quiz_result.php?attempt=<?= $q['my_attempt_id'] ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-chart-bar"></i> Results
                    </a>
                    <?php if(!$q['my_passed'] && $q['allow_retake']): ?>
                    <a href="quiz.php?id=<?= $q['quiz_id'] ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-redo"></i> Retake
                    </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="score-ring pending">
                    <i class="fas fa-pen" style="font-size:18px"></i>
                    <div class="score-ring-label">NEW</div>
                </div>
                <a href="quiz.php?id=<?= $q['quiz_id'] ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-play"></i> Take Quiz
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

</div>
<?php endforeach; ?>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
