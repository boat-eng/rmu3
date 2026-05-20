<?php
require_once '../includes/config.php';
requireRole('instructor');

$uid = (int)$_SESSION['user_id'];
$qid = (int)($_GET['id'] ?? 0);

// Load quiz
$quiz = $pdo->prepare('SELECT q.*, c.title AS course_title, c.id AS course_id FROM quizzes q JOIN courses c ON c.id=q.course_id WHERE q.id=? AND q.created_by=? LIMIT 1');
$quiz->execute([$qid, $uid]);
$quiz = $quiz->fetch();
if (!$quiz) { header('Location: courses.php'); exit; }

$cid = $quiz['course_id'];

// ── AJAX: Add Question ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_add_question']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $qtext = trim($_POST['q_text'] ?? '');
    $qtype = $_POST['q_type'] ?? 'mcq';
    $marks = max(1, (int)($_POST['q_marks'] ?? 5));

    if (!$qtext) { echo json_encode(['ok'=>false,'msg'=>'Question text is required.']); exit; }
    if (!in_array($qtype, ['mcq','truefalse'])) { echo json_encode(['ok'=>false,'msg'=>'Invalid question type.']); exit; }

    $ord = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE quiz_id=?');
    $ord->execute([$qid]); $ord = (int)$ord->fetchColumn();

    $pdo->prepare('INSERT INTO quiz_questions (quiz_id,question,type,marks,sort_order) VALUES (?,?,?,?,?)')
        ->execute([$qid, $qtext, $qtype, $marks, $ord]);
    $newQqid = (int)$pdo->lastInsertId();

    $options = [];
    if ($qtype === 'mcq') {
        $optTexts = $_POST['opt_text'] ?? [];
        $correct  = (int)($_POST['opt_correct'] ?? 0);
        foreach ($optTexts as $i => $optText) {
            $optText = trim($optText);
            if ($optText) {
                $pdo->prepare('INSERT INTO quiz_options (question_id,option_text,is_correct,sort_order) VALUES (?,?,?,?)')
                    ->execute([$newQqid, $optText, ($i === $correct ? 1 : 0), $i]);
                $options[] = ['text'=>$optText,'correct'=>($i===$correct)];
            }
        }
    } elseif ($qtype === 'truefalse') {
        $correct = trim($_POST['tf_correct'] ?? 'true');
        $pdo->prepare('INSERT INTO quiz_options (question_id,option_text,is_correct,sort_order) VALUES (?,?,?,?)')
            ->execute([$newQqid, 'True', $correct==='true' ? 1 : 0, 0]);
        $pdo->prepare('INSERT INTO quiz_options (question_id,option_text,is_correct,sort_order) VALUES (?,?,?,?)')
            ->execute([$newQqid, 'False', $correct==='false' ? 1 : 0, 1]);
        $options = [
            ['text'=>'True',  'correct'=>$correct==='true'],
            ['text'=>'False', 'correct'=>$correct==='false'],
        ];
    }

    $countQ = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(marks),0) AS tot FROM quiz_questions WHERE quiz_id=?');
    $countQ->execute([$qid]); $row = $countQ->fetch();

    echo json_encode([
        'ok'      => true,
        'id'      => $newQqid,
        'text'    => $qtext,
        'type'    => $qtype,
        'marks'   => $marks,
        'options' => $options,
        'count'   => (int)$row['cnt'],
        'total'   => (int)$row['tot'],
    ]); exit;
}

// ── AJAX: Delete Question ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_del_question']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $qqid = (int)$_POST['qqid'];
    $pdo->prepare('DELETE FROM quiz_options  WHERE question_id=?')->execute([$qqid]);
    $pdo->prepare('DELETE FROM quiz_answers  WHERE question_id=?')->execute([$qqid]);
    $pdo->prepare('DELETE FROM quiz_questions WHERE id=? AND quiz_id=?')->execute([$qqid, $qid]);

    $countQ = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(marks),0) AS tot FROM quiz_questions WHERE quiz_id=?');
    $countQ->execute([$qid]); $row = $countQ->fetch();
    echo json_encode(['ok'=>true,'count'=>(int)$row['cnt'],'total'=>(int)$row['tot']]); exit;
}

// ── AJAX: Save Settings ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_update_quiz']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $title  = trim($_POST['q_title']  ?? '');
    $desc   = trim($_POST['q_desc']   ?? '');
    $pass   = max(1, min(100, (int)($_POST['q_pass']  ?? 70)));
    $time   = (int)($_POST['q_time']  ?? 0);
    $retake = (int)($_POST['q_retake'] ?? 0);
    if (!$title) { echo json_encode(['ok'=>false,'msg'=>'Title is required.']); exit; }
    $pdo->prepare('UPDATE quizzes SET title=?,description=?,pass_mark=?,time_limit=?,allow_retake=? WHERE id=?')
        ->execute([$title, $desc, $pass, $time ?: null, $retake, $qid]);
    echo json_encode(['ok'=>true,'title'=>$title,'pass'=>$pass]); exit;
}

// ── Toggle Publish (normal GET) ──
if (isset($_GET['toggle'])) {
    $pdo->prepare('UPDATE quizzes SET is_published=NOT is_published WHERE id=? AND created_by=?')->execute([$qid, $uid]);
    header('Location: quiz_manage.php?id=' . $qid); exit;
}

