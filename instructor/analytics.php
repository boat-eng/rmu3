<?php
require_once '../includes/config.php';
requireRole('instructor');

$uid = (int)$_SESSION['user_id'];

// ── 1. All courses by this instructor ─────────────────────────────────────
$courses = $pdo->prepare('
    SELECT c.id, c.title,
           COUNT(DISTINCT e.student_id) AS n_enrolled,
           COUNT(DISTINCT l.id)         AS n_lessons
    FROM courses c
    LEFT JOIN enrollments e ON e.course_id = c.id
    LEFT JOIN lessons l     ON l.course_id = c.id
    WHERE c.instructor_id = ?
    GROUP BY c.id
    ORDER BY c.created_at DESC
');
$courses->execute([$uid]);
$courses = $courses->fetchAll();


// ── 2. Enrolments per course (no created_at on enrollments table) ────────
$enrolByCourse = $pdo->prepare('
    SELECT c.title, COUNT(DISTINCT e.student_id) AS total
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    WHERE c.instructor_id = ?
    GROUP BY c.id
    ORDER BY total DESC
');
$enrolByCourse->execute([$uid]);
$enrolByCourse = $enrolByCourse->fetchAll();

// ── 3. Completion rate per course ─────────────────────────────────────────
$completionData = [];
foreach ($courses as $c) {
    if ($c['n_lessons'] == 0 || $c['n_enrolled'] == 0) {
        $completionData[] = ['title' => $c['title'], 'rate' => 0];
        continue;
    }
    // Count students who completed ALL lessons
    $comp = $pdo->prepare('
        SELECT COUNT(*) FROM (
            SELECT lp.student_id
            FROM lesson_progress lp
            JOIN lessons l ON l.id = lp.lesson_id
            WHERE l.course_id = ?
            GROUP BY lp.student_id
            HAVING COUNT(DISTINCT lp.lesson_id) >= ?
        ) AS completed
    ');
    $comp->execute([$c['id'], $c['n_lessons']]);
    $nCompleted = (int)$comp->fetchColumn();
    $rate = $c['n_enrolled'] > 0 ? round(($nCompleted / $c['n_enrolled']) * 100) : 0;
    $completionData[] = ['title' => $c['title'], 'rate' => $rate];
}

// ── 4. Lesson drop-off — for each lesson, how many students completed it ──
$dropoff = $pdo->prepare('
    SELECT l.id, l.title, l.sort_order, l.course_id,
           c.title AS course_title,
           COUNT(DISTINCT lp.student_id) AS n_done,
           COUNT(DISTINCT e.student_id)  AS n_enrolled
    FROM lessons l
    JOIN courses c       ON c.id  = l.course_id
    LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id
    LEFT JOIN enrollments e      ON e.course_id  = l.course_id
    WHERE c.instructor_id = ?
    GROUP BY l.id
    ORDER BY l.course_id, l.sort_order
');
$dropoff->execute([$uid]);
$dropoff = $dropoff->fetchAll();

// Group drop-off by course
$dropoffByCourse = [];
foreach ($dropoff as $row) {
    $dropoffByCourse[$row['course_id']]['title']    = $row['course_title'];
    $dropoffByCourse[$row['course_id']]['lessons'][] = $row;
}

// ── 5. Average rating per course ─────────────────────────────────────────
$ratings = $pdo->prepare('
    SELECT c.title,
           ROUND(AVG(r.rating), 1) AS avg_rating,
           COUNT(r.id)             AS n_ratings
    FROM course_ratings r
    JOIN courses c ON c.id = r.course_id
    WHERE c.instructor_id = ?
    GROUP BY c.id
    ORDER BY avg_rating DESC
');
$ratings->execute([$uid]);
$ratings = $ratings->fetchAll();

// ── 6. Student activity — started vs never started ────────────────────────
$activity = $pdo->prepare('
    SELECT
        COUNT(DISTINCT e.student_id) AS total_enrolled,
        COUNT(DISTINCT lp.student_id) AS total_active
    FROM enrollments e
    JOIN courses c ON c.id = e.course_id
    LEFT JOIN lesson_progress lp ON lp.student_id = e.student_id
        AND lp.lesson_id IN (SELECT id FROM lessons WHERE course_id = e.course_id)
    WHERE c.instructor_id = ?
');
$activity->execute([$uid]);
$activity = $activity->fetch();
$nActive   = (int)$activity['total_active'];
$nInactive = max(0, (int)$activity['total_enrolled'] - $nActive);

// ── Prepare JS data ───────────────────────────────────────────────────────
$enrolLabels = json_encode(array_map(function($r){ return mb_strlen($r['title'])>22?mb_substr($r['title'],0,22).'…':$r['title']; }, $enrolByCourse));
$enrolData   = json_encode(array_column($enrolByCourse, 'total'));

$compLabels  = json_encode(array_map(function($r) {
    return mb_strlen($r['title']) > 22 ? mb_substr($r['title'], 0, 22) . '…' : $r['title'];
}, $completionData));
$compData    = json_encode(array_column($completionData, 'rate'));

$ratingLabels = json_encode(array_map(function($r) {
    return mb_strlen($r['title']) > 22 ? mb_substr($r['title'], 0, 22) . '…' : $r['title'];
}, $ratings));
$ratingData   = json_encode(array_column($ratings, 'avg_rating'));

// ── 7. Quiz analytics ────────────────────────────────────────────────────
$quizStats = [];
try {
    $qzSt = $pdo->prepare('
        SELECT qz.title AS quiz_title, c.title AS course_title,
               COUNT(qa.id) AS total_attempts,
               SUM(qa.passed) AS total_passed,
               ROUND(AVG(qa.score),1) AS avg_score,
               ROUND(SUM(qa.passed)/COUNT(qa.id)*100) AS pass_rate
        FROM quizzes qz
        JOIN courses c ON c.id = qz.course_id
        LEFT JOIN quiz_attempts qa ON qa.quiz_id = qz.id
        WHERE qz.created_by = ?
        GROUP BY qz.id
        ORDER BY c.title, qz.title
    ');
    $qzSt->execute([$uid]);
    $quizStats = $qzSt->fetchAll();
} catch(Exception $e) {}

$pageTitle    = 'Analytics';
$pageSubtitle = 'Insights across your courses';
$activePage   = 'analytics';
$depth        = 1;

ob_start();
?>

<style>
.analytics-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
.analytics-grid .full { grid-column: 1 / -1; }
.chart-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:22px; }
.chart-title { font-family:'Cinzel',serif; font-size:14px; color:var(--gold); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.chart-wrap  { position:relative; height:240px; }
.dropoff-course { margin-bottom:28px; }
.dropoff-course h4 { font-size:13px; color:var(--gold); margin-bottom:10px; font-family:'Cinzel',serif; }
.dropoff-row { display:flex; align-items:center; gap:10px; margin-bottom:7px; }
.dropoff-label { font-size:12px; color:var(--text); width:160px; flex-shrink:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dropoff-bar-bg { flex:1; height:10px; background:rgba(255,255,255,.07); border-radius:6px; overflow:hidden; }
.dropoff-bar-fill { height:100%; border-radius:6px; background:linear-gradient(90deg,#c8a84b,#f0cc6a); transition:width .6s ease; }
.dropoff-pct { font-size:12px; color:var(--muted); width:38px; text-align:right; flex-shrink:0; }
.stat-row { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
.mini-stat { flex:1; min-width:120px; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:16px 18px; }
.mini-stat-val { font-size:28px; font-weight:700; color:var(--white); }
.mini-stat-lbl { font-size:12px; color:var(--muted); margin-top:2px; }
@media(max-width:768px){ .analytics-grid { grid-template-columns:1fr; } .analytics-grid .full { grid-column:1; } }
</style>

<!-- Mini stats row -->
<div class="stat-row">
    <div class="mini-stat">
        <div class="mini-stat-val"><?= count($courses) ?></div>
        <div class="mini-stat-lbl"><i class="fas fa-book" style="color:var(--gold)"></i> Total Courses</div>
    </div>
    <div class="mini-stat">
        <div class="mini-stat-val"><?= array_sum(array_column($courses, 'n_enrolled')) ?></div>
        <div class="mini-stat-lbl"><i class="fas fa-users" style="color:#64b5f6"></i> Total Enrolments</div>
    </div>
    <div class="mini-stat">
        <div class="mini-stat-val"><?= $nActive ?></div>
        <div class="mini-stat-lbl"><i class="fas fa-play-circle" style="color:#4caf82"></i> Active Students</div>
    </div>
    <div class="mini-stat">
        <div class="mini-stat-val"><?= $nInactive ?></div>
        <div class="mini-stat-lbl"><i class="fas fa-user-clock" style="color:#ff8a80"></i> Never Started</div>
    </div>
    <div class="mini-stat">
        <div class="mini-stat-val"><?= empty($ratings) ? '—' : number_format(array_sum(array_column($ratings,'avg_rating'))/count($ratings),1) ?></div>
        <div class="mini-stat-lbl"><i class="fas fa-star" style="color:var(--gold)"></i> Avg Rating</div>
    </div>
</div>

<div class="analytics-grid">

    <!-- Enrolments over time -->
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-chart-bar"></i> Enrolments per Course</div>
        <?php if (empty($enrolByCourse)): ?>
        <p style="color:var(--muted);font-size:13px;text-align:center;padding:60px 0">No enrolment data yet.</p>
        <?php else: ?>
        <div class="chart-wrap"><canvas id="enrolChart"></canvas></div>
        <?php endif; ?>
    </div>

    <!-- Student activity donut -->
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-users"></i> Student Activity</div>
        <?php if ($nActive + $nInactive === 0): ?>
        <p style="color:var(--muted);font-size:13px;text-align:center;padding:60px 0">No students enrolled yet.</p>
        <?php else: ?>
        <div class="chart-wrap"><canvas id="activityChart"></canvas></div>
        <?php endif; ?>
    </div>

    <!-- Completion rate per course -->
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-check-circle"></i> Completion Rate per Course</div>
        <?php if (empty($completionData)): ?>
        <p style="color:var(--muted);font-size:13px;text-align:center;padding:60px 0">No courses yet.</p>
        <?php else: ?>
        <div class="chart-wrap"><canvas id="compChart"></canvas></div>
        <?php endif; ?>
    </div>

    <!-- Average rating per course -->
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-star"></i> Average Rating per Course</div>
        <?php if (empty($ratings)): ?>
        <p style="color:var(--muted);font-size:13px;text-align:center;padding:60px 0">No ratings submitted yet.</p>
        <?php else: ?>
        <div class="chart-wrap"><canvas id="ratingChart"></canvas></div>
        <?php endif; ?>
    </div>

    <!-- Lesson drop-off (full width) -->
    <div class="chart-card full">
        <div class="chart-title"><i class="fas fa-filter"></i> Lesson Drop-off by Course</div>
        <?php if (empty($dropoffByCourse)): ?>
        <p style="color:var(--muted);font-size:13px;text-align:center;padding:40px 0">No lesson data yet.</p>
        <?php else: ?>
        <?php foreach ($dropoffByCourse as $cid => $cd): ?>
        <div class="dropoff-course">
            <h4><?= h($cd['title']) ?></h4>
            <?php foreach ($cd['lessons'] as $l):
                $pct = $l['n_enrolled'] > 0 ? round(($l['n_done'] / $l['n_enrolled']) * 100) : 0;
                $label = mb_strlen($l['title']) > 30 ? mb_substr($l['title'],0,30).'…' : $l['title'];
            ?>
            <div class="dropoff-row">
                <div class="dropoff-label" title="<?= h($l['title']) ?>"><?= h($label) ?></div>
                <div class="dropoff-bar-bg">
                    <div class="dropoff-bar-fill" style="width:<?= $pct ?>%"></div>
                </div>
                <div class="dropoff-pct"><?= $pct ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Quiz performance (full width) -->
    <?php if(!empty($quizStats)): ?>
    <div class="chart-card full">
        <div class="chart-title"><i class="fas fa-question-circle"></i> Quiz Performance</div>
        <div class="tbl-wrap">
        <table>
            <thead>
                <tr><th>Quiz</th><th>Course</th><th>Attempts</th><th>Pass Rate</th><th>Avg Score</th><th>Passed</th></tr>
            </thead>
            <tbody>
            <?php foreach($quizStats as $qs): ?>
            <tr>
                <td><strong style="color:var(--white)"><?= h($qs['quiz_title']) ?></strong></td>
                <td><span class="badge bg-blue" style="font-size:10px"><?= h($qs['course_title']) ?></span></td>
                <td><?= $qs['total_attempts'] ?? 0 ?></td>
                <td>
                    <?php if($qs['total_attempts'] > 0): ?>
                    <div style="display:flex;align-items:center;gap:8px">
                        <div style="width:80px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;height:6px">
                            <div style="height:100%;width:<?= $qs['pass_rate'] ?>%;background:<?= $qs['pass_rate']>=70?'#27ae60':'#e74c3c' ?>;border-radius:4px"></div>
                        </div>
                        <span style="font-size:12px;font-weight:700;color:<?= $qs['pass_rate']>=70?'#4caf82':'#ff8a80' ?>"><?= $qs['pass_rate'] ?>%</span>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--muted);font-size:12px">No attempts</span>
                    <?php endif; ?>
                </td>
                <td style="font-weight:700;color:var(--gold)"><?= $qs['avg_score'] ?? '—' ?><?= $qs['total_attempts']>0?'%':'' ?></td>
                <td><span class="badge bg-green" style="font-size:10px"><?= $qs['total_passed'] ?? 0 ?> / <?= $qs['total_attempts'] ?? 0 ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.color = '#6e849e';
Chart.defaults.font.family = "'Raleway', sans-serif";
Chart.defaults.font.size   = 12;

const gridColor  = 'rgba(200,168,75,0.08)';
const goldColor  = '#c8a84b';
const blueColor  = '#2980b9';
const greenColor = '#27ae60';
const redColor   = '#e74c3c';

// ── Enrolments line chart ──────────────────────────────────────────────────
<?php if (!empty($enrolByCourse)): ?>
new Chart(document.getElementById('enrolChart'), {
    type: 'bar',
    data: {
        labels: <?= $enrolLabels ?>,
        datasets: [{
            label: 'Enrolments',
            data: <?= $enrolData ?>,
            backgroundColor: 'rgba(200,168,75,0.7)',
            borderColor: goldColor,
            borderWidth: 1,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: gridColor } },
            y: { grid: { color: gridColor }, beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});
<?php endif; ?>

// ── Activity donut ─────────────────────────────────────────────────────────
<?php if ($nActive + $nInactive > 0): ?>
new Chart(document.getElementById('activityChart'), {
    type: 'doughnut',
    data: {
        labels: ['Active Students', 'Never Started'],
        datasets: [{
            data: [<?= $nActive ?>, <?= $nInactive ?>],
            backgroundColor: [greenColor, 'rgba(231,76,60,0.6)'],
            borderColor: ['#0f1e33','#0f1e33'],
            borderWidth: 3,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
            legend: { position: 'bottom', labels: { padding: 20, usePointStyle: true } }
        }
    }
});
<?php endif; ?>

// ── Completion rate bar chart ──────────────────────────────────────────────
<?php if (!empty($completionData)): ?>
new Chart(document.getElementById('compChart'), {
    type: 'bar',
    data: {
        labels: <?= $compLabels ?>,
        datasets: [{
            label: 'Completion %',
            data: <?= $compData ?>,
            backgroundColor: 'rgba(39,174,96,0.7)',
            borderColor: greenColor,
            borderWidth: 1,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: gridColor } },
            y: { grid: { color: gridColor }, beginAtZero: true, max: 100,
                 ticks: { callback: v => v + '%' } }
        }
    }
});
<?php endif; ?>

// ── Rating bar chart ───────────────────────────────────────────────────────
<?php if (!empty($ratings)): ?>
new Chart(document.getElementById('ratingChart'), {
    type: 'bar',
    data: {
        labels: <?= $ratingLabels ?>,
        datasets: [{
            label: 'Avg Rating',
            data: <?= $ratingData ?>,
            backgroundColor: 'rgba(200,168,75,0.7)',
            borderColor: goldColor,
            borderWidth: 1,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: gridColor } },
            y: { grid: { color: gridColor }, beginAtZero: true, max: 5,
                 ticks: { stepSize: 1, callback: v => v + '★' } }
        }
    }
});
<?php endif; ?>
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
