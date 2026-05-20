<?php
require_once '../includes/config.php';
requireRole('instructor');

$uid = (int)$_SESSION['user_id'];

// Toggle publish — only primary instructor can publish/unpublish
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $cid = (int)$_GET['toggle'];
    $pdo->prepare('UPDATE courses SET is_published = NOT is_published WHERE id=? AND instructor_id=?')
        ->execute([$cid, $uid]);
    header('Location: courses.php');
    exit;
}

// Load ALL courses this instructor can access (primary + co-instructor)
$stmt = $pdo->prepare('
    SELECT DISTINCT c.*,
           u.full_name AS primary_instructor_name,
           COUNT(DISTINCT e.student_id) AS n_students,
           COUNT(DISTINCT l.id)         AS n_lessons,
           CASE WHEN c.instructor_id = :uid1 THEN 1 ELSE 0 END AS is_primary_instructor
    FROM courses c
    JOIN users u ON u.id = c.instructor_id
    LEFT JOIN enrollments e ON e.course_id = c.id
    LEFT JOIN lessons l     ON l.course_id = c.id
    WHERE c.instructor_id = :uid2
       OR c.id IN (SELECT course_id FROM course_instructors WHERE instructor_id = :uid3)
    GROUP BY c.id
    ORDER BY c.created_at DESC
');
$stmt->execute([':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid]);
$courses = $stmt->fetchAll();

$pageTitle    = 'My Courses';
$pageSubtitle = count($courses) . ' courses total';
$activePage   = 'courses';
$depth        = 1;

ob_start();
?>

<div class="flex-between mb2">
  <span class="card-title">All Courses</span>
</div>

<?php if (empty($courses)): ?>
<div class="card" style="text-align:center;padding:50px">
  <p style="color:var(--muted);margin-bottom:18px">No courses assigned to you yet. Contact the administrator.</p>
</div>
<?php else: ?>
<div class="card">
  <div class="tbl-wrap">
  <table>
    <thead><tr>
      <th>Title</th>
      <th>Your Role</th>
      <th>Level</th>
      <th>Semester</th>
      <th>Students</th>
      <th>Lessons</th>
      <th>Status</th>
      <th>Created</th>
      <th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($courses as $c): ?>
    <tr>
      <td>
        <strong style="color:var(--white)"><?= h($c['title']) ?></strong>
        <?php if($c['description']): ?>
        <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= h(mb_substr($c['description'],0,60)) ?>…</div>
        <?php endif; ?>
        <?php if(!$c['is_primary_instructor']): ?>
        <div style="font-size:10px;color:var(--muted);margin-top:3px">
          <i class="fas fa-user"></i> Primary: <?= h($c['primary_instructor_name']) ?>
        </div>
        <?php endif; ?>
      </td>
      <td>
        <?php if($c['is_primary_instructor']): ?>
          <span class="badge bg-gold" style="font-size:10px"><i class="fas fa-star"></i> Primary</span>
        <?php else: ?>
          <span class="badge bg-blue" style="font-size:10px"><i class="fas fa-chalkboard-teacher"></i> Co-Instructor</span>
        <?php endif; ?>
      </td>
      <td><span class="badge bg-blue">Level <?= h($c['level']) ?></span></td>
      <td><span class="badge bg-blue" style="background:rgba(41,128,185,.15);color:#64b5f6;border-color:rgba(41,128,185,.3)">Sem <?= h($c['semester'] ?? 1) ?></span></td>
      <td><?= $c['n_students'] ?></td>
      <td><?= $c['n_lessons'] ?></td>
      <td><span class="badge <?= $c['is_published'] ? 'bg-green' : 'bg-muted' ?>"><?= $c['is_published'] ? 'Published' : 'Draft' ?></span></td>
      <td><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
      <td>
        <div class="flex gap">
          <a href="manage.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-cog"></i> Manage</a>
          <?php if($c['is_primary_instructor']): ?>
          <a href="courses.php?toggle=<?= $c['id'] ?>" class="btn btn-secondary btn-sm" title="Toggle publish status" onclick="return confirm('Toggle publish status?')">
            <i class="fas fa-<?= $c['is_published'] ? 'eye-slash' : 'eye' ?>"></i>
          </a>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
