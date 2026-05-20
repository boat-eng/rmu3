<?php
require_once '../includes/config.php';
requireRole('admin');

$uid = (int)$_SESSION['user_id'];
$msg = '';
$err = '';

// ── ENSURE TABLES & COLUMNS EXIST (run once per session, not every request) ─
if (empty($_SESSION['rmu_schema_checked'])) {
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS approved_students (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        student_id    VARCHAR(50)  NOT NULL UNIQUE,
        email         VARCHAR(150) NOT NULL UNIQUE,
        full_name     VARCHAR(150) NOT NULL,
        programme     VARCHAR(20)  NOT NULL DEFAULT 'IT',
        year_enrolled YEAR         NOT NULL,
        reg_code      VARCHAR(20)  DEFAULT NULL,
        code_used     TINYINT(1)   NOT NULL DEFAULT 0,
        is_registered TINYINT(1)   NOT NULL DEFAULT 0,
        added_by      INT          DEFAULT NULL,
        added_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    // Add columns if missing (safe upgrade)
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    foreach (['reg_code VARCHAR(20) DEFAULT NULL', 'code_used TINYINT(1) NOT NULL DEFAULT 0'] as $colDef) {
        $col = explode(' ', $colDef)[0];
        $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='approved_students' AND COLUMN_NAME=?");
        $chk->execute([$dbName, $col]);
        if (!(int)$chk->fetchColumn()) {
            $pdo->exec("ALTER TABLE approved_students ADD COLUMN $colDef");
        }
    }
    // Auto-generate codes for any rows missing one
    $pdo->exec("UPDATE approved_students SET reg_code = CONCAT('RMU-', UPPER(SUBSTRING(MD5(CONCAT(student_id,email,id)),1,4)),'-',UPPER(SUBSTRING(MD5(CONCAT(email,id,student_id)),5,4))) WHERE reg_code IS NULL OR reg_code = ''");

    // reg_settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS reg_settings (
        id           INT NOT NULL DEFAULT 1,
        window_open  TINYINT(1)  NOT NULL DEFAULT 0,
        open_from    DATETIME    DEFAULT NULL,
        open_until   DATETIME    DEFAULT NULL,
        updated_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    )");
    $pdo->exec("INSERT IGNORE INTO reg_settings (id,window_open) VALUES (1,0)");
    $_SESSION['rmu_schema_checked'] = true;
} catch(\Exception $e) { error_log('[RMU students.php] Schema error: '.$e->getMessage()); }
}

// ── REGISTRATION WINDOW SETTINGS ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_save_window'])) {
    $openFrom  = trim($_POST['open_from']  ?? '');
    $openUntil = trim($_POST['open_until'] ?? '');

    if (!$openFrom || !$openUntil) {
        header('Location: students.php?tab=approved&window_err=missing'); exit;
    }

    $fromTs  = strtotime($openFrom);
    $untilTs = strtotime($openUntil);

    if (!$fromTs || !$untilTs) {
        header('Location: students.php?tab=approved&window_err=invalid'); exit;
    }
    if ($untilTs <= $fromTs) {
        header('Location: students.php?tab=approved&window_err=order'); exit;
    }

    $openFromDb  = date('Y-m-d H:i:s', $fromTs);
    $openUntilDb = date('Y-m-d H:i:s', $untilTs);

    try {
        // 1. Save to DB
        $pdo->prepare('UPDATE reg_settings SET window_open=1, open_from=?, open_until=? WHERE id=1')
            ->execute([$openFromDb, $openUntilDb]);

        // 2. Send emails — same as close handler (direct, no background process)
        require_once '../includes/mailer.php';
        $fromLabel  = date('M j, Y \a\t g:i A', $fromTs);
        $untilLabel = date('M j, Y \a\t g:i A', $untilTs);
        set_time_limit(300); // allow enough time for all emails
        notifyRegistrationWindow($pdo, 'open', $fromLabel, $untilLabel);

        // 3. Redirect after emails are done
        header('Location: students.php?tab=approved&window_saved=1'); exit;

    } catch(Exception $e) {
        header('Location: students.php?tab=approved&window_err=db'); exit;
    }
}

// ── CLOSE WINDOW MANUALLY ──────────────────────────────────────────
if (isset($_GET['close_window'])) {
    // 1. Save to DB instantly
    $pdo->prepare('UPDATE reg_settings SET window_open=0, open_from=NULL, open_until=NULL WHERE id=1')->execute([]);

    // 2. Send emails directly
    require_once '../includes/mailer.php';
    notifyRegistrationWindow($pdo, 'close', '', '');

    // 3. Redirect
    header('Location: students.php?tab=approved&window_closed=1'); exit;
}

// ── CLEAR WINDOW DATES (reset schedule but keep open) ─────────────
if (isset($_GET['clear_window'])) {
    $pdo->prepare('UPDATE reg_settings SET window_open=0, open_from=NULL, open_until=NULL WHERE id=1')->execute([]);
    header('Location: students.php?tab=approved&window_cleared=1'); exit;
}

// ── OPEN WINDOW IMMEDIATELY ────────────────────────────────────────
if (isset($_GET['open_now'])) {
    $from  = date('Y-m-d H:i:s');
    $until = date('Y-m-d H:i:s', strtotime('+7 days'));
    $pdo->prepare('UPDATE reg_settings SET window_open=1, open_from=?, open_until=? WHERE id=1')
        ->execute([$from, $until]);
    header('Location: students.php?tab=approved&window_saved=1'); exit;
}