// ── Download Question CSV Template ──
if (isset($_GET['dl_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="quiz_questions_template.csv"');
    echo "question,type,option_a,option_b,option_c,option_d,correct,marks\r\n";
    echo "\"What does CPU stand for?\",mcq,\"Central Processing Unit\",\"Core Processing Unit\",\"Computer Power Unit\",\"Central Program Unit\",A,2\r\n";
    echo "\"Python is a compiled language.\",truefalse,True,False,,,B,1\r\n";
    echo "\"What is 2 to the power of 3?\",mcq,6,8,9,12,B,2\r\n";
    echo "\"Briefly explain what an algorithm is.\",short,,,,,,3\r\n";
    exit;
}

// ── Bulk Import Questions (CSV / PDF / DOCX) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_bulk_import'])) {
    header('Content-Type: application/json');

    if (!isset($_FILES['bulk_file']) || $_FILES['bulk_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'msg'=>'No file uploaded or upload error.']); exit;
    }

    $file     = $_FILES['bulk_file'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmpPath  = $file['tmp_name'];
    $inserted = 0; $skipped = 0; $errors = [];

    // Get next sort order
    $maxOrd = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM quiz_questions WHERE quiz_id=$qid")->fetchColumn();

    $stmtQ = $pdo->prepare('INSERT INTO quiz_questions (quiz_id,question,type,marks,sort_order) VALUES (?,?,?,?,?)');
    $stmtO = $pdo->prepare('INSERT INTO quiz_options (question_id,option_text,is_correct,sort_order) VALUES (?,?,?,?)');

    function insertQuestion(PDO $pdo, $stmtQ, $stmtO, int $qid, string $question, string $type, array $options, string $correct, int $marks, int &$maxOrd, int &$inserted, int &$skipped, array &$errors, int $rowNum): void {
        if (!$question) { $errors[] = "Row $rowNum: question text is required."; $skipped++; return; }
        $validTypes = ['mcq','truefalse','short'];
        if (!in_array($type, $validTypes)) { $errors[] = "Row $rowNum: type '$type' invalid (use mcq/truefalse/short)."; $skipped++; return; }

        $maxOrd++;
        $stmtQ->execute([$qid, $question, $type, max(1,$marks), $maxOrd]);
        $newQid = (int)$pdo->lastInsertId();

        if ($type === 'mcq') {
            $correct = strtoupper(trim($correct));
            $correctMap = ['A'=>0,'B'=>1,'C'=>2,'D'=>3];
            $correctIdx = $correctMap[$correct] ?? 0;
            foreach ($options as $i => $opt) {
                if (trim($opt)) {
                    $stmtO->execute([$newQid, trim($opt), $i === $correctIdx ? 1 : 0, $i]);
                }
            }
        } elseif ($type === 'truefalse') {
            $correct = strtolower(trim($correct));
            $isTrue  = in_array($correct, ['a','true','1']);
            $stmtO->execute([$newQid, 'True',  $isTrue  ? 1 : 0, 0]);
            $stmtO->execute([$newQid, 'False', !$isTrue ? 1 : 0, 1]);
        }
        $inserted++;
    }

    if ($ext === 'csv') {
        // ── CSV Import ──
        $handle = fopen($tmpPath, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $header = fgetcsv($handle);
        $header = array_map(fn($h) => strtolower(trim(str_replace(['"',"'","\xEF\xBB\xBF"], '', $h))), $header);
        $colMap = array_flip($header);

        $required = ['question','type','correct','marks'];
        $missing  = array_diff($required, array_keys($colMap));
        if (!empty($missing)) {
            echo json_encode(['ok'=>false,'msg'=>'CSV missing columns: ' . implode(', ', $missing) . '. Download the template.']); exit;
        }

        $rowNum = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($row) < 2 || !trim($row[0])) continue;

            $question = trim($row[$colMap['question']] ?? '');
            $type     = strtolower(trim($row[$colMap['type']] ?? 'mcq'));
            $marks    = max(1, (int)($row[$colMap['marks']] ?? 1));
            $correct  = trim($row[$colMap['correct']] ?? 'A');
            $options  = [
                trim($row[$colMap['option_a']] ?? ''),
                trim($row[$colMap['option_b']] ?? ''),
                trim($row[$colMap['option_c']] ?? ''),
                trim($row[$colMap['option_d']] ?? ''),
            ];

            insertQuestion($pdo, $stmtQ, $stmtO, $qid, $question, $type, $options, $correct, $marks, $maxOrd, $inserted, $skipped, $errors, $rowNum);
        }
        fclose($handle);

    } elseif ($ext === 'txt') {
        // ── Plain Text Import ──
        // Format per question block:
        // Q: What is ...?
        // A) Option one
        // B) Option two
        // C) Option three
        // D) Option four
        // CORRECT: A
        // MARKS: 2
        // TYPE: mcq  (optional, default mcq)
        // [blank line between questions]

        $content = file_get_contents($tmpPath);
        $blocks  = preg_split('/\n\s*\n/', trim($content));
        $rowNum  = 0;

        foreach ($blocks as $block) {
            $rowNum++;
            $lines   = array_map('trim', explode("\n", $block));
            $question = ''; $type = 'mcq'; $marks = 1; $correct = 'A';
            $options  = ['', '', '', ''];

            foreach ($lines as $line) {
                if (preg_match('/^Q[:\.]?\s+(.+)/i', $line, $m))          $question   = trim($m[1]);
                elseif (preg_match('/^A[\)\.]\s*(.+)/i', $line, $m))     $options[0] = trim($m[1]);
                elseif (preg_match('/^B[\)\.]\s*(.+)/i', $line, $m))     $options[1] = trim($m[1]);
                elseif (preg_match('/^C[\)\.]\s*(.+)/i', $line, $m))     $options[2] = trim($m[1]);
                elseif (preg_match('/^D[\)\.]\s*(.+)/i', $line, $m))     $options[3] = trim($m[1]);
                elseif (preg_match('/^CORRECT[:\s]+(.+)/i', $line, $m)) $correct    = strtoupper(trim($m[1]));
                elseif (preg_match('/^MARKS[:\s]+(\d+)/i', $line, $m))  $marks      = (int)$m[1];
                elseif (preg_match('/^TYPE[:\s]+(\w+)/i', $line, $m))   $type       = strtolower(trim($m[1]));
            }

            insertQuestion($pdo, $stmtQ, $stmtO, $qid, $question, $type, $options, $correct, $marks, $maxOrd, $inserted, $skipped, $errors, $rowNum);
        }

    } elseif (in_array($ext, ['docx'])) {
        // ── DOCX Import ──
        // Extract text from docx (reads word/document.xml inside the zip)
        if (!class_exists('ZipArchive')) {
            echo json_encode(['ok'=>false,'msg'=>'DOCX import requires ZipArchive PHP extension. Use CSV or TXT instead.']); exit;
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            echo json_encode(['ok'=>false,'msg'=>'Could not read DOCX file. Please try CSV or TXT format.']); exit;
        }
        $xml  = $zip->getFromName('word/document.xml');
        $zip->close();

        // Strip XML tags and decode entities to get plain text
        $text = html_entity_decode(strip_tags(str_replace(
            ['</w:p>','</w:tr>'],
            ["\n", "\n"],
            $xml
        )), ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Parse same format as TXT
        $blocks = preg_split('/\n\s*\n/', trim($text));
        $rowNum = 0;

        foreach ($blocks as $block) {
            $rowNum++;
            $lines    = array_map('trim', array_filter(explode("\n", $block)));
            $question = ''; $type = 'mcq'; $marks = 1; $correct = 'A';
            $options  = ['', '', '', ''];

            foreach ($lines as $line) {
                if (preg_match('/^Q[:\.]?\s+(.+)/i', $line, $m))         $question   = trim($m[1]);
                elseif (preg_match('/^A[\)\.]\s*(.+)/i', $line, $m))    $options[0] = trim($m[1]);
                elseif (preg_match('/^B[\)\.]\s*(.+)/i', $line, $m))    $options[1] = trim($m[1]);
                elseif (preg_match('/^C[\)\.]\s*(.+)/i', $line, $m))    $options[2] = trim($m[1]);
                elseif (preg_match('/^D[\)\.]\s*(.+)/i', $line, $m))    $options[3] = trim($m[1]);
                elseif (preg_match('/^CORRECT[:\s]+(.+)/i', $line, $m)) $correct    = strtoupper(trim($m[1]));
                elseif (preg_match('/^MARKS[:\s]+(\d+)/i', $line, $m))  $marks      = (int)$m[1];
                elseif (preg_match('/^TYPE[:\s]+(\w+)/i', $line, $m))   $type       = strtolower(trim($m[1]));
            }

            if ($question) insertQuestion($pdo, $stmtQ, $stmtO, $qid, $question, $type, $options, $correct, $marks, $maxOrd, $inserted, $skipped, $errors, $rowNum);
        }

    } else {
        echo json_encode(['ok'=>false,'msg'=>'Unsupported file type. Please use CSV, TXT, or DOCX.']); exit;
    }

    // Return updated counts
    $countQ = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(marks),0) AS tot FROM quiz_questions WHERE quiz_id=?');
    $countQ->execute([$qid]); $countRow = $countQ->fetch();

    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'errors'   => array_slice($errors, 0, 5),
        'count'    => (int)$countRow['cnt'],
        'total'    => (int)$countRow['tot'],
    ]); exit;
}

