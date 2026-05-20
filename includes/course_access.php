<?php
/**
 * course_access.php  —  Drop into /includes/
 *
 * Provides helper functions so co-instructors can see and upload to
 * any course they have been assigned to, exactly like the primary instructor.
 *
 * Usage in any instructor page:
 *
 *   require_once '../includes/course_access.php';
 *
 *   // Get all courses this instructor can manage:
 *   $courses = get_instructor_courses($pdo, $uid);
 *
 *   // Before letting them upload/edit a lesson, gate-check:
 *   if (!instructor_can_access_course($pdo, $uid, $courseId)) {
 *       http_response_code(403); die('Access denied.');
 *   }
 *
 *   // When inserting a lesson, record who uploaded it:
 *   $pdo->prepare('INSERT INTO lessons (course_id, uploaded_by, title, file_path, ...)
 *                  VALUES (?, ?, ?, ?, ...)')
 *       ->execute([$courseId, $uid, $title, $filePath, ...]);
 */


/**
 * Check if an instructor has access to a specific course.
 * Returns true if they are the primary instructor OR a co-instructor.
 */
function instructor_can_access_course(PDO $pdo, int $instructorId, int $courseId): bool
{
    $st = $pdo->prepare("
        SELECT 1 FROM courses WHERE id = ? AND instructor_id = ?
        UNION
        SELECT 1 FROM course_instructors WHERE course_id = ? AND instructor_id = ?
        LIMIT 1
    ");
    $st->execute([$courseId, $instructorId, $courseId, $instructorId]);
    return (bool) $st->fetchColumn();
}


/**
 * Get all courses an instructor can access (primary + co-instructor).
 * Returns the full course rows plus uploader info for each lesson.
 */
function get_instructor_courses(PDO $pdo, int $instructorId): array
{
    $st = $pdo->prepare("
        SELECT DISTINCT c.*,
               u.full_name AS primary_instructor_name,
               COUNT(DISTINCT e.student_id) AS n_students,
               COUNT(DISTINCT l.id)         AS n_lessons
        FROM courses c
        JOIN users u ON u.id = c.instructor_id
        LEFT JOIN enrollments e ON e.course_id = c.id
        LEFT JOIN lessons l     ON l.course_id = c.id
        WHERE c.instructor_id = ?
           OR c.id IN (
               SELECT course_id FROM course_instructors WHERE instructor_id = ?
           )
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ");
    $st->execute([$instructorId, $instructorId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Get all instructors assigned to a course (primary first, then co-instructors).
 * Useful to display the team on the course detail page.
 */
function get_course_instructors(PDO $pdo, int $courseId): array
{
    $st = $pdo->prepare("
        SELECT u.id, u.full_name, u.email,
               ci.is_primary,
               ci.added_at
        FROM course_instructors ci
        JOIN users u ON u.id = ci.instructor_id
        WHERE ci.course_id = ?
        ORDER BY ci.is_primary DESC, u.full_name ASC
    ");
    $st->execute([$courseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Get all lessons for a course, including who uploaded each one.
 * All assigned instructors can see all lessons regardless of uploader.
 */
function get_course_lessons(PDO $pdo, int $courseId): array
{
    $st = $pdo->prepare("
        SELECT l.*,
               uploader.full_name AS uploaded_by_name
        FROM lessons l
        LEFT JOIN users uploader ON uploader.id = l.uploaded_by
        WHERE l.course_id = ?
        ORDER BY l.created_at ASC
    ");
    $st->execute([$courseId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Check if the current instructor is the PRIMARY instructor of a course.
 * Use this only when you need to restrict actions (e.g. deleting a course)
 * to the primary instructor only.
 */
function instructor_is_primary(PDO $pdo, int $instructorId, int $courseId): bool
{
    $st = $pdo->prepare("SELECT 1 FROM courses WHERE id = ? AND instructor_id = ? LIMIT 1");
    $st->execute([$courseId, $instructorId]);
    return (bool) $st->fetchColumn();
}
