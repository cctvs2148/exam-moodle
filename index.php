<?php
declare(strict_types=1);
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    echo '<h1>Cycle Exam Portal is not configured</h1><p>Copy <code>config.example.php</code> to <code>config.php</code>, edit the database settings, then import <code>install.sql</code> in phpMyAdmin.</p>';
    exit;
}
$config = require $configFile;
require_once __DIR__.'/descriptive_marking.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Kolkata');
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('cycle_exam_session');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax'
]);
session_start();
if (!empty($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > ((int)($config['session_timeout_minutes'] ?? 45) * 60)) {
    $_SESSION = [];
    session_destroy();
    session_start();
    $_SESSION['flash'] = ['type'=>'warning','message'=>'Your session expired. Please sign in again.'];
}
$_SESSION['last_activity'] = time();

try {
    $pdo = new PDO(
        'mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',
        $config['db_user'], $config['db_pass'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Cycle Exam DB connection error: '.$e->getMessage());
    echo '<h1>Database connection failed</h1><p>Check config.php, confirm MySQL is running, and import install.sql.</p>';
    exit;
}

try {
    $hasFlagColumn = static function () use ($pdo): bool {
        $s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attempt_answers' AND COLUMN_NAME='is_flagged'");
        $s->execute();
        return (int)$s->fetchColumn() > 0;
    };
    if (!$hasFlagColumn()) {
        try {
            $pdo->exec('ALTER TABLE attempt_answers ADD is_flagged TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $migrationError) {
            if (!$hasFlagColumn()) throw $migrationError;
        }
    }
} catch (Throwable $e) {
    http_response_code(503);
    error_log('Cycle Exam schema migration error (attempt_answers.is_flagged): '.$e->getMessage());
    echo '<h1>Database update required</h1><p>The exam database is missing a required column, and automatic setup could not apply the update. Ask your administrator to run this SQL in phpMyAdmin:</p><pre>ALTER TABLE attempt_answers ADD is_flagged TINYINT(1) NOT NULL DEFAULT 0;</pre><p>Then reload the page. No exam answers were deleted.</p>';
    exit;
}

try {
    foreach (['questions'=>'is_archived','descriptive_questions'=>'is_archived'] as $table=>$column) {
        $s=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $s->execute([$table,$column]);
        if ((int)$s->fetchColumn()===0) {
            try {
                $pdo->exec('ALTER TABLE '.$table.' ADD '.$column.' TINYINT(1) NOT NULL DEFAULT 0');
            } catch (Throwable $migrationError) {
                $s->execute([$table,$column]);
                if ((int)$s->fetchColumn()===0) throw $migrationError;
            }
        }
    }
} catch (Throwable $e) {
    http_response_code(503);
    error_log('Cycle Exam schema migration error (question archive flags): '.$e->getMessage());
    $archiveSql=[];
    foreach (['questions'=>'is_archived','descriptive_questions'=>'is_archived'] as $table=>$column) {
        $check=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $check->execute([$table,$column]);
        if ((int)$check->fetchColumn()===0)$archiveSql[]='ALTER TABLE '.$table.' ADD '.$column.' TINYINT(1) NOT NULL DEFAULT 0;';
    }
    echo '<h1>Database update required</h1><p>Question archiving needs additional columns. Ask your administrator to run the missing SQL in phpMyAdmin:</p><pre>'.htmlspecialchars(implode("\n",$archiveSql),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</pre><p>No question or exam data was deleted.</p>';
    exit;
}

function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): void {
    $sent = (string)($_POST['csrf'] ?? '');
    if (!$sent || !hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) { http_response_code(419); exit('Security token expired. Refresh and try again.'); }
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void { if (!current_user()) { header('Location: index.php?action=login'); exit; } }
function require_role(array $roles): void { require_login(); if (!in_array(current_user()['role'] ?? '', $roles, true)) { http_response_code(403); exit('Access denied.'); } }
function flash(string $type, string $message): void { $_SESSION['flash'] = compact('type','message'); }
function redirect(string $action): never { header('Location: index.php?action='.rawurlencode($action)); exit; }
function audit(PDO $pdo, string $action, string $targetType='', ?string $targetId=null, array $meta=[]): void {
    try {
        $s=$pdo->prepare('INSERT INTO audit_logs(actor_user_id,action,target_type,target_id,metadata,ip_address,created_at) VALUES(?,?,?,?,?,?,NOW())');
        $s->execute([current_user()['id'] ?? null,$action,$targetType,$targetId,json_encode($meta, JSON_UNESCAPED_SLASHES),substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)]);
    } catch (Throwable $ignored) {}
}
function setting(PDO $pdo, string $key, string $default=''): string {
    try { $s=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?'); $s->execute([$key]); $v=$s->fetchColumn(); return $v === false ? $default : (string)$v; }
    catch(Throwable $e) { return $default; }
}
function h(string $s): string { return e($s); }
function student_exam_access(array $exam, ?array $attempt): array {
    if ($attempt && $attempt['status'] === 'cancelled') return ['label'=>'Attempt cancelled','action'=>null];
    if ($attempt && $attempt['status'] !== 'in_progress') return ['label'=>'Submitted','action'=>null];
    if ($attempt) {
        $deadline=min(strtotime($attempt['started_at'])+(int)$exam['duration_minutes']*60,strtotime($exam['end_at']));
        if ($exam['status'] !== 'active' || time() >= $deadline) return ['label'=>'Attempt saved · resume to submit','action'=>'Resume / submit'];
        return ['label'=>'In progress','action'=>'Resume exam'];
    }
    if ($exam['status'] !== 'active') return ['label'=>ucfirst((string)$exam['status']),'action'=>null];
    if (time() < strtotime($exam['start_at'])) return ['label'=>'Opens '.date('d M, H:i',strtotime($exam['start_at'])),'action'=>null];
    if (time() > strtotime($exam['end_at'])) return ['label'=>'Exam window ended','action'=>null];
    return ['label'=>'Available now','action'=>'Take exam'];
}

$action = (string)($_GET['action'] ?? (current_user() ? 'dashboard' : 'login'));
$publicActions = ['login','logout','public_policies'];
if (!in_array($action, $publicActions, true)) require_login();
if (current_user() && !empty(current_user()['must_change_password']) && !in_array($action, ['change_password','logout'], true)) redirect('change_password');
if (current_user() && current_user()['role']==='teacher' && !in_array($action,['subjects','questions','descriptive_questions','descriptive_review','profile','change_password','logout'],true)) redirect('subjects');

if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(), '', time()-42000, $p['path'], '', $p['secure'], $p['httponly']); }
    session_destroy();
    header('Location: index.php?action=login'); exit;
}

if ($action === 'student_template') {
    require_role(['super_admin','exam_admin']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_import_template.csv"');
    $out=fopen('php://output','w');
    fputcsv($out,['register_number','student_name','department','year_level','section']);
    fputcsv($out,['REG2026001','Example Student','Computer Science','1','A']);
    fclose($out); exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45);
    $count = 0;
    try { $q=$pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND succeeded=0"); $q->execute([$ip]); $count=(int)$q->fetchColumn(); } catch(Throwable $e) {}
    if ($count >= 12) { flash('danger','Too many login attempts. Please wait 15 minutes.'); redirect('login'); }
    $s=$pdo->prepare('SELECT id,login_id,display_name,password_hash,role,is_active,must_change_password FROM users WHERE login_id=? LIMIT 1');
    $s->execute([$identifier]); $u=$s->fetch();
    $ok = $u && (int)$u['is_active']===1 && password_verify($password,(string)$u['password_hash']);
    try { $q=$pdo->prepare('INSERT INTO login_attempts(ip_address,login_id,succeeded,attempted_at) VALUES(?,?,?,NOW())'); $q->execute([$ip,$identifier,$ok?1:0]); } catch(Throwable $e) {}
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['user']=['id'=>(int)$u['id'],'login_id'=>$u['login_id'],'name'=>$u['display_name'],'role'=>$u['role'],'must_change_password'=>(int)$u['must_change_password']];
        audit($pdo,'login_success','user',(string)$u['id']);
        redirect(!empty($u['must_change_password']) ? 'change_password' : 'dashboard');
    }
    audit($pdo,'login_failure','user',null,['login_id'=>$identifier]);
    flash('danger','Sign-in failed. Check your register number/username and password.');
    redirect('login');
}

if ($action === 'save_answer') {
    require_role(['student']);
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['saved'=>false,'message'=>'Answer saves require POST.']);
        exit;
    }

    $examId=(int)($_POST['exam_id']??0);$attemptId=(int)($_POST['attempt_id']??0);$questionKey=(string)($_POST['question_id']??'');$questionType='mcq';if(preg_match('/^([md])?(\d+)$/',$questionKey,$questionMatch)){$questionType=($questionMatch[1]??'')==='d'?'typing':'mcq';$questionId=(int)$questionMatch[2];}else $questionId=0;
    $rawAnswer=$_POST['answer']??'';$answer=is_string($rawAnswer)?$rawAnswer:'invalid';$flagged=$_POST['flagged']??'0';
    if($examId<1||$attemptId<1||$questionId<1||$answer==='invalid'||strlen($answer)>60000||!in_array($flagged,['0','1'],true)){
        http_response_code(422);echo json_encode(['saved'=>false,'message'=>'Invalid answer data.']);exit;
    }

    try{
        $s=$pdo->prepare("SELECT e.subject_id,e.exam_pattern,e.status,e.start_at,e.end_at,e.duration_minutes,a.started_at FROM exam_attempts a JOIN exams e ON e.id=a.exam_id LEFT JOIN student_profiles sp ON sp.user_id=a.student_user_id WHERE a.id=? AND a.exam_id=? AND a.student_user_id=? AND a.status='in_progress' AND e.is_archived=0 AND (e.department_id IS NULL OR e.department_id=sp.department_id) AND (e.year_level IS NULL OR e.year_level=sp.year_level)");
        $s->execute([$attemptId,$examId,current_user()['id']]);$attempt=$s->fetch();
        if(!$attempt||$attempt['status']!=='active'||strtotime($attempt['start_at'])>time()||strtotime($attempt['end_at'])<time()||time()>min(strtotime($attempt['started_at'])+(int)$attempt['duration_minutes']*60,strtotime($attempt['end_at']))){
            http_response_code(409);echo json_encode(['saved'=>false,'message'=>'This exam attempt is no longer open.']);exit;
        }
        if($questionType==='typing'&&in_array($attempt['exam_pattern'],['typing','mixed'],true)){
            $s=$pdo->prepare('SELECT 1 FROM descriptive_questions WHERE id=? AND exam_id=? AND is_archived=0');$s->execute([$questionId,$examId]);
            if(!$s->fetchColumn()){http_response_code(422);echo json_encode(['saved'=>false,'message'=>'Question does not belong to this exam.']);exit;}
            $s=$pdo->prepare('INSERT INTO descriptive_answers(attempt_id,descriptive_question_id,answer_text,marking_status,saved_at) VALUES(?,?,?,\'pending_review\',NOW()) ON DUPLICATE KEY UPDATE answer_text=VALUES(answer_text),saved_at=NOW()');
            $s->execute([$attemptId,$questionId,$answer]);
        }elseif($questionType==='mcq'&&in_array($attempt['exam_pattern'],['mcq','mixed'],true)){
            $choice=strtoupper(trim($answer));
            if(!in_array($choice,['','A','B','C','D'],true)){http_response_code(422);echo json_encode(['saved'=>false,'message'=>'Invalid multiple-choice answer.']);exit;}
            $s=$pdo->prepare('SELECT 1 FROM questions WHERE id=? AND subject_id=? AND is_archived=0');$s->execute([$questionId,$attempt['subject_id']]);
            if(!$s->fetchColumn()){http_response_code(422);echo json_encode(['saved'=>false,'message'=>'Question does not belong to this exam.']);exit;}
            $s=$pdo->prepare('INSERT INTO attempt_answers(attempt_id,question_id,selected_option,is_correct,marks_awarded,is_flagged,saved_at) VALUES(?,?,?,NULL,0,?,NOW()) ON DUPLICATE KEY UPDATE selected_option=VALUES(selected_option),is_flagged=VALUES(is_flagged),saved_at=NOW()');
            $s->execute([$attemptId,$questionId,$choice?:null,(int)$flagged]);
        }else{http_response_code(422);echo json_encode(['saved'=>false,'message'=>'Question type does not belong to this exam.']);exit;}
        echo json_encode(['saved'=>true]);
    }catch(Throwable $e){
        error_log('Cycle Exam answer autosave error: '.$e->getMessage());
        http_response_code(500);echo json_encode(['saved'=>false,'message'=>'Answer could not be saved.']);
    }
    exit;
}

function page_start(string $title, PDO $pdo, array $config): void {
    $u=current_user(); $brand=setting($pdo,'institution_name',$config['app_name'] ?? 'Cycle Exam Portal');
    $logo=setting($pdo,'logo_path','');if($logo==='')$logo='favicon_io/android-chrome-192x192.png';
    $primary=setting($pdo,'primary_color','#e31825'); $accent=setting($pdo,'accent_color','#ffc20e');
    $primary=preg_match('/^#[0-9a-fA-F]{6}$/',$primary)?$primary:'#e31825';
    $accent=preg_match('/^#[0-9a-fA-F]{6}$/',$accent)?$accent:'#ffc20e';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="'.$primary.'"><title>'.e($title).' · '.e($brand).'</title><link rel="icon" href="favicon_io/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="favicon_io/favicon-32x32.png"><link rel="icon" type="image/png" sizes="16x16" href="favicon_io/favicon-16x16.png"><link rel="apple-touch-icon" href="favicon_io/apple-touch-icon.png"><link rel="manifest" href="favicon_io/site.webmanifest"><link rel="stylesheet" href="assets/app.css"><style>:root{--primary:'.$primary.';--accent:'.$accent.'}</style></head><body>';
    echo '<header class="topbar"><a class="brand" href="index.php?action=dashboard">'.($logo?'<img class="logo" src="'.e($logo).'" alt="Logo">':'<span class="logo-placeholder">CE</span>').'<span>'.e($brand).'</span></a>';
    if($u)     echo '<div class="top-right"><span>'.e($u['name']).' <small>('.e($u['role']).')</small></span><a class="btn btn-light btn-sm" href="index.php?action=profile">My profile</a><a class="btn btn-light btn-sm" href="index.php?action=change_password">Change password</a><a class="btn btn-light btn-sm" href="index.php?action=logout">Sign out</a></div>';
    echo '</header>';
    if($u) {
        echo '<div class="layout"><aside class="sidebar"><div class="nav-label">WORKSPACE</div>';
        $links = [
            ['dashboard','Dashboard','▦',['super_admin','exam_admin','student']],
            ['students','Students','♙',['super_admin','exam_admin']],
            ['staff','Staff & teachers','♟',['super_admin','exam_admin']],
            ['subjects','Subjects','▤',['super_admin','exam_admin','teacher']],
            ['questions','Question bank','?',['super_admin','exam_admin','teacher']],
            ['descriptive_questions','Typing questions','✎',['super_admin','exam_admin','teacher']],
            ['descriptive_review','Typing review','✓',['super_admin','exam_admin','teacher']],
            ['exams','Examinations','◷',['super_admin','exam_admin','student']],
            ['results','Results & reports','▥',['super_admin','exam_admin','student']],
            ['profile','My profile','♙',['super_admin','exam_admin','teacher','student']],
            ['change_password','Change password','⚿',['super_admin','exam_admin','teacher','student']],
            ['settings','Branding & settings','⚙',['super_admin']],
            ['policies','Policies & rules','☷',['super_admin','exam_admin','student']],
            ['audit','Audit log','≡',['super_admin']]
        ];
        foreach($links as [$key,$label,$ico,$roles]) if(in_array($u['role'],$roles,true)) echo '<a class="nav-link '.($GLOBALS['action']===$key?'active':'').'" href="index.php?action='.$key.'"><span>'.$ico.'</span>'.e($label).'</a>';
        echo '</aside><main class="content">';
    } else echo '<main class="auth-main">';
    if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="alert alert-'.e($f['type']).'">'.e($f['message']).'</div>'; }
    echo '<div class="page-heading"><div><h1>'.e($title).'</h1><p class="muted">'.date('l, d M Y').'</p></div></div>';
}
function page_end(): void { if(current_user()) echo '</main></div>'; else echo '</main>'; echo '<footer class="footer">Powered by College Cycle Test Portal · Use only for authorized academic examinations.</footer><script src="assets/app.js"></script></body></html>'; }
function post_field(string $name, string $label, string $type='text', string $value='', bool $required=false, string $attrs=''): void {
    echo '<label class="field"><span>'.e($label).'</span><input type="'.e($type).'" name="'.e($name).'" value="'.e($value).'" '.($required?'required':'').' '.$attrs.'></label>';
}
function csrf_input(): void { echo '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">'; }
function persist_exam_result(PDO $pdo, int $examId, int $studentId, int $attemptId, float $score, float $max, bool $markEvaluated=false): void {
    $pct=$max>0?($score/$max*100):0;
    $pdo->prepare("UPDATE exam_attempts SET ".($markEvaluated?"status='evaluated',":"")."marks_obtained=?,max_marks=? WHERE id=?")->execute([$score,$max,$attemptId]);
    $pdo->prepare('INSERT INTO results(exam_id,student_user_id,attempt_id,marks_obtained,max_marks,percentage,is_published,created_at) VALUES(?,?,?,?,?,?,0,NOW()) ON DUPLICATE KEY UPDATE marks_obtained=VALUES(marks_obtained),max_marks=VALUES(max_marks),percentage=VALUES(percentage),is_published=0,published_at=NULL')->execute([$examId,$studentId,$attemptId,$score,$max,$pct]);
}