// ── Delete Question (normal GET fallback) ──
if (isset($_GET['delq']) && is_numeric($_GET['delq'])) {
    $qqid = (int)$_GET['delq'];
    $pdo->prepare('DELETE FROM quiz_options  WHERE question_id=?')->execute([$qqid]);
    $pdo->prepare('DELETE FROM quiz_answers  WHERE question_id=?')->execute([$qqid]);
    $pdo->prepare('DELETE FROM quiz_questions WHERE id=? AND quiz_id=?')->execute([$qqid, $qid]);
    header('Location: quiz_manage.php?id=' . $qid); exit;
}

// Load questions + options
$questions = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order, id');
$questions->execute([$qid]);
$questions = $questions->fetchAll();

$optMap = [];
if ($questions) {
    $qids = implode(',', array_column($questions, 'id'));
    if ($qids) {
        $opts = $pdo->query("SELECT * FROM quiz_options WHERE question_id IN ($qids) ORDER BY sort_order")->fetchAll();
        foreach ($opts as $o) { $optMap[$o['question_id']][] = $o; }
    }
}

$totalMarks  = array_sum(array_column($questions, 'marks'));
$passMarkRaw = $totalMarks > 0 ? ceil($totalMarks * $quiz['pass_mark'] / 100) : 0;
$nMcq        = count(array_filter($questions, fn($q) => $q['type']==='mcq'));
$nTf         = count(array_filter($questions, fn($q) => $q['type']==='truefalse'));
$nShort      = count(array_filter($questions, fn($q) => $q['type']==='short'));

$pageTitle    = 'Edit Quiz';
$pageSubtitle = h($quiz['title']) . ' &nbsp;·&nbsp; ' . h($quiz['course_title']);
$activePage   = 'courses';
$depth        = 1;
ob_start();
?>

<style>
.qb-wrap { display:grid; grid-template-columns:1fr 300px; gap:18px; align-items:start; }
@media(max-width:860px){ .qb-wrap { grid-template-columns:1fr; } }

/* Top bar */
.qb-topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:18px; }
.qb-topbar-left h2 { font-family:'Cinzel',serif; font-size:18px; color:var(--white); margin-bottom:3px; }
.qb-topbar-left p  { font-size:12px; color:var(--muted); }

/* Publish banner */
.pub-banner { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
    background:rgba(39,174,96,.08); border:1px solid rgba(39,174,96,.25); border-radius:10px;
    padding:12px 18px; margin-bottom:16px; }