// ── CSV TEMPLATE DOWNLOADS ────────────────────────────────────────
if (isset($_GET['dl_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rmu_approved_students_template.csv"');
    echo "full_name,student_id,email,programme,year_enrolled\r\n";
    echo "Kofi Mensah,BIT2244901,kofi.mensah@st.rmu.edu.gh,IT,2024\r\n";
    echo "Ama Asante,BCS2244902,ama.asante@st.rmu.edu.gh,CS,2024\r\n";
    echo "Kweku Boateng,BCE2244903,kweku.boateng@st.rmu.edu.gh,CE,2024\r\n";
    exit;
}
// Bulk student accounts template (creates real accounts)
if (isset($_GET['dl_bulk_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rmu_bulk_import_template.csv"');
    echo "full_name,student_id,email,programme,year_enrolled\r\n";
    echo "Kofi Mensah,BIT2244901,kofi.mensah@st.rmu.edu.gh,IT,2026\r\n";
    echo "Ama Asante,BCS2244902,ama.asante@st.rmu.edu.gh,CS,2026\r\n";
    echo "Kweku Boateng,BCE2244903,kweku.boateng@st.rmu.edu.gh,CE,2026\r\n";
    echo "Abena Owusu,DIT2244904,abena.owusu@st.rmu.edu.gh,DIT,2026\r\n";
    exit;
}

// ── CSV IMPORT ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import_csv'])) {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Please select a valid CSV file to upload.';
    } else {
        $tmpPath = $_FILES['csv_file']['tmp_name'];
        $handle  = fopen($tmpPath, 'r');
        if (!$handle) {
            $err = 'Could not read the uploaded file.';
        } else {
            $inserted = 0;
            $skipped  = 0;
            $errors   = [];
            $rowNum   = 0;

            // Skip header row
            $header = fgetcsv($handle);
            // Normalize header keys (lower, trim)
            $header = array_map(fn($h) => strtolower(trim($h)), $header);
            $colMap = array_flip($header);

            $required = ['full_name','student_id','email','programme','year_enrolled'];
            $missing  = array_diff($required, array_keys($colMap));
            if (!empty($missing)) {
                $err = 'CSV is missing required columns: ' . implode(', ', $missing) . '. Please use the template.';
            } else {
                $stmt = $pdo->prepare('INSERT IGNORE INTO approved_students
                    (student_id, email, full_name, programme, year_enrolled, is_registered, added_by)
                    VALUES (?,?,?,?,?,0,?)');

                while (($row = fgetcsv($handle)) !== false) {
                    $rowNum++;
                    if (count($row) < count($required)) { $skipped++; continue; }

                    $rName  = trim($row[$colMap['full_name']]     ?? '');
                    $rSid   = strtoupper(trim($row[$colMap['student_id']]  ?? ''));
                    $rEmail = strtolower(trim($row[$colMap['email']]       ?? ''));
                    $rProg  = strtoupper(trim($row[$colMap['programme']]   ?? 'IT'));
                    $rYear  = (int)trim($row[$colMap['year_enrolled']]     ?? date('Y'));

                    if (!$rName || !$rSid || !$rEmail) {
                        $errors[] = "Row $rowNum: name, student ID and email are required.";
                        $skipped++;
                        continue;
                    }
                    if (!filter_var($rEmail, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "Row $rowNum: invalid email '$rEmail'.";
                        $skipped++;
                        continue;
                    }
                    if ($rYear < 2000 || $rYear > (int)date('Y') + 10) {
                        $errors[] = "Row $rowNum: invalid year '$rYear'.";
                        $skipped++;
                        continue;
                    }

                    try {
                        $stmt->execute([$rSid, $rEmail, $rName, $rProg, $rYear, $uid]);
                        if ($stmt->rowCount() > 0) $inserted++;
                        else $skipped++;
                    } catch (PDOException $e) {
                        $errors[] = "Row $rowNum: duplicate entry skipped ($rSid / $rEmail).";
                        $skipped++;
                    }
                }
                fclose($handle);

                $msg = "Import complete — $inserted student(s) added, $skipped skipped.";
                if (!empty($errors)) {
                    $msg .= ' Issues: ' . implode(' | ', array_slice($errors, 0, 5));
                    if (count($errors) > 5) $msg .= ' ... and ' . (count($errors) - 5) . ' more.';
                }
            }
        }
    }
}

// ── BULK IMPORT STUDENTS DIRECTLY INTO students TABLE ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_bulk_import'])) {
    if (!isset($_FILES['bulk_csv']) || $_FILES['bulk_csv']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Please select a valid CSV file to upload.';
    } else {
        $tmpPath = $_FILES['bulk_csv']['tmp_name'];
        $handle  = fopen($tmpPath, 'r');
        if (!$handle) {
            $err = 'Could not read the uploaded file.';
        } else {
            $inserted = 0; $skipped = 0; $errors = []; $rowNum = 0;

            // Strip BOM if present
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);

            $header = fgetcsv($handle);
            if (!$header) { $err = 'CSV file is empty or unreadable.'; goto skip_bulk; }
            $header = array_map(fn($h) => strtolower(trim(str_replace(['"',"'"], '', $h))), $header);
            $colMap = array_flip($header);

            $required = ['full_name', 'student_id', 'email', 'programme', 'year_enrolled'];
            $missing  = array_diff($required, array_keys($colMap));
            if (!empty($missing)) {
                $err = 'CSV is missing columns: <strong>' . implode(', ', $missing) . '</strong>. Please use the template.';
            } else {
                $stmtChkAppr = $pdo->prepare('SELECT id FROM approved_students WHERE student_id=? OR email=? LIMIT 1');
                $stmtIns     = $pdo->prepare('INSERT INTO approved_students
                    (student_id, email, full_name, programme, year_enrolled, is_registered, added_by)
                    VALUES (?,?,?,?,?,0,?)');

                while (($row = fgetcsv($handle)) !== false) {
                    $rowNum++;
                    if (count($row) < 2) continue; // skip blank rows

                    $rName  = trim($row[$colMap['full_name']]     ?? '');
                    $rSid   = strtoupper(trim($row[$colMap['student_id']]  ?? ''));
                    $rEmail = strtolower(trim($row[$colMap['email']]       ?? ''));
                    $rProg  = strtoupper(trim($row[$colMap['programme']]   ?? 'IT'));
                    $rYear  = (int)trim($row[$colMap['year_enrolled']]     ?? date('Y'));

                    if (!$rName || !$rSid || !$rEmail) {
                        $errors[] = "Row $rowNum: name, student ID and email are required.";
                        $skipped++; continue;
                    }
                    if (!filter_var($rEmail, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "Row $rowNum: invalid email '$rEmail'.";
                        $skipped++; continue;
                    }
                    if ($rYear < 2000 || $rYear > (int)date('Y') + 5) {
                        $errors[] = "Row $rowNum: invalid year '$rYear'.";
                        $skipped++; continue;
                    }

                    // Skip if already in approved list
                    $stmtChkAppr->execute([$rSid, $rEmail]);
                    if ($stmtChkAppr->fetch()) {
                        $errors[] = "Row $rowNum: $rSid / $rEmail already exists — skipped.";
                        $skipped++; continue;
                    }

                    try {
                        $pdo->prepare('INSERT INTO approved_students
                            (student_id, email, full_name, programme, year_enrolled, is_registered, added_by)
                            VALUES (?,?,?,?,?,0,?)')
                            ->execute([$rSid, $rEmail, $rName, $rProg, $rYear, $uid]);
                        $inserted++;
                    } catch (PDOException $e) {
                        $errors[] = "Row $rowNum: duplicate entry ($rSid) — skipped.";
                        $skipped++;
                    }
                }
                fclose($handle);

                if ($inserted > 0) {
                    $msg = "<strong>$inserted</strong> student(s) added to the approved list successfully. "
                         . "They can now register on the login page using their Student ID and Email during the registration window.";
                } else {
                    $err = "No students were imported. " . ($errors ? 'See issues below.' : 'Check your CSV file.');
                }
                if (!empty($errors)) {
                    $issueList = '<br><div style="margin-top:8px;padding:10px;background:rgba(231,76,60,.08);border:1px solid rgba(231,76,60,.2);border-radius:8px;font-size:12px;color:#ff8a80">'
                        . '<strong>Issues (' . count($errors) . '):</strong><br>'
                        . implode('<br>', array_slice($errors, 0, 8))
                        . (count($errors) > 8 ? '<br>… and ' . (count($errors)-8) . ' more.' : '')
                        . '</div>';
                    $msg .= $issueList;
                }
            }
            skip_bulk:;
        }
    }
}

// ── DELETE APPROVED STUDENT ──────────────────────────────────────
if (isset($_GET['del_approved']) && is_numeric($_GET['del_approved'])) {
    $pdo->prepare('DELETE FROM approved_students WHERE id=?')->execute([(int)$_GET['del_approved']]);
    header('Location: students.php?tab=approved&deleted_a=1'); exit;
}

// ── EDIT APPROVED STUDENT ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_edit_approved'])) {
    $eaId   = (int)($_POST['ea_id']       ?? 0);
    $eaName = trim($_POST['ea_name']      ?? '');
    $eaSid  = strtoupper(trim($_POST['ea_sid']  ?? ''));
    $eaEmail= strtolower(trim($_POST['ea_email']?? ''));
    $eaProg = trim($_POST['ea_programme'] ?? 'IT');
    $eaYear = (int)($_POST['ea_year']     ?? date('Y'));

    if (!$eaId || !$eaName || !$eaSid || !$eaEmail) {
        $err = 'All fields are required.';
    } elseif (!filter_var($eaEmail, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email address.';
    } else {
        try {
            $pdo->prepare('UPDATE approved_students SET full_name=?, student_id=?, email=?, programme=?, year_enrolled=? WHERE id=?')
                ->execute([$eaName, $eaSid, $eaEmail, $eaProg, $eaYear, $eaId]);
            $msg = "Approved student record updated successfully.";
        } catch(PDOException $e) {
            $err = 'That Student ID or Email is already used by another entry.';
        }
    }
    header('Location: students.php?tab=approved' . ($msg ? '&updated=1' : '&err=' . urlencode($err))); exit;
}

// ── ADD SINGLE APPROVED STUDENT ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_add_approved'])) {
    $aName  = trim($_POST['a_name']  ?? '');
    $aSid   = strtoupper(trim($_POST['a_sid']   ?? ''));
    $aEmail = strtolower(trim($_POST['a_email'] ?? ''));
    $aProg  = trim($_POST['a_programme'] ?? 'IT');
    $aYear  = (int)($_POST['a_year'] ?? date('Y'));

    if (!$aName || !$aSid || !$aEmail) {
        $err = 'Name, Student ID and Email are required.';
    } elseif (!filter_var($aEmail, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email address.';
    } else {
        try {
            $pdo->prepare('INSERT INTO approved_students (student_id,email,full_name,programme,year_enrolled,added_by) VALUES (?,?,?,?,?,?)')
                ->execute([$aSid, $aEmail, $aName, $aProg, $aYear, $uid]);
            $msg = "Student '$aName' added to the approved list.";
        } catch (PDOException $e) {
            $err = 'That Student ID or Email is already in the approved list.';
        }
    }
}


// ── CREATE STUDENT ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_create_student'])) {
    $full_name  = trim($_POST['s_name']      ?? '');
    $email      = strtolower(trim($_POST['s_email']     ?? ''));
    // No password set by admin — student sets their own via registration
    $programme  = trim($_POST['s_programme'] ?? '');
    $student_id = trim($_POST['s_sid']       ?? '');

    if (!$full_name || !$email) {
        $err = 'Full name and email are required.';
    } else {
        // Students live in the `students` table (not `users`)
        $chk = $pdo->prepare('SELECT id FROM students WHERE email=? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $err = 'An account with that email already exists.';
        } else {
            // No password set by admin — student sets their own when they self-register
            $pdo->prepare('INSERT INTO students (full_name,email,password,department,programme,student_id,email_verified) VALUES (?,?,?,?,?,?,0)')
                ->execute([$full_name, $email, '', 'Information Technology Department', $programme ?: null, $student_id ?: null]);
            $msg = 'Student account created. They can register on the login page using their Student ID and email.';
        }
    }
}

// ── EDIT STUDENT ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_edit_student'])) {
    $esid      = (int)($_POST['es_id']        ?? 0);
    $full_name = trim($_POST['es_name']        ?? '');
    $email     = strtolower(trim($_POST['es_email']   ?? ''));
    $programme = trim($_POST['es_programme']   ?? '');
    $sid       = trim($_POST['es_sid']         ?? '');
    $newpass   = trim($_POST['es_password']    ?? '');

    if (!$esid || !$full_name || !$email) {
        $err = 'Name and email are required.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM students WHERE email=? AND id!=? LIMIT 1');
        $chk->execute([$email, $esid]);
        if ($chk->fetch()) {
            $err = 'That email is already in use by another account.';
        } else {
            if ($newpass) {
                if (strlen($newpass) < 6) { $err = 'New password must be at least 6 characters.'; goto skip_edit_s; }
                $hash = password_hash($newpass, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE students SET full_name=?,email=?,password=?,programme=?,student_id=? WHERE id=?')
                    ->execute([$full_name, $email, $hash, $programme ?: null, $sid ?: null, $esid]);
            } else {
                $pdo->prepare('UPDATE students SET full_name=?,email=?,programme=?,student_id=? WHERE id=?')
                    ->execute([$full_name, $email, $programme ?: null, $sid ?: null, $esid]);
            }
            $msg = 'Student updated successfully.';
        }
    }
    skip_edit_s:;
}

// ── DELETE STUDENT ──
if (isset($_GET['del']) && is_numeric($_GET['del'])) {
    $did = (int)$_GET['del'];
    if ($did !== $uid) {
        $pdo->prepare('DELETE FROM students WHERE id=?')->execute([$did]);
    }
    header('Location: students.php?deleted=1'); exit;
}

// ── TOGGLE ACTIVE ──
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $pdo->prepare('UPDATE students SET is_active=NOT is_active WHERE id=?')->execute([(int)$_GET['toggle']]);
    header('Location: students.php'); exit;
}