function finalize_exam_attempt(PDO $pdo, int $examId, int $studentId, array $attempt, array $exam, array $answers, array $flags, bool $expired): void {
    if ($expired) $answers = [];
    $pdo->beginTransaction();
    try {
        $score=0.0;$max=0.0;$needsReview=false;
        if (in_array($exam['exam_pattern']??'mcq',['mcq','mixed'],true)) {
            $q=$pdo->prepare('SELECT id,correct_option,marks FROM questions WHERE subject_id=? AND is_archived=0');$q->execute([$exam['subject_id']]);$questionRows=$q->fetchAll();$map=[];foreach($questionRows as $row)$map[(int)$row['id']]=$row;
            $savedAnswers=[];$savedFlags=[];$s=$pdo->prepare('SELECT question_id,selected_option,is_flagged FROM attempt_answers WHERE attempt_id=?');$s->execute([$attempt['id']]);foreach($s->fetchAll() as $saved){$qid=(int)$saved['question_id'];$savedAnswers[$qid]=$saved['selected_option'];$savedFlags[$qid]=(int)$saved['is_flagged'];}
            foreach($map as $qid=>$row){$answerKey='m'.$qid;$max+=(float)$row['marks'];$submitted=$expired?($savedAnswers[$qid]??''):($answers[$answerKey]??$answers[$qid]??'');$choice=is_string($submitted)?strtoupper($submitted):'';$choice=in_array($choice,['A','B','C','D'],true)?$choice:'';$flagInput=$flags[$answerKey]??$flags[$qid]??($savedFlags[$qid]??0);$isFlagged=$flagInput===1||$flagInput==='1';$correct=$choice!==''&&$choice===$row['correct_option'];$awarded=$correct?(float)$row['marks']:0.0;$ins=$pdo->prepare('INSERT INTO attempt_answers(attempt_id,question_id,selected_option,is_correct,marks_awarded,is_flagged,saved_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE selected_option=VALUES(selected_option),is_correct=VALUES(is_correct),marks_awarded=VALUES(marks_awarded),is_flagged=VALUES(is_flagged),saved_at=NOW()');$ins->execute([$attempt['id'],$qid,$choice?:null,$correct?1:0,$awarded,$isFlagged?1:0]);$score+=$awarded;}
        }
        if (in_array($exam['exam_pattern']??'mcq',['typing','mixed'],true)) {
            $s=$pdo->prepare('SELECT * FROM descriptive_questions WHERE exam_id=? AND is_archived=0 ORDER BY question_number,id');$s->execute([$examId]);$descriptiveQuestions=$s->fetchAll();
            $saved=$pdo->prepare('SELECT descriptive_question_id,answer_text FROM descriptive_answers WHERE attempt_id=?');$saved->execute([$attempt['id']]);$savedAnswers=[];foreach($saved->fetchAll() as $row)$savedAnswers[(int)$row['descriptive_question_id']]=$row['answer_text'];
            foreach($descriptiveQuestions as $question){
                $questionId=(int)$question['id'];$answerKey='d'.$questionId;$max+=(float)$question['max_marks'];$submitted=$expired?($savedAnswers[$questionId]??''):($answers[$answerKey]??($savedAnswers[$questionId]??''));if(!is_string($submitted))$submitted='';
                $accepted=json_decode((string)$question['accepted_answers'],true);$keywords=json_decode((string)$question['keyword_rules'],true);
                if(!is_array($accepted)||!is_array($keywords))throw new RuntimeException('Descriptive question marking rules are invalid.');
                $marked=mark_descriptive_answer($submitted,$accepted,$keywords,(float)$question['max_marks'],$question['numeric_tolerance']===null?null:(float)$question['numeric_tolerance'],(bool)$question['ignore_punctuation'],(int)$question['spelling_tolerance']);
                $ins=$pdo->prepare("INSERT INTO descriptive_answers(attempt_id,descriptive_question_id,answer_text,marks_awarded,marking_status,matched_keywords,marking_explanation,saved_at) VALUES(?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE answer_text=VALUES(answer_text),marks_awarded=VALUES(marks_awarded),marking_status=VALUES(marking_status),matched_keywords=VALUES(matched_keywords),marking_explanation=VALUES(marking_explanation),reviewed_by=NULL,reviewed_at=NULL,review_reason=NULL,saved_at=NOW()");
                $ins->execute([$attempt['id'],$questionId,$submitted,$marked['marks'],$marked['status'],json_encode($marked['matched'],JSON_UNESCAPED_UNICODE),$marked['explanation']]);
                if($marked['status']==='pending_review')$needsReview=true;else $score+=(float)$marked['marks'];
            }
        }
        $s=$pdo->prepare("UPDATE exam_attempts SET status='submitted',submitted_at=NOW(),marks_obtained=?,max_marks=? WHERE id=? AND exam_id=? AND student_user_id=? AND status='in_progress'");$s->execute([$needsReview?null:$score,$max,$attempt['id'],$examId,$studentId]);
        if($s->rowCount()!==1)throw new RuntimeException('Attempt could not be finalized because its status changed.');
        if(!$needsReview)persist_exam_result($pdo,$examId,$studentId,(int)$attempt['id'],$score,$max);
        $pdo->commit();audit($pdo,'exam_submitted','attempt',(string)$attempt['id'],['automatic'=>$expired,'pending_review'=>$needsReview]);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

if ($action === 'login') {
    $brand=setting($pdo,'institution_name',$config['app_name'] ?? 'College Cycle Test Portal');
    $logo=setting($pdo,'logo_path','');
    if($logo==='')$logo='favicon_io/android-chrome-192x192.png';
    $primary=setting($pdo,'primary_color','#e31825');$accent=setting($pdo,'accent_color','#ffc20e');
    $primary=preg_match('/^#[0-9a-fA-F]{6}$/',$primary)?$primary:'#e31825';$accent=preg_match('/^#[0-9a-fA-F]{6}$/',$accent)?$accent:'#ffc20e';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="'.$primary.'"><title>Sign in · '.e($brand).'</title><link rel="icon" href="favicon_io/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="favicon_io/favicon-32x32.png"><link rel="icon" type="image/png" sizes="16x16" href="favicon_io/favicon-16x16.png"><link rel="apple-touch-icon" href="favicon_io/apple-touch-icon.png"><link rel="manifest" href="favicon_io/site.webmanifest"><link rel="stylesheet" href="assets/app.css"><style>:root{--primary:'.$primary.';--accent:'.$accent.'}</style></head><body class="login-body"><div class="login-wrap"><div class="login-brand"><img class="login-logo" src="'.e($logo).'" alt="'.e($brand).' logo"><h1>'.e($brand).'</h1><p>Monthly cycle tests · Student examination portal</p></div>';
    if(!empty($_SESSION['flash'])){$f=$_SESSION['flash'];unset($_SESSION['flash']);echo '<div class="alert alert-'.e($f['type']).'">'.e($f['message']).'</div>';}
    echo '<form method="post" action="index.php?action=login" class="card login-card"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><h2>Sign in</h2><p class="muted">Student accounts are created by your college administrator. Sign in with the register number and temporary password they provided. Staff: use your assigned username.</p><label class="field"><span>Register number / username</span><input name="identifier" autocomplete="username" required maxlength="100"></label><label class="field"><span>Password</span><input type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-primary btn-block" type="submit">Sign in securely</button><p class="small muted">You will be asked to change a temporary password at first sign-in. For account access or a password reset, contact your college examination administrator.</p></form><p class="login-policy"><a href="index.php?action=public_policies">Privacy policy & examination rules</a></p></div><script src="assets/app.js"></script></body></html>';
    exit;
}

$titleMap=['dashboard'=>'Dashboard','profile'=>'My profile','change_password'=>'Change password','students'=>'Student management','staff'=>'Staff & teachers','student_import'=>'Import students','subjects'=>'Subjects','questions'=>'Question bank','descriptive_questions'=>'Typing answer questions','descriptive_review'=>'Typing answer review','exams'=>'Examinations','take_exam'=>'Take examination','results'=>'Results & reports','result_detail'=>'Result breakdown','settings'=>'Institution branding & settings','policies'=>'Policies & examination rules','audit'=>'Audit log','public_policies'=>'Policies & examination rules'];
page_start($titleMap[$action] ?? 'Cycle Test Portal',$pdo,$config);

switch($action) {
case 'dashboard':
    $role=current_user()['role'];
    if($role==='student') {
        $sid=(int)current_user()['id'];
        $s=$pdo->prepare("SELECT e.*,s.subject_name FROM exams e JOIN subjects s ON s.id=e.subject_id LEFT JOIN student_profiles sp ON sp.user_id=? WHERE e.status IN ('scheduled','active','closed','published') AND (e.department_id IS NULL OR e.department_id=sp.department_id) AND (e.year_level IS NULL OR e.year_level=sp.year_level) AND e.is_archived=0 ORDER BY e.start_at DESC LIMIT 20"); $s->execute([$sid]); $exams=$s->fetchAll();
        echo '<div class="card"><h2>Your examinations</h2><div class="table-wrap"><table><thead><tr><th>Exam</th><th>Subject</th><th>Starts</th><th>Duration</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach($exams as $ex){$a=$pdo->prepare('SELECT id,status,started_at FROM exam_attempts WHERE exam_id=? AND student_user_id=? LIMIT 1');$a->execute([$ex['id'],$sid]);$attempt=$a->fetch()?:null;$access=student_exam_access($ex,$attempt);echo '<tr><td>'.e($ex['title']).'</td><td>'.e($ex['subject_name']).'</td><td>'.e($ex['start_at']).'</td><td>'.(int)$ex['duration_minutes'].' min</td><td><span class="pill">'.e($access['label']).'</span></td><td>'.($access['action']?'<a class="btn btn-primary btn-sm" href="index.php?action=take_exam&id='.(int)$ex['id'].'">'.e($access['action']).'</a>':'—').'</td></tr>';}
        if(!$exams)echo '<tr><td colspan="6">No examinations are currently assigned to your group.</td></tr>';
        echo '</tbody></table></div></div>';
        echo '<div class="card"><h2>Recent published results</h2>'; $r=$pdo->prepare("SELECT e.title,r.marks_obtained,r.max_marks,r.percentage,r.published_at FROM results r JOIN exams e ON e.id=r.exam_id WHERE r.student_user_id=? AND r.is_published=1 ORDER BY r.published_at DESC LIMIT 10");$r->execute([$sid]);$rows=$r->fetchAll();echo '<div class="table-wrap"><table><thead><tr><th>Exam</th><th>Marks</th><th>Percentage</th><th>Published</th></tr></thead><tbody>';foreach($rows as $x)echo '<tr><td>'.e($x['title']).'</td><td>'.e($x['marks_obtained']).' / '.e($x['max_marks']).'</td><td>'.number_format((float)$x['percentage'],2).'%</td><td>'.e($x['published_at']).'</td></tr>';if(!$rows)echo '<tr><td colspan="4">No published results yet.</td></tr>';echo '</tbody></table></div></div>';
    } else {
        $counts=[]; foreach(['users'=>'SELECT COUNT(*) FROM users WHERE role="student" AND is_active=1','subjects'=>'SELECT COUNT(*) FROM subjects WHERE is_active=1','exams'=>'SELECT COUNT(*) FROM exams WHERE is_archived=0','attempts'=>'SELECT COUNT(*) FROM exam_attempts','pending'=>'SELECT COUNT(*) FROM exam_attempts WHERE status="submitted"'] as $k=>$sql){try{$counts[$k]=(int)$pdo->query($sql)->fetchColumn();}catch(Throwable $e){$counts[$k]=0;}}
        echo '<div class="stats-grid">';foreach(['users'=>'Active students','subjects'=>'Subjects','exams'=>'Examinations','attempts'=>'Exam attempts','pending'=>'Pending evaluation'] as $k=>$v)echo '<div class="stat-card"><span>'.e($v).'</span><strong>'.number_format($counts[$k]??0).'</strong></div>';echo '</div>';
        echo '<div class="quick-grid"><a class="quick-card" href="index.php?action=students"><b>♙ Student management</b><span>Add students or import a CSV list.</span></a><a class="quick-card" href="index.php?action=questions"><b>? Question bank</b><span>Create and maintain MCQ questions.</span></a><a class="quick-card" href="index.php?action=exams"><b>◷ Schedule cycle test</b><span>Create monthly exams and assign groups.</span></a><a class="quick-card" href="index.php?action=results"><b>▥ Results and reports</b><span>Review submissions and publish results.</span></a></div>';
        echo '<div class="card"><h2>Upcoming examinations</h2>'; $rows=$pdo->query("SELECT e.id,e.title,e.start_at,e.end_at,e.status,s.subject_name FROM exams e JOIN subjects s ON s.id=e.subject_id WHERE e.is_archived=0 AND e.end_at>=NOW() ORDER BY e.start_at LIMIT 8")->fetchAll();echo '<div class="table-wrap"><table><thead><tr><th>Exam</th><th>Subject</th><th>Start</th><th>End</th><th>Status</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td>'.e($r['title']).'</td><td>'.e($r['subject_name']).'</td><td>'.e($r['start_at']).'</td><td>'.e($r['end_at']).'</td><td><span class="pill">'.e($r['status']).'</span></td></tr>';if(!$rows)echo '<tr><td colspan="5">No upcoming examinations.</td></tr>';echo '</tbody></table></div></div>';
    }
    break;

case 'profile':
    require_login();
    $userId=(int)current_user()['id'];$student=current_user()['role']==='student';$profile=null;
    if($student){$s=$pdo->prepare('SELECT register_number,email,phone FROM student_profiles WHERE user_id=?');$s->execute([$userId]);$profile=$s->fetch();if(!$profile){http_response_code(409);echo '<div class="alert alert-danger">Your student profile is missing. Contact the examination administrator to repair the account.</div>';break;}}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $name=trim((string)($_POST['display_name']??''));$email=trim((string)($_POST['email']??''));$phone=trim((string)($_POST['phone']??''));
        if($name===''||strlen($name)>180||($student&&((strlen($email)>190)||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))||strlen($phone)>40))){
            flash('danger','Enter a name and valid email/phone values within the allowed lengths.');redirect('profile');
        }
        try{
            $pdo->beginTransaction();$pdo->prepare('UPDATE users SET display_name=?,updated_at=NOW() WHERE id=?')->execute([$name,$userId]);
            if($student)$pdo->prepare('UPDATE student_profiles SET email=?,phone=? WHERE user_id=?')->execute([$email?:null,$phone?:null,$userId]);
            $pdo->commit();$_SESSION['user']['name']=$name;audit($pdo,'profile_updated','user',(string)$userId);flash('success','Profile updated.');redirect('profile');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Cycle Exam profile update error: '.$e->getMessage());flash('danger','Profile could not be saved. Please try again.');redirect('profile');}
    }
    $s=$pdo->prepare('SELECT login_id,display_name,role FROM users WHERE id=?');$s->execute([$userId]);$account=$s->fetch();
    echo '<div class="card narrow"><h2>Account profile</h2><p class="muted">Account ID and role are managed by your institution.</p><div class="profile-account"><p><b>Login / register number:</b> '.e($student?($profile['register_number']??''):$account['login_id']).'</p><p><b>Role:</b> '.e($account['role']).'</p></div><form method="post">';csrf_input();post_field('display_name','Display name','text',(string)$account['display_name'],true,'maxlength="180"');if($student){post_field('email','Email address','email',(string)($profile['email']??''),false,'maxlength="190"');post_field('phone','Phone number','tel',(string)($profile['phone']??''),false,'maxlength="40"');}echo '<button class="btn btn-primary">Save profile</button></form><p class="small muted">To change your password, use the Change password link in the top bar.</p></div>';
    break;

case 'change_password':
    require_login();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $pw=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm']??'');
        if(strlen($pw)<10 || !preg_match('/[A-Za-z]/',$pw) || !preg_match('/[0-9]/',$pw) || $pw!==$confirm) flash('danger','Use at least 10 characters including a letter and a number; both entries must match.');
        else{$s=$pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0,updated_at=NOW() WHERE id=?');$s->execute([password_hash($pw,PASSWORD_DEFAULT),current_user()['id']]);$_SESSION['user']['must_change_password']=0;audit($pdo,'password_changed','user',(string)current_user()['id']);flash('success','Password updated.');redirect('dashboard');}
    }
    echo '<div class="card narrow"><p>Choose a new password. Use at least 10 characters, including a letter and a number.</p><form method="post">';csrf_input();post_field('password','New password','password','',true,'minlength="10"');post_field('confirm','Confirm password','password','',true,'minlength="10"');echo '<button class="btn btn-primary">Update password</button></form></div>';
    break;

case 'students':
    require_role(['super_admin','exam_admin']);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        require_role(['super_admin']);
        $name=trim((string)($_POST['display_name']??''));$reg=trim((string)($_POST['register_number']??''));$dept=trim((string)($_POST['department']??''));$year=trim((string)($_POST['year_level']??''));$section=trim((string)($_POST['section']??''));
        if(!$name||!$reg)flash('danger','Name and register number are required.');
        else {try{$pdo->beginTransaction();$s=$pdo->prepare('INSERT INTO users(login_id,display_name,password_hash,role,is_active,must_change_password,created_at,updated_at) VALUES(?,?,?, ?,1,1,NOW(),NOW())');$temp=bin2hex(random_bytes(8));$s->execute([$reg,$name,password_hash($temp,PASSWORD_DEFAULT),'student']);$uid=(int)$pdo->lastInsertId();$d=null;if($dept!==''){$q=$pdo->prepare('SELECT id FROM departments WHERE department_name=?');$q->execute([$dept]);$d=$q->fetchColumn();if(!$d){$q=$pdo->prepare('INSERT INTO departments(department_name) VALUES(?)');$q->execute([$dept]);$d=$pdo->lastInsertId();}}$q=$pdo->prepare('INSERT INTO student_profiles(user_id,register_number,department_id,year_level,section_name) VALUES(?,?,?,?,?)');$q->execute([$uid,$reg,$d,$year?:null,$section?:null]);$pdo->commit();audit($pdo,'student_created','user',(string)$uid);flash('warning','Student account created. Register number: '.$reg.'. One-time temporary password: '.$temp.'. Share these credentials privately; the student must change the password at first sign-in. This password is shown only once.');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('danger','Student could not be created. Check whether the register number already exists.');error_log($e->getMessage());}}
        redirect('students');
    }
    $q=trim((string)($_GET['q']??''));$sql="SELECT u.id,u.display_name,u.login_id,u.is_active,sp.year_level,sp.section_name,d.department_name FROM users u LEFT JOIN student_profiles sp ON sp.user_id=u.id LEFT JOIN departments d ON d.id=sp.department_id WHERE u.role='student'";$params=[];if($q!==''){$sql.=" AND (u.display_name LIKE ? OR u.login_id LIKE ?)";$params=["%$q%","%$q%"];} $sql.=" ORDER BY u.display_name LIMIT 100";$s=$pdo->prepare($sql);$s->execute($params);$rows=$s->fetchAll();
    if(current_user()['role']==='super_admin')echo '<div class="card"><h2>Add a student</h2><p class="muted">A unique temporary password is generated for each student and shown once after creation. Share it privately; the student must change it at first sign-in.</p><form method="post" class="form-grid">';if(current_user()['role']==='super_admin'){csrf_input();post_field('display_name','Student name','text','',true);post_field('register_number','Register number','text','',true);post_field('department','Department','text');post_field('year_level','Year / level','text');post_field('section','Section / batch','text');echo '<div class="form-actions"><button class="btn btn-primary">Create student</button><a class="btn btn-light" href="index.php?action=student_import">Import CSV</a><a class="btn btn-light" href="index.php?action=student_template">Download template</a></div></form></div>';}
    echo '<div class="card"><div class="card-title-row"><h2>Students</h2><form class="inline-search"><input name="q" value="'.e($q).'" placeholder="Search name or register number"><input type="hidden" name="action" value="students"><button class="btn btn-light">Search</button></form></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>Register number</th><th>Department</th><th>Year</th><th>Section</th><th>Status</th><th>Action</th></tr></thead><tbody>';foreach($rows as $r){echo '<tr><td>'.e($r['display_name']).'</td><td>'.e($r['login_id']).'</td><td>'.e($r['department_name']??'—').'</td><td>'.e($r['year_level']??'—').'</td><td>'.e($r['section_name']??'—').'</td><td>'.((int)$r['is_active']?'Active':'Inactive').'</td><td><div class="inline-form"><form method="post" action="index.php?action=toggle_student">';csrf_input();echo '<input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn btn-light btn-sm">'.((int)$r['is_active']?'Deactivate':'Activate').'</button></form><form method="post" action="index.php?action=reset_student_password" onsubmit="return confirm(&quot;Generate a one-time temporary password?&quot;)">';csrf_input();echo '<input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn btn-light btn-sm">Reset password</button></form></div></td></tr>';}if(!$rows)echo '<tr><td colspan="7">No students found.</td></tr>';echo '</tbody></table></div><p class="muted small">Showing up to 100 rows. Search to narrow the list. Historical exam records are preserved.</p></div>';
    break;

