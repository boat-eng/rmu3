<?php
// =============================================
// RMU E-Learning — PHPMailer Helper
// =============================================
// Requires: composer require phpmailer/phpmailer
// =============================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/vendor/autoload.php'; 

// ── SMTP Configuration ──
// Replace these two lines with your own Gmail + App Password
define('MAIL_USERNAME', 'henryakwasiapengye@gmail.com');
define('MAIL_PASSWORD', 'qvqm jral aysq bmys');  // spaces required by Gmail           
define('MAIL_FROM',     'henryakwasiapengye@gmail.com');
define('MAIL_FROM_NAME','RMU E-Learning Platform');

/**
 * Send a single email.
 *
 * @param string $toEmail   Recipient email
 * @param string $toName    Recipient name
 * @param string $subject   Email subject
 * @param string $htmlBody  HTML email body
 * @return bool
 */
function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug  = 2;
        $mail->Debugoutput = function($str, $level) {
            error_log('[RMU SMTP] ' . trim($str));
        };
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // port 587 — confirmed working
        $mail->Port       = 587;
        $mail->Timeout    = 30;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'allow_self_signed'=> true,
            ]
        ];

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Subject  = $subject;
        $mail->Body     = $htmlBody;
        $mail->AltBody  = strip_tags(str_replace(['<br>','<br/>','<br />','</p>'], "\n", $htmlBody));

        $mail->send();
        error_log('[RMU Mailer] sendMail SUCCESS to ' . $toEmail);
        return true;
    } catch (\Exception $e) {
        error_log('[RMU Mailer] sendMail FAILED to ' . $toEmail . ' | Error: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send emails to ALL enrolled students of a course.
 * Runs silently — errors are logged, never shown to instructor.
 *
 * @param PDO    $pdo
 * @param int    $courseId
 * @param string $subject
 * @param string $htmlBody   Use {name} as placeholder for student name
 */
function notifyEnrolledStudents(PDO $pdo, int $courseId, string $subject, string $htmlBody): void {
    try {
        $stmt = $pdo->prepare('
            SELECT s.email, s.full_name
            FROM enrollments e
            JOIN students s ON s.id = e.student_id
            WHERE e.course_id = ?
              AND s.email IS NOT NULL
              AND s.email != ""
        ');
        $stmt->execute([$courseId]);
        $students = $stmt->fetchAll();

        // DEBUG — log how many students were found
        error_log('[RMU Mailer] notifyEnrolledStudents: course_id=' . $courseId . ', students found=' . count($students));

        if (empty($students)) {
            error_log('[RMU Mailer] No enrolled students found for course_id=' . $courseId . '. Check enrollments table and students table.');
            return;
        }

        foreach ($students as $s) {
            error_log('[RMU Mailer] Attempting to email: ' . $s['full_name'] . ' <' . $s['email'] . '>');
            $personalBody = str_replace('{name}', htmlspecialchars($s['full_name']), $htmlBody);
            $sent = sendMail($s['email'], $s['full_name'], $subject, $personalBody);
            error_log('[RMU Mailer] Email to ' . $s['email'] . ': ' . ($sent ? 'SENT OK' : 'FAILED'));
        }
    } catch (\Exception $e) {
        error_log('[RMU Mailer] notifyEnrolledStudents error: ' . $e->getMessage());
    }
}

/**
 * Notify the PRIMARY instructor when a co-instructor uploads a lesson or quiz.
 */
function notifyPrimaryInstructor(PDO $pdo, int $courseId, string $uploaderName, string $contentType, string $contentTitle, string $contentSubtype = ''): void {
    try {
        $stmt = $pdo->prepare('SELECT u.full_name, u.email, c.title AS course_title FROM courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=? LIMIT 1');
        $stmt->execute([$courseId]);
        $primary = $stmt->fetch();
        if (!$primary || empty($primary['email'])) return;
        if (strtolower($primary['full_name']) === strtolower($uploaderName)) return;

        $courseTitle = $primary['course_title'];
        $isQuiz      = $contentType === 'quiz';
        $icon        = $isQuiz ? '📝' : '📚';
        $typeLabel   = $isQuiz ? 'Quiz' : 'Lesson';
        $color       = $isQuiz ? '#ce93d8' : '#c8a84b';
        $borderColor = $isQuiz ? 'rgba(142,68,173,.5)' : '#c8a84b';
        $manageLink  = BASE_URL . '/instructor/manage.php?id=' . $courseId;

        $subtypeLabel = match(strtolower($contentSubtype)) {
            'video'   => '🎬 Video',
            'youtube' => '▶️ YouTube Video',
            'slide'   => '📊 Slide Presentation',
            'quiz'    => '📝 Quiz',
            default   => ucfirst($contentSubtype ?: $typeLabel),
        };

        $subject  = $icon . ' Co-instructor ' . $typeLabel . ': ' . $contentTitle . ' — ' . $courseTitle;
        $bodyHtml = buildEmailTemplate($subject,
            '<p style="font-size:15px;color:rgba(255,255,255,.9);margin:0 0 16px">Hi <strong style="color:#fff">' . htmlspecialchars($primary['full_name']) . '</strong>,</p>
             <p style="font-size:14px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 0 20px">
               Your co-instructor <strong style="color:' . $color . '">' . htmlspecialchars($uploaderName) . '</strong>
               has added a new ' . strtolower($typeLabel) . ' to your course
               <strong style="color:' . $color . '">' . htmlspecialchars($courseTitle) . '</strong>.
             </p>
             <div style="background:#0d2a4e;border:1px solid #1e3a5f;border-left:4px solid ' . $borderColor . ';border-radius:8px;padding:16px 20px;margin-bottom:24px">
               <div style="font-size:11px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">' . $subtypeLabel . '</div>
               <div style="font-size:16px;font-weight:700;color:#fff;margin-bottom:4px">' . htmlspecialchars($contentTitle) . '</div>
               <div style="font-size:12px;color:rgba(255,255,255,.4)">Added by <strong style="color:rgba(255,255,255,.65)">' . htmlspecialchars($uploaderName) . '</strong> · ' . date('M j, Y \a\t g:i A') . '</div>
             </div>
             <a href="' . $manageLink . '" style="display:inline-block;background:#c8a84b;color:#001a3e;font-weight:700;font-size:14px;padding:12px 28px;border-radius:8px;text-decoration:none">🎓 View Course</a>'
        );
        sendMail($primary['email'], $primary['full_name'], $subject, $bodyHtml);
        error_log('[RMU Mailer] notifyPrimaryInstructor sent to ' . $primary['email'] . ' for ' . $contentType . ': ' . $contentTitle);
    } catch (Exception $e) {
        error_log('[RMU Mailer] notifyPrimaryInstructor error: ' . $e->getMessage());
    }
}

/**
 * Dispatch window notification emails in the background.
 * Returns immediately — emails are sent by a separate PHP process.
 * Works on WAMP (Windows) and Linux.
 *
 * @param string $type       'open' or 'close'
 * @param string $fromLabel  Formatted open date (for open emails)
 * @param string $untilLabel Formatted close date
 */
function dispatchWindowEmail(string $type, string $fromLabel = '', string $untilLabel = ''): void {
    $phpExe  = PHP_BINARY; // path to php.exe on WAMP
    $script  = dirname(__DIR__) . '/includes/send_window_email.php';

    // Escape arguments safely
    $args = escapeshellarg($type)
          . ' ' . escapeshellarg($fromLabel)
          . ' ' . escapeshellarg($untilLabel);

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows (WAMP) — run in background with start /B
        $cmd = 'start /B "" ' . escapeshellarg($phpExe) . ' ' . escapeshellarg($script) . ' ' . $args . ' > NUL 2>&1';
        pclose(popen($cmd, 'r'));
    } else {
        // Linux/Mac — run in background with &
        $cmd = escapeshellarg($phpExe) . ' ' . escapeshellarg($script) . ' ' . $args . ' > /dev/null 2>&1 &';
        exec($cmd);
    }

    error_log('[RMU Mailer] dispatchWindowEmail fired — type=' . $type . ' cmd=' . $cmd);
}

/**
 * Notify all unregistered approved students when the registration window
 * opens or closes.
 *
 * @param PDO    $pdo
 * @param string $type      'open' or 'close'
 * @param string $openFrom  Formatted open date string (for open emails)
 * @param string $openUntil Formatted close date string
 */
function notifyRegistrationWindow(PDO $pdo, string $type, string $openFrom = '', string $openUntil = ''): void {
    try {
        $stmt = $pdo->query("SELECT full_name, email FROM approved_students WHERE is_registered=0 AND email IS NOT NULL AND email != ''");
        $students = $stmt->fetchAll();

        if (empty($students)) {
            error_log('[RMU Mailer] notifyRegistrationWindow: no unregistered students to notify.');
            return;
        }

        $registerLink = BASE_URL . '/index.php';

        if ($type === 'open') {
            $subject = '🎓 RMU Registration is Now Open — Register Before ' . $openUntil;
            $bodyHtml = '
                <p style="font-size:15px;color:rgba(255,255,255,.9);margin:0 0 16px">
                    Hi <strong style="color:#fff">{name}</strong>,
                </p>
                <p style="font-size:14px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 0 20px">
                    Great news! The RMU E-Learning student registration window is now
                    <strong style="color:#4caf82">open</strong>.
                    You can now create your account and access your courses.
                </p>
                <div style="background:rgba(39,174,96,.08);border:1px solid rgba(39,174,96,.3);border-radius:12px;padding:18px 22px;margin-bottom:24px">
                    <div style="font-size:11px;color:#4caf82;text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px">📅 Registration Window</div>
                    <div style="display:flex;gap:20px;flex-wrap:wrap">
                        <div>
                            <div style="font-size:11px;color:rgba(255,255,255,.4);margin-bottom:3px">Opens</div>
                            <div style="font-size:14px;font-weight:700;color:#fff">' . htmlspecialchars($openFrom) . '</div>
                        </div>
                        <div style="border-left:1px solid rgba(255,255,255,.1);padding-left:20px">
                            <div style="font-size:11px;color:rgba(255,255,255,.4);margin-bottom:3px">Closes</div>
                            <div style="font-size:14px;font-weight:700;color:#ff8a80">' . htmlspecialchars($openUntil) . '</div>
                        </div>
                    </div>
                </div>
                <div style="background:rgba(255,183,77,.07);border-left:3px solid #ffb74d;border-radius:0 8px 8px 0;padding:12px 16px;margin-bottom:24px">
                    <p style="font-size:12px;color:rgba(255,255,255,.7);margin:0;line-height:1.7">
                        ⚠️ <strong style="color:#fff">Act fast.</strong>
                        Registration closes on <strong style="color:#ff8a80">' . htmlspecialchars($openUntil) . '</strong>.
                        You will need your <strong style="color:#fff">Student ID</strong> and
                        <strong style="color:#fff">RMU email address</strong> to register.
                    </p>
                </div>
                <div style="text-align:center">
                    <a href="' . $registerLink . '"
                       style="display:inline-block;background:linear-gradient(135deg,#4caf82,#27ae60);color:#fff;font-weight:700;font-size:15px;padding:14px 40px;border-radius:10px;text-decoration:none;letter-spacing:.03em">
                        🎓 Register Now
                    </a>
                </div>';
        } else {
            $subject = '🔒 RMU Registration Window is Now Closed';
            $bodyHtml = '
                <p style="font-size:15px;color:rgba(255,255,255,.9);margin:0 0 16px">
                    Hi <strong style="color:#fff">{name}</strong>,
                </p>
                <p style="font-size:14px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 0 20px">
                    The RMU E-Learning student registration window has now
                    <strong style="color:#ff8a80">closed</strong>.
                    New student accounts can no longer be created at this time.
                </p>
                <div style="background:rgba(231,76,60,.08);border:1px solid rgba(231,76,60,.3);border-radius:12px;padding:18px 22px;margin-bottom:24px;text-align:center">
                    <div style="font-size:36px;margin-bottom:10px">🔒</div>
                    <div style="font-size:15px;font-weight:700;color:#ff8a80;margin-bottom:6px">Registration Closed</div>
                    <div style="font-size:13px;color:rgba(255,255,255,.5)">The registration period has ended.</div>
                </div>
                <div style="background:rgba(200,168,75,.07);border-left:3px solid #c8a84b;border-radius:0 8px 8px 0;padding:12px 16px;margin-bottom:24px">
                    <p style="font-size:12px;color:rgba(255,255,255,.7);margin:0;line-height:1.7">
                        If you missed the registration window or need assistance,
                        please <strong style="color:#fff">contact the administration office</strong> directly.
                    </p>
                </div>
                <div style="text-align:center">
                    <a href="' . $registerLink . '"
                       style="display:inline-block;background:rgba(200,168,75,.15);color:#c8a84b;font-weight:700;font-size:14px;padding:12px 32px;border-radius:9px;text-decoration:none;border:1px solid rgba(200,168,75,.3)">
                        Visit Platform
                    </a>
                </div>';
        }

        // ── Single SMTP connection for all students ──────────────────
        // Connecting once and reusing saves ~3-4s per student
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->Timeout    = 30;
        $mail->SMTPKeepAlive = true;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;

        $sent = 0;
        foreach ($students as $s) {
            try {
                $personalBody  = str_replace('{name}', htmlspecialchars($s['full_name']), $bodyHtml);
                $mail->Body    = buildEmailTemplate($subject, $personalBody);
                $mail->AltBody = strip_tags($mail->Body);
                $mail->clearAddresses();
                $mail->addAddress($s['email'], $s['full_name']);
                $mail->send();
                $sent++;
                error_log('[RMU Mailer] Window email sent to ' . $s['email']);
            } catch (Exception $e) {
                error_log('[RMU Mailer] Window email FAILED to ' . $s['email'] . ': ' . $mail->ErrorInfo);
            }
        }

        $mail->smtpClose(); // close connection when done
        error_log('[RMU Mailer] notifyRegistrationWindow (' . $type . '): ' . $sent . '/' . count($students) . ' sent.');

    } catch (Exception $e) {
        error_log('[RMU Mailer] notifyRegistrationWindow error: ' . $e->getMessage());
    }
}

/**
 * Build a consistent RMU-branded HTML email template.
 *
 * @param string $preheader  Short preview text (shown in email clients)
 * @param string $bodyHtml   Inner HTML content (headings, paragraphs, buttons)
 * @return string
 */
function buildEmailTemplate(string $preheader, string $bodyHtml): string {
    return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($preheader) . '</title>
</head>
<body style="margin:0;padding:0;background:#0d1b2e;font-family:Arial,sans-serif">
  <!-- Preheader (hidden preview text) -->
  <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;color:#0d1b2e;line-height:1px">
    ' . htmlspecialchars($preheader) . '
  </div>
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0d1b2e;padding:30px 0">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#001a3e,#003580);border-radius:14px 14px 0 0;padding:28px 36px;text-align:center;border-bottom:2px solid #c8a84b">
            <div style="font-family:Georgia,serif;font-size:22px;font-weight:700;color:#c8a84b;letter-spacing:2px">RMU</div>
            <div style="font-size:11px;color:rgba(255,255,255,.5);letter-spacing:3px;text-transform:uppercase;margin-top:2px">E-Learning Platform</div>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="background:#0f2138;padding:32px 36px;border-left:1px solid #1e3a5f;border-right:1px solid #1e3a5f">
            ' . $bodyHtml . '
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#0a1628;border-radius:0 0 14px 14px;padding:20px 36px;text-align:center;border:1px solid #1e3a5f;border-top:none">
            <p style="font-size:11px;color:rgba(255,255,255,.3);margin:0">
              &copy; ' . date('Y') . ' Regional Maritime University &nbsp;·&nbsp;
              <a href="' . BASE_URL . '" style="color:#c8a84b;text-decoration:none">Visit Platform</a>
            </p>
            <p style="font-size:10px;color:rgba(255,255,255,.2);margin:6px 0 0">
              You received this email because you are enrolled in a course on RMU E-Learning.
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>';
}