.pub-banner p { font-size:13px; color:#4caf82; font-weight:700; margin:0; }
.pub-banner span { font-size:12px; color:rgba(76,175,130,.7); font-weight:400; }

/* Type tabs */
.type-tabs { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; margin-bottom:16px; }
.type-tab { padding:10px 8px; border-radius:10px; border:1px solid var(--border);
    background:var(--surf2); text-align:center; cursor:pointer;
    font-size:12px; color:var(--muted); transition:all .15s; }
.type-tab .tab-icon { font-size:18px; display:block; margin-bottom:4px; }
.type-tab:hover { border-color:var(--gold); color:var(--text); }
.type-tab.active { border-color:var(--gold); background:rgba(200,168,75,.1); color:var(--gold); font-weight:700; }

/* Add panel */
.add-panel { border:1px dashed rgba(200,168,75,.35); border-radius:12px;
    padding:18px; margin-bottom:14px; background:rgba(200,168,75,.03); }

/* Option builder rows */
.opt-build-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
.opt-build-row input[type=radio] { accent-color:var(--gold); width:15px; height:15px; flex-shrink:0; cursor:pointer; }
.opt-build-row input[type=text]  { flex:1; }

/* Question cards */
.q-card { background:var(--surf2); border:1px solid var(--border); border-radius:12px;
    padding:16px 18px; margin-bottom:10px; transition:border-color .15s; }
.q-card:hover { border-color:rgba(200,168,75,.35); }
.q-card-head { display:flex; align-items:flex-start; gap:10px; margin-bottom:10px; }
.q-num { width:26px; height:26px; border-radius:50%; background:rgba(200,168,75,.12);
    display:flex; align-items:center; justify-content:center;
    font-size:11px; font-weight:700; color:var(--gold); flex-shrink:0; }
.q-badge { font-size:10px; padding:3px 9px; border-radius:20px; font-weight:700; }
.q-badge-mcq   { background:rgba(41,128,185,.15); color:#64b5f6; }
.q-badge-tf    { background:rgba(39,174,96,.15);  color:#4caf82; }
.q-badge-short { background:rgba(200,168,75,.15); color:var(--gold); }
.q-badge-marks { background:rgba(255,255,255,.06); color:var(--muted); }

/* Options display */
.q-opt { display:flex; align-items:center; gap:8px; font-size:12px; padding:5px 10px;
    border-radius:6px; margin-bottom:4px; color:var(--muted); }
.q-opt.correct { background:rgba(39,174,96,.1); color:#4caf82; font-weight:600; }
.q-opt-dot { width:13px; height:13px; border-radius:50%; border:1.5px solid var(--border); flex-shrink:0; }
.q-opt.correct .q-opt-dot { background:#4caf82; border-color:#4caf82; }
.q-short-box { background:var(--surface); border-radius:8px; padding:9px 13px;
    font-size:12px; color:var(--muted); font-style:italic; margin-top:6px; }

/* Sidebar stat boxes */
.stat-pair { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:12px; }
.stat-box { background:var(--surf2); border-radius:10px; padding:12px; text-align:center; }
.stat-box-val { font-size:24px; font-weight:700; font-family:'Cinzel',serif; color:var(--white); }
.stat-box-lbl { font-size:11px; color:var(--muted); margin-top:3px; }
.type-row { display:flex; justify-content:space-between; font-size:12px;
    color:var(--muted); padding:5px 0; border-top:1px solid var(--border); }
.type-row strong { color:var(--white); font-weight:700; }

/* Toggle switch */
.tog-row { display:flex; align-items:center; justify-content:space-between;
    font-size:13px; color:var(--text); padding:8px 0; border-top:1px solid var(--border); }
.tog { width:36px; height:20px; border-radius:10px; background:var(--surf2);
    border:1px solid var(--border); cursor:pointer; position:relative;
    transition:background .2s; flex-shrink:0; }
.tog.on { background:rgba(200,168,75,.8); border-color:var(--gold); }
.tog::after { content:''; position:absolute; width:14px; height:14px; border-radius:50%;
    background:#fff; top:2px; left:2px; transition:left .2s; }
.tog.on::after { left:18px; }

/* Toast */
#qb-toast { position:fixed; top:78px; right:22px; z-index:9999;
    padding:11px 18px; border-radius:10px; font-size:13px; font-weight:700;
    display:flex; align-items:center; gap:10px;
    box-shadow:0 6px 24px rgba(0,0,0,.4);
    transform:translateY(-10px); opacity:0;
    transition:all .25s ease; pointer-events:none; }

.empty-qs { text-align:center; padding:48px 0; color:var(--muted); }
.empty-qs i { font-size:40px; display:block; margin-bottom:12px; opacity:.3; }
</style>

<!-- Top bar -->
<div class="qb-topbar">
    <div class="qb-topbar-left">
        <h2 id="qb-title"><?= h($quiz['title']) ?></h2>
        <p><?= h($quiz['course_title']) ?> &nbsp;·&nbsp; <span id="qb-q-count"><?= count($questions) ?></span> question<?= count($questions)!=1?'s':'' ?> &nbsp;·&nbsp; <span id="qb-marks"><?= $totalMarks ?></span> total marks</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a href="quiz_manage.php?id=<?= $qid ?>&dl_template=1" class="btn btn-secondary btn-sm" title="Download question template">
            <i class="fas fa-download"></i> Template
        </a>
        <button class="btn btn-secondary btn-sm"
                style="background:rgba(39,174,96,.1);border-color:rgba(39,174,96,.35);color:#6fcf97"
                onclick="openModal('m-bulk-import')">
            <i class="fas fa-file-upload"></i> Bulk Import
        </button>
        <a href="quiz_results.php?id=<?= $qid ?>" class="btn btn-secondary btn-sm">
            <i class="fas fa-chart-bar"></i> Results
        </a>
        <a href="quiz_create.php?course=<?= $cid ?>" class="btn btn-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> All Quizzes
        </a>
        <button class="btn <?= $quiz['is_published'] ? 'btn-secondary' : 'btn-primary' ?> btn-sm"
                id="pub-btn" onclick="togglePublish()">
            <i class="fas fa-<?= $quiz['is_published'] ? 'eye-slash' : 'globe' ?>"></i>
            <?= $quiz['is_published'] ? 'Unpublish' : 'Publish Quiz' ?>
        </button>
    </div>
</div>

<!-- Published banner -->
<div class="pub-banner" id="pub-banner" <?= !$quiz['is_published'] ? 'style="display:none"' : '' ?>>
    <p><i class="fas fa-check-circle"></i> Quiz is published <span>· Students can now see and take this quiz</span></p>
    <button class="btn btn-secondary btn-sm" onclick="togglePublish()"><i class="fas fa-eye-slash"></i> Unpublish</button>
</div>

<?php if(isset($_GET['created'])): ?>
<div class="alert alert-ok" style="margin-bottom:16px"><i class="fas fa-check-circle"></i> Quiz created! Add your questions below.</div>
<?php endif; ?>

<!-- Toast -->
<div id="qb-toast"></div>

<div class="qb-wrap">

  <!-- LEFT: Question builder + list -->
  <div>

    <!-- Add question panel toggle -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div style="font-size:14px;font-weight:700;color:var(--white)">
            Questions <span style="color:var(--muted);font-weight:400;font-size:13px" id="q-count-label">(<?= count($questions) ?>)</span>
        </div>
        <button class="btn btn-primary btn-sm" id="add-toggle-btn" onclick="toggleAddPanel()">
            <i class="fas fa-plus"></i> Add Question
        </button>
    </div>

    <!-- Add question panel -->
    <div class="add-panel" id="add-panel" style="display:none">
        <div style="font-size:13px;font-weight:700;color:var(--white);margin-bottom:14px">New question</div>

        <!-- Type tabs -->
        <div class="type-tabs" style="grid-template-columns:1fr 1fr">
            <div class="type-tab active" id="tab-mcq" onclick="switchType('mcq')">
                <span class="tab-icon"><i class="fas fa-list-ul"></i></span>
                Multiple choice
            </div>
            <div class="type-tab" id="tab-truefalse" onclick="switchType('truefalse')">
                <span class="tab-icon"><i class="fas fa-check-circle"></i></span>
                True / False
            </div>
        </div>

        <input type="hidden" id="active-type" value="mcq">

        <!-- Question text -->
        <div class="fg">
            <label class="lbl2">Question text *</label>
            <textarea class="fc" id="q-text" rows="3" placeholder="Type your question here…"></textarea>
        </div>

        <!-- MCQ options -->
        <div id="mcq-section">
            <label class="lbl2">Answer options <span style="color:var(--muted);font-weight:400;font-size:11px">— select the correct one</span></label>
            <div class="opt-build-row"><input type="radio" name="opt_correct" value="0" checked><input class="fc" type="text" id="opt0" placeholder="Option A" style="margin-bottom:0"></div>
            <div class="opt-build-row"><input type="radio" name="opt_correct" value="1"><input class="fc" type="text" id="opt1" placeholder="Option B" style="margin-bottom:0"></div>
            <div class="opt-build-row"><input type="radio" name="opt_correct" value="2"><input class="fc" type="text" id="opt2" placeholder="Option C (optional)" style="margin-bottom:0"></div>
            <div class="opt-build-row"><input type="radio" name="opt_correct" value="3"><input class="fc" type="text" id="opt3" placeholder="Option D (optional)" style="margin-bottom:0"></div>
        </div>

        <!-- True/False -->
        <div id="tf-section" style="display:none">
            <label class="lbl2">Correct answer</label>
            <div style="display:flex;gap:10px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text)">
                    <input type="radio" name="tf_correct" value="true" checked style="accent-color:var(--gold);width:15px;height:15px"> True
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text)">
                    <input type="radio" name="tf_correct" value="false" style="accent-color:var(--gold);width:15px;height:15px"> False
                </label>
            </div>
        </div>

        <!-- Marks + actions -->
        <div style="display:flex;align-items:flex-end;gap:10px;margin-top:14px;flex-wrap:wrap">
            <div style="flex:1;min-width:80px">
                <label class="lbl2">Marks per question</label>
                <input class="fc" type="number" id="q-marks" value="5" min="1" style="margin-bottom:0">
            </div>
            <button class="btn btn-primary" style="height:42px;padding:0 20px" onclick="submitQuestion()">
                <i class="fas fa-plus-circle"></i> Add question
            </button>
            <button class="btn btn-secondary" style="height:42px;padding:0 14px" onclick="toggleAddPanel()" title="Cancel">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <!-- Questions list -->
    <div id="q-list">
        <?php if(empty($questions)): ?>
        <div class="empty-qs" id="empty-state">
            <i class="fas fa-question-circle"></i>
            No questions yet. Click <strong>Add Question</strong> to get started.
        </div>
        <?php else: ?>
        <?php foreach($questions as $i => $q): ?>
        <div class="q-card" id="qcard-<?= $q['id'] ?>">
            <div class="q-card-head">
                <div class="q-num"><?= $i+1 ?></div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px">
                        <span class="q-badge q-badge-<?= $q['type']==='truefalse'?'tf':$q['type'] ?>">
                            <?= $q['type']==='mcq'?'Multiple choice':($q['type']==='truefalse'?'True / False':'Short answer') ?>
                        </span>
                        <span class="q-badge q-badge-marks"><?= $q['marks'] ?> mark<?= $q['marks']!=1?'s':'' ?></span>
                    </div>
                    <div style="font-size:13px;color:var(--white);line-height:1.55;font-weight:600"><?= h($q['question']) ?></div>
                </div>
                <button class="btn btn-secondary btn-sm" style="color:#ff8a80;border-color:rgba(231,76,60,.25);flex-shrink:0"
                        onclick="deleteQuestion(<?= $q['id'] ?>, this)" title="Delete question">
                    <i class="fas fa-trash"></i>
                </button>
            </div>

            <?php if(!empty($optMap[$q['id']])): ?>
            <div style="margin-top:4px">
                <?php foreach($optMap[$q['id']] as $opt): ?>
                <div class="q-opt <?= $opt['is_correct']?'correct':'' ?>">
                    <div class="q-opt-dot"></div>
                    <?= h($opt['option_text']) ?>
                    <?php if($opt['is_correct']): ?>
                    <span style="font-size:10px;margin-left:4px;background:rgba(39,174,96,.15);color:#4caf82;border-radius:4px;padding:1px 6px">Correct</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

  </div>

  <!-- RIGHT: Summary + Settings sidebar -->
  <div>

    <!-- Summary card -->
    <div class="card mb2">
        <div class="card-hd">
            <span class="card-title"><i class="fas fa-chart-pie" style="color:var(--gold)"></i> Summary</span>
        </div>
        <div class="stat-pair">
            <div class="stat-box">
                <div class="stat-box-val" id="sb-questions"><?= count($questions) ?></div>
                <div class="stat-box-lbl">Questions</div>
            </div>
            <div class="stat-box">
                <div class="stat-box-val" id="sb-marks"><?= $totalMarks ?></div>
                <div class="stat-box-lbl">Total marks</div>
            </div>
        </div>
        <div class="type-row"><span>Multiple choice</span><strong id="sb-mcq"><?= $nMcq ?></strong></div>
        <div class="type-row"><span>True / False</span><strong id="sb-tf"><?= $nTf ?></strong></div>
        <div style="margin-top:12px;font-size:12px;color:var(--muted)">Pass mark</div>
        <div style="font-size:20px;font-weight:700;font-family:'Cinzel',serif;color:var(--white)" id="sb-passmark">
            <?= $passMarkRaw ?> / <?= $totalMarks ?>
            <span style="font-size:12px;color:var(--muted);font-family:inherit;font-weight:400">(<?= $quiz['pass_mark'] ?>%)</span>
        </div>
    </div>

    <!-- Settings card -->
    <div class="card">
        <div class="card-hd">
            <span class="card-title"><i class="fas fa-sliders-h" style="color:var(--gold)"></i> Settings</span>
            <span id="settings-saved" style="font-size:11px;color:#4caf82;display:none"><i class="fas fa-check"></i> Saved</span>
        </div>

        <div class="fg">
            <label class="lbl2">Quiz title</label>
            <input class="fc" type="text" id="s-title" value="<?= h($quiz['title']) ?>">
        </div>
        <div class="fg">
            <label class="lbl2">Description</label>
            <textarea class="fc" id="s-desc" rows="2"><?= h($quiz['description'] ?? '') ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div class="fg" style="margin-bottom:0">
                <label class="lbl2">Pass mark (%)</label>
                <input class="fc" type="number" id="s-pass" value="<?= $quiz['pass_mark'] ?>" min="1" max="100" oninput="updatePassDisplay()">
            </div>
            <div class="fg" style="margin-bottom:0">
                <label class="lbl2">Time limit (min)</label>
                <input class="fc" type="number" id="s-time" value="<?= $quiz['time_limit'] ?? '' ?>" placeholder="None">
            </div>
        </div>

        <div class="tog-row" style="margin-top:10px">
            <span>Allow retakes</span>
            <div class="tog <?= $quiz['allow_retake'] ? 'on' : '' ?>" id="tog-retake" onclick="this.classList.toggle('on')"></div>
        </div>

        <button class="btn btn-primary" style="width:100%;justify-content:center;margin-top:14px" onclick="saveSettings()">
            <i class="fas fa-save"></i> Save Settings
        </button>

        <a href="quiz_results.php?id=<?= $qid ?>" class="btn btn-secondary"
           style="width:100%;justify-content:center;margin-top:8px">
            <i class="fas fa-chart-bar"></i> View Results
        </a>
    </div>

  </div>

</div>

<script>
var QID   = <?= $qid ?>;
var CID   = <?= $cid ?>;
var qData = <?= json_encode(array_map(fn($q) => ['id'=>$q['id'],'type'=>$q['type'],'marks'=>(int)$q['marks']], $questions)) ?>;
var passPct = <?= $quiz['pass_mark'] ?>;

// ── Toast ──
function showToast(msg, ok) {
    var t = document.getElementById('qb-toast');
    t.style.background = ok ? 'rgba(39,174,96,.95)' : 'rgba(231,76,60,.95)';
    t.innerHTML = '<i class="fas fa-' + (ok?'check':'times') + '-circle"></i> ' + msg;
    t.style.opacity = '1'; t.style.transform = 'translateY(0)';
    clearTimeout(t._t);
    t._t = setTimeout(function(){ t.style.opacity='0'; t.style.transform='translateY(-10px)'; }, 2000);
}

// ── Toggle add panel ──
function toggleAddPanel() {
    var p = document.getElementById('add-panel');
    var b = document.getElementById('add-toggle-btn');
    var open = p.style.display === 'none';
    p.style.display = open ? 'block' : 'none';
    b.innerHTML = open ? '<i class="fas fa-times"></i> Cancel' : '<i class="fas fa-plus"></i> Add Question';
}

// ── Type switcher ──
var activeType = 'mcq';
function switchType(type) {
    activeType = type;
    ['mcq','truefalse'].forEach(function(t){
        document.getElementById('tab-'+t).classList.toggle('active', t===type);
    });
    document.getElementById('mcq-section').style.display = type==='mcq'       ? 'block' : 'none';
    document.getElementById('tf-section').style.display  = type==='truefalse' ? 'block' : 'none';
}

// ── Submit question via AJAX ──
function submitQuestion() {
    var txt = document.getElementById('q-text').value.trim();
    if (!txt) { document.getElementById('q-text').focus(); showToast('Question text is required.', false); return; }

    var marks = parseInt(document.getElementById('q-marks').value) || 5;
    var fd = new FormData();
    fd.append('do_add_question', '1');
    fd.append('q_text', txt);
    fd.append('q_type', activeType);
    fd.append('q_marks', marks);

    if (activeType === 'mcq') {
        var correct = document.querySelector('input[name="opt_correct"]:checked');
        fd.append('opt_correct', correct ? correct.value : '0');
        ['opt0','opt1','opt2','opt3'].forEach(function(id){ fd.append('opt_text[]', document.getElementById(id).value.trim()); });
    } else if (activeType === 'truefalse') {
        var tf = document.querySelector('input[name="tf_correct"]:checked');
        fd.append('tf_correct', tf ? tf.value : 'true');
    }

    fetch('quiz_manage.php?id=' + QID, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: fd
    }).then(r => r.json()).then(function(d) {
        if (!d.ok) { showToast(d.msg || 'Error.', false); return; }

        // Add card to DOM
        qData.push({id: d.id, type: d.type, marks: d.marks});
        var num = qData.length;

        var badgeClass = d.type==='mcq' ? 'q-badge-mcq' : 'q-badge-tf';
        var badgeLabel = d.type==='mcq' ? 'Multiple choice' : 'True / False';

        var optsHtml = '';
        if (d.options && d.options.length) {
            optsHtml = '<div style="margin-top:4px">';
            d.options.forEach(function(o){
                optsHtml += '<div class="q-opt' + (o.correct?' correct':'') + '"><div class="q-opt-dot"></div>' + escHtml(o.text);
                if (o.correct) optsHtml += '<span style="font-size:10px;margin-left:4px;background:rgba(39,174,96,.15);color:#4caf82;border-radius:4px;padding:1px 6px">Correct</span>';
                optsHtml += '</div>';
            });
            optsHtml += '</div>';
        }

        var card = document.createElement('div');
        card.className = 'q-card';
        card.id = 'qcard-' + d.id;
        card.innerHTML =
            '<div class="q-card-head">' +
            '<div class="q-num">' + num + '</div>' +
            '<div style="flex:1;min-width:0">' +
            '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px">' +
            '<span class="q-badge ' + badgeClass + '">' + badgeLabel + '</span>' +
            '<span class="q-badge q-badge-marks">' + d.marks + ' mark' + (d.marks!==1?'s':'') + '</span>' +
            '</div>' +
            '<div style="font-size:13px;color:var(--white);line-height:1.55;font-weight:600">' + escHtml(d.text) + '</div>' +
            '</div>' +
            '<button class="btn btn-secondary btn-sm" style="color:#ff8a80;border-color:rgba(231,76,60,.25);flex-shrink:0" ' +
            'onclick="deleteQuestion(' + d.id + ', this)" title="Delete question"><i class="fas fa-trash"></i></button>' +
            '</div>' + optsHtml;

        // Remove empty state if present
        var empty = document.getElementById('empty-state');
        if (empty) empty.remove();

        document.getElementById('q-list').appendChild(card);
        updateSummary(d.count, d.total);

        // Reset form
        document.getElementById('q-text').value = '';
        ['opt0','opt1','opt2','opt3'].forEach(function(id){ document.getElementById(id).value = ''; });
        document.querySelector('input[name="opt_correct"][value="0"]').checked = true;
        document.querySelector('input[name="tf_correct"][value="true"]').checked = true;
        toggleAddPanel();
        showToast('Question added!', true);
    }).catch(function(){ showToast('Network error. Try again.', false); });
}

// ── Delete question via AJAX ──
function deleteQuestion(id, btn) {
    if (!confirm('Delete this question?')) return;
    btn.disabled = true;
    var fd = new FormData();
    fd.append('do_del_question', '1');
    fd.append('qqid', id);
    fetch('quiz_manage.php?id=' + QID, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: fd
    }).then(r => r.json()).then(function(d) {
        if (!d.ok) { btn.disabled = false; showToast('Error deleting.', false); return; }
        var card = document.getElementById('qcard-' + id);
        if (card) card.remove();
        qData = qData.filter(function(q){ return q.id !== id; });
        renumber();
        updateSummary(d.count, d.total);
        if (d.count === 0) {
            document.getElementById('q-list').innerHTML =
                '<div class="empty-qs" id="empty-state"><i class="fas fa-question-circle"></i>No questions yet. Click <strong>Add Question</strong> to get started.</div>';
        }
        showToast('Question deleted.', true);
    }).catch(function(){ btn.disabled = false; showToast('Network error.', false); });
}

// ── Save settings via AJAX ──
function saveSettings() {
    var fd = new FormData();
    fd.append('do_update_quiz', '1');
    fd.append('q_title',  document.getElementById('s-title').value.trim());
    fd.append('q_desc',   document.getElementById('s-desc').value.trim());
    fd.append('q_pass',   document.getElementById('s-pass').value);
    fd.append('q_time',   document.getElementById('s-time').value);
    fd.append('q_retake', document.getElementById('tog-retake').classList.contains('on') ? '1' : '0');

    fetch('quiz_manage.php?id=' + QID, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: fd
    }).then(r => r.json()).then(function(d) {
        if (!d.ok) { showToast(d.msg || 'Error saving.', false); return; }
        passPct = d.pass;
        document.querySelector('.qb-topbar-left h2').textContent = d.title;
        var saved = document.getElementById('settings-saved');
        saved.style.display = 'inline';
        setTimeout(function(){ saved.style.display = 'none'; }, 2500);
        updatePassDisplay();
        showToast('Settings saved!', true);
    }).catch(function(){ showToast('Network error.', false); });
}

// ── Toggle publish ──
function togglePublish() {
    window.location.href = 'quiz_manage.php?id=' + QID + '&toggle=1';
}

// ── Helpers ──
function renumber() {
    document.querySelectorAll('.q-card .q-num').forEach(function(el, i){ el.textContent = i + 1; });
}

function updateSummary(count, total) {
    document.getElementById('sb-questions').textContent    = count;
    document.getElementById('sb-marks').textContent        = total;
    document.getElementById('q-count-label').textContent   = '(' + count + ')';
    document.querySelector('.qb-topbar-left p').innerHTML  =
        '<?= h($quiz['course_title']) ?> &nbsp;·&nbsp; <span id="qb-q-count">' + count + '</span> question' + (count!==1?'s':'') +
        ' &nbsp;·&nbsp; <span id="qb-marks">' + total + '</span> total marks';

    var mcq   = qData.filter(function(q){ return q.type==='mcq'; }).length;
    var tf    = qData.filter(function(q){ return q.type==='truefalse'; }).length;
    document.getElementById('sb-mcq').textContent   = mcq;
    document.getElementById('sb-tf').textContent    = tf;
    updatePassDisplay(total);
}

function updatePassDisplay(total) {
    total = total || parseInt(document.getElementById('sb-marks').textContent) || 0;
    var pct  = parseInt(document.getElementById('s-pass').value) || passPct;
    var raw  = Math.ceil(total * pct / 100);
    document.getElementById('sb-passmark').innerHTML =
        raw + ' / ' + total +
        ' <span style="font-size:12px;color:var(--muted);font-family:inherit;font-weight:400">(' + pct + '%)</span>';
}

// ── Bulk Import via AJAX ──
function doBulkImport() {
    var file = document.getElementById('bulk-file').files[0];
    if (!file) { showToast('Please select a file first.', false); return; }

    var btn = document.getElementById('bulk-import-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing…';

    var fd = new FormData();
    fd.append('do_bulk_import', '1');
    fd.append('bulk_file', file);

    fetch('quiz_manage.php?id=' + QID, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: fd
    }).then(function(r){ return r.json(); })
    .then(function(d) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-upload"></i> Import Questions';

        if (!d.ok) { showToast(d.msg || 'Import failed.', false); return; }

        // Show result
        var msg = d.inserted + ' question(s) imported successfully.';
        if (d.skipped) msg += ' ' + d.skipped + ' skipped.';
        showToast(msg, true);

        // Update summary counts
        updateSummary(d.count, d.total);

        // Close modal and reload questions list
        closeModal('m-bulk-import');
        setTimeout(function(){ location.reload(); }, 800);
    })
    .catch(function(){ btn.disabled = false; btn.innerHTML = '<i class="fas fa-file-upload"></i> Import Questions'; showToast('Network error. Try again.', false); });
}