case 'staff':
    require_role(['super_admin','exam_admin']);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        require_role(['super_admin']);
        $name=trim((string)($_POST['display_name']??''));$login=trim((string)($_POST['login_id']??''));$role=(string)($_POST['role']??'teacher');
        $allowedRoles=current_user()['role']==='super_admin'?['teacher','exam_admin']:['teacher'];
        if($name===''||strlen($name)>180||$login===''||strlen($login)>100||!in_array($role,$allowedRoles,true)){
            flash('danger','Enter a valid name and login ID, then choose a role you are authorized to create.');redirect('staff');
        }
        try{
            $temp=bin2hex(random_bytes(8));
            $s=$pdo->prepare('INSERT INTO users(login_id,display_name,password_hash,role,is_active,must_change_password,created_at,updated_at) VALUES(?,?,?,?,1,1,NOW(),NOW())');
            $s->execute([$login,$name,password_hash($temp,PASSWORD_DEFAULT),$role]);
            $id=(int)$pdo->lastInsertId();audit($pdo,'staff_created','user',(string)$id,['role'=>$role]);
            flash('warning',ucfirst(str_replace('_',' ',$role)).' account created. Login ID: '.$login.'. One-time temporary password: '.$temp.'. Share these credentials privately; the user must change the password at first sign-in. This password is shown only once.');
        }catch(Throwable $e){error_log('Cycle Exam staff account creation error: '.$e->getMessage());flash('danger','Account could not be created. Check whether the login ID is already in use.');}
        redirect('staff');
    }
    $isSuperAdmin=current_user()['role']==='super_admin';
    $allowedRoles=['teacher','exam_admin'];
    $staffSql="SELECT id,login_id,display_name,role,is_active FROM users WHERE role IN ('teacher','exam_admin')";
    if(!$isSuperAdmin)$staffSql.=" AND role='teacher'";
    $staffSql.=" ORDER BY role,display_name";
    $s=$pdo->query($staffSql);
    $rows=$s->fetchAll();
    if($isSuperAdmin){echo '<div class="card"><h2>Create staff account</h2><p class="muted">Create a login for a teacher or authorized examination staff member. A temporary password is generated and shown once; the user must change it at first sign-in.</p><form method="post" class="form-grid">';csrf_input();post_field('display_name','Staff / teacher name','text','',true,'maxlength="180"');post_field('login_id','Login ID','text','',true,'maxlength="100"');echo '<label class="field"><span>Role</span><select name="role" required>';foreach($allowedRoles as $role)echo '<option value="'.e($role).'">'.e(ucfirst(str_replace('_',' ',$role))).'</option>';echo '</select></label><div class="form-actions"><button class="btn btn-primary">Create account</button></div></form></div>';}
    else echo '<div class="card"><p class="muted">Exam admins can manage teacher access and reset teacher passwords. Only a super admin can create staff accounts or manage exam-admin accounts.</p></div>';
    echo '<div class="card"><h2>Staff and teacher accounts</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Login ID</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    foreach($rows as $r){$targetId=(int)$r['id'];$isSelf=$targetId===(int)current_user()['id'];$canManage=(!$isSelf)&&($isSuperAdmin||$r['role']==='teacher');echo '<tr><td>'.e($r['display_name']).'</td><td>'.e($r['login_id']).'</td><td>'.e(ucfirst(str_replace('_',' ',$r['role']))).'</td><td>'.((int)$r['is_active']?'Active':'Inactive').'</td><td>';if($canManage){echo '<div class="inline-form"><form method="post" action="index.php?action=toggle_staff">';csrf_input();echo '<input type="hidden" name="id" value="'.$targetId.'"><button class="btn btn-light btn-sm">'.((int)$r['is_active']?'Deactivate':'Activate').'</button></form><form method="post" action="index.php?action=reset_staff_password" onsubmit="return confirm(&quot;Generate a one-time temporary password?&quot;)">';csrf_input();echo '<input type="hidden" name="id" value="'.$targetId.'"><button class="btn btn-light btn-sm">Reset password</button></form></div>';}else echo '<span class="muted small">'.($isSelf?'Your own account':'Managed by a super admin').'</span>';echo '</td></tr>';}
    if(!$rows)echo '<tr><td colspan="5">No staff or teacher accounts found.</td></tr>';echo '</tbody></table></div></div>';
    break;

case 'toggle_staff':
    require_role(['super_admin','exam_admin']);
    $id=(int)($_POST['id']??0);$s=$pdo->prepare("SELECT role FROM users WHERE id=? AND role IN ('teacher','exam_admin')");$s->execute([$id]);$targetRole=$s->fetchColumn();
    if(!$targetRole||$id===(int)current_user()['id']||(current_user()['role']==='exam_admin'&&$targetRole!=='teacher')){flash('danger','You are not authorized to change this account.');redirect('staff');}
    $s=$pdo->prepare('UPDATE users SET is_active=IF(is_active=1,0,1),updated_at=NOW() WHERE id=?');$s->execute([$id]);audit($pdo,'staff_status_toggled','user',(string)$id,['role'=>$targetRole]);flash('success','Staff account status updated.');redirect('staff');

case 'reset_staff_password':
    require_role(['super_admin','exam_admin']);
    $id=(int)($_POST['id']??0);$s=$pdo->prepare("SELECT role FROM users WHERE id=? AND role IN ('teacher','exam_admin')");$s->execute([$id]);$targetRole=$s->fetchColumn();
    if(!$targetRole||$id===(int)current_user()['id']||(current_user()['role']==='exam_admin'&&$targetRole!=='teacher')){flash('danger','You are not authorized to reset this account.');redirect('staff');}
    $temp=bin2hex(random_bytes(6));$s=$pdo->prepare('UPDATE users SET password_hash=?,must_change_password=1,updated_at=NOW() WHERE id=?');$s->execute([password_hash($temp,PASSWORD_DEFAULT),$id]);audit($pdo,'staff_password_reset','user',(string)$id,['role'=>$targetRole]);flash('warning','One-time temporary password: '.$temp.'. Share it securely; the user must change it at first sign-in. This password is shown only once.');redirect('staff');

case 'toggle_student':
    require_role(['super_admin','exam_admin']);
    $id=(int)($_POST['id']??0);$s=$pdo->prepare("UPDATE users SET is_active=IF(is_active=1,0,1),updated_at=NOW() WHERE id=? AND role='student'");$s->execute([$id]);audit($pdo,'student_status_toggled','user',(string)$id);flash('success','Student status updated.');redirect('students');

case 'reset_student_password':
    require_role(['super_admin','exam_admin']);
    $id=(int)($_POST['id']??0);$temp=bin2hex(random_bytes(6));$s=$pdo->prepare("UPDATE users SET password_hash=?,must_change_password=1,updated_at=NOW() WHERE id=? AND role='student'");$s->execute([password_hash($temp,PASSWORD_DEFAULT),$id]);if($s->rowCount()){audit($pdo,'student_password_reset','user',(string)$id);flash('warning','One-time temporary password for this student: '.$temp.'. Share it securely and ask the student to change it immediately. It will not be shown again.');}else{flash('danger','Student account was not found.');}redirect('students');

case 'student_template':
    require_role(['super_admin']);
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="student_import_template.csv"');$out=fopen('php://output','w');fputcsv($out,['register_number','student_name','department','year_level','section']);fputcsv($out,['REG2026001','Example Student','Computer Science','1','A']);fclose($out);exit;

case 'student_import':
    require_role(['super_admin']);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(empty($_FILES['csv']['tmp_name'])||($_FILES['csv']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) {flash('danger','Choose a valid CSV file.');redirect('student_import');}
        if((int)$_FILES['csv']['size']>((int)($config['max_upload_mb']??5)*1024*1024)){flash('danger','File exceeds the upload size limit.');redirect('student_import');}
        $f=fopen($_FILES['csv']['tmp_name'],'r');$header=fgetcsv($f);$header=array_map(fn($x)=>strtolower(trim(preg_replace('/^\\xEF\\xBB\\xBF/', '', (string)$x))), (array)$header);$required=['register_number','student_name'];if(array_diff($required,$header)){fclose($f);flash('danger','CSV must contain register_number and student_name columns. Download the template.');redirect('student_import');}
        $idx=array_flip($header);$seen=[];$inserted=0;$skipped=0;$errors=[];$rowNum=1;
        while(($row=fgetcsv($f))!==false){$rowNum++;$reg=trim((string)($row[$idx['register_number']]??''));$name=trim((string)($row[$idx['student_name']]??''));$dept=trim((string)($row[$idx['department']]??''));$year=trim((string)($row[$idx['year_level']]??''));$section=trim((string)($row[$idx['section']]??''));
            if($reg===''||$name===''){$errors[]="Row $rowNum: register number and student name are required.";continue;}if(isset($seen[$reg])){$errors[]="Row $rowNum: duplicate register number in file.";continue;}$seen[$reg]=true;
            try{$pdo->beginTransaction();$check=$pdo->prepare('SELECT id FROM users WHERE login_id=?');$check->execute([$reg]);if($check->fetchColumn()){$pdo->rollBack();$skipped++;continue;}
                $s=$pdo->prepare('INSERT INTO users(login_id,display_name,password_hash,role,is_active,must_change_password,created_at,updated_at) VALUES(?,?,?, ?,1,1,NOW(),NOW())');$temp=bin2hex(random_bytes(12));$s->execute([$reg,$name,password_hash($temp,PASSWORD_DEFAULT),'student']);$uid=(int)$pdo->lastInsertId();$deptId=null;if($dept!==''){$d=$pdo->prepare('SELECT id FROM departments WHERE department_name=?');$d->execute([$dept]);$deptId=$d->fetchColumn();if(!$deptId){$d=$pdo->prepare('INSERT INTO departments(department_name) VALUES(?)');$d->execute([$dept]);$deptId=(int)$pdo->lastInsertId();}}$p=$pdo->prepare('INSERT INTO student_profiles(user_id,register_number,department_id,year_level,section_name) VALUES(?,?,?,?,?)');$p->execute([$uid,$reg,$deptId,$year?:null,$section?:null]);$pdo->commit();$inserted++;
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$errors[]="Row $rowNum: import failed (possibly a duplicate).";error_log($e->getMessage());}
        }fclose($f);audit($pdo,'student_csv_import','students',null,['inserted'=>$inserted,'skipped'=>$skipped,'errors'=>count($errors)]);$_SESSION['import_result']=['inserted'=>$inserted,'skipped'=>$skipped,'errors'=>$errors];redirect('student_import');
    }
    echo '<div class="card narrow"><p>Import students from a UTF-8 CSV file. Download the template first. Existing register numbers are skipped; passwords are not imported.</p><form method="post" enctype="multipart/form-data">';csrf_input();echo '<label class="field"><span>CSV file</span><input type="file" name="csv" accept=".csv,text/csv" required></label><button class="btn btn-primary">Validate and import CSV</button></form><p class="muted small">For Excel, open the workbook and choose Save As → CSV UTF-8. XLSX parsing can be added with PhpSpreadsheet if Composer is available.</p></div>';
    if(!empty($_SESSION['import_result'])){$r=$_SESSION['import_result'];unset($_SESSION['import_result']);echo '<div class="card"><h2>Import summary</h2><p>Inserted: <b>'.(int)$r['inserted'].'</b> · Skipped existing: <b>'.(int)$r['skipped'].'</b> · Errors: <b>'.count($r['errors']).'</b></p><ul>';foreach(array_slice($r['errors'],0,100) as $err)echo '<li>'.e($err).'</li>';echo '</ul></div>';}
    break;

case 'subjects':
    require_role(['super_admin','exam_admin','teacher']);
    $canManageSubjects=in_array(current_user()['role'],['super_admin','exam_admin'],true);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        require_role(['super_admin','exam_admin']);$name=trim((string)($_POST['subject_name']??''));$code=trim((string)($_POST['subject_code']??''));$id=(int)($_POST['subject_id']??0);
        if($name===''||strlen($name)>180||strlen($code)>80){flash('danger','Enter a subject name (up to 180 characters) and code (up to 80 characters).');if($id){header('Location: index.php?action=subjects&edit='.$id);exit;}redirect('subjects');}
        try{
            if($id){$s=$pdo->prepare('UPDATE subjects SET subject_name=?,subject_code=? WHERE id=?');$s->execute([$name,$code?:null,$id]);if(!$s->rowCount()){ $s=$pdo->prepare('SELECT id FROM subjects WHERE id=?');$s->execute([$id]);if(!$s->fetchColumn())throw new RuntimeException('Subject not found.');}audit($pdo,'subject_updated','subject',(string)$id);flash('success','Subject updated.');}
            else{$s=$pdo->prepare('INSERT INTO subjects(subject_name,subject_code,is_active,created_at) VALUES(?,?,1,NOW())');$s->execute([$name,$code?:null]);audit($pdo,'subject_created','subject',(string)$pdo->lastInsertId());flash('success','Subject created.');}
        }catch(Throwable $e){error_log('Cycle Exam subject save error: '.$e->getMessage());flash('danger','Subject could not be saved. Check that the subject code is not already used.');}
        redirect('subjects');
    }
    $editId=(int)($_GET['edit']??0);$editSubject=null;if($editId){$s=$pdo->prepare('SELECT * FROM subjects WHERE id=?');$s->execute([$editId]);$editSubject=$s->fetch()?:null;}
    if($editId&&!$editSubject)echo '<div class="alert alert-danger">Subject not found.</div>';
    if($canManageSubjects){echo '<div class="card"><h2>'.($editSubject?'Edit subject':'Add subject').'</h2><form method="post" class="form-grid">';csrf_input();if($editSubject)echo '<input type="hidden" name="subject_id" value="'.(int)$editSubject['id'].'">';post_field('subject_name','Subject name','text',(string)($editSubject['subject_name']??''),true,'maxlength="180"');post_field('subject_code','Subject code','text',(string)($editSubject['subject_code']??''),false,'maxlength="80"');echo '<div class="form-actions"><button class="btn btn-primary">'.($editSubject?'Save subject':'Add subject').'</button>'.($editSubject?'<a class="btn btn-light" href="index.php?action=subjects">Cancel</a>':'').'</div></form></div>';}
    $rows=$pdo->query('SELECT * FROM subjects ORDER BY subject_name')->fetchAll();echo '<div class="card"><h2>Subjects</h2><div class="table-wrap"><table><thead><tr><th>Subject</th><th>Code</th><th>Status</th>';if($canManageSubjects)echo '<th>Action</th>';echo '</tr></thead><tbody>';foreach($rows as $r){echo '<tr><td>'.e($r['subject_name']).'</td><td>'.e($r['subject_code']??'—').'</td><td>'.((int)$r['is_active']?'Active':'Inactive').'</td>';if($canManageSubjects)echo '<td><a class="btn btn-light btn-sm" href="index.php?action=subjects&edit='.(int)$r['id'].'">Edit</a></td>';echo '</tr>';}if(!$rows)echo '<tr><td colspan="'.($canManageSubjects?4:3).'">No subjects added yet.</td></tr>';echo '</tbody></table></div></div>';
    break;

