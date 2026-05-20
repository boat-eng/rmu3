<?php
require_once "../includes/config.php";
requireRole("instructor");
require_once "../includes/mailer.php";

$uid = (int)$_SESSION["user_id"];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax_reply"])) {
    header("Content-Type: application/json");
    $parentId = (int)($_POST["parent_id"] ?? 0);
    $lessonId = (int)($_POST["lesson_id"] ?? 0);
    $courseId = (int)($_POST["course_id"] ?? 0);
    $msg      = trim($_POST["message"] ?? "");
    if (!$msg || !$parentId || !$lessonId) { echo json_encode(["ok"=>false,"msg"=>"Invalid."]); exit; }
    $chk = $pdo->prepare("SELECT 1 FROM courses WHERE id=? AND instructor_id=? LIMIT 1");
    $chk->execute([$courseId, $uid]);
    if (!$chk->fetch()) { echo json_encode(["ok"=>false,"msg"=>"Access denied."]); exit; }
    $pdo->prepare("INSERT INTO lesson_discussion (lesson_id,course_id,user_id,user_type,message,parent_id) VALUES (?,?,?,?,?,?)")
        ->execute([$lessonId,$courseId,$uid,"instructor",$msg,$parentId]);
    $instrRow = $pdo->prepare("SELECT full_name FROM users WHERE id=? LIMIT 1");
    $instrRow->execute([$uid]);
    $instrName = $instrRow->fetchColumn() ?: "Instructor";
    $orig = $pdo->prepare("SELECT d.user_id,d.user_type,d.message,c.title AS ct,l.title AS lt FROM lesson_discussion d JOIN courses c ON c.id=d.course_id JOIN lessons l ON l.id=d.lesson_id WHERE d.id=? LIMIT 1");
    $orig->execute([$parentId]);
    $origRow = $orig->fetch();
    if ($origRow && $origRow["user_type"]==="student") {
        $sRow = $pdo->prepare("SELECT full_name,email FROM students WHERE id=? LIMIT 1");
        $sRow->execute([$origRow["user_id"]]);
        $student = $sRow->fetch();
        if ($student && $student["email"]) {
            $html = buildEmailTemplate("Your instructor replied",
                "<p style='font-size:14px;color:rgba(255,255,255,.8)'>Hi <strong>"
                .htmlspecialchars($student["full_name"])."</strong>,</p>"
                ."<p style='font-size:13px;color:rgba(255,255,255,.7);line-height:1.7;margin:0 0 16px'>"
                .htmlspecialchars($instrName)." replied to your question in <strong style='color:#c8a84b'>".htmlspecialchars($origRow["ct"])."</strong>.</p>"
                ."<div style='background:#0d2a4e;border-left:4px solid rgba(255,255,255,.15);border-radius:8px;padding:12px 16px;margin-bottom:12px'><div style='font-size:11px;color:rgba(255,255,255,.3);margin-bottom:4px'>Your question</div><div style='font-size:12px;color:rgba(255,255,255,.6);font-style:italic'>".htmlspecialchars(mb_substr($origRow["message"],0,200))."</div></div>"
                ."<div style='background:#0d2a4e;border-left:4px solid #c8a84b;border-radius:8px;padding:12px 16px;margin-bottom:20px'><div style='font-size:11px;color:#c8a84b;margin-bottom:4px'>Reply from instructor</div><div style='font-size:13px;color:#fff'>".nl2br(htmlspecialchars($msg))."</div></div>"
                ."<a href='".BASE_URL."/student/watch.php?id=".$courseId."'style='display:inline-block;background:#c8a84b;color:#001a3e;font-weight:700;padding:11px 24px;border-radius:8px;text-decoration:none;font-size:13px'> View Discussion</a>"
            );
            sendMail($student["email"],$student["full_name"],$instrName." replied to your question",$html);
        }
    }
    echo json_encode(["ok"=>true,"full_name"=>htmlspecialchars($instrName),"message"=>htmlspecialchars($msg),"initial"=>strtoupper(mb_substr($instrName,0,1)),"time"=>date("g:i A")]);
    exit;
}