function showBulkFile(inp) {
    var lbl = document.getElementById('bulk-filename');
    if (inp.files && inp.files[0]) {
        lbl.textContent = '✓ ' + inp.files[0].name;
        lbl.style.display = 'block';
    }
}

// Drag & drop on bulk zone
document.addEventListener('DOMContentLoaded', function() {
    var dz = document.getElementById('bulk-drop-zone');
    if (!dz) return;
    dz.addEventListener('dragover', function(e){ e.preventDefault(); this.style.borderColor='#6fcf97'; });
    dz.addEventListener('dragleave', function(){ this.style.borderColor='rgba(39,174,96,.35)'; });
    dz.addEventListener('drop', function(e){
        e.preventDefault(); this.style.borderColor='rgba(39,174,96,.35)';
        var f = e.dataTransfer.files[0];
        if (f) {
            var inp = document.getElementById('bulk-file');
            var dt = new DataTransfer(); dt.items.add(f); inp.files = dt.files;
            showBulkFile(inp);
        }
    });
});
</script>

<!-- ══ BULK IMPORT MODAL ══ -->
<div class="modal-wrap" id="m-bulk-import">
  <div class="modal-box" style="max-width:600px">
    <div class="modal-head">
      <h3><i class="fas fa-file-upload" style="color:#6fcf97"></i> Bulk Import Questions</h3>
      <button class="modal-close" onclick="closeModal('m-bulk-import')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">

      <!-- Format tabs -->
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:18px">
        <div style="background:rgba(39,174,96,.07);border:1px solid rgba(39,174,96,.3);border-radius:10px;padding:12px;text-align:center">
          <i class="fas fa-file-csv" style="font-size:22px;color:#6fcf97;display:block;margin-bottom:6px"></i>
          <div style="font-size:12px;font-weight:700;color:#fff;margin-bottom:3px">CSV / Excel</div>
          <div style="font-size:10px;color:rgba(255,255,255,.4)">Best for bulk imports</div>
        </div>
        <div style="background:rgba(100,181,246,.07);border:1px solid rgba(100,181,246,.3);border-radius:10px;padding:12px;text-align:center">
          <i class="fas fa-file-alt" style="font-size:22px;color:#64b5f6;display:block;margin-bottom:6px"></i>
          <div style="font-size:12px;font-weight:700;color:#fff;margin-bottom:3px">Plain Text (.txt)</div>
          <div style="font-size:10px;color:rgba(255,255,255,.4)">Simple structured format</div>
        </div>
        <div style="background:rgba(206,147,216,.07);border:1px solid rgba(206,147,216,.3);border-radius:10px;padding:12px;text-align:center">
          <i class="fas fa-file-word" style="font-size:22px;color:#ce93d8;display:block;margin-bottom:6px"></i>
          <div style="font-size:12px;font-weight:700;color:#fff;margin-bottom:3px">Word (.docx)</div>
          <div style="font-size:10px;color:rgba(255,255,255,.4)">From Word documents</div>
        </div>
      </div>

      <!-- Format guide tabs -->
      <div style="margin-bottom:18px">
        <div style="display:flex;gap:6px;margin-bottom:10px">
          <button class="btn btn-secondary btn-sm fmt-tab active-fmt" onclick="showFmt(this,'fmt-csv')" style="font-size:11px">CSV Format</button>
          <button class="btn btn-secondary btn-sm fmt-tab" onclick="showFmt(this,'fmt-txt')" style="font-size:11px">TXT / DOCX Format</button>
        </div>

        <div id="fmt-csv" style="background:rgba(0,0,0,.2);border-radius:8px;padding:14px;font-size:11px;line-height:1.8;color:rgba(255,255,255,.6)">
          <strong style="color:#6fcf97">Columns:</strong>
          <code style="background:rgba(0,0,0,.3);padding:2px 6px;border-radius:4px;color:#fff">question, type, option_a, option_b, option_c, option_d, correct, marks</code><br>
          <strong style="color:var(--white)">type</strong> = <code style="color:#6fcf97">mcq</code> | <code style="color:#64b5f6">truefalse</code> | <code style="color:#ce93d8">short</code><br>
          <strong style="color:var(--white)">correct</strong> = A / B / C / D for MCQ &nbsp;·&nbsp; A=True / B=False for truefalse &nbsp;·&nbsp; leave blank for short<br>
          <a href="quiz_manage.php?id=<?= $qid ?>&dl_template=1" style="color:#6fcf97;font-weight:700;text-decoration:none;display:inline-block;margin-top:6px">
            <i class="fas fa-download"></i> Download CSV Template
          </a>
        </div>

        <div id="fmt-txt" style="display:none;background:rgba(0,0,0,.2);border-radius:8px;padding:14px;font-size:11px;line-height:1.8;color:rgba(255,255,255,.6)">
          <strong style="color:#64b5f6">One question block per blank line. Example:</strong><br>
          <pre style="background:rgba(0,0,0,.3);padding:10px;border-radius:6px;margin:8px 0;color:#fff;font-size:11px;overflow-x:auto">Q: What does RAM stand for?
