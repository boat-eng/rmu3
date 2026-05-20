<?php
require_once '../includes/config.php';
requireRole('instructor');

$uid = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare('
    SELECT s.id, s.full_name, s.email, s.programme, s.student_id, s.created_at,
           COUNT(DISTINCT e.course_id) AS n_courses,
           COUNT(DISTINCT lp.id)       AS n_done
    FROM students s
    JOIN enrollments e ON e.student_id=s.id
    JOIN courses c ON c.id=e.course_id AND c.instructor_id=?
    LEFT JOIN lesson_progress lp ON lp.student_id=s.id
    GROUP BY s.id
    ORDER BY s.full_name
');
$stmt->execute([$uid]);
$students = $stmt->fetchAll();

$pageTitle    = 'Students';
$pageSubtitle = count($students) . ' enrolled across your courses';
$activePage   = 'students';
$depth        = 1;

ob_start();
?>

<div class="card">
  <div class="card-hd">
    <span class="card-title"><i class="fas fa-users" style="color:var(--gold)"></i> All Enrolled Students</span>
  </div>
  <?php if(empty($students)): ?>
    <p style="color:var(--muted);text-align:center;padding:40px">
      No students enrolled in your courses yet. Publish a course to get started.
    </p>
  <?php else: ?>
  <div class="tbl-wrap">
  <table>
    <thead><tr><th>Student</th><th>Student ID</th><th>Programme</th><th>Courses</th><th>Lessons Done</th><th>Joined</th></tr></thead>
    <tbody>
    <?php foreach($students as $s): ?>
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <div class="avatar" style="width:34px;height:34px;font-size:13px"><?= strtoupper(mb_substr($s['full_name'],0,1)) ?></div>
          <div>
            <strong style="color:var(--white)"><?= h($s['full_name']) ?></strong>
            <div style="font-size:11px;color:var(--muted)"><?= h($s['email']) ?></div>
          </div>
        </div>
      </td>
      <td><?= h($s['student_id'] ?: '—') ?></td>
      <td><?= h($s['programme'] ? ($s['programme']==='IT'?'Information Technology':($s['programme']==='CS'?'Computer Science':'Computer Engineering')) : '—') ?></td>
      <td><span class="badge bg-blue"><?= $s['n_courses'] ?></span></td>
      <td><?= $s['n_done'] ?></td>
      <td><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