case 'questions':
    require_role(['super_admin','exam_admin','teacher']);
    $canManageQuestions=in_array(current_user()['role'],['super_admin','exam_admin','teacher'],true);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        require_role(['super_admin','exam_admin','teacher']);
        $id=(int)($_POST['question_id']??0);$archiveId=(int)($_POST['archive_question_id']??0);$archiveValue=(int)($_POST['archive_value']??0);
        $returnSubject=(int)($_POST['return_subject']??0);$returnSearch=trim((string)($_POST['return_search']??''));$returnArchived=($_POST['return_archived']??'1')==='1';$returnPage=max(1,(int)($_POST['return_page']??1));
        $returnParams=['action'=>'questions'];if($returnSubject>0)$returnParams['subject_id']=$returnSubject;if($returnSearch!=='')$returnParams['search']=$returnSearch;$returnParams['archived']=$returnArchived?'1':'0';if($returnPage>1)$returnParams['page']=$returnPage;
        if($archiveId>0){
            try{
                $s=$pdo->prepare('SELECT subject_id,is_archived FROM questions WHERE id=?');$s->execute([$archiveId]);$archiveQuestion=$s->fetch();
                if(!$archiveQuestion)throw new RuntimeException('Question not found.');
                $s=$pdo->prepare('SELECT COUNT(*) FROM exams e LEFT JOIN exam_attempts ea ON ea.exam_id=e.id AND ea.status="in_progress" WHERE e.subject_id=? AND (ea.id IS NOT NULL OR (e.status="active" AND NOW() BETWEEN e.start_at AND e.end_at))');$s->execute([$archiveQuestion['subject_id']]);
                if((int)$s->fetchColumn()>0)throw new RuntimeException('This subject has an in-progress or currently open exam. Wait until the exam is closed before archiving or restoring this question.');
                $pdo->prepare('UPDATE questions SET is_archived=? WHERE id=?')->execute([$archiveValue?1:0,$archiveId]);
                audit($pdo,$archiveValue?'question_archived':'question_restored','question',(string)$archiveId);
                flash('success',$archiveValue?'Question archived. Existing attempts and results are preserved.':'Question restored to the active question bank.');
            }catch(Throwable $e){error_log('Cycle Exam question archive error: '.$e->getMessage());flash('danger',$e instanceof RuntimeException?$e->getMessage():'Question status could not be changed. Please try again.');}
            header('Location: index.php?'.http_build_query($returnParams));exit;
        }
        $subject=(int)($_POST['subject_id']??0);$returnSubject=$subject;$returnParams['subject_id']=$subject;$text=trim((string)($_POST['question_text']??''));$a=trim((string)($_POST['option_a']??''));$b=trim((string)($_POST['option_b']??''));$c=trim((string)($_POST['option_c']??''));$d=trim((string)($_POST['option_d']??''));$correct=strtoupper((string)($_POST['correct_option']??''));$marks=filter_var($_POST['marks']??null,FILTER_VALIDATE_FLOAT);
        if(!$subject||$text===''||$a===''||$b===''||$c===''||$d===''||!in_array($correct,['A','B','C','D'],true)||$marks===false||$marks<=0){flash('danger','Complete all question fields, enter positive marks, and select the correct option.');if($id){header('Location: index.php?'.http_build_query($returnParams+['edit'=>$id]));exit;}header('Location: index.php?'.http_build_query($returnParams));exit;}
        $s=$pdo->prepare('SELECT id FROM subjects WHERE id=? AND is_active=1');$s->execute([$subject]);
        if(!$s->fetchColumn()){flash('danger','Choose an active subject.');header('Location: index.php?'.http_build_query($returnParams));exit;}
        try{
            if($id){
                $s=$pdo->prepare('SELECT id,subject_id,is_archived FROM questions WHERE id=?');$s->execute([$id]);$existingQuestion=$s->fetch();if(!$existingQuestion)throw new RuntimeException('Question not found.');if((int)$existingQuestion['is_archived']===1)throw new RuntimeException('Restore this archived question before editing it.');
                $s=$pdo->prepare('SELECT COUNT(*) FROM attempt_answers WHERE question_id=?');$s->execute([$id]);$hasSavedAnswer=(int)$s->fetchColumn()>0;
                $s=$pdo->prepare('SELECT COUNT(*) FROM exam_attempts ea JOIN exams e ON e.id=ea.exam_id WHERE (e.subject_id=? OR e.subject_id=?) AND ea.status="in_progress"');$s->execute([$existingQuestion['subject_id'],$subject]);$hasActiveAttempt=(int)$s->fetchColumn()>0;
                if($hasSavedAnswer||$hasActiveAttempt)throw new RuntimeException('This question has been used in a saved answer or active exam and is locked to preserve exam history.');
                $s=$pdo->prepare('UPDATE questions SET subject_id=?,question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,marks=? WHERE id=?');$s->execute([$subject,$text,$a,$b,$c,$d,$correct,$marks,$id]);audit($pdo,'question_updated','question',(string)$id);flash('success','Question updated.');
            }else{$s=$pdo->prepare('INSERT INTO questions(subject_id,question_text,option_a,option_b,option_c,option_d,correct_option,marks,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())');$s->execute([$subject,$text,$a,$b,$c,$d,$correct,$marks,current_user()['id']]);audit($pdo,'question_created','question',(string)$pdo->lastInsertId());flash('success','Question added.');}
        }catch(Throwable $e){error_log('Cycle Exam question save error: '.$e->getMessage());flash('danger',$e instanceof RuntimeException?'Question could not be saved. '.$e->getMessage():'Question could not be saved. Please review the subject and question details.');}
        header('Location: index.php?'.http_build_query($returnParams));exit;
    }
    $editId=(int)($_GET['edit']??0);$selectedSubjectId=(int)($_GET['subject_id']??0);$questionSearch=trim((string)($_GET['search']??''));$showArchived=($_GET['archived']??'0')==='1';$questionPage=max(1,(int)($_GET['page']??1));
    $editQuestion=null;$editQuestionUsed=false;$editQuestionArchived=false;if($editId){$s=$pdo->prepare('SELECT * FROM questions WHERE id=?');$s->execute([$editId]);$editQuestion=$s->fetch()?:null;if($editQuestion){$selectedSubjectId=(int)$editQuestion['subject_id'];$editQuestionArchived=(int)$editQuestion['is_archived']===1;$s=$pdo->prepare('SELECT COUNT(*) FROM attempt_answers WHERE question_id=?');$s->execute([$editId]);$editQuestionUsed=(int)$s->fetchColumn()>0;if(!$editQuestionUsed){$s=$pdo->prepare('SELECT COUNT(*) FROM exam_attempts ea JOIN exams e ON e.id=ea.exam_id WHERE e.subject_id=? AND ea.status="in_progress"');$s->execute([$editQuestion['subject_id']]);$editQuestionUsed=(int)$s->fetchColumn()>0;}}}
    if($editId&&!$editQuestion)echo '<div class="alert alert-danger">Question not found.</div>';
    $subjects=$pdo->query('SELECT id,subject_name FROM subjects WHERE is_active=1 ORDER BY subject_name')->fetchAll();$allSubjects=$pdo->query('SELECT id,subject_name FROM subjects ORDER BY subject_name')->fetchAll();
    $subjectFormId=(int)($editQuestion['subject_id']??$selectedSubjectId);$formContext=['action'=>'questions'];if($selectedSubjectId>0)$formContext['subject_id']=$selectedSubjectId;if($questionSearch!=='')$formContext['search']=$questionSearch;$formContext['archived']=$showArchived?'1':'0';if($questionPage>1)$formContext['page']=$questionPage;
    echo '<div class="card"><h2>'.($editQuestion?'Edit MCQ question':'Add MCQ question').'</h2>';if($editQuestionUsed)echo '<div class="alert alert-warning">This question is already in an exam attempt and cannot be edited, to preserve exam history.</div>';elseif($editQuestionArchived)echo '<div class="alert alert-warning">This question is archived. Restore it from the archived-question list before editing.</div>';else{echo '<form method="post">';csrf_input();if($editQuestion)echo '<input type="hidden" name="question_id" value="'.(int)$editQuestion['id'].'">';echo '<input type="hidden" name="return_subject" value="'.$selectedSubjectId.'"><input type="hidden" name="return_search" value="'.e($questionSearch).'"><input type="hidden" name="return_archived" value="'.($showArchived?'1':'0').'"><input type="hidden" name="return_page" value="'.$questionPage.'"><label class="field"><span>Subject</span><select name="subject_id" required><option value="">Select subject</option>';foreach($subjects as $subjectOption)echo '<option value="'.(int)$subjectOption['id'].'"'.($subjectFormId===(int)$subjectOption['id']?' selected':'').'>'.e($subjectOption['subject_name']).'</option>';echo '</select></label>';echo '<label class="field"><span>Question text</span><textarea name="question_text" required rows="3">'.e($editQuestion['question_text']??'').'</textarea></label><div class="form-grid">';foreach(['a'=>'Option A','b'=>'Option B','c'=>'Option C','d'=>'Option D'] as $k=>$v)post_field('option_'.$k,$v,'text',(string)($editQuestion['option_'.$k]??''),true);echo '</div><div class="form-grid"><label class="field"><span>Correct option</span><select name="correct_option" required>';foreach(['A','B','C','D'] as $option)echo '<option'.(($editQuestion['correct_option']??'A')===$option?' selected':'').'>'.$option.'</option>';echo '</select></label>';post_field('marks','Marks','number',(string)($editQuestion['marks']??'1.00'),true,'min="0.01" step="0.01"');echo '</div><div class="form-actions"><button class="btn btn-primary">'.($editQuestion?'Save question':'Add question').'</button>'.($editQuestion?'<a class="btn btn-light" href="index.php?'.http_build_query($formContext).'">Cancel</a>':'').'</div></form>';}echo '</div>';
    echo '<div class="card"><h2>Question bank</h2><form method="get" class="inline-search"><input type="hidden" name="action" value="questions"><label class="field"><span>Subject</span><select name="subject_id"><option value="0">Choose a subject</option>';foreach($allSubjects as $subjectOption)echo '<option value="'.(int)$subjectOption['id'].'"'.($selectedSubjectId===(int)$subjectOption['id']?' selected':'').'>'.e($subjectOption['subject_name']).'</option>';echo '</select></label><label class="field"><span>Search question</span><input type="search" name="search" value="'.e($questionSearch).'" maxlength="200" placeholder="Search question text"></label><label class="field"><span>Show</span><select name="archived"><option value="0"'.(!$showArchived?' selected':'').'>Active questions</option><option value="1"'.($showArchived?' selected':'').'>Archived questions</option></select></label><button class="btn btn-light">Apply filters</button></form>';
    if($selectedSubjectId<1)echo '<p class="muted">Choose a subject to view its questions.</p>';
    else{
        $where='q.subject_id=? AND q.is_archived=?';$params=[$selectedSubjectId,$showArchived?1:0];if($questionSearch!==''){$where.=' AND q.question_text LIKE ?';$params[]='%'.$questionSearch.'%';}
        $s=$pdo->prepare('SELECT COUNT(*) FROM questions q WHERE '.$where);$s->execute($params);$questionCount=(int)$s->fetchColumn();$perPage=25;$pageCount=max(1,(int)ceil($questionCount/$perPage));$questionPage=min($questionPage,$pageCount);$offset=($questionPage-1)*$perPage;
        $s=$pdo->prepare('SELECT q.id,q.question_text,q.marks,q.is_archived,s.subject_name FROM questions q JOIN subjects s ON s.id=q.subject_id WHERE '.$where.' ORDER BY q.id DESC LIMIT '.$perPage.' OFFSET '.$offset);$s->execute($params);$questionRows=$s->fetchAll();
        echo '<p class="muted small">'.$questionCount.' '.($showArchived?'archived':'active').' question(s) for this subject'.($questionCount?' · showing '.($offset+1).'–'.min($offset+$perPage,$questionCount):'').'.</p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Subject</th><th>Question</th><th>Marks</th><th>Action</th></tr></thead><tbody>';
        foreach($questionRows as $row){$editUrl='index.php?'.http_build_query($formContext+['edit'=>(int)$row['id']]);echo '<tr><td>'.(int)$row['id'].'</td><td>'.e($row['subject_name']).'</td><td>'.e($row['question_text']).'</td><td>'.e($row['marks']).'</td><td>'.(!$showArchived?'<a class="btn btn-light btn-sm" href="'.$editUrl.'">Edit</a> ':'');if($canManageQuestions){echo '<form method="post" class="inline-form" onsubmit="return confirm(\''.($showArchived?'Restore this question to the active question bank?':'Archive this question? Existing exam answers and results will be preserved.').'\')">';csrf_input();echo '<input type="hidden" name="archive_question_id" value="'.(int)$row['id'].'"><input type="hidden" name="archive_value" value="'.($showArchived?'0':'1').'"><input type="hidden" name="return_subject" value="'.$selectedSubjectId.'"><input type="hidden" name="return_search" value="'.e($questionSearch).'"><input type="hidden" name="return_archived" value="'.($showArchived?'1':'0').'"><input type="hidden" name="return_page" value="'.$questionPage.'"><button class="btn btn-light btn-sm">'.($showArchived?'Restore':'Archive').'</button></form>';}echo '</td></tr>';}
        if(!$questionRows)echo '<tr><td colspan="5">No questions found for this subject and filter.</td></tr>';echo '</tbody></table></div>';
        if($pageCount>1){echo '<div class="form-actions question-pagination">'.($questionPage>1?'<a class="btn btn-light btn-sm" href="index.php?'.http_build_query(array_replace($formContext,['page'=>$questionPage-1])).'">Previous</a>':'').'<span class="pill">Page '.$questionPage.' of '.$pageCount.'</span>'.($questionPage<$pageCount?'<a class="btn btn-light btn-sm" href="index.php?'.http_build_query(array_replace($formContext,['page'=>$questionPage+1])).'">Next</a>':'').'</div>';}
    }
    echo '</div>';
    break;