// ── MANUALLY VERIFY STUDENT EMAIL ──
if (isset($_GET['verify_student']) && is_numeric($_GET['verify_student'])) {
    $vid = (int)$_GET['verify_student'];
    $pdo->prepare('UPDATE students SET email_verified=1, verify_token=NULL WHERE id=?')->execute([$vid]);
    header('Location: students.php?verified=1'); exit;
}

// ── LOAD DATA — only fetch what the active tab needs ──────────────
$activeTab = $_GET['tab'] ?? 'approved';

if ($activeTab !== 'approved') {
    // Registered students tab — select only needed columns, no passwords/tokens
    $allStudents = $pdo->query("
        SELECT s.id, s.full_name, s.email, s.student_id, s.programme,
               s.department, s.profile_pic, s.is_active, s.email_verified,
               s.created_at,
               COUNT(DISTINCT e.course_id) AS n_enrolled
        FROM   students s
        LEFT JOIN enrollments e ON e.student_id = s.id
        GROUP  BY s.id
        ORDER  BY s.full_name
    ")->fetchAll();
} else {
    // Approved tab — only need counts from students, not full data
    $allStudents = $pdo->query("
        SELECT id, full_name, email, student_id, programme,
               is_active, email_verified, created_at, 0 AS n_enrolled
        FROM students
        ORDER BY full_name
    ")->fetchAll();
}

$totalStudents = count($allStudents);
$totalActive   = count(array_filter($allStudents, fn($s) => $s['is_active']));
$totalEnrolled = isset($allStudents[0]['n_enrolled'])
    ? count(array_filter($allStudents, fn($s) => $s['n_enrolled'] > 0))
    : 0;

// ── Load approved students (only on approved tab) ──────────────────
$approvedStudents = [];
$totalApproved    = 0;
$totalRegistered  = 0;
if ($activeTab === 'approved') {
    try {
        $approvedStudents = $pdo->query("
            SELECT id, student_id, email, full_name, programme,
                   year_enrolled, is_registered, added_at
            FROM approved_students
            ORDER BY added_at DESC
        ")->fetchAll();
        $totalApproved   = count($approvedStudents);
        $totalRegistered = count(array_filter($approvedStudents, fn($a) => $a['is_registered']));
    } catch(\Exception $e) {}
} else {
    // Just get counts for the tab badge without fetching all rows
    try {
        $r = $pdo->query("SELECT COUNT(*) AS t, SUM(is_registered) AS r FROM approved_students")->fetch();
        $totalApproved   = (int)($r['t'] ?? 0);
        $totalRegistered = (int)($r['r'] ?? 0);
    } catch(\Exception $e) {}
}

// Load registration window settings
$regSettings = ['window_open'=>0,'open_from'=>null,'open_until'=>null];
try {
    $rs = $pdo->query('SELECT * FROM reg_settings WHERE id=1 LIMIT 1')->fetch();
    if ($rs) $regSettings = $rs;
} catch(Exception $e) {}

$pageTitle    = 'Students';
$pageSubtitle = 'Manage All Students';
$activePage   = 'students';
$depth        = 1;

ob_start();
?>

<style>
/* ── Page tabs ── */
.page-tabs { display:flex; gap:4px; margin-bottom:22px; border-bottom:1px solid var(--border); padding-bottom:0; }
.ptab {
    padding:10px 20px; font-size:13px; font-weight:700; cursor:pointer;
    border-radius:10px 10px 0 0; color:var(--muted); border:1px solid transparent;
    border-bottom:none; text-decoration:none; transition:all .2s;
    display:inline-flex; align-items:center; gap:7px;
}
.ptab:hover { color:var(--white); background:rgba(255,255,255,.04); }
.ptab.active { color:var(--gold); background:rgba(200,168,75,.08); border-color:var(--border); border-bottom-color:var(--surface); }

/* ── Approved table ── */
.appr-table { width:100%; border-collapse:collapse; font-size:13px; }
.appr-table th { padding:10px 14px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); border-bottom:1px solid var(--border); background:rgba(0,0,0,.15); }
.appr-table td { padding:11px 14px; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; }
.appr-table tr:last-child td { border-bottom:none; }
.appr-table tr:hover td { background:rgba(200,168,75,.025); }

/* ── Import drop zone ── */
.csv-drop {
    border:2px dashed var(--border); border-radius:12px; padding:28px;
    text-align:center; cursor:pointer; transition:all .2s;
    background:rgba(200,168,75,.02);
}
.csv-drop:hover, .csv-drop.drag-over { border-color:var(--gold); background:rgba(200,168,75,.06); }
.csv-drop i { font-size:32px; color:var(--gold); display:block; margin-bottom:10px; }
.csv-drop p { color:var(--muted); font-size:13px; margin:0; }
.csv-drop strong { color:var(--white); }

/* ── Empty state ── */
.empty-state { text-align:center; padding:60px 20px; color:var(--muted); }
.empty-state i { font-size:48px; display:block; margin-bottom:14px; opacity:.4; }
.empty-state h3 { font-family:'Cinzel',serif; font-size:16px; color:var(--white); margin-bottom:8px; }

/* ── Registered students grid ── */
.students-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
    gap:16px;
    margin-bottom:20px;
}
.student-card {
    background:rgba(255,255,255,.03);
    border:1px solid var(--border);
    border-radius:12px;
    overflow:hidden;
    transition:border-color .2s, transform .15s, box-shadow .2s;
    cursor:pointer;
    display:flex;
    flex-direction:column;
}
.student-card:hover {
    border-color:rgba(200,168,75,.35);
    transform:translateY(-2px);
    box-shadow:0 6px 24px rgba(0,0,0,.3);
}
.student-card-top {
    display:flex;
    align-items:center;
    gap:12px;
    padding:16px 16px 12px;
    border-bottom:1px solid rgba(255,255,255,.05);
}
.student-avatar {
    width:42px; height:42px;
    border-radius:50%;
    background:linear-gradient(135deg,#0d2a4e,#1565c0);
    display:flex; align-items:center; justify-content:center;
    font-family:'Cinzel',serif;
    font-size:17px; font-weight:700;
    color:var(--gold);
    flex-shrink:0;
}
.student-name {
    font-weight:700; font-size:14px;
    color:var(--white);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.student-email {
    font-size:11px; color:var(--muted);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    margin-top:2px;
}
.student-card-body {
    padding:12px 16px;
    display:flex; flex-direction:column; gap:7px;
    flex:1;
}
.student-meta-row {
    display:flex; align-items:center; gap:8px;
    font-size:12px; color:var(--muted);
}
.student-meta-row i {
    width:14px; text-align:center;
    color:rgba(200,168,75,.6);
    flex-shrink:0;
}
.student-card-footer {
    display:flex; align-items:center;
    justify-content:space-between;
    padding:10px 16px;
    border-top:1px solid rgba(255,255,255,.05);
    background:rgba(0,0,0,.12);
}
.filter-tabs {
    display:flex; flex-wrap:wrap; gap:6px;
    margin-bottom:16px;
}
.ftab {
    padding:5px 14px; font-size:12px; font-weight:600;
    border-radius:20px; cursor:pointer;
    border:1px solid var(--border); color:var(--muted);
    background:transparent; transition:all .15s;
    user-select:none;
}
.ftab:hover { color:var(--white); border-color:rgba(255,255,255,.2); }
.ftab.active {
    color:var(--gold);
    background:rgba(200,168,75,.1);
    border-color:rgba(200,168,75,.35);
}
.search-wrap {
    position:relative; margin-bottom:14px;
}
.search-wrap i {
    position:absolute; left:13px; top:50%;
    transform:translateY(-50%);
    color:var(--muted); font-size:13px; pointer-events:none;
}
.search-wrap input {
    width:100%; padding:9px 12px 9px 36px;
    background:rgba(255,255,255,.04);
    border:1px solid var(--border); border-radius:8px;
    color:var(--white); font-size:13px;
    outline:none; transition:border-color .2s;
}
.search-wrap input:focus { border-color:rgba(200,168,75,.4); }

</style>

<!-- ═══════════════ FLASH MESSAGES ═══════════════ -->
<?php if ($msg): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> <?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> <?= h($err) ?></div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> Student removed.</div><?php endif; ?>
<?php if (isset($_GET['verified'])): ?><div class="alert alert-ok"><i class="fas fa-shield-alt"></i> Student email verified. They can now log in.</div><?php endif; ?>
<?php if (isset($_GET['deleted_a'])): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> Approved student removed.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> Approved student updated successfully.</div><?php endif; ?>
<?php if (isset($_GET['err'])): ?><div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> <?= h($_GET['err']) ?></div><?php endif; ?>
<?php if (isset($_GET['window_saved'])): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> Registration window scheduled. All unregistered students have been notified by email.</div><?php endif; ?>
<?php if (isset($_GET['window_closed'])): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> Registration window closed. All unregistered students have been notified by email.</div><?php endif; ?>
<?php if (isset($_GET['window_cleared'])): ?><div class="alert alert-ok"><i class="fas fa-check-circle"></i> Registration window schedule cleared. Set a new schedule to re-open.</div><?php endif; ?>
<?php if (isset($_GET['window_err'])): ?>
  <?php
    $werr = $_GET['window_err'];
    $werrMsg = match($werr) {
        'missing' => 'Both "Opens From" and "Closes At" dates are required.',
        'invalid' => 'One or both dates are invalid. Please check the format.',
        'order'   => '"Closes At" must be after "Opens From".',
        'db'      => 'Database error saving settings. Please try again.',
        default   => 'Could not save registration window settings.',
    };
  ?>
  <div class="alert alert-err"><i class="fas fa-exclamation-circle"></i> <?= h($werrMsg) ?></div>
<?php endif; ?>

<!-- ═══════════════ PAGE TABS ═══════════════ -->
<div class="page-tabs">
    <a href="students.php?tab=approved"
       class="ptab <?= $activeTab==='approved'?'active':'' ?>">
        <i class="fas fa-shield-alt"></i> Approved Registry
        <?php if($totalApproved): ?>
        <span class="badge bg-gold" style="font-size:10px;padding:2px 7px"><?= $totalApproved ?></span>
        <?php endif; ?>
    </a>
    <a href="students.php?tab=students"
       class="ptab <?= $activeTab!=='approved'?'active':'' ?>">
        <i class="fas fa-user-graduate"></i> Registered Students
        <?php if($totalStudents): ?>
        <span class="badge bg-blue" style="font-size:10px;padding:2px 7px"><?= $totalStudents ?></span>
        <?php endif; ?>
    </a>
</div>

<?php if ($activeTab === 'approved'): ?>
<!-- ═══════════════ APPROVED STUDENTS TAB ═══════════════ -->
<div class="flex-between mb2">
    <div>
        <div style="font-family:'Cinzel',serif;font-size:16px;color:var(--white)">Pre-Approved Student Registry</div>
        <div style="font-size:12px;color:var(--muted);margin-top:3px">Only students on this list can self-register</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="students.php?dl_template=1" class="btn btn-secondary btn-sm">
            <i class="fas fa-download"></i> CSV Template
        </a>
        <button class="btn btn-secondary btn-sm" onclick="openModal('m-import-csv')">
            <i class="fas fa-file-csv"></i> Import CSV
        </button>
        <button class="btn btn-secondary btn-sm" onclick="openModal('m-bulk-import')"
                style="background:rgba(39,174,96,.12);border-color:rgba(39,174,96,.35);color:#6fcf97">
            <i class="fas fa-file-upload"></i> Bulk Import
        </button>
        <button class="btn btn-primary btn-sm" onclick="openModal('m-add-approved')">
            <i class="fas fa-plus"></i> Add Student
        </button>
    </div>
</div>

<!-- ── REGISTRATION WINDOW PANEL ── -->
<?php
$windowNow   = (bool)$regSettings['window_open'];
$windowFrom  = $regSettings['open_from']  ?? null;
$windowUntil = $regSettings['open_until'] ?? null;
$nowTs       = time();

// Determine real current status
if ($windowNow && $windowFrom && $windowUntil) {
    $fromTs  = strtotime($windowFrom);
    $untilTs = strtotime($windowUntil);
    if ($nowTs < $fromTs) {
        $windowStatus = 'scheduled'; // set but not open yet
    } elseif ($nowTs >= $fromTs && $nowTs <= $untilTs) {
        $windowStatus = 'open';      // currently open
    } else {
        $windowStatus = 'expired';   // window has passed
    }
} elseif ($windowNow && !$windowFrom) {
    $windowStatus = 'open';          // manual open with no schedule
} else {
    $windowStatus = 'closed';
}

$statusConfig = match($windowStatus) {
    'open'      => ['color'=>'39,174,96',  'label'=>'OPEN',      'icon'=>'fa-door-open',   'text'=>'#4caf82'],
    'scheduled' => ['color'=>'255,183,77', 'label'=>'SCHEDULED', 'icon'=>'fa-clock',       'text'=>'#ffb74d'],
    'expired'   => ['color'=>'231,76,60',  'label'=>'EXPIRED',   'icon'=>'fa-times-circle','text'=>'#ff8a80'],
    default     => ['color'=>'231,76,60',  'label'=>'CLOSED',    'icon'=>'fa-door-closed', 'text'=>'#ff8a80'],
};
?>

<div style="background:rgba(<?= $statusConfig['color'] ?>,.06);border:1px solid rgba(<?= $statusConfig['color'] ?>,.3);border-radius:14px;padding:20px 22px;margin-bottom:20px">

  <!-- Status header -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:<?= $windowStatus!=='closed'?'16':'0' ?>px">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="width:46px;height:46px;border-radius:12px;background:rgba(<?= $statusConfig['color'] ?>,.15);border:1px solid rgba(<?= $statusConfig['color'] ?>,.3);display:flex;align-items:center;justify-content:center;font-size:20px;color:<?= $statusConfig['text'] ?>">
        <i class="fas <?= $statusConfig['icon'] ?>"></i>
      </div>
      <div>
        <div style="font-family:'Cinzel',serif;font-size:15px;color:var(--white);margin-bottom:3px">
          Registration Window
          <span style="font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px;background:rgba(<?= $statusConfig['color'] ?>,.15);color:<?= $statusConfig['text'] ?>;border:1px solid rgba(<?= $statusConfig['color'] ?>,.3);margin-left:8px;font-family:'Raleway',sans-serif">
            <?= $statusConfig['label'] ?>
          </span>
        </div>
        <?php if($windowFrom && $windowUntil): ?>
        <div style="font-size:12px;color:var(--muted);display:flex;gap:16px;flex-wrap:wrap;margin-top:2px">
          <span><i class="fas fa-play-circle" style="color:#4caf82;margin-right:4px"></i> Opens: <strong style="color:var(--white)"><?= date('M j, Y · g:i A', strtotime($windowFrom)) ?></strong></span>
          <span><i class="fas fa-stop-circle" style="color:#ff8a80;margin-right:4px"></i> Closes: <strong style="color:var(--white)"><?= date('M j, Y · g:i A', strtotime($windowUntil)) ?></strong></span>
        </div>
        <?php if($windowStatus === 'open'): ?>
        <div style="font-size:11px;color:#4caf82;margin-top:4px"><i class="fas fa-hourglass-half"></i> Closes in: <strong id="countdown-display">calculating…</strong></div>
        <?php elseif($windowStatus === 'scheduled'): ?>
        <div style="font-size:11px;color:#ffb74d;margin-top:4px"><i class="fas fa-hourglass-start"></i> Opens in: <strong id="countdown-display">calculating…</strong></div>
        <?php endif; ?>
        <?php else: ?>
        <div style="font-size:12px;color:var(--muted);margin-top:2px">No schedule set — registration is closed.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Action buttons -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <!-- Open Immediately shortcut -->
      <?php if($windowStatus === 'closed' || $windowStatus === 'expired'): ?>
      <a href="students.php?open_now=1&tab=approved"
         class="btn btn-sm"
         style="background:rgba(39,174,96,.15);border:1px solid rgba(39,174,96,.4);color:#4caf82"
         onclick="return confirm('Open registration window RIGHT NOW for 7 days?')">
        <i class="fas fa-door-open"></i> Open Now
      </a>
      <?php endif; ?>

      <button class="btn btn-secondary btn-sm" onclick="toggleWindowForm()">
        <i class="fas fa-calendar-alt"></i> <?= ($windowFrom ? 'Edit Schedule' : 'Set Schedule') ?>
      </button>

      <?php if($windowFrom || $windowUntil): ?>
      <a href="students.php?clear_window=1&tab=approved"
         class="btn btn-sm" style="background:rgba(255,183,77,.1);border:1px solid rgba(255,183,77,.3);color:#ffb74d"
         onclick="return confirm('Clear the current schedule dates? This will close the window.')">
        <i class="fas fa-eraser"></i> Clear
      </a>
      <?php endif; ?>

      <?php if($windowStatus !== 'closed' && $windowStatus !== 'expired'): ?>
      <a href="students.php?close_window=1&tab=approved"
         class="btn btn-sm" style="background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.3);color:#ff8a80"
         onclick="return confirm('Close the registration window now?')">
        <i class="fas fa-ban"></i> Close Window
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Configure form -->
  <div id="window-form" style="display:none;border-top:1px solid rgba(255,255,255,.07);padding-top:18px;margin-top:4px">
    <form method="POST" action="students.php?tab=approved" onsubmit="return validateWindowForm()">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px">
        <div>
          <label style="font-size:11px;font-weight:700;color:#4caf82;text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:7px">
            <i class="fas fa-play-circle"></i> Opens From *
          </label>
          <input class="fc" type="datetime-local" name="open_from" id="inp-open-from"
                 value="<?= $windowFrom ? date('Y-m-d\TH:i', strtotime($windowFrom)) : '' ?>"
                 style="border-color:rgba(39,174,96,.4)" required>
          <div style="display:flex;gap:6px;margin-top:6px">
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px;padding:4px 10px" onclick="setToNow('inp-open-from')">
              <i class="fas fa-clock"></i> Set to Now
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px;padding:4px 10px;color:#ff8a80;border-color:rgba(231,76,60,.3)" onclick="clearField('inp-open-from')">
              <i class="fas fa-times"></i> Clear
            </button>
          </div>
          <div style="font-size:10px;color:var(--muted);margin-top:4px">When registration opens for students</div>
        </div>

        <div>
          <label style="font-size:11px;font-weight:700;color:#ff8a80;text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:7px">
            <i class="fas fa-stop-circle"></i> Closes At *
          </label>
          <input class="fc" type="datetime-local" name="open_until" id="inp-open-until"
                 value="<?= $windowUntil ? date('Y-m-d\TH:i', strtotime($windowUntil)) : '' ?>"
                 style="border-color:rgba(231,76,60,.4)" required>
          <div style="display:flex;gap:6px;margin-top:6px">
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px;padding:4px 10px" onclick="setQuick('inp-open-until','inp-open-from',60)">
              <i class="fas fa-plus"></i> +1 Hour
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px;padding:4px 10px" onclick="setQuick('inp-open-until','inp-open-from',1440)">
              <i class="fas fa-plus"></i> +1 Day
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px;padding:4px 10px" onclick="setQuick('inp-open-until','inp-open-from',10080)">
              <i class="fas fa-plus"></i> +7 Days
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px;padding:4px 10px;color:#ff8a80;border-color:rgba(231,76,60,.3)" onclick="clearField('inp-open-until')">
              <i class="fas fa-times"></i> Clear
            </button>
          </div>
          <div style="font-size:10px;color:var(--muted);margin-top:4px">When registration automatically closes</div>
        </div>
      </div>

      <div id="window-form-err" style="display:none;background:rgba(231,76,60,.1);border:1px solid rgba(231,76,60,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#ff8a80;margin-bottom:14px">
        <i class="fas fa-exclamation-circle"></i> <span id="window-form-err-msg"></span>
      </div>

      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-primary btn-sm" type="submit" name="do_save_window">
          <i class="fas fa-calendar-check"></i> Save Schedule
        </button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleWindowForm()">Cancel</button>
        <span style="font-size:11px;color:var(--muted);margin-left:4px">
          <i class="fas fa-info-circle"></i> Registration opens and closes automatically based on the schedule.
        </span>
      </div>
    </form>
  </div>
</div>

<!-- ── HOW IT WORKS ── -->
<div style="background:rgba(200,168,75,.06);border:1px solid rgba(200,168,75,.2);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px">
    <i class="fas fa-shield-alt" style="color:var(--gold);font-size:16px;margin-top:1px;flex-shrink:0"></i>
    <div style="font-size:12px;color:var(--muted);line-height:1.7">
        <strong style="color:var(--white)">How it works:</strong>
        Students must be on this list to register. Their
        <strong style="color:#64b5f6">Student ID</strong> and
        <strong style="color:#64b5f6">Email</strong> must both match an entry here.
        Registration is only allowed during the configured window below.
        After registering, students verify their email before they can log in.
    </div>
</div>

<?php if (empty($approvedStudents)): ?>
<div class="card empty-state">
    <i class="fas fa-shield-alt"></i>
    <h3>No Pre-Approved Students Yet</h3>
    <p>Import a CSV file or add students one by one to build your approved registry.</p>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:20px;flex-wrap:wrap">
        <button class="btn btn-primary" onclick="openModal('m-import-csv')"><i class="fas fa-file-csv"></i> Import CSV</button>
        <button class="btn btn-secondary" onclick="openModal('m-add-approved')"><i class="fas fa-plus"></i> Add Manually</button>
    </div>
</div>
<?php else: ?>

<!-- Search approved -->
<div class="search-wrap">
    <i class="fas fa-search"></i>
    <input type="text" id="approved-search" placeholder="Search by name, ID or email…" oninput="filterApproved()">
</div>

<div class="card" style="padding:0;overflow:hidden">
<div class="tbl-wrap">
<table class="appr-table">
    <thead><tr>
        <th>#</th>
        <th>Student</th>
        <th>Student ID</th>
        <th>Programme</th>
        <th>Year Enrolled</th>
        <th>Status</th>
        <th>Added</th>
        <th>Action</th>
    </tr></thead>
    <tbody id="approved-tbody">
    <?php foreach($approvedStudents as $i => $a): ?>
    <tr data-search="<?= strtolower(h($a['full_name']).' '.h($a['student_id']).' '.h($a['email'])) ?>">
        <td style="color:var(--muted);font-size:12px"><?= $i+1 ?></td>
        <td>
            <div style="font-weight:700;color:var(--white)"><?= h($a['full_name']) ?></div>
            <div style="font-size:11px;color:var(--muted)"><?= h($a['email']) ?></div>
        </td>
        <td><span style="font-family:monospace;font-size:13px;color:var(--gold);font-weight:700"><?= h($a['student_id']) ?></span></td>
        <td><span class="badge bg-blue" style="font-size:10px"><?= h($a['programme']) ?></span></td>
        <td style="color:var(--muted);font-size:12px"><?= h($a['year_enrolled']) ?></td>
        <td>
            <?php if($a['is_registered']): ?>
            <span class="badge bg-green" style="font-size:10px"><i class="fas fa-check-circle"></i> Registered</span>
            <?php else: ?>
            <span class="badge bg-muted" style="font-size:10px"><i class="fas fa-clock"></i> Pending</span>
            <?php endif; ?>
        </td>
        <td style="color:var(--muted);font-size:11px"><?= date('M d, Y', strtotime($a['added_at'])) ?></td>
        <td>
            <div class="flex gap">
            <button class="btn btn-secondary btn-sm" title="Edit"
                onclick="openEditApproved(
                    <?= $a['id'] ?>,
                    '<?= h(addslashes($a['full_name'])) ?>',
                    '<?= h(addslashes($a['student_id'])) ?>',
                    '<?= h(addslashes($a['email'])) ?>',
                    '<?= h($a['programme']) ?>',
                    '<?= h($a['year_enrolled']) ?>'
                )">
                <i class="fas fa-edit"></i>
            </button>
            <?php if(!$a['is_registered']): ?>
            <a href="students.php?del_approved=<?= $a['id'] ?>&tab=approved"
               class="btn btn-danger btn-sm"
               onclick="return confirm('Remove <?= h(addslashes($a['full_name'])) ?> from the approved list?')">
                <i class="fas fa-trash"></i>
            </a>
            <?php else: ?>
            <span style="font-size:11px;color:var(--muted);padding:4px 6px" title="Cannot remove — student has already registered">
                <i class="fas fa-lock"></i>
            </span>
            <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
<div id="approved-no-results" style="display:none" class="empty-state">
    <i class="fas fa-search"></i><h3>No Matches</h3><p>Try a different search term.</p>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ═══════════════ REGISTERED STUDENTS TAB ═══════════════ -->


    <div class="stat-card" style="--sc:#e74c3c">
        <div class="stat-ico" style="background:rgba(231,76,60,.15);color:#ff8a80"><i class="fas fa-ban"></i></div>
        <div><div class="stat-val"><?= $totalStudents - $totalActive ?></div><div class="stat-lbl">Disabled</div></div>
    </div>
</div>

<!-- Header -->
<div class="flex-between mb2">
    <div>
        <div style="font-family:'Cinzel',serif;font-size:18px;color:var(--white)">All Students</div>
        <div style="font-size:12px;color:var(--muted);margin-top:3px"><?= $totalStudents ?> registered student<?= $totalStudents!=1?'s':'' ?></div>
    </div>
    <button class="btn btn-primary" onclick="openModal('m-add-student')">
        <i class="fas fa-user-plus"></i> Add Student
    </button>
</div>

<!-- Search -->
<div class="search-wrap">
    <i class="fas fa-search"></i>
    <input type="text" id="student-search" placeholder="Search by name, email or student ID…" oninput="filterStudents()">
</div>
<!-- Programme filter -->
<div class="filter-tabs">
    <span class="ftab active" onclick="setProgFilter(this,'all')">All</span>
    <span class="ftab" onclick="setProgFilter(this,'IT')">IT</span>
    <span class="ftab" onclick="setProgFilter(this,'CS')">CS</span>
    <span class="ftab" onclick="setProgFilter(this,'CE')">CE</span>
    <span class="ftab" onclick="setProgFilter(this,'active')">Active Only</span>
    <span class="ftab" onclick="setProgFilter(this,'disabled')">Disabled</span>
</div>

<!-- Students grid -->
<?php if (empty($allStudents)): ?>
<div class="empty-state">
    <i class="fas fa-user-graduate"></i>
    <h3>No Students Yet</h3>
    <p>Add your first student to get started.</p>

</div>
<?php else: ?>
<div class="students-grid" id="students-grid">
<?php foreach ($allStudents as $s): ?>
<div class="student-card"
     data-search="<?= strtolower(h($s['full_name']).' '.h($s['email']).' '.h($s['student_id']??'')) ?>"
     data-programme="<?= strtolower(h($s['programme'] ?? '')) ?>"
     data-active="<?= $s['is_active'] ? 'active' : 'disabled' ?>">

    <div class="student-card-top">
        <div class="student-avatar"><?= strtoupper(mb_substr($s['full_name'],0,1)) ?></div>
        <div style="min-width:0;flex:1">
            <div class="student-name"><?= h($s['full_name']) ?></div>
            <div class="student-email" title="<?= h($s['email']) ?>"><?= h($s['email']) ?></div>
        </div>
    </div>

    <div class="student-card-body">
        <div class="student-meta-row">
            <i class="fas fa-id-card"></i>
            <?= $s['student_id'] ? h($s['student_id']) : '<span style="color:var(--muted);font-style:italic">No ID assigned</span>' ?>
        </div>
        <div class="student-meta-row">
            <i class="fas fa-graduation-cap"></i>
            <?= $s['programme'] ? h($s['programme']) : '<span style="color:var(--muted);font-style:italic">No programme</span>' ?>
        </div>
        <div class="student-meta-row">
            <i class="fas fa-book-open"></i>
            <?= $s['n_enrolled'] ?> course<?= $s['n_enrolled']!=1?'s':'' ?> enrolled
        </div>
        <div class="student-meta-row">
            <i class="fas fa-calendar"></i>
            Joined <?= date('M d, Y', strtotime($s['created_at'])) ?>
        </div>
    </div>

    <div class="student-card-footer">
        <div style="display:flex;flex-direction:column;gap:5px">
            <span class="badge <?= $s['is_active'] ? 'bg-green' : 'bg-red' ?>">
                <?= $s['is_active'] ? 'Active' : 'Disabled' ?>
            </span>
            <?php if($s['email_verified']): ?>
            <span class="badge bg-green" style="font-size:9px;background:rgba(39,174,96,.12);color:#4caf82;border-color:rgba(39,174,96,.3)">
                <i class="fas fa-envelope-circle-check"></i> Email Verified
            </span>
            <?php else: ?>
            <span class="badge bg-muted" style="font-size:9px;color:#ffb74d;background:rgba(255,183,77,.1);border-color:rgba(255,183,77,.3)">
                <i class="fas fa-envelope"></i> Unverified
            </span>
            <?php endif; ?>
        </div>
        <div class="flex gap">
            <button class="btn btn-secondary btn-sm" title="Edit"
                onclick="openEditStudent(
                    <?= $s['id'] ?>,
                    <?= htmlspecialchars(json_encode($s['full_name']), ENT_QUOTES) ?>,
                    <?= htmlspecialchars(json_encode($s['email']), ENT_QUOTES) ?>,
                    <?= htmlspecialchars(json_encode($s['programme'] ?? ''), ENT_QUOTES) ?>,
                    <?= htmlspecialchars(json_encode($s['student_id'] ?? ''), ENT_QUOTES) ?>
                )">
                <i class="fas fa-edit"></i>
            </button>
            <?php if(!$s['email_verified']): ?>
            <a href="students.php?verify_student=<?= $s['id'] ?>"
               class="btn btn-secondary btn-sm"
               style="color:#4caf82;border-color:rgba(39,174,96,.4)"
               title="Manually verify this student's email so they can log in"
               onclick="return confirm('Manually verify <?= h(addslashes($s['full_name'])) ?>\'s email? They will be able to log in immediately.')">
                <i class="fas fa-user-check"></i>
            </a>
            <?php endif; ?>
            <a href="students.php?toggle=<?= $s['id'] ?>" class="btn btn-secondary btn-sm"
               title="<?= $s['is_active'] ? 'Disable' : 'Enable' ?> account">
                <i class="fas fa-<?= $s['is_active'] ? 'ban' : 'check' ?>"></i>
            </a>
            <a href="students.php?del=<?= $s['id'] ?>" class="btn btn-danger btn-sm"
               onclick="return confirm('Permanently delete this student? This cannot be undone.')">
                <i class="fas fa-trash"></i>
            </a>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<div id="no-results" style="display:none" class="empty-state">
    <i class="fas fa-search"></i>
    <h3>No Matches</h3>
    <p>Try a different search or filter.</p>
</div>
<?php endif; ?>

<?php endif; /* end registered students tab */ ?>


<!-- ===== BULK IMPORT STUDENTS MODAL ===== -->
<div class="modal-wrap" id="m-bulk-import">
  <div class="modal-box" style="max-width:580px">
    <div class="modal-head">
      <h3><i class="fas fa-file-upload" style="color:#6fcf97"></i> Bulk Import Students</h3>
      <button class="modal-close" onclick="closeModal('m-bulk-import')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">

      <!-- Info block -->
      <div style="background:rgba(39,174,96,.07);border:1px solid rgba(39,174,96,.25);border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:12px;color:#6fcf97;line-height:1.8">
        <i class="fas fa-shield-alt" style="margin-right:5px"></i>
        <strong style="color:#fff">Secure bulk import.</strong>
        Each student is added to the <strong>approved list</strong> with a unique registration code automatically generated.
        Students then self-register on the login page using their Student ID, Email, and Code.<br><br>
        <strong>Required columns:</strong>
        <code style="background:rgba(0,0,0,.3);padding:2px 7px;border-radius:4px;color:#fff">full_name, student_id, email, programme, year_enrolled</code><br>
        <strong>Programme values:</strong> <code style="background:rgba(0,0,0,.3);padding:2px 7px;border-radius:4px;color:#fff">IT, CS, CE, DIT</code><br><br>
        <a href="students.php?dl_bulk_template=1" style="color:#6fcf97;text-decoration:none;font-weight:700">
          <i class="fas fa-download"></i> Download CSV Template
        </a>
        &nbsp;&nbsp;
        <a href="students.php?dl_codes=1" style="color:var(--gold);text-decoration:none;font-weight:700">
          <i class="fas fa-key"></i> Download All Codes After Import
        </a>
      </div>

      <!-- Upload form -->
      <form method="POST" enctype="multipart/form-data">
        <div class="fg">
          <label class="lbl2">Select CSV File *</label>
          <div class="csv-drop" id="bulk-drop-zone"
               onclick="document.getElementById('bulk_csv').click()"
               style="border-color:rgba(39,174,96,.35)">
            <i class="fas fa-cloud-upload-alt" style="color:#6fcf97;font-size:34px;margin-bottom:10px;display:block"></i>
            <p style="color:rgba(255,255,255,.7)"><strong>Click to choose CSV</strong> or drag &amp; drop</p>
            <p style="font-size:11px;color:rgba(255,255,255,.3);margin-top:6px">Accepted: .csv files only</p>
            <p id="bulk-filename" style="margin-top:10px;font-size:13px;color:#6fcf97;display:none;font-weight:700"></p>
          </div>
          <input type="file" name="bulk_csv" id="bulk_csv" accept=".csv,text/csv"
                 style="display:none" onchange="showBulkFileName(this)">
        </div>

        <!-- What happens next info -->
        <div style="background:rgba(0,0,0,.15);border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:12px;color:rgba(255,255,255,.5);line-height:1.7">
          <strong style="color:rgba(255,255,255,.7)">What happens after import:</strong><br>
          ✅ Each student is added to the approved list<br>
          🔑 A unique registration code is auto-generated per student<br>
          📥 Download the codes CSV and distribute to students<br>
          🔓 Students self-register using their ID + Email + Code
        </div>

        <button class="btn btn-primary" style="width:100%;background:linear-gradient(135deg,#1b5e20,#2e7d32);font-size:14px;padding:13px" type="submit" name="do_bulk_import">
          <i class="fas fa-file-upload"></i> Import &amp; Generate Codes
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ===== IMPORT CSV MODAL ===== -->
<div class="modal-wrap" id="m-import-csv">
  <div class="modal-box" style="max-width:540px">
    <div class="modal-head">
      <h3><i class="fas fa-file-csv" style="color:var(--gold)"></i> Import Students from CSV</h3>
      <button class="modal-close" onclick="closeModal('m-import-csv')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom:16px;padding:12px 16px;background:rgba(41,128,185,.08);border:1px solid rgba(41,128,185,.25);border-radius:8px;font-size:12px;color:#64b5f6;line-height:1.7">
        <i class="fas fa-info-circle"></i>
        &nbsp;CSV must have these columns (in any order):<br>
        <code style="background:rgba(0,0,0,.25);padding:2px 6px;border-radius:4px;color:#fff">full_name, student_id, email, programme, year_enrolled</code><br>
        Programme values: <strong>IT</strong>, <strong>CS</strong>, <strong>CE</strong>, <strong>DIT</strong>.
        <a href="students.php?dl_template=1" style="color:var(--gold);text-decoration:none;margin-left:6px">
          <i class="fas fa-download"></i> Download Template
        </a>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <div class="fg">
          <label class="lbl2">Select CSV File *</label>
          <div class="csv-drop" id="csv-drop-zone" onclick="document.getElementById('csv_file').click()">
            <i class="fas fa-cloud-upload-alt"></i>
            <p><strong>Click to choose a CSV file</strong><br>or drag and drop it here</p>
            <p id="csv-filename" style="margin-top:8px;font-size:12px;color:var(--gold);display:none"></p>
          </div>
          <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv"
                 style="display:none" onchange="showFileName(this)">
        </div>
        <button class="btn btn-primary" style="width:100%" type="submit" name="do_import_csv">
          <i class="fas fa-upload"></i> Import Students
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ===== ADD SINGLE APPROVED STUDENT MODAL ===== -->
<div class="modal-wrap" id="m-add-approved">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-head">
      <h3><i class="fas fa-user-shield" style="color:var(--gold)"></i> Add to Approved List</h3>
      <button class="modal-close" onclick="closeModal('m-add-approved')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" action="students.php?tab=approved">
        <div class="g2">
          <div class="fg">
            <label class="lbl2">Full Name *</label>
            <input class="fc" type="text" name="a_name" placeholder="e.g. Kofi Mensah" required>
          </div>
          <div class="fg">
            <label class="lbl2">Student ID *</label>
            <input class="fc" type="text" name="a_sid" placeholder="e.g. BIT2244901" required
                   oninput="this.value=this.value.toUpperCase()">
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Email Address *</label>
          <input class="fc" type="email" name="a_email" placeholder="e.g. kofi.mensah@st.rmu.edu.gh" required>
        </div>
        <div class="g2">
          <div class="fg">
            <label class="lbl2">Programme *</label>
            <select class="fc" name="a_programme" required>
              <option value="IT">Information Technology (IT)</option>
              <option value="CS">Computer Science (CS)</option>
              <option value="CE">Computer Engineering (CE)</option>
              <option value="DIT">Diploma in IT (DIT)</option>
            </select>
          </div>
          <div class="fg">
            <label class="lbl2">Year Enrolled *</label>
            <input class="fc" type="number" name="a_year" value="<?= date('Y') ?>"
                   min="2000" max="<?= date('Y')+2 ?>" required>
          </div>
        </div>
        <button class="btn btn-primary" style="width:100%" type="submit" name="do_add_approved">
          <i class="fas fa-user-shield"></i> Add to Approved List
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ===== EDIT APPROVED STUDENT MODAL ===== -->
<div class="modal-wrap" id="m-edit-approved">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-head">
      <h3><i class="fas fa-user-edit" style="color:var(--gold)"></i> Edit Approved Student</h3>
      <button class="modal-close" onclick="closeModal('m-edit-approved')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" action="students.php">
        <input type="hidden" name="ea_id" id="ea_id">
        <div class="g2">
          <div class="fg">
            <label class="lbl2">Full Name *</label>
            <input class="fc" type="text" name="ea_name" id="ea_name" placeholder="e.g. Kofi Mensah" required>
          </div>
          <div class="fg">
            <label class="lbl2">Student ID *</label>
            <input class="fc" type="text" name="ea_sid" id="ea_sid" placeholder="e.g. BIT2244901" required
                   oninput="this.value=this.value.toUpperCase()">
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Email Address *</label>
          <input class="fc" type="email" name="ea_email" id="ea_email" placeholder="e.g. kofi.mensah@st.rmu.edu.gh" required>
        </div>
        <div class="g2">
          <div class="fg">
            <label class="lbl2">Programme *</label>
            <select class="fc" name="ea_programme" id="ea_programme" required>
              <option value="IT">Information Technology (IT)</option>
              <option value="CS">Computer Science (CS)</option>
              <option value="CE">Computer Engineering (CE)</option>
              <option value="DIT">Diploma in IT (DIT)</option>
            </select>
          </div>
          <div class="fg">
            <label class="lbl2">Year Enrolled *</label>
            <input class="fc" type="number" name="ea_year" id="ea_year"
                   min="2000" max="<?= date('Y')+2 ?>" required>
          </div>
        </div>
        <div style="background:rgba(255,183,77,.06);border:1px solid rgba(255,183,77,.2);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:rgba(255,255,255,.6)">
          <i class="fas fa-exclamation-triangle" style="color:#ffb74d"></i>
          &nbsp;If this student has already registered, changing their Student ID or Email will prevent them from logging in. Edit with care.
        </div>
        <button class="btn btn-primary" style="width:100%" type="submit" name="do_edit_approved">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </form>
    </div>
  </div>
</div>

<script>
function toggleWindowForm() {
    var f = document.getElementById('window-form');
    f.style.display = f.style.display === 'block' ? 'none' : 'block';
    if (f.style.display === 'block') {
        // Auto-focus the from field if empty
        var from = document.getElementById('inp-open-from');
        if (from && !from.value) from.focus();
    }
}

// Set a datetime-local input to the current date/time (rounded to nearest minute)
function setToNow(inputId) {
    var inp = document.getElementById(inputId);
    if (!inp) return;
    var now = new Date();
    now.setSeconds(0, 0);
    var pad = function(n){ return String(n).padStart(2,'0'); };
    inp.value = now.getFullYear() + '-' + pad(now.getMonth()+1) + '-' + pad(now.getDate())
              + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
    inp.dispatchEvent(new Event('change'));
}

// Clear a field
function clearField(inputId) {
    var inp = document.getElementById(inputId);
    if (inp) { inp.value = ''; inp.dispatchEvent(new Event('change')); }
}

// Set close time = open time + offsetMinutes
function setQuick(targetId, sourceId, offsetMinutes) {
    var src = document.getElementById(sourceId);
    var tgt = document.getElementById(targetId);
    if (!src || !tgt) return;
    // If source is empty, use now
    var base = src.value ? new Date(src.value) : new Date();
    base.setSeconds(0, 0);
    base.setMinutes(base.getMinutes() + offsetMinutes);
    var pad = function(n){ return String(n).padStart(2,'0'); };
    tgt.value = base.getFullYear() + '-' + pad(base.getMonth()+1) + '-' + pad(base.getDate())
              + 'T' + pad(base.getHours()) + ':' + pad(base.getMinutes());
    tgt.dispatchEvent(new Event('change'));
}

function validateWindowForm() {
    var from  = document.getElementById('inp-open-from').value;
    var until = document.getElementById('inp-open-until').value;
    var errBox = document.getElementById('window-form-err');
    var errMsg = document.getElementById('window-form-err-msg');
    errBox.style.display = 'none';
    if (!from || !until) {
        errMsg.textContent = 'Both "Opens From" and "Closes At" are required.';
        errBox.style.display = 'block'; return false;
    }
    if (new Date(until) <= new Date(from)) {
        errMsg.textContent = '"Closes At" must be after "Opens From".';
        errBox.style.display = 'block'; return false;
    }
    return true;
}

// ── Live countdown ──
<?php if(in_array($windowStatus, ['open','scheduled'])): ?>
(function() {
    var targetTs = <?= $windowStatus === 'open' ? strtotime($windowUntil) * 1000 : strtotime($windowFrom) * 1000 ?>;
    var display  = document.getElementById('countdown-display');
    if (!display) return;
    function tick() {
        var diff = Math.floor((targetTs - Date.now()) / 1000);
        if (diff <= 0) { display.textContent = '— refreshing…'; location.reload(); return; }
        var d = Math.floor(diff/86400);
        var h = Math.floor((diff%86400)/3600);
        var m = Math.floor((diff%3600)/60);
        var s = diff%60;
        var parts = [];
        if (d > 0) parts.push(d+'d');
        if (h > 0 || d > 0) parts.push(h+'h');
        parts.push(String(m).padStart(2,'0')+'m');
        parts.push(String(s).padStart(2,'0')+'s');
        display.textContent = parts.join(' ');
    }
    tick(); setInterval(tick, 1000);
})();
<?php endif; ?>

// Auto-open form after save or error
<?php if(isset($_GET['window_saved']) || isset($_GET['window_err'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    var f = document.getElementById('window-form');
    if (f) f.style.display = 'block';
});
<?php endif; ?>

function openEditApproved(id, name, sid, email, programme, year) {
    document.getElementById('ea_id').value        = id;
    document.getElementById('ea_name').value      = name;
    document.getElementById('ea_sid').value       = sid;
    document.getElementById('ea_email').value     = email;
    document.getElementById('ea_programme').value = programme;
    document.getElementById('ea_year').value      = year;
    openModal('m-edit-approved');
}
</script>
<div class="modal-wrap" id="m-add-student">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-head">
      <h3><i class="fas fa-user-plus" style="color:#64b5f6"></i> Add New Student</h3>
      <button class="modal-close" onclick="closeModal('m-add-student')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <div class="g2">
          <div class="fg">
            <label class="lbl2">Full Name *</label>
            <input class="fc" type="text" name="s_name" placeholder="e.g. Kwame Asante" required>
          </div>
          <div class="fg">
            <label class="lbl2">Student ID</label>
            <input class="fc" type="text" name="s_sid" placeholder="RMU/2024/001">
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Email Address *</label>
          <input class="fc" type="email" name="s_email" placeholder="e.g. kwame.asante@st.rmu.edu.gh" required>
        </div>
        <div class="fg">
          <label class="lbl2">Programme</label>
          <select class="fc" name="s_programme">
            <option value="">— Select Programme —</option>
            <option value="IT">Information Technology (IT)</option>
            <option value="CS">Computer Science (CS)</option>
            <option value="CE">Computer Engineering (CE)</option>
          </select>
        </div>
        <div class="fg" style="background:rgba(30,144,255,.07);border:1px solid rgba(30,144,255,.2);border-radius:8px;padding:11px 14px">
          <i class="fas fa-info-circle" style="color:#63b3ff;margin-right:6px"></i>
          <span style="font-size:12px;color:#a0b4c8">No password needed — the student will set their own when they register on the login page.</span>
        </div>
        <button class="btn btn-primary" style="width:100%;background:linear-gradient(135deg,#1565c0,#1976d2)" type="submit" name="do_create_student">
          <i class="fas fa-user-plus"></i> Create Student Account
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ===== EDIT STUDENT MODAL ===== -->
<div class="modal-wrap" id="m-edit-student">
  <div class="modal-box" style="max-width:520px">
    <div class="modal-head">
      <h3><i class="fas fa-user-edit" style="color:var(--gold)"></i> Edit Student</h3>
      <button class="modal-close" onclick="closeModal('m-edit-student')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="es_id" id="es_id">
        <div class="g2">
          <div class="fg">
            <label class="lbl2">Full Name *</label>
            <input class="fc" type="text" name="es_name" id="es_name" required>
          </div>
          <div class="fg">
            <label class="lbl2">Student ID</label>
            <input class="fc" type="text" name="es_sid" id="es_sid">
          </div>
        </div>
        <div class="fg">
          <label class="lbl2">Email Address *</label>
          <input class="fc" type="email" name="es_email" id="es_email" required>
        </div>
        <div class="fg">
          <label class="lbl2">Programme</label>
          <select class="fc" name="es_programme" id="es_programme">
            <option value="">— Select Programme —</option>
            <option value="IT">Information Technology (IT)</option>
            <option value="CS">Computer Science (CS)</option>
            <option value="CE">Computer Engineering (CE)</option>
          </select>
        </div>

        <button class="btn btn-primary" style="width:100%" type="submit" name="do_edit_student">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </form>
    </div>
  </div>
</div>

<script>
var activeProg = 'all';

function filterStudents() {
    var q = document.getElementById('student-search').value.toLowerCase().trim();
    var cards = document.querySelectorAll('#students-grid .student-card');
    var visible = 0;
    cards.forEach(function(card) {
        var matchSearch = !q || card.getAttribute('data-search').indexOf(q) !== -1;
        var matchProg;
        if (activeProg === 'all') {
            matchProg = true;
        } else if (activeProg === 'active') {
            matchProg = card.getAttribute('data-active') === 'active';
        } else if (activeProg === 'disabled') {
            matchProg = card.getAttribute('data-active') === 'disabled';
        } else {
            matchProg = card.getAttribute('data-programme') === activeProg;
        }
        var show = matchSearch && matchProg;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var noRes = document.getElementById('no-results');
    if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
}

function setProgFilter(el, prog) {
    activeProg = prog;
    document.querySelectorAll('.ftab').forEach(function(t){ t.classList.remove('active'); });
    el.classList.add('active');
    filterStudents();
}

function openEditStudent(id, name, email, programme, sid) {
    document.getElementById('es_id').value         = id;
    document.getElementById('es_name').value       = name;
    document.getElementById('es_email').value      = email;
    document.getElementById('es_programme').value  = programme;
    document.getElementById('es_sid').value        = sid;
    openModal('m-edit-student');
}

// ── Approved students search ──
function filterApproved() {
    var q = (document.getElementById('approved-search')?.value || '').toLowerCase().trim();
    var rows = document.querySelectorAll('#approved-tbody tr');
    var visible = 0;
    rows.forEach(function(row) {
        var show = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var noRes = document.getElementById('approved-no-results');
    if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
}

// ── CSV file drop zone ──
function showBulkFileName(inp) {
    var label = document.getElementById('bulk-filename');
    if (inp.files && inp.files[0]) {
        label.textContent = '✓ ' + inp.files[0].name;
        label.style.display = 'block';
    }
}

var bulkDropZone = document.getElementById('bulk-drop-zone');
if (bulkDropZone) {
    bulkDropZone.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
    bulkDropZone.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
    bulkDropZone.addEventListener('drop', function(e) {
        e.preventDefault(); this.classList.remove('drag-over');
        var file = e.dataTransfer.files[0];
        if (file) {
            var input = document.getElementById('bulk_csv');
            var dt = new DataTransfer(); dt.items.add(file); input.files = dt.files;
            showBulkFileName(input);
        }
    });
}

function showFileName(inp) {
    var label = document.getElementById('csv-filename');
    if (inp.files && inp.files[0]) {
        label.textContent = '✓ ' + inp.files[0].name;
        label.style.display = 'block';
    }
}

var dropZone = document.getElementById('csv-drop-zone');
if (dropZone) {
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault(); this.classList.add('drag-over');
    });
    dropZone.addEventListener('dragleave', function() {
        this.classList.remove('drag-over');
    });
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault(); this.classList.remove('drag-over');
        var file = e.dataTransfer.files[0];
        if (file) {
            var input = document.getElementById('csv_file');
            var dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            showFileName(input);
        }
    });
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