$questions = $pdo->prepare("
    SELECT d.id,d.lesson_id,d.course_id,d.message,d.created_at,d.user_id,d.user_type,
           COALESCE(NULLIF(s.full_name,''),NULLIF(u2.full_name,''),'Unknown') AS student_name,
           l.title AS lesson_title, c.title AS course_title,
           (SELECT COUNT(*) FROM lesson_discussion r WHERE r.parent_id=d.id) AS reply_count
    FROM lesson_discussion d
    JOIN courses c ON c.id=d.course_id AND c.instructor_id=?
    JOIN lessons l ON l.id=d.lesson_id
    LEFT JOIN students s ON s.id=d.user_id
    LEFT JOIN users u2  ON u2.id=d.user_id
    WHERE d.parent_id IS NULL
    ORDER BY d.created_at DESC
");
$questions->execute([$uid]);
$questions = $questions->fetchAll();

$replies = [];
if ($questions) {
    $qIds = array_column($questions,"id");
    $ph = implode(",",array_fill(0,count($qIds),"?"));
    $rStmt = $pdo->prepare("SELECT d.id,d.parent_id,d.message,d.created_at,d.user_id,d.user_type,
        COALESCE(NULLIF(s.full_name,''),NULLIF(u2.full_name,''),'Unknown') AS full_name
        FROM lesson_discussion d
        LEFT JOIN students s ON s.id=d.user_id
        LEFT JOIN users u2  ON u2.id=d.user_id
        WHERE d.parent_id IN ($ph) ORDER BY d.created_at ASC");
    $rStmt->execute($qIds);
    foreach($rStmt->fetchAll() as $r) $replies[$r["parent_id"]][] = $r;
}

$pageTitle="Student Q&A";$pageSubtitle=count($questions)." question(s) from your students";$activePage="qa";$depth=1;
ob_start();
?>
<?php if(empty($questions)): ?>
<div style="text-align:center;padding:60px 20px;color:var(--muted)">
  <i class="fas fa-comments" style="font-size:48px;display:block;margin-bottom:16px;opacity:.3"></i>
  <h3 style="font-family:'Cinzel',serif;color:var(--white);margin-bottom:8px">No Questions Yet</h3>
  <p>When students post questions in your course lessons, they will appear here for you to reply.</p>
</div>
<?php else: ?>
<style>
.qa-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;margin-bottom:16px;overflow:hidden}
.qa-head{padding:14px 18px;display:flex;align-items:flex-start;gap:12px}
.qa-av{width:36px;height:36px;border-radius:50%;background:rgba(200,168,75,.15);border:1px solid rgba(200,168,75,.3);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:var(--gold);flex-shrink:0}
.qa-av.instr{background:rgba(41,128,185,.15);border-color:rgba(41,128,185,.3);color:#64b5f6}
.qa-meta{font-size:11px;color:var(--muted);margin-bottom:4px}
.qa-meta strong{color:var(--white)}
.qa-msg{font-size:14px;color:var(--text);line-height:1.6}
.qa-foot{padding:10px 18px;background:rgba(0,0,0,.12);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.qa-replies{padding:0 18px 0 52px;border-top:1px solid var(--border)}
.qa-ri{padding:10px 0;border-bottom:1px solid rgba(255,255,255,.04);display:flex;gap:10px;align-items:flex-start}
.qa-ri:last-child{border-bottom:none}
.reply-box{display:none;padding:12px 18px 14px;border-top:1px solid var(--border);background:rgba(0,0,0,.1)}
.reply-box textarea{width:100%;background:var(--surf2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'Raleway',sans-serif;font-size:13px;padding:10px 12px;resize:vertical;min-height:70px;outline:none;box-sizing:border-box}
.reply-box textarea:focus{border-color:var(--gold)}
.instr-badge{display:inline-flex;align-items:center;gap:3px;font-size:9px;padding:1px 7px;border-radius:8px;font-weight:700;background:rgba(41,128,185,.15);color:#64b5f6;margin-left:5px}
</style>
<?php foreach($questions as $q): ?>
<div class="qa-card" id="q-<?= $q["id"] ?>">
  <div class="qa-head">
    <div class="qa-av"><?= strtoupper(mb_substr($q["student_name"],0,1)) ?></div>
    <div style="flex:1;min-width:0">
      <div class="qa-meta">
        <strong><?= h($q["student_name"]) ?></strong> &middot;
        <span style="color:var(--gold)"><?= h($q["course_title"]) ?></span> &middot;
        <?= h($q["lesson_title"]) ?> &middot; <?= date("M j, Y g:i A",strtotime($q["created_at"])) ?>
      </div>
      <div class="qa-msg"><?= nl2br(h($q["message"])) ?></div>
    </div>
  </div>
  <?php if(!empty($replies[$q["id"]])): ?>
  <div class="qa-replies">
    <?php foreach($replies[$q["id"]] as $r): ?>
    <div class="qa-ri">
      <div class="qa-av <?= $r["user_type"]==="instructor"?"instr":"" ?>" style="width:30px;height:30px;font-size:11px"><?= strtoupper(mb_substr($r["full_name"],0,1)) ?></div>
      <div style="flex:1">
        <div class="qa-meta">
          <strong><?= h($r["full_name"]) ?></strong>
          <?php if($r["user_type"]==="instructor"): ?><span class="instr-badge"><i class="fas fa-chalkboard-teacher"></i> Instructor</span><?php endif; ?>
          &middot; <?= date("M j, Y g:i A",strtotime($r["created_at"])) ?>
        </div>
        <div class="qa-msg" style="font-size:13px"><?= nl2br(h($r["message"])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="qa-foot">
    <span style="font-size:12px;color:var(--muted)"><i class="fas fa-reply"></i> <?= $q["reply_count"] ?> repl<?= $q["reply_count"]!=1?"ies":"y" ?></span>
    <button class="btn btn-primary btn-sm" onclick="toggleReply(<?= $q["id"] ?>)"><i class="fas fa-reply"></i> Reply</button>
  </div>
  <div class="reply-box" id="rb-<?= $q["id"] ?>">
    <textarea id="rt-<?= $q["id"] ?>" placeholder="Reply to <?= h($q["student_name"]) ?>..."></textarea>
    <div style="display:flex;gap:8px;margin-top:8px">
      <button class="btn btn-primary btn-sm" onclick="doReply(<?= $q["id"] ?>,<?= $q["lesson_id"] ?>,<?= $q["course_id"] ?>)"><i class="fas fa-paper-plane"></i> Send</button>
      <button class="btn btn-secondary btn-sm" onclick="toggleReply(<?= $q["id"] ?>)">Cancel</button>
    </div>
  </div>
</div>
<?php endforeach; ?>
<script>
function toggleReply(id){
    var rb=document.getElementById("rb-"+id);
    rb.style.display=rb.style.display==="block"?"none":"block";
    if(rb.style.display==="block") document.getElementById("rt-"+id).focus();
}
function doReply(qid,lid,cid){
    var ta=document.getElementById("rt-"+qid);
    var msg=ta.value.trim();
    if(!msg){alert("Write a reply first.");return;}

    var fd=new FormData();
    fd.append("ajax_reply","1");
    fd.append("parent_id",qid);
    fd.append("lesson_id",lid);
    fd.append("course_id",cid);
    fd.append("message",msg);

    fetch("qa.php",{method:"POST",body:fd})
        .then(function(r){return r.json();})
        .then(function(res){
            if(!res.ok){alert(res.msg||"Error sending reply.");return;}

            var card=document.getElementById("q-"+qid);
            var rep=card.querySelector(".qa-replies");
            if(!rep){
                rep=document.createElement("div");
                rep.className="qa-replies";
                card.querySelector(".qa-foot").before(rep);
            }
            var safeMsg=res.message.replace(/(?:\r\n|\r|\n)/g,"<br>");
            rep.insertAdjacentHTML("beforeend",
                "<div class='qa-ri'>"+
                  "<div class='qa-av instr' style='width:30px;height:30px;font-size:11px'>"+res.initial+"</div>"+
                  "<div style='flex:1'>"+
                    "<div class='qa-meta'><strong>"+res.full_name+"</strong>"+
                    "<span class='instr-badge'><i class='fas fa-chalkboard-teacher'></i> Instructor</span>"+
                    " &middot; "+res.time+"</div>"+
                    "<div class='qa-msg' style='font-size:13px'>"+safeMsg+"</div>"+
                  "</div>"+
                "</div>"
            );

            ta.value="";
            document.getElementById("rb-"+qid).style.display="none";

            var f=card.querySelector(".qa-foot span");
            var c=(parseInt(f.textContent)||0)+1;
            f.innerHTML="<i class='fas fa-reply'></i> "+c+" repl"+(c!==1?"ies":"y");
        })
        .catch(function(){alert("Network error. Please try again.");});
}
</script>
<?php endif; ?>
<?php $pageContent=ob_get_clean();require_once "../includes/layout.php";