case 'descriptive_questions':
    require_role(['super_admin','exam_admin','teacher']);
    $canManageQuestions=in_array(current_user()['role'],['super_admin','exam_admin','teacher'],true);
    $editingId=(int)($_GET['edit']??0);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $archiveId=(int)($_POST['archive_question_id']??0);
        if($archiveId>0){
            $archiveValue=(int)($_POST['archive_value']??0);$returnExamId=(int)($_POST['return_exam_id']??0);$returnSearch=trim((string)($_POST['return_search']??''));$returnArchived=($_POST['return_archived']??'1')==='1';$returnPage=max(1,(int)($_POST['return_page']??1));
            $returnParams=['action'=>'descriptive_questions'];if($returnExamId>0)$returnParams['exam_id']=$returnExamId;if($returnSearch!=='')$returnParams['search']=$returnSearch;$returnParams['archived']=$returnArchived?'1':'0';if($returnPage>1)$returnParams['page']=$returnPage;
            try{
                $s=$pdo->prepare('SELECT exam_id,is_archived FROM descriptive_questions WHERE id=?');$s->execute([$archiveId]);$archiveQuestion=$s->fetch();
                if(!$archiveQuestion)throw new RuntimeException('Question not found.');
                $s=$pdo->prepare('SELECT COUNT(*) FROM exams e LEFT JOIN exam_attempts ea ON ea.exam_id=e.id AND ea.status="in_progress" WHERE e.id=? AND (ea.id IS NOT NULL OR (e.status="active" AND NOW() BETWEEN e.start_at AND e.end_at))');$s->execute([$archiveQuestion['exam_id']]);
                if((int)$s->fetchColumn()>0)throw new RuntimeException('This exam has an in-progress attempt or is currently open. Wait until the exam is closed before archiving or restoring this question.');
                $pdo->prepare('UPDATE descriptive_questions SET is_archived=? WHERE id=?')->execute([$archiveValue?1:0,$archiveId]);
                audit($pdo,$archiveValue?'descriptive_question_archived':'descriptive_question_restored','descriptive_question',(string)$archiveId);
                flash('success',$archiveValue?'Typing question archived. Existing attempts and results are preserved.':'Typing question restored to the active exam question list.');
            }catch(Throwable $e){error_log('Cycle Exam typing question archive error: '.$e->getMessage());flash('danger',$e instanceof RuntimeException?$e->getMessage():'Question status could not be changed. Please try again.');}
            header('Location: index.php?'.http_build_query($returnParams));exit;
        }
        $id=(int)($_POST['question_id']??0);$examId=(int)($_POST['exam_id']??0);$number=(int)($_POST['question_number']??0);$prompt=trim((string)($_POST['question_text']??''));$max=filter_var($_POST['max_marks']??null,FILTER_VALIDATE_FLOAT);$expected=trim((string)($_POST['expected_answer']??''));$acceptedText=(string)($_POST['accepted_answers']??'');$keywordText=(string)($_POST['keyword_rules']??'');$toleranceRaw=trim((string)($_POST['numeric_tolerance']??''));$spelling=filter_var($_POST['spelling_tolerance']??'0',FILTER_VALIDATE_INT);$ignorePunctuation=isset($_POST['ignore_punctuation'])?1:0;
        $returnExamId=(int)($_POST['return_exam_id']??$examId);if($id<1)$returnExamId=$examId;$returnSearch=trim((string)($_POST['return_search']??''));$returnArchived=($_POST['return_archived']??'0')==='1';$returnPage=max(1,(int)($_POST['return_page']??1));$returnParams=['action'=>'descriptive_questions'];if($returnExamId>0)$returnParams['exam_id']=$returnExamId;if($returnSearch!=='')$returnParams['search']=$returnSearch;$returnParams['archived']=$returnArchived?'1':'0';if($returnPage>1)$returnParams['page']=$returnPage;
        $s=$pdo->prepare("SELECT id,subject_id,exam_pattern FROM exams WHERE id=? AND is_archived=0");$s->execute([$examId]);$examForQuestion=$s->fetch();
        $accepted=[$expected];foreach(preg_split('/\r\n|\r|\n/',$acceptedText)?:[] as $line){$line=trim($line);if($line!==''&&!in_array($line,$accepted,true))$accepted[]=$line;}
        $keywordRules=[];$keywordError=false;$weightTotal=0.0;$seenKeywords=[];
        foreach(preg_split('/\r\n|\r|\n/',$keywordText)?:[] as $line){$line=trim($line);if($line==='')continue;$parts=explode('|',$line);if(count($parts)!==2||trim($parts[0])===''||!is_numeric(trim($parts[1]))||(float)$parts[1]<=0){$keywordError=true;break;}$phrase=trim($parts[0]);$normalizedPhrase=descriptive_normalize_answer($phrase,(bool)$ignorePunctuation);if(isset($seenKeywords[$normalizedPhrase])){$keywordError=true;break;}$seenKeywords[$normalizedPhrase]=true;$weight=(float)$parts[1];$weightTotal+=$weight;$keywordRules[]=['phrase'=>$phrase,'marks'=>$weight];}
        $numericTolerance=null;if($toleranceRaw!==''){if(!is_numeric($toleranceRaw)||(float)$toleranceRaw<0||(float)$toleranceRaw>99999999.9999||filter_var($expected,FILTER_VALIDATE_FLOAT)===false)$keywordError=true;else $numericTolerance=(float)$toleranceRaw;}
        if(!$examForQuestion||!in_array($examForQuestion['exam_pattern'],['typing','mixed'],true)||$number<1||$prompt===''||$max===false||$max<=0||$max>999999.99||$expected===''||$keywordError||$weightTotal>((float)$max+0.00001)||$spelling===false||$spelling<0||$spelling>3||strlen($prompt)>60000||strlen($expected)>20000||strlen($acceptedText)>60000||strlen($keywordText)>20000){
            flash('danger','Check the exam pattern, question number, prompt, positive maximum marks, expected answer, spelling tolerance, and rubric weights (their total must not exceed maximum marks).');if($id){header('Location: index.php?'.http_build_query($returnParams+['edit'=>$id]));exit;}header('Location: index.php?'.http_build_query($returnParams));exit;
        }
        try{
            if($id){$s=$pdo->prepare('SELECT exam_id,is_archived FROM descriptive_questions WHERE id=?');$s->execute([$id]);$existingQuestion=$s->fetch();$existingExamId=$existingQuestion['exam_id']??false;if(!$existingExamId||(int)$existingExamId!==$examId)throw new RuntimeException('A descriptive question cannot be moved to another exam.');if((int)$existingQuestion['is_archived']===1)throw new RuntimeException('Restore this archived question before editing it.');}
            $s=$pdo->prepare("SELECT COUNT(*) FROM exam_attempts WHERE exam_id=?");$s->execute([$examId]);if((int)$s->fetchColumn()>0){flash('danger','Descriptive questions are locked after an exam attempt starts.');if($id){header('Location: index.php?'.http_build_query($returnParams+['edit'=>$id]));exit;}header('Location: index.php?'.http_build_query($returnParams));exit;}
            $acceptedJson=json_encode($accepted,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$rulesJson=json_encode($keywordRules,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($id){$s=$pdo->prepare('UPDATE descriptive_questions SET exam_id=?,subject_id=?,question_number=?,question_text=?,max_marks=?,accepted_answers=?,keyword_rules=?,numeric_tolerance=?,ignore_punctuation=?,spelling_tolerance=? WHERE id=?');$s->execute([$examId,$examForQuestion['subject_id'],$number,$prompt,$max,$acceptedJson,$rulesJson,$numericTolerance,$ignorePunctuation,$spelling,$id]);audit($pdo,'descriptive_question_updated','descriptive_question',(string)$id);flash('success','Typing question updated.');}
            else{$s=$pdo->prepare('INSERT INTO descriptive_questions(exam_id,subject_id,question_number,question_text,max_marks,accepted_answers,keyword_rules,numeric_tolerance,ignore_punctuation,spelling_tolerance,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())');$s->execute([$examId,$examForQuestion['subject_id'],$number,$prompt,$max,$acceptedJson,$rulesJson,$numericTolerance,$ignorePunctuation,$spelling,current_user()['id']]);$newId=(int)$pdo->lastInsertId();audit($pdo,'descriptive_question_created','descriptive_question',(string)$newId);flash('success','Typing question created.');}
        }catch(Throwable $e){error_log('Cycle Exam descriptive question save error: '.$e->getMessage());flash('danger','Typing question could not be saved. Verify the question number is unique within this exam.');}
        header('Location: index.php?'.http_build_query($returnParams));exit;
    }
    $exams=$pdo->query("SELECT e.id,e.title,e.exam_pattern,e.subject_id,s.subject_name FROM exams e JOIN subjects s ON s.id=e.subject_id WHERE e.is_archived=0 AND e.exam_pattern IN ('typing','mixed') ORDER BY e.start_at DESC")->fetchAll();
    $editQuestion=null;$editQuestionLocked=false;$editQuestionArchived=false;if($editingId){$s=$pdo->prepare('SELECT * FROM descriptive_questions WHERE id=?');$s->execute([$editingId]);$editQuestion=$s->fetch()?:null;if($editQuestion){$s=$pdo->prepare('SELECT COUNT(*) FROM exam_attempts WHERE exam_id=?');$s->execute([$editQuestion['exam_id']]);$editQuestionLocked=(int)$s->fetchColumn()>0;$editQuestionArchived=(int)$editQuestion['is_archived']===1;}}
    if($editingId&&!$editQuestion)echo '<div class="alert alert-danger">Typing question not found.</div>';
    $requestedExamId=(int)($_GET['exam_id']??0);$selectedExamId=(int)($editQuestion['exam_id']??($requestedExamId>0?$requestedExamId:($exams[0]['id']??0)));
    if(!$editQuestion&&!in_array($selectedExamId,array_map(static fn($examOption)=>(int)$examOption['id'],$exams),true))$selectedExamId=(int)($exams[0]['id']??0);
    $acceptedExisting=$editQuestion?json_decode((string)$editQuestion['accepted_answers'],true):[];$acceptedExtra=is_array($acceptedExisting)?array_slice($acceptedExisting,1):[];
    $keywordExisting=$editQuestion?json_decode((string)$editQuestion['keyword_rules'],true):[];$keywordLines=[];if(is_array($keywordExisting))foreach($keywordExisting as $rule)if(is_array($rule))$keywordLines[]=(string)$rule['phrase'].'|'.(string)$rule['marks'];
    echo '<div class="card"><h2>'.($editQuestion?'Edit typing question':'Create typing-answer question').'</h2><p class="muted">To add a written question: create a Typing Answer or Mixed Exam first, then fill in the question form below and press Add typing question. Students type their responses later in the exam.</p><p class="muted">Expected answers and marking rules are private and never sent to student exam pages. Keyword rules use one phrase and weight per line: <code>phrase|marks</code>.</p>';
    if($editQuestionLocked)echo '<div class="alert alert-warning">This exam has attempts; its question and marking key are locked.</div>';
    elseif($editQuestionArchived)echo '<div class="alert alert-warning">This question is archived. Restore it from the archived-question list before editing.</div>';
    elseif(!$exams)echo '<div class="alert alert-warning">There is no Typing Answer or Mixed Exam available yet. Create one first, then come back here to enter the question text and answer key.</div><a class="btn btn-primary" href="index.php?action=exams">1. Create a Typing Answer exam</a>';
    else{
        echo '<form method="post" class="descriptive-question-form">';csrf_input();if($editQuestion)echo '<input type="hidden" name="question_id" value="'.(int)$editQuestion['id'].'"><input type="hidden" name="return_exam_id" value="'.$selectedExamId.'"><input type="hidden" name="return_search" value="'.e((string)($_GET['search']??'')).'"><input type="hidden" name="return_archived" value="'.((($_GET['archived']??'0')==='1')?'1':'0').'"><input type="hidden" name="return_page" value="'.max(1,(int)($_GET['page']??1)).'">';
        if(!$editQuestion)echo '<input type="hidden" name="return_exam_id" value="'.$selectedExamId.'"><input type="hidden" name="return_search" value="'.e((string)($_GET['search']??'')).'"><input type="hidden" name="return_archived" value="'.((($_GET['archived']??'0')==='1')?'1':'0').'"><input type="hidden" name="return_page" value="'.max(1,(int)($_GET['page']??1)).'">';
        echo '<label class="field"><span>Exam</span><select name="exam_id" required>';foreach($exams as $examOption)echo '<option value="'.(int)$examOption['id'].'"'.($selectedExamId===(int)$examOption['id']?' selected':'').'>'.e($examOption['title'].' — '.$examOption['subject_name'].' ('.ucfirst($examOption['exam_pattern']).')').'</option>';echo '</select></label>';
        $examSubjectNames=[];foreach($exams as $examOption)$examSubjectNames[(int)$examOption['id']]=$examOption['subject_name'];$selectedSubject=$examSubjectNames[$selectedExamId]??false;
        echo '<p class="muted">Subject: '.e($selectedSubject?:'The selected exam subject').'</p><div class="form-grid">';
        post_field('question_number','Question number','number',(string)($editQuestion['question_number']??''),true,'min="1"');post_field('max_marks','Maximum marks','number',(string)($editQuestion['max_marks']??'5'),true,'min="0.01" step="0.01"');echo '</div>';
        echo '<label class="field"><span>Question text — type the prompt students will answer</span><textarea name="question_text" rows="4" required maxlength="60000" placeholder="Type the full question or instruction here">'.e($editQuestion['question_text']??'').'</textarea></label>';
        echo '<label class="field"><span>Expected answer / answer key — private, students cannot see this</span><textarea name="expected_answer" rows="3" required maxlength="20000" placeholder="Type the correct answer or a model answer here">'.e($acceptedExisting[0]??'').'</textarea></label>';
        echo '<label class="field"><span>Other accepted answers (optional, one answer per line)</span><textarea name="accepted_answers" rows="3" placeholder="Add equivalent answers, one on each line">'.e(implode("\n",$acceptedExtra)).'</textarea></label>';
        echo '<label class="field"><span>Required keywords and marks (optional, phrase|marks, one per line)</span><textarea name="keyword_rules" rows="4" placeholder="photosynthesis|2&#10;chlorophyll|1">'.e(implode("\n",$keywordLines)).'</textarea><small class="muted">Example: <code>photosynthesis|2</code> awards 2 marks when that phrase appears.</small></label><div class="form-grid">';
        post_field('numeric_tolerance','Numeric tolerance (optional)','number',(string)($editQuestion['numeric_tolerance']??''),false,'min="0" step="0.0001"');post_field('spelling_tolerance','Spelling tolerance (ASCII edit count, 0 = off)','number',(string)($editQuestion['spelling_tolerance']??'0'),false,'min="0" max="3" step="1"');echo '</div><label class="field"><span><input type="checkbox" name="ignore_punctuation" value="1"'.((!$editQuestion||(int)$editQuestion['ignore_punctuation']===1)?' checked':'').'> Ignore punctuation when comparing answers</span></label><div class="form-actions"><button class="btn btn-primary">'.($editQuestion?'Save typing question':'Add typing question').'</button>'.($editQuestion?'<a class="btn btn-light" href="index.php?action=descriptive_questions">Cancel</a>':'').'</div></form>';
    }echo '</div>';
    $examFilterId=(int)($editQuestion['exam_id']??($_GET['exam_id']??0));$questionSearch=trim((string)($_GET['search']??''));$showArchived=($_GET['archived']??'0')==='1';$questionPage=max(1,(int)($_GET['page']??1));
    $examFilterOptions=$pdo->query("SELECT e.id,e.title,e.exam_pattern,e.subject_id,e.start_at,e.status,e.is_archived,s.subject_name FROM exams e JOIN subjects s ON s.id=e.subject_id WHERE e.exam_pattern IN ('typing','mixed') ORDER BY e.start_at DESC")->fetchAll();
    $listContext=['action'=>'descriptive_questions'];if($examFilterId>0)$listContext['exam_id']=$examFilterId;if($questionSearch!=='')$listContext['search']=$questionSearch;$listContext['archived']=$showArchived?'1':'0';if($questionPage>1)$listContext['page']=$questionPage;
    echo '<div class="card"><h2>Typing-answer questions</h2><p class="muted">Choose an exam to manage only its written questions. Archived questions stay attached to existing attempts and results.</p><form method="get" class="inline-search"><input type="hidden" name="action" value="descriptive_questions"><label class="field"><span>Exam</span><select name="exam_id"><option value="0">Choose an exam</option>';foreach($examFilterOptions as $examOption)echo '<option value="'.(int)$examOption['id'].'"'.($examFilterId===(int)$examOption['id']?' selected':'').'>'.e($examOption['title'].' — '.$examOption['subject_name'].' · '.date('d M Y',strtotime($examOption['start_at'])).((int)$examOption['is_archived']?' (archived exam)':'')).'</option>';echo '</select></label><label class="field"><span>Search question</span><input type="search" name="search" value="'.e($questionSearch).'" maxlength="200" placeholder="Search question text"></label><label class="field"><span>Show</span><select name="archived"><option value="0"'.(!$showArchived?' selected':'').'>Active questions</option><option value="1"'.($showArchived?' selected':'').'>Archived questions</option></select></label><button class="btn btn-light">Apply filters</button></form>';
    if($examFilterId<1)echo '<p class="muted">Choose an exam to view its typing-answer questions.</p>';
    else{
        $filterExam=null;foreach($examFilterOptions as $examOption)if((int)$examOption['id']===$examFilterId){$filterExam=$examOption;break;}
        if(!$filterExam)echo '<p class="muted">Exam not found.</p>';
        else{
            $where='dq.exam_id=? AND dq.is_archived=?';$params=[$examFilterId,$showArchived?1:0];if($questionSearch!==''){$where.=' AND dq.question_text LIKE ?';$params[]='%'.$questionSearch.'%';}
            $s=$pdo->prepare('SELECT COUNT(*) FROM descriptive_questions dq WHERE '.$where);$s->execute($params);$questionCount=(int)$s->fetchColumn();$perPage=25;$pageCount=max(1,(int)ceil($questionCount/$perPage));$questionPage=min($questionPage,$pageCount);$offset=($questionPage-1)*$perPage;
            $s=$pdo->prepare('SELECT dq.id,dq.exam_id,dq.question_number,dq.question_text,dq.max_marks,dq.is_archived,e.title,s.subject_name,e.start_at,e.status,e.is_archived AS exam_archived,(SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id=dq.exam_id) AS has_attempts FROM descriptive_questions dq JOIN exams e ON e.id=dq.exam_id JOIN subjects s ON s.id=dq.subject_id WHERE '.$where.' ORDER BY dq.question_number,dq.id LIMIT '.$perPage.' OFFSET '.$offset);$s->execute($params);$questionRows=$s->fetchAll();
            echo '<p><b>'.e($filterExam['title']).'</b> · '.e($filterExam['subject_name']).' · '.e(date('d M Y',strtotime($filterExam['start_at']))).' · '.e($filterExam['status']).'</p><p class="muted small">'.$questionCount.' '.($showArchived?'archived':'active').' question(s)'.($questionCount?' · showing '.($offset+1).'–'.min($offset+$perPage,$questionCount):'').'.</p><div class="table-wrap"><table><thead><tr><th>Question no.</th><th>Question</th><th>Maximum marks</th><th>Action</th></tr></thead><tbody>';
            foreach($questionRows as $row){$editUrl='index.php?'.http_build_query($listContext+['edit'=>(int)$row['id']]);echo '<tr><td>'.(int)$row['question_number'].'</td><td>'.e($row['question_text']).'</td><td>'.e($row['max_marks']).'</td><td>'.(!$showArchived&&!(int)$row['has_attempts']?'<a class="btn btn-light btn-sm" href="'.$editUrl.'">Edit</a> ':'');if($canManageQuestions){echo '<form method="post" class="inline-form" onsubmit="return confirm(\''.($showArchived?'Restore this question to the active exam? Existing answers and results will be preserved.':'Archive this typing question? Existing answers and results will be preserved.').'\')">';csrf_input();echo '<input type="hidden" name="archive_question_id" value="'.(int)$row['id'].'"><input type="hidden" name="archive_value" value="'.($showArchived?'0':'1').'"><input type="hidden" name="return_exam_id" value="'.$examFilterId.'"><input type="hidden" name="return_search" value="'.e($questionSearch).'"><input type="hidden" name="return_archived" value="'.($showArchived?'1':'0').'"><input type="hidden" name="return_page" value="'.$questionPage.'"><button class="btn btn-light btn-sm">'.($showArchived?'Restore':'Archive').'</button></form>';}echo '</td></tr>';}
            if(!$questionRows)echo '<tr><td colspan="4">No questions found for this exam and filter.</td></tr>';echo '</tbody></table></div>';
            if($pageCount>1){echo '<div class="form-actions question-pagination">'.($questionPage>1?'<a class="btn btn-light btn-sm" href="index.php?'.http_build_query(array_replace($listContext,['page'=>$questionPage-1])).'">Previous</a>':'').'<span class="pill">Page '.$questionPage.' of '.$pageCount.'</span>'.($questionPage<$pageCount?'<a class="btn btn-light btn-sm" href="index.php?'.http_build_query(array_replace($listContext,['page'=>$questionPage+1])).'">Next</a>':'').'</div>';}
        }
    }
    echo '</div>';
    break;

case 'descriptive_review':
    require_role(['super_admin','exam_admin','teacher']);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $answerId=(int)($_POST['answer_id']??0);$awarded=filter_var($_POST['awarded_marks']??null,FILTER_VALIDATE_FLOAT);$reason=trim((string)($_POST['review_reason']??''));
        $returnDate=(string)($_POST['review_date']??'');$returnExam=(int)($_POST['review_exam']??0);$returnQuestion=(int)($_POST['review_question']??0);$returnStudent=(int)($_POST['review_student']??0);$showReviewed=(($_POST['show_reviewed']??'')==='1');$searchRegister=trim((string)($_POST['search_register']??''));$studentPage=max(1,(int)($_POST['student_page']??1));$nextPending=($_POST['next_pending']??'')==='1';$nextStudentId=0;$nextQuestionId=0;$reviewSaved=false;
        $returnQuery=['action'=>'descriptive_review'];
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$returnDate)&&checkdate((int)substr($returnDate,5,2),(int)substr($returnDate,8,2),(int)substr($returnDate,0,4)))$returnQuery['date']=$returnDate;
        if($returnExam>0)$returnQuery['exam']=$returnExam;
        if($returnQuestion>0)$returnQuery['question']=$returnQuestion;
        if($returnStudent>0)$returnQuery['student']=$returnStudent;
        if($showReviewed)$returnQuery['show_reviewed']='1';
        if($searchRegister!=='')$returnQuery['student_search']=$searchRegister;
        if($studentPage>1)$returnQuery['student_page']=$studentPage;
        $returnUrl='index.php?'.http_build_query($returnQuery);
        if($answerId<1||$awarded===false||$reason===''||strlen($reason)>2000){flash('danger','Enter valid marks and a review reason (up to 2,000 characters).');header('Location: '.$returnUrl);exit;}
        $pdo->beginTransaction();
        try{
            $s=$pdo->prepare('SELECT da.*,dq.max_marks,dq.exam_id,ea.id AS attempt_id,ea.student_user_id,ea.status AS attempt_status FROM descriptive_answers da JOIN descriptive_questions dq ON dq.id=da.descriptive_question_id JOIN exam_attempts ea ON ea.id=da.attempt_id WHERE da.id=? FOR UPDATE');$s->execute([$answerId]);$answerRow=$s->fetch();
            if(!$answerRow||!in_array($answerRow['attempt_status'],['submitted','evaluated'],true)||$awarded<0||(float)$awarded>(float)$answerRow['max_marks'])throw new RuntimeException('Answer is unavailable or the score exceeds the question maximum.');
            $pdo->prepare('INSERT INTO descriptive_answer_reviews(descriptive_answer_id,reviewer_user_id,previous_marks,awarded_marks,reason,created_at) VALUES(?,?,?,?,?,NOW())')->execute([$answerId,current_user()['id'],$answerRow['marks_awarded'],$awarded,$reason]);
            $pdo->prepare("UPDATE descriptive_answers SET marks_awarded=?,marking_status='teacher_reviewed',marking_explanation=?,reviewed_by=?,reviewed_at=NOW(),review_reason=? WHERE id=?")->execute([$awarded,'Teacher-reviewed: '.$reason,current_user()['id'],$reason,$answerId]);
            $s=$pdo->prepare("SELECT COUNT(*) FROM descriptive_answers da JOIN exam_attempts ea ON ea.id=da.attempt_id WHERE ea.id=? AND da.marking_status='pending_review'");$s->execute([$answerRow['attempt_id']]);$pending=(int)$s->fetchColumn();
            if($pending===0){
                $s=$pdo->prepare('SELECT COALESCE(SUM(aa.marks_awarded),0) FROM attempt_answers aa WHERE aa.attempt_id=?');$s->execute([$answerRow['attempt_id']]);$score=(float)$s->fetchColumn();
                $s=$pdo->prepare('SELECT COALESCE(SUM(q.marks),0) FROM exam_attempts ea JOIN exams e ON e.id=ea.exam_id JOIN questions q ON q.subject_id=e.subject_id WHERE ea.id=?');$s->execute([$answerRow['attempt_id']]);$mcqMax=(float)$s->fetchColumn();
                $s=$pdo->prepare('SELECT COALESCE(SUM(dq.max_marks),0),COALESCE(SUM(da.marks_awarded),0) FROM descriptive_questions dq JOIN descriptive_answers da ON da.descriptive_question_id=dq.id WHERE da.attempt_id=?');$s->execute([$answerRow['attempt_id']]);$totals=$s->fetch(PDO::FETCH_NUM);$max=$mcqMax+(float)$totals[0];$score+=(float)$totals[1];
                persist_exam_result($pdo,(int)$answerRow['exam_id'],(int)$answerRow['student_user_id'],(int)$answerRow['attempt_id'],$score,$max,true);
            }
            if($nextPending){
                $s=$pdo->prepare("SELECT dq.id FROM descriptive_answers da JOIN descriptive_questions dq ON dq.id=da.descriptive_question_id WHERE da.attempt_id=? AND da.marking_status='pending_review' ORDER BY dq.question_number,dq.id LIMIT 1");$s->execute([$answerRow['attempt_id']]);$nextQuestionId=(int)$s->fetchColumn();
                if($nextQuestionId>0)$nextStudentId=(int)$answerRow['student_user_id'];
                else{
                    $s=$pdo->prepare("SELECT u.id,(SELECT dq2.id FROM descriptive_answers da2 JOIN descriptive_questions dq2 ON dq2.id=da2.descriptive_question_id WHERE da2.attempt_id=ea.id AND da2.marking_status='pending_review' ORDER BY dq2.question_number,dq2.id LIMIT 1) AS next_question_id,sp.register_number FROM exam_attempts ea JOIN users u ON u.id=ea.student_user_id JOIN student_profiles sp ON sp.user_id=u.id WHERE ea.exam_id=? AND ea.status IN ('submitted','evaluated') AND u.id<>? AND EXISTS (SELECT 1 FROM descriptive_answers da3 WHERE da3.attempt_id=ea.id AND da3.marking_status='pending_review') ORDER BY (sp.register_number<=?) ASC,sp.register_number ASC LIMIT 1");$s->execute([$answerRow['exam_id'],$answerRow['student_user_id'],(string)($_POST['current_register']??'')]);$next=$s->fetch();if($next){$nextStudentId=(int)$next['id'];$nextQuestionId=(int)$next['next_question_id'];}
                }
            }
            $pdo->commit();$reviewSaved=true;audit($pdo,'descriptive_answer_reviewed','descriptive_answer',(string)$answerId,['awarded_marks'=>(float)$awarded,'reason'=>$reason]);flash('success',$pending?'Review saved; the attempt remains pending other answers.':'Review saved; final result is now available for publication.');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Cycle Exam descriptive review error: '.$e->getMessage());flash('danger','Review could not be saved. Confirm the score is within the question maximum.');}
        if($reviewSaved&&!$nextPending){
            $s=$pdo->prepare("SELECT dq.id FROM descriptive_answers da JOIN descriptive_questions dq ON dq.id=da.descriptive_question_id WHERE da.attempt_id=? AND da.marking_status='pending_review' ORDER BY dq.question_number,dq.id LIMIT 1");$s->execute([$answerRow['attempt_id']]);$nextQuestionId=(int)$s->fetchColumn();
            if($nextQuestionId>0){$returnQuery['student']=(int)$answerRow['student_user_id'];$returnQuery['question']=$nextQuestionId;}
            else unset($returnQuery['student'],$returnQuery['question']);
        }elseif($nextPending&&$reviewSaved&&$nextStudentId>0){$returnQuery['student']=$nextStudentId;$returnQuery['question']=$nextQuestionId>0?$nextQuestionId:0;}
        elseif($nextPending&&$reviewSaved){unset($returnQuery['student'],$returnQuery['question']);}
        header('Location: index.php?'.http_build_query($returnQuery));exit;
    }
    $summaryRows=$pdo->query("SELECT e.id AS exam_id,e.title,e.start_at,DATE(e.start_at) AS exam_date,COUNT(ea.id) AS answer_count,SUM(CASE WHEN ea.id IS NOT NULL AND da.marking_status='pending_review' THEN 1 ELSE 0 END) AS pending_count FROM exams e JOIN descriptive_questions dq ON dq.exam_id=e.id LEFT JOIN descriptive_answers da ON da.descriptive_question_id=dq.id LEFT JOIN exam_attempts ea ON ea.id=da.attempt_id AND ea.exam_id=e.id AND ea.status IN ('submitted','evaluated') GROUP BY e.id,e.title,e.start_at ORDER BY DATE(e.start_at) DESC,e.start_at DESC,e.title")->fetchAll();
    $requestedDate=(string)($_GET['date']??'');$selectedDate='';
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$requestedDate)&&checkdate((int)substr($requestedDate,5,2),(int)substr($requestedDate,8,2),(int)substr($requestedDate,0,4)))$selectedDate=$requestedDate;
    $selectedExam=(int)($_GET['exam']??0);$selectedQuestion=(int)($_GET['question']??0);$selectedStudent=(int)($_GET['student']??0);$showReviewed=($_GET['show_reviewed']??'')==='1';$studentSearch=trim((string)($_GET['student_search']??''));$studentPage=max(1,(int)($_GET['student_page']??1));
    $examById=[];$dates=[];
    foreach($summaryRows as $summary){$examById[(int)$summary['exam_id']]=$summary;$dates[$summary['exam_date']][]=$summary;}
    if($selectedExam>0&&!isset($examById[$selectedExam]))$selectedExam=0;
    if($selectedExam>0){$selectedExamDate=$examById[$selectedExam]['exam_date'];if($selectedDate!==''&&$selectedDate!==$selectedExamDate)$selectedExam=0;else $selectedDate=$selectedExamDate;}
    if($selectedDate!==''&&!isset($dates[$selectedDate]))$selectedDate='';
    $questionOptions=[];
    if($selectedExam>0){$s=$pdo->prepare('SELECT id,question_number,question_text,max_marks FROM descriptive_questions WHERE exam_id=? ORDER BY question_number,id');$s->execute([$selectedExam]);$questionOptions=$s->fetchAll();if($selectedQuestion>0&&!in_array($selectedQuestion,array_map(static fn($question)=>(int)$question['id'],$questionOptions),true))$selectedQuestion=0;}

    echo '<div class="card"><h2>Typing-answer review</h2><p class="muted">Choose an exam date, then an exam to review answers grouped by question. Only pending answers are shown by default. Pending scores are excluded from results until reviewed; score changes require a reason and are recorded in the review history.</p></div>';
    if(!$summaryRows)echo '<div class="card">No typing-answer exams with configured questions are available for review.</div>';
    if($selectedDate!=='')echo '<p><a class="btn btn-light" href="index.php?action=descriptive_review">All exam dates</a></p>';
    foreach($dates as $date=>$examsForDate){
        if($selectedDate!==''&&$selectedDate!==$date)continue;
        $dateUrl='index.php?'.http_build_query(['action'=>'descriptive_review','date'=>$date]);
        echo '<section class="card"><div class="card-title-row"><h2>'.e(date('l, d M Y',strtotime($date))).'</h2><span><span class="pill">'.array_sum(array_map(static fn($exam)=>(int)$exam['pending_count'],$examsForDate)).' pending</span> '.($selectedDate===$date?'':'<a class="btn btn-light btn-sm" href="'.$dateUrl.'">View date</a>').'</span></div>';
        echo '<div class="table-wrap"><table><thead><tr><th>Exam</th><th>Start time</th><th>Pending answers</th><th>Total answers</th><th></th></tr></thead><tbody>';
        foreach($examsForDate as $exam){$isSelected=(int)$exam['exam_id']===$selectedExam;$examUrl='index.php?'.http_build_query(['action'=>'descriptive_review','date'=>$date,'exam'=>(int)$exam['exam_id']]);echo '<tr'.($isSelected?' class="review-selected"':'').'><td>'.e($exam['title']).'</td><td>'.e(date('H:i',strtotime($exam['start_at']))).'</td><td>'.(int)$exam['pending_count'].'</td><td>'.(int)$exam['answer_count'].'</td><td><a class="btn btn-light btn-sm" href="'.$examUrl.'">'.($isSelected?'Selected':'Review exam').'</a></td></tr>';}
        echo '</tbody></table></div></section>';
    }

    if($selectedExam>0){
        $exam=$examById[$selectedExam];
        echo '<div class="card"><h2>'.e($exam['title']).' · '.e(date('d M Y',strtotime($exam['exam_date']))).'</h2><p class="muted">Search by register number to review one student at a time. Pending reviews appear first; search can also match student names.</p>';
        echo '<form method="get" class="inline-search"><input type="hidden" name="action" value="descriptive_review"><input type="hidden" name="date" value="'.e($selectedDate).'"><input type="hidden" name="exam" value="'.$selectedExam.'"><label class="field"><span>Register number or student name</span><input type="search" name="student_search" value="'.e($studentSearch).'" maxlength="100" placeholder="Enter register number"></label><label class="field"><span>Student list</span><select name="show_reviewed"><option value="0"'.(!$showReviewed?' selected':'').'>Pending students only</option><option value="1"'.($showReviewed?' selected':'').'>Include reviewed students</option></select></label><button class="btn btn-light">Find students</button></form></div>';
        $studentWhere="ea.exam_id=? AND ea.status IN ('submitted','evaluated')";
        $studentParams=[$selectedExam];
        if(!$showReviewed)$studentWhere.=" AND EXISTS (SELECT 1 FROM descriptive_answers pda WHERE pda.attempt_id=ea.id AND pda.marking_status='pending_review')";
        if($studentSearch!==''){$studentWhere.=" AND (sp.register_number LIKE ? OR u.display_name LIKE ?)";$studentParams[]='%'.$studentSearch.'%';$studentParams[]='%'.$studentSearch.'%';}
        $s=$pdo->prepare("SELECT COUNT(*) FROM exam_attempts ea JOIN users u ON u.id=ea.student_user_id JOIN student_profiles sp ON sp.user_id=u.id WHERE ".$studentWhere);$s->execute($studentParams);$studentCount=(int)$s->fetchColumn();
        $perPage=50;$studentPageCount=max(1,(int)ceil($studentCount/$perPage));$studentPage=min($studentPage,$studentPageCount);$studentOffset=($studentPage-1)*$perPage;
        $s=$pdo->prepare("SELECT u.id AS student_id,u.display_name,sp.register_number,ea.id AS attempt_id,ea.submitted_at,COUNT(da.id) AS answer_count,SUM(CASE WHEN da.marking_status='pending_review' THEN 1 ELSE 0 END) AS pending_count FROM exam_attempts ea JOIN users u ON u.id=ea.student_user_id JOIN student_profiles sp ON sp.user_id=u.id LEFT JOIN descriptive_answers da ON da.attempt_id=ea.id WHERE ".$studentWhere." GROUP BY ea.id,u.id,u.display_name,sp.register_number,ea.submitted_at ORDER BY sp.register_number LIMIT ".$perPage." OFFSET ".$studentOffset);$s->execute($studentParams);$studentRows=$s->fetchAll();
        $studentBase=['action'=>'descriptive_review','date'=>$selectedDate,'exam'=>$selectedExam,'show_reviewed'=>$showReviewed?'1':'0'];if($studentSearch!=='')$studentBase['student_search']=$studentSearch;
        echo '<div class="card"><div class="card-title-row"><h2>Students to review</h2><span class="pill">'.$studentCount.' student'.($studentCount===1?'':'s').'</span></div>';
        if($studentCount>0){echo '<p class="muted small">Showing '.($studentOffset+1).'–'.min($studentOffset+$perPage,$studentCount).' of '.$studentCount.' students. The list is limited to 50 per page.</p><div class="table-wrap"><table><thead><tr><th>Register number</th><th>Student</th><th>Pending answers</th><th>Total answers</th><th></th></tr></thead><tbody>';
            foreach($studentRows as $student){$studentUrl='index.php?'.http_build_query($studentBase+['student'=>(int)$student['student_id'],'student_page'=>$studentPage]);echo '<tr'.((int)$student['student_id']===$selectedStudent?' class="review-selected"':'').'><td>'.e($student['register_number']).'</td><td>'.e($student['display_name']).'</td><td>'.(int)$student['pending_count'].'</td><td>'.(int)$student['answer_count'].'</td><td><a class="btn btn-light btn-sm" href="'.$studentUrl.'">Review student</a></td></tr>';}
            echo '</tbody></table></div>';
            if($studentPageCount>1){echo '<div class="form-actions review-pagination">';for($pageNumber=1;$pageNumber<=$studentPageCount;$pageNumber++){if($pageNumber===$studentPage)echo '<span class="pill">Page '.$pageNumber.'</span>';else echo '<a class="btn btn-light btn-sm" href="index.php?'.http_build_query($studentBase+['student_page'=>$pageNumber]).'">Page '.$pageNumber.'</a>';}echo '</div>';}
        }else echo '<p class="muted">No students match this search and review filter.</p>';
        echo '</div>';

        if($selectedStudent>0){
            $s=$pdo->prepare("SELECT ea.id AS attempt_id,u.id AS student_id,u.display_name,u.login_id,sp.register_number,ea.submitted_at FROM exam_attempts ea JOIN users u ON u.id=ea.student_user_id JOIN student_profiles sp ON sp.user_id=u.id WHERE ea.exam_id=? AND u.id=? AND ea.status IN ('submitted','evaluated')");$s->execute([$selectedExam,$selectedStudent]);$student=$s->fetch();
            if(!$student){echo '<div class="alert alert-warning">That student does not have a submitted attempt for this exam.</div>';}
            else{
                $closeUrl='index.php?'.http_build_query($studentBase+['student_page'=>$studentPage]);
                $allQuestionsUrl='index.php?'.http_build_query($studentBase+['student'=>(int)$student['student_id'],'student_page'=>$studentPage]);
                echo '<div class="card"><div class="card-title-row"><h2>'.e($student['display_name']).' · '.e($student['register_number']).'</h2><div class="form-actions">'.($selectedQuestion>0?'<a class="btn btn-light" href="'.$allQuestionsUrl.'">View all questions</a>':'').'<a class="btn btn-light" href="'.$closeUrl.'">Close student review</a></div></div><p class="muted">Login: '.e($student['login_id']).' · Submitted '.e($student['submitted_at']?date('d M Y, H:i',strtotime($student['submitted_at'])):'—').'</p></div>';
                $answerQuery="SELECT da.id AS answer_id,da.answer_text,da.marks_awarded,da.marking_status,da.matched_keywords,da.marking_explanation,da.review_reason,dq.id AS question_id,dq.question_number,dq.question_text,dq.max_marks,dq.accepted_answers,dq.keyword_rules,ea.id AS attempt_id,ea.submitted_at FROM descriptive_answers da JOIN descriptive_questions dq ON dq.id=da.descriptive_question_id JOIN exam_attempts ea ON ea.id=da.attempt_id WHERE ea.id=?".(!$showReviewed?" AND da.marking_status='pending_review'":"").($selectedQuestion>0?" AND dq.id=?":"")." ORDER BY dq.question_number,dq.id";
                $answerStatement=$pdo->prepare($answerQuery);$answerParams=[(int)$student['attempt_id']];if($selectedQuestion>0)$answerParams[]=$selectedQuestion;$answerStatement->execute($answerParams);$studentAnswers=$answerStatement->fetchAll();$answersByQuestion=[];
                foreach($studentAnswers as $answer)$answersByQuestion[(int)$answer['question_id']][]=$answer;
                if(!$studentAnswers)echo '<div class="card">'.($showReviewed?'No answers found for this student and question.':'This student has no pending answers in the current view. Select “Include reviewed students” and open the student again to inspect completed answers.').'</div>';
                foreach($answersByQuestion as $questionAnswers){$question=$questionAnswers[0];$accepted=json_decode((string)$question['accepted_answers'],true);$rules=json_decode((string)$question['keyword_rules'],true);echo '<section class="card" id="question-'.(int)$question['question_id'].'"><div class="card-title-row"><h2>Question '.(int)$question['question_number'].'</h2><span class="pill">'.e($question['max_marks']).' max marks</span></div><p><b>Question:</b><br>'.nl2br(e($question['question_text'])).'</p><details><summary>Private answer key and rubric</summary><p><b>Accepted answers:</b> '.e(is_array($accepted)?implode(' | ',$accepted):'').'</p><p><b>Keyword marks:</b> '.e(is_array($rules)?implode(', ',array_map(static fn($rule)=>$rule['phrase'].'='.$rule['marks'],$rules)):'').'</p></details>';
                    foreach($questionAnswers as $row){$matched=json_decode((string)$row['matched_keywords'],true);$pending=$row['marking_status']==='pending_review';
                        echo '<article class="card review-answer"><div class="card-title-row"><h3>Student answer</h3><span class="pill">'.($pending?'Pending review':e(str_replace('_',' ',$row['marking_status']))).'</span></div><p><b>Answer:</b><br>'.($row['answer_text']===''?'<span class="muted">No answer provided</span>':nl2br(e($row['answer_text']))).'</p><p><b>Automatic score:</b> '.($row['marks_awarded']===null?'Pending':e($row['marks_awarded'])).' / '.e($row['max_marks']).'<br><b>Matched keywords:</b> '.e(is_array($matched)?implode(', ',$matched):'None').'<br><b>Explanation:</b> '.e($row['marking_explanation']??'').'</p><form method="post" class="form-grid">';csrf_input();echo '<input type="hidden" name="answer_id" value="'.(int)$row['answer_id'].'"><input type="hidden" name="review_date" value="'.e($selectedDate).'"><input type="hidden" name="review_exam" value="'.$selectedExam.'"><input type="hidden" name="review_question" value="'.(int)$row['question_id'].'"><input type="hidden" name="review_student" value="'.$selectedStudent.'"><input type="hidden" name="current_register" value="'.e($student['register_number']).'"><input type="hidden" name="show_reviewed" value="'.($showReviewed?'1':'0').'"><input type="hidden" name="search_register" value="'.e($studentSearch).'"><input type="hidden" name="student_page" value="'.$studentPage.'">';post_field('awarded_marks','Teacher-reviewed marks','number',(string)($row['marks_awarded']??'0'),true,'min="0" max="'.e($row['max_marks']).'" step="0.01"');echo '<label class="field"><span>Review reason (required)</span><textarea name="review_reason" rows="2" maxlength="2000" required>'.e($row['review_reason']??'').'</textarea></label><div class="form-actions"><button class="btn btn-primary" name="save_review" value="1">'.($pending?'Save teacher review':'Update teacher review').'</button>'.($pending?'<button class="btn btn-light" name="next_pending" value="1">Save &amp; next pending answer</button>':'').'</div></form></article>';
                    }
                    echo '</section>';
                }
            }
        }
    }elseif($selectedDate!==''){
        echo '<div class="alert alert-warning">Choose an exam above to view its questions and student answers.</div>';
    }elseif($summaryRows){
        echo '<div class="alert alert-warning">Choose an exam above to view its questions and student answers.</div>';
    }
    break;