A) Random Access Memory
B) Read Access Memory
C) Random Allocated Memory
D) Read Allocated Memory
CORRECT: A
MARKS: 2
TYPE: mcq

Q: Python is a compiled language.
CORRECT: B
MARKS: 1
TYPE: truefalse

Q: Briefly explain what an algorithm is.
MARKS: 3
TYPE: short</pre>
          Save your Word doc as <strong style="color:#fff">.docx</strong> with the same format above.
        </div>
      </div>

      <!-- File upload -->
      <div id="bulk-drop-zone"
           style="border:2px dashed rgba(39,174,96,.35);border-radius:12px;padding:28px;text-align:center;cursor:pointer;background:rgba(39,174,96,.02);transition:all .2s"
           onclick="document.getElementById('bulk-file').click()">
        <i class="fas fa-cloud-upload-alt" style="font-size:34px;color:#6fcf97;display:block;margin-bottom:10px"></i>
        <p style="color:rgba(255,255,255,.8);margin:0;font-size:14px"><strong>Click to choose file</strong> or drag & drop</p>
        <p style="font-size:11px;color:rgba(255,255,255,.35);margin-top:6px">Accepted: .csv &nbsp;·&nbsp; .txt &nbsp;·&nbsp; .docx</p>
        <p id="bulk-filename" style="display:none;margin-top:10px;font-size:13px;color:#6fcf97;font-weight:700"></p>
      </div>
      <input type="file" id="bulk-file" accept=".csv,.txt,.docx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
             style="display:none" onchange="showBulkFile(this)">

      <div style="margin-top:16px">
        <button id="bulk-import-btn" class="btn btn-primary" style="width:100%;background:linear-gradient(135deg,#1b5e20,#2e7d32);font-size:14px;padding:13px"
                onclick="doBulkImport()">
          <i class="fas fa-file-upload"></i> Import Questions
        </button>
      </div>

    </div>
  </div>
</div>

<style>
.fmt-tab { opacity:.6; }
.fmt-tab.active-fmt { opacity:1; border-color:rgba(200,168,75,.4); color:var(--gold); }
</style>
<script>
function showFmt(btn, id) {
    document.querySelectorAll('.fmt-tab').forEach(function(b){ b.classList.remove('active-fmt'); });
    btn.classList.add('active-fmt');
    document.getElementById('fmt-csv').style.display = 'none';
    document.getElementById('fmt-txt').style.display = 'none';
    document.getElementById(id).style.display = 'block';
}
</script>

<?php
$pageContent = ob_get_clean();
require_once '../includes/layout.php';