case 'exams':
    require_role(['super_admin','exam_admin','teacher','student']);
    if(current_user()['role']!=='student' && $_SERVER['REQUEST_METHOD']==='POST'){
        require_role(['super_admin','exam_admin']);
        $title=trim((string)($_POST['title']??''));$subject=(int)($_POST['subject_id']??0);$pattern=(string)($_POST['exam_pattern']??'mcq');$start=str_replace('T',' ',(string)($_POST['start_at']??''));$end=str_replace('T',' ',(string)($_POST['end_at']??''));$duration=(int)($_POST['duration_minutes']??0);$marks=(float)($_POST['total_marks']??0);$pass=(float)($_POST['pass_marks']??0);$dept=(int)($_POST['department_id']??0);$year=trim((string)($_POST['year_level']??''));$status=(string)($_POST['status']??'scheduled');$examId=(int)($_POST['exam_id']??0);
        if(!in_array($status,['draft','scheduled','active','closed'],true)){$status='invalid';}if(!in_array($pattern,['mcq','typing','mixed'],true)){$pattern='invalid';}
        if($examId>0){
            $pdo->beginTransaction();
            try{
                $s=$pdo->prepare('SELECT id FROM exams WHERE id=? AND is_archived=0 FOR UPDATE');$s->execute([$examId]);$existing=$s->fetchColumn();
                if(!$existing){$pdo->rollBack();flash('danger','Examination not found.');redirect('exams');}
                $s=$pdo->prepare('SELECT COUNT(*) FROM exam_attempts WHERE exam_id=?');$s->execute([$examId]);$hasAttempts=(int)$s->fetchColumn()>0;
                if($hasAttempts){
                    if(!in_array($status,['active','closed'],true)){flash('danger','After an attempt exists, the status can only be Active or Closed.');}
                    else{$pdo->prepare('UPDATE exams SET status=? WHERE id=?')->execute([$status,$examId]);audit($pdo,'exam_status_updated','exam',(string)$examId,['status'=>$status]);flash('success','Examination status updated. Schedule details stay locked after an attempt exists.');}
                }elseif(!$title||!$subject||!$start||!$end||$duration<1||$marks<=0||$pass<0||$pass>$marks||strtotime($end)<=strtotime($start)||$status==='invalid'||$pattern==='invalid'){
                    flash('danger','Check title, subject, dates, duration, marks and status.');
                }else{
                    $s=$pdo->prepare('SELECT id FROM subjects WHERE id=? AND is_active=1');$s->execute([$subject]);
                    if(!$s->fetchColumn()){flash('danger','Choose an active subject.');}
                    else{$s=$pdo->prepare('UPDATE exams SET title=?,subject_id=?,exam_pattern=?,start_at=?,end_at=?,duration_minutes=?,total_marks=?,pass_marks=?,department_id=?,year_level=?,status=? WHERE id=? AND is_archived=0');$s->execute([$title,$subject,$pattern,date('Y-m-d H:i:s',strtotime($start)),date('Y-m-d H:i:s',strtotime($end)),$duration,$marks,$pass,$dept?:null,$year?:null,$status,$examId]);audit($pdo,'exam_updated','exam',(string)$examId,['exam_pattern'=>$pattern]);flash('success','Examination schedule updated.');}
                }
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Cycle Exam schedule update error: '.$e->getMessage());flash('danger','Examination could not be updated. Please review the schedule and try again.');}
            redirect('exams');
        }
        if(!$title||!$subject||!$start||!$end||$duration<1||$marks<=0||$pass<0||$pass>$marks||strtotime($end)<=strtotime($start)||$status==='invalid'||$pattern==='invalid'){
            flash('danger','Check title, subject, dates, duration, marks and status.');
        }else{$s=$pdo->prepare('SELECT id FROM subjects WHERE id=? AND is_active=1');$s->execute([$subject]);if(!$s->fetchColumn()){flash('danger','Choose an active subject.');}else{$s=$pdo->prepare('INSERT INTO exams(title,subject_id,exam_pattern,start_at,end_at,duration_minutes,total_marks,pass_marks,department_id,year_level,status,is_archived,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,0,?,NOW())');$s->execute([$title,$subject,$pattern,date('Y-m-d H:i:s',strtotime($start)),date('Y-m-d H:i:s',strtotime($end)),$duration,$marks,$pass,$dept?:null,$year?:null,$status,current_user()['id']]);audit($pdo,'exam_created','exam',(string)$pdo->lastInsertId(),['exam_pattern'=>$pattern]);flash('success','Exam created and scheduled.');}}
        redirect('exams');
    }
    if(in_array(current_user()['role'],['super_admin','exam_admin'],true)){
        $subjects=$pdo->query('SELECT id,subject_name FROM subjects WHERE is_active=1 ORDER BY subject_name')->fetchAll();$depts=$pdo->query('SELECT id,department_name FROM departments ORDER BY department_name')->fetchAll();$editId=(int)($_GET['edit']??0);$edit=null;$attemptCount=0;
        if($editId>0){$s=$pdo->prepare('SELECT * FROM exams WHERE id=? AND is_archived=0');$s->execute([$editId]);$edit=$s->fetch();if($edit){$s=$pdo->prepare('SELECT COUNT(*) FROM exam_attempts WHERE exam_id=?');$s->execute([$editId]);$attemptCount=(int)$s->fetchColumn();}}
        if($editId>0&&!$edit)echo '<div class="alert alert-danger">Examination not found.</div>';
        $formTitle=$edit?'Edit examination schedule':'Create monthly cycle test';echo '<div class="card"><h2>'.e($formTitle).'</h2>';
        if($edit&&$attemptCount>0)echo '<div class="alert alert-warning">Students have started this exam. Schedule details are locked to protect their attempts. You can still change the status.</div>';
        echo '<form method="post">';csrf_input();if($edit)echo '<input type="hidden" name="exam_id" value="'.(int)$edit['id'].'">';
        if(!$edit||$attemptCount===0){
            post_field('title','Exam title (e.g. October Cycle Test)','text',(string)($edit['title']??''),true);echo '<label class="field"><span>Subject</span><select name="subject_id" required><option value="">Choose subject</option>';foreach($subjects as $subj)echo '<option value="'.(int)$subj['id'].'"'.((int)($edit['subject_id']??0)===(int)$subj['id']?' selected':'').'>'.e($subj['subject_name']).'</option>';echo '</select></label><label class="field"><span>Exam pattern</span><select name="exam_pattern" required>';foreach(['mcq'=>'MCQ','typing'=>'Typing Answer','mixed'=>'Mixed Exam'] as $patternValue=>$patternLabel)echo '<option value="'.$patternValue.'"'.(($edit['exam_pattern']??'mcq')===$patternValue?' selected':'').'>'.$patternLabel.'</option>';echo '</select><small class="muted">Choose Typing Answer or Mixed Exam, save the exam, then open Typing questions to enter the prompt and answer key.</small></label><div class="form-grid">';
            post_field('start_at','Start date/time','datetime-local',$edit?date('Y-m-d\\TH:i',strtotime($edit['start_at'])):'',true);post_field('end_at','End date/time','datetime-local',$edit?date('Y-m-d\\TH:i',strtotime($edit['end_at'])):'',true);post_field('duration_minutes','Duration (minutes)','number',(string)($edit['duration_minutes']??30),true,'min="1"');post_field('total_marks','Total marks','number',(string)($edit['total_marks']??20),true,'min="0.01" step="0.01"');post_field('pass_marks','Pass marks','number',(string)($edit['pass_marks']??10),true,'min="0" step="0.01"');echo '<label class="field"><span>Department (optional)</span><select name="department_id"><option value="0">All departments</option>';foreach($depts as $d)echo '<option value="'.(int)$d['id'].'"'.((int)($edit['department_id']??0)===(int)$d['id']?' selected':'').'>'.e($d['department_name']).'</option>';echo '</select></label>';post_field('year_level','Year / level (optional)','text',(string)($edit['year_level']??''));echo '</div>';
        }else{echo '<p><b>'.e($edit['title']).'</b> · '.e($edit['start_at']).' to '.e($edit['end_at']).' · '.(int)$edit['duration_minutes'].' minutes</p>';}
        echo '<label class="field"><span>Exam status</span><select name="status">';$allowedStatuses=$attemptCount>0?['active'=>'Active','closed'=>'Closed']:['draft'=>'Draft','scheduled'=>'Scheduled','active'=>'Active','closed'=>'Closed'];foreach($allowedStatuses as $value=>$label)echo '<option value="'.$value.'"'.(($edit['status']??'scheduled')===$value?' selected':'').'>'.$label.'</option>';echo '</select></label><div class="form-actions"><button class="btn btn-primary">'.($edit?'Save examination':'Create exam').'</button>'.($edit?'<a class="btn btn-light" href="index.php?action=exams">Cancel</a>':'').'</div></form>';
        if(!$edit)echo '<p class="muted small">Question-to-exam assignment and randomization are not included; exams currently use the subject question bank.</p>';echo '</div>';
    }
    $canManageExams=in_array(current_user()['role'],['super_admin','exam_admin'],true);
    if(current_user()['role']==='student'){$s=$pdo->prepare("SELECT e.*,s.subject_name FROM exams e JOIN subjects s ON s.id=e.subject_id LEFT JOIN student_profiles sp ON sp.user_id=? WHERE e.is_archived=0 AND e.status IN ('scheduled','active','closed','evaluated','published') AND (e.department_id IS NULL OR e.department_id=sp.department_id) AND (e.year_level IS NULL OR e.year_level=sp.year_level) ORDER BY e.start_at DESC LIMIT 100");$s->execute([current_user()['id']]);$rows=$s->fetchAll();}
    else{$rows=$pdo->query('SELECT e.*,s.subject_name FROM exams e JOIN subjects s ON s.id=e.subject_id WHERE e.is_archived=0 ORDER BY e.start_at DESC LIMIT 100')->fetchAll();}
    echo '<div class="card"><h2>Examination schedule</h2>';if(current_user()['role']==='student')echo '<p class="muted">Use Take exam during the scheduled window. If an attempt is already in progress, resume it here or from Dashboard.</p>';echo '<div class="table-wrap"><table><thead><tr><th>Exam</th><th>Subject</th><th>Pattern</th><th>Start</th><th>End</th><th>Duration</th><th>Status</th>';if($canManageExams||current_user()['role']==='student')echo '<th>Action</th>';echo '</tr></thead><tbody>';
    foreach($rows as $r){echo '<tr><td>'.e($r['title']).'</td><td>'.e($r['subject_name']).'</td><td>'.e(ucfirst($r['exam_pattern']??'mcq')).'</td><td>'.e($r['start_at']).'</td><td>'.e($r['end_at']).'</td><td>'.(int)$r['duration_minutes'].' min</td>';
        if(current_user()['role']==='student'){$a=$pdo->prepare('SELECT id,status,started_at FROM exam_attempts WHERE exam_id=? AND student_user_id=? LIMIT 1');$a->execute([$r['id'],current_user()['id']]);$attempt=$a->fetch()?:null;$access=student_exam_access($r,$attempt);echo '<td><span class="pill">'.e($access['label']).'</span></td><td>'.($access['action']?'<a class="btn btn-primary btn-sm" href="index.php?action=take_exam&id='.(int)$r['id'].'">'.e($access['action']).'</a>':'—').'</td>';}
        else{echo '<td><span class="pill">'.e($r['status']).'</span></td>';if($canManageExams)echo '<td><a class="btn btn-light btn-sm" href="index.php?action=exams&edit='.(int)$r['id'].'">Edit / status</a></td>';}
        echo '</tr>';}
    if(!$rows)echo '<tr><td colspan="'.(($canManageExams||current_user()['role']==='student')?8:7).'">No examinations scheduled for your account.</td></tr>';echo '</tbody></table></div></div>';
    break;

case 'take_exam':
    require_role(['student']);
    $examId=(int)($_GET['id']??$_POST['exam_id']??0);$studentId=(int)current_user()['id'];
    $s=$pdo->prepare("SELECT e.*,su.subject_name FROM exams e JOIN subjects su ON su.id=e.subject_id LEFT JOIN student_profiles sp ON sp.user_id=? WHERE e.id=? AND e.is_archived=0 AND (e.department_id IS NULL OR e.department_id=sp.department_id) AND (e.year_level IS NULL OR e.year_level=sp.year_level)");$s->execute([$studentId,$examId]);$exam=$s->fetch();if(!$exam){echo '<div class="card">Exam not found or you are not eligible.</div>';break;}
    $s=$pdo->prepare("SELECT * FROM exam_attempts WHERE exam_id=? AND student_user_id=? LIMIT 1");$s->execute([$examId,$studentId]);$attempt=$s->fetch();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $attemptId=(int)($_POST['attempt_id']??0);$sub=$pdo->prepare("SELECT * FROM exam_attempts WHERE id=? AND exam_id=? AND student_user_id=? AND status='in_progress'");$sub->execute([$attemptId,$examId,$studentId]);$postedAttempt=$sub->fetch();
        if(!$postedAttempt){flash('danger','This attempt is no longer active.');redirect('dashboard');}
        $answers=$_POST['answers']??[];$flags=$_POST['flags']??[];if(!is_array($answers))$answers=[];if(!is_array($flags))$flags=[];
        $deadline=min(strtotime($postedAttempt['started_at'])+(int)$exam['duration_minutes']*60,strtotime($exam['end_at']));
        $expired=$exam['status']!=='active'||time()>=$deadline;
        try{finalize_exam_attempt($pdo,$examId,$studentId,$postedAttempt,$exam,$answers,$flags,$expired);$pendingCheck=$pdo->prepare("SELECT COUNT(*) FROM descriptive_answers WHERE attempt_id=? AND marking_status='pending_review'");$pendingCheck->execute([$attemptId]);$pendingReviews=(int)$pendingCheck->fetchColumn();flash('success',$pendingReviews?'Your examination was submitted. '.$pendingReviews.' descriptive answer(s) need teacher review before a final result can be published.':'Your examination was submitted. Results will appear after the examination administrator publishes them.');redirect('dashboard');}
        catch(Throwable $e){error_log('Cycle Exam submission error: '.$e->getMessage());flash('danger','Submission could not be completed. Your saved answers are still available; please retry.');header('Location: index.php?action=take_exam&id='.$examId);exit;}
    }
    if($attempt&&$attempt['status']!=='in_progress'){echo '<div class="card"><h2>Attempt submitted</h2><p>Your attempt has already been submitted. A second attempt is not allowed for this examination.</p></div>';break;}
    if($attempt){
        $attemptDeadline=min(strtotime($attempt['started_at'])+(int)$exam['duration_minutes']*60,strtotime($exam['end_at']));
        if($exam['status']!=='active'||time()>=$attemptDeadline){
            try{finalize_exam_attempt($pdo,$examId,$studentId,$attempt,$exam,[],[],true);flash('warning','Your saved answers were submitted because the exam ended or was closed. Your attempt was not lost.');redirect('dashboard');}
            catch(Throwable $e){error_log('Cycle Exam expired attempt finalization error: '.$e->getMessage());echo '<div class="alert alert-danger">Your saved attempt could not be submitted yet. Please retry when your connection is available.</div>';break;}
        }
    }elseif($exam['status']!=='active'||strtotime($exam['start_at'])>time()||strtotime($exam['end_at'])<time()){
        echo '<div class="card">This examination is not currently open.</div>';break;
    }
    $questions=[];
    if(in_array($exam['exam_pattern']??'mcq',['mcq','mixed'],true)){
        $q=$pdo->prepare('SELECT id,question_text,option_a,option_b,option_c,option_d,marks FROM questions WHERE subject_id=? AND is_archived=0 ORDER BY id');$q->execute([$exam['subject_id']]);foreach($q->fetchAll() as $item){$item['question_type']='mcq';$item['question_number']=count($questions)+1;$questions[]=$item;}
    }
    if(in_array($exam['exam_pattern']??'mcq',['typing','mixed'],true)){
        $q=$pdo->prepare('SELECT id,question_number,question_text,max_marks AS marks FROM descriptive_questions WHERE exam_id=? AND is_archived=0 ORDER BY question_number,id');$q->execute([$examId]);foreach($q->fetchAll() as $item){$item['question_type']='typing';$questions[]=$item;}
    }
    if(!$questions){echo '<div class="card"><h2>No questions available</h2><p>Contact an examination administrator or teacher to configure questions for this exam. No attempt was started.</p></div>';break;}
    if(!$attempt){$pdo->prepare("INSERT INTO exam_attempts(exam_id,student_user_id,status,started_at) VALUES(?,?,'in_progress',NOW())")->execute([$examId,$studentId]);$attemptId=(int)$pdo->lastInsertId();$attempt=['id'=>$attemptId,'started_at'=>date('Y-m-d H:i:s')];}else $attemptId=(int)$attempt['id'];
    $deadline=min(strtotime($attempt['started_at'])+(int)$exam['duration_minutes']*60,strtotime($exam['end_at']));
    $answers=[];$flags=[];$saved=$pdo->prepare('SELECT question_id,selected_option,is_flagged FROM attempt_answers WHERE attempt_id=?');$saved->execute([$attemptId]);foreach($saved->fetchAll() as $a){$qid=(int)$a['question_id'];$answers['m'.$qid]=$a['selected_option'];$flags['m'.$qid]=(int)$a['is_flagged']===1;}
    $saved=$pdo->prepare('SELECT descriptive_question_id,answer_text FROM descriptive_answers WHERE attempt_id=?');$saved->execute([$attemptId]);foreach($saved->fetchAll() as $a)$answers['d'.(int)$a['descriptive_question_id']]=$a['answer_text'];
    echo '<div class="exam-top card"><div><h2>'.e($exam['title']).'</h2><p>'.e($exam['subject_name']).' · <span id="exam-progress">0 of '.count($questions).' answered</span></p></div><div class="timer" id="exam-timer" data-deadline="'.(int)$deadline.'">--:--</div></div><div id="exam-connectivity" class="alert alert-success exam-connectivity" role="status"><span id="exam-connection-text">Connected — answers save automatically.</span><button class="btn btn-light btn-sm" type="button" id="exam-fullscreen">Optional fullscreen</button></div><div id="exam-focus-warning" class="alert alert-warning exam-focus-warning" role="status" hidden></div><p class="muted small">If your connection drops, keep this page open and reconnect. This web exam can warn when you switch apps, but cannot lock your phone or prevent tab switching.</p><form method="post" id="exam-form" class="exam-form" data-attempt-id="'.$attemptId.'">';csrf_input();echo '<input type="hidden" name="exam_id" value="'.$examId.'"><input type="hidden" name="attempt_id" value="'.$attemptId.'"><div class="exam-layout"><div class="exam-questions">';
    foreach($questions as $i=>$q){
        $qid=(int)$q['id'];$answerKey=($q['question_type']==='typing'?'d':'m').$qid;$label=$q['question_type']==='typing'?'Written question':'MCQ question';
        echo '<section class="card question-card" data-question-index="'.$i.'"><div class="question-number">'.e($label).' '.(int)$q['question_number'].' · '.e($q['marks']).' mark(s)</div><h3>'.e($q['question_text']).'</h3>';
        if($q['question_type']==='typing')echo '<label class="field"><span>Your answer</span><textarea name="answers['.$answerKey.']" data-question-id="'.$answerKey.'" rows="7" maxlength="60000" autocomplete="off">'.e($answers[$answerKey]??'').'</textarea></label>';
        else foreach(['A'=>'option_a','B'=>'option_b','C'=>'option_c','D'=>'option_d'] as $letter=>$col)echo '<label class="option"><input type="radio" name="answers['.$answerKey.']" value="'.$letter.'" data-question-id="'.$answerKey.'" '.(($answers[$answerKey]??'')===$letter?'checked':'').'> <span><b>'.$letter.'.</b> '.e($q[$col]).'</span></label>';
        echo '<div class="question-actions"><span class="muted">Question '.($i+1).' of '.count($questions).'</span>';
        if($q['question_type']==='mcq')echo '<input type="hidden" name="flags['.$answerKey.']" value="'.(!empty($flags[$answerKey])?'1':'0').'" data-flag-value data-question-id="'.$answerKey.'"><button class="btn btn-light btn-sm" type="button" data-flag-button data-question-id="'.$answerKey.'" aria-pressed="'.(!empty($flags[$answerKey])?'true':'false').'">'.(!empty($flags[$answerKey])?'Unflag question':'Flag for review').'</button>';
        echo '</div></section>';
    }
    echo '</div><aside class="card question-navigation"><h2>Question navigator</h2><div class="question-nav-grid">';
    foreach($questions as $i=>$q){$qid=(int)$q['id'];$answerKey=($q['question_type']==='typing'?'d':'m').$qid;$answered=trim((string)($answers[$answerKey]??''))!=='';$flagged=!empty($flags[$answerKey]);echo '<button class="question-nav-button'.($i===0?' current':'').($answered?' answered':'').($flagged?' flagged':'').'" type="button" data-nav-index="'.$i.'" aria-label="Question '.(int)$q['question_number'].($answered?', answered':', unanswered').($flagged?', flagged for review':'').'">'.(int)$q['question_number'].'</button>';}
    echo '</div><div class="question-legend"><span><i class="legend-dot answered-dot"></i>Answered</span><span><i class="legend-dot"></i>Unanswered</span><span><i class="legend-dot flagged-dot"></i>Flagged</span></div><p id="answer-save-status" class="save-status" role="status" aria-live="polite">Answers save automatically.</p></aside></div><div class="sticky-submit"><button class="btn btn-light" type="button" id="previous-question">Previous</button><span class="muted">Review unanswered questions before submitting.</span><div class="form-actions"><button class="btn btn-light" type="button" id="next-question">Next</button><button class="btn btn-primary" type="submit">Submit exam</button></div></div></form>';
    break;

case 'results':
    require_role(['super_admin','exam_admin','teacher','student']);
    if(current_user()['role']!=='student' && $_SERVER['REQUEST_METHOD']==='POST'){require_role(['super_admin','exam_admin']);$id=(int)($_POST['result_id']??0);$s=$pdo->prepare('UPDATE results SET is_published=1,published_at=NOW() WHERE id=?');$s->execute([$id]);audit($pdo,'result_published','result',(string)$id);flash('success','Result published to the student.');redirect('results');}
    if(current_user()['role']==='student'){$s=$pdo->prepare("SELECT r.*,e.title,u.display_name,u.login_id FROM results r JOIN exams e ON e.id=r.exam_id JOIN users u ON u.id=r.student_user_id WHERE r.student_user_id=? AND r.is_published=1 ORDER BY r.published_at DESC");$s->execute([current_user()['id']]);$rows=$s->fetchAll();}
    else {$rows=$pdo->query("SELECT r.*,e.title,u.display_name,u.login_id FROM results r JOIN exams e ON e.id=r.exam_id JOIN users u ON u.id=r.student_user_id ORDER BY r.created_at DESC LIMIT 300")->fetchAll();}
    if(current_user()['role']!=='student'){$pendingCount=(int)$pdo->query("SELECT COUNT(DISTINCT ea.id) FROM exam_attempts ea JOIN descriptive_answers da ON da.attempt_id=ea.id WHERE ea.status='submitted' AND da.marking_status='pending_review'")->fetchColumn();if($pendingCount)echo '<div class="alert alert-warning">'.$pendingCount.' descriptive attempt(s) are awaiting review. Their provisional marks are not included in results.</div>';}
    echo '<div class="card"><h2>'.(current_user()['role']==='student'?'Your published results':'Exam results · latest 300').'</h2><div class="table-wrap"><table><thead><tr><th>Exam</th><th>Student</th><th>Register no.</th><th>Marks</th><th>Percentage</th><th>Status</th><th></th></tr></thead><tbody>';foreach($rows as $r){$published=(int)$r['is_published'];echo '<tr><td>'.e($r['title']).'</td><td>'.e($r['display_name']).'</td><td>'.e($r['login_id']).'</td><td>'.e($r['marks_obtained']).' / '.e($r['max_marks']).'</td><td>'.number_format((float)$r['percentage'],2).'%</td><td>'.($published?'Published':'Pending').'</td><td>'.(!$published&&current_user()['role']!=='student'?'<form method="post">':'');if(!$published&&current_user()['role']!=='student'){csrf_input();echo '<input type="hidden" name="result_id" value="'.(int)$r['id'].'"><button class="btn btn-primary btn-sm">Publish</button></form> ';}echo '<a class="btn btn-light btn-sm" href="index.php?action=result_detail&id='.(int)$r['id'].'">Question marks</a></td></tr>';}if(!$rows)echo '<tr><td colspan="7">No results found.</td></tr>';echo '</tbody></table></div></div>';
    break;

case 'result_detail':
    require_role(['super_admin','exam_admin','student']);
    $resultId=(int)($_GET['id']??0);$sql='SELECT r.*,e.title,e.exam_pattern,u.display_name FROM results r JOIN exams e ON e.id=r.exam_id JOIN users u ON u.id=r.student_user_id WHERE r.id=?';$params=[$resultId];if(current_user()['role']==='student'){$sql.=' AND r.student_user_id=? AND r.is_published=1';$params[]=(int)current_user()['id'];}
    $s=$pdo->prepare($sql);$s->execute($params);$result=$s->fetch();if(!$result){http_response_code(404);echo '<div class="card">Result not found or not available.</div>';break;}
    echo '<div class="card"><h2>'.e($result['title']).' · '.e($result['display_name']).'</h2><p><b>Total:</b> '.e($result['marks_obtained']).' / '.e($result['max_marks']).' · <b>Percentage:</b> '.number_format((float)$result['percentage'],2).'%</p></div>';
    if(in_array($result['exam_pattern'],['mcq','mixed'],true)){$s=$pdo->prepare('SELECT q.question_text,q.marks,aa.marks_awarded,aa.is_correct FROM attempt_answers aa JOIN questions q ON q.id=aa.question_id WHERE aa.attempt_id=? ORDER BY q.id');$s->execute([$result['attempt_id']]);$mcqRows=$s->fetchAll();if($mcqRows){echo '<div class="card"><h2>Multiple-choice questions</h2><div class="table-wrap"><table><thead><tr><th>Question</th><th>Maximum marks</th><th>Awarded</th></tr></thead><tbody>';foreach($mcqRows as $row)echo '<tr><td>'.e($row['question_text']).'</td><td>'.e($row['marks']).'</td><td>'.e($row['marks_awarded']).'</td></tr>';echo '</tbody></table></div></div>';}}
    if(in_array($result['exam_pattern'],['typing','mixed'],true)){$s=$pdo->prepare('SELECT dq.question_number,dq.question_text,dq.max_marks,da.answer_text,da.marks_awarded,da.marking_status,da.marking_explanation FROM descriptive_answers da JOIN descriptive_questions dq ON dq.id=da.descriptive_question_id WHERE da.attempt_id=? ORDER BY dq.question_number');$s->execute([$result['attempt_id']]);$writtenRows=$s->fetchAll();if($writtenRows){echo '<div class="card"><h2>Typing-answer questions</h2><div class="table-wrap"><table><thead><tr><th>Question</th><th>Your answer</th><th>Maximum marks</th><th>Awarded</th><th>Marking status</th></tr></thead><tbody>';foreach($writtenRows as $row)echo '<tr><td>'.e($row['question_text']).'</td><td>'.nl2br(e($row['answer_text'])).'</td><td>'.e($row['max_marks']).'</td><td>'.e($row['marks_awarded']).'</td><td>'.e(str_replace('_',' ',$row['marking_status'])).'</td></tr>';echo '</tbody></table></div></div>';}}
    break;

case 'settings':
    require_role(['super_admin']);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $allowed=['institution_name','portal_description','primary_color','accent_color','footer_text','contact_email','privacy_policy','exam_rules'];$updates=[];
        foreach($allowed as $key){$val=trim((string)($_POST[$key]??''));if(in_array($key,['primary_color','accent_color'],true)&&!preg_match('/^#[0-9a-fA-F]{6}$/',$val)){flash('danger','Colors must be valid six-digit hex values.');redirect('settings');}$updates[$key]=$val;}
        foreach($updates as $key=>$val){$s=$pdo->prepare('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');$s->execute([$key,$val]);}
        if(!empty($_FILES['logo']['tmp_name'])&&($_FILES['logo']['error']??1)===UPLOAD_ERR_OK){$size=(int)$_FILES['logo']['size'];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['logo']['tmp_name']);$ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??null;if($size<2*1024*1024&&$ext){$dir=__DIR__.'/uploads';if(!is_dir($dir))mkdir($dir,0750,true);$name='logo_'.bin2hex(random_bytes(8)).'.'.$ext;if(move_uploaded_file($_FILES['logo']['tmp_name'],$dir.'/'.$name)){$path='uploads/'.$name;$s=$pdo->prepare('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');$s->execute(['logo_path',$path]);}}else{flash('warning','Settings saved, but logo upload was rejected. Use PNG/JPEG/WebP under 2 MB.');}}
        audit($pdo,'settings_updated','settings');flash('success','Settings saved.');redirect('settings');
    }
    echo '<div class="card"><h2>Institution branding</h2><p class="muted">Changes apply across the login page and portal. Current brand palette uses aviation red (#E31825) and gold (#FFC20E). Logo upload accepts PNG, JPEG or WebP up to 2 MB.</p><form method="post" enctype="multipart/form-data">';csrf_input();post_field('institution_name','Institution name','text',setting($pdo,'institution_name',$config['app_name']),true);post_field('portal_description','Portal description','text',setting($pdo,'portal_description','Monthly cycle test examination portal'));echo '<div class="form-grid">';post_field('primary_color','Primary color','color',setting($pdo,'primary_color','#e31825'),true);post_field('accent_color','Accent color','color',setting($pdo,'accent_color','#ffc20e'),true);post_field('contact_email','Contact email','email',setting($pdo,'contact_email',''));echo '</div>';post_field('footer_text','Footer text','text',setting($pdo,'footer_text',''));echo '<label class="field"><span>College logo</span><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>';echo '<label class="field"><span>Privacy policy (plain text)</span><textarea name="privacy_policy" rows="5">'.e(setting($pdo,'privacy_policy','This portal uses student information only for authorized academic examination purposes. Contact the institution for questions about data handling.')).'</textarea></label><label class="field"><span>Examination rules</span><textarea name="exam_rules" rows="5">'.e(setting($pdo,'exam_rules','Use your own login. Read each question carefully. Do not refresh repeatedly or close the browser during an exam. Submit before the timer expires. Contact the examination office if a technical issue occurs.')).'</textarea></label><button class="btn btn-primary">Save settings</button></form></div>';
    break;

case 'policies':
case 'public_policies':
    echo '<div class="card"><h2>Privacy policy</h2><div class="policy-text">'.nl2br(e(setting($pdo,'privacy_policy','This portal uses student information only for authorized academic examination purposes. Contact the institution for questions about data handling.'))).'</div><p class="muted small">Template text — institution administrators should review and approve before deployment.</p></div><div class="card"><h2>Examination rules</h2><div class="policy-text">'.nl2br(e(setting($pdo,'exam_rules','Use your own login. Read each question carefully. Do not refresh repeatedly or close the browser during an exam. Submit before the timer expires. Contact the examination office if a technical issue occurs.'))).'</div></div>';
    break;

case 'audit':
    require_role(['super_admin']);
    $rows=$pdo->query('SELECT a.*,u.display_name FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.id DESC LIMIT 200')->fetchAll();echo '<div class="card"><h2>Recent security and admin events</h2><div class="table-wrap"><table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Target</th><th>IP</th></tr></thead><tbody>';foreach($rows as $r)echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['display_name']??'System/Unknown').'</td><td>'.e($r['action']).'</td><td>'.e($r['target_type'].' '.$r['target_id']).'</td><td>'.e($r['ip_address']).'</td></tr>';if(!$rows)echo '<tr><td colspan="5">No events recorded yet.</td></tr>';echo '</tbody></table></div></div>';
    break;

default:
    echo '<div class="card"><h2>Page not found</h2><p>The requested page does not exist.</p><a class="btn btn-primary" href="index.php?action=dashboard">Return to dashboard</a></div>';
}
page_end();
