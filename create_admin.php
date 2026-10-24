<?php
declare(strict_types=1);
/**
 * Run once from CLI: php create_admin.php
 * Then delete this file from htdocs. It refuses to run through the browser.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this script from Command Prompt only.\n"); }
$configPath=__DIR__.'/config.php';
if(!is_file($configPath)){fwrite(STDERR,"Missing config.php. Copy config.example.php to config.php first.\n");exit(1);}
$c=require $configPath;
try{$pdo=new PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4',$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
catch(Throwable $e){fwrite(STDERR,"Database connection failed. Check config.php and import install.sql.\n");exit(1);}
$login=trim((string)readline("Admin username (e.g. examadmin): "));
$name=trim((string)readline("Admin display name: "));
fwrite(STDOUT,"Choose a strong admin password (input will be visible in this basic script; run in a private console): ");
$password=trim((string)readline());
if($login===''||$name===''||strlen($password)<12){fwrite(STDERR,"\nUsername/name required; password must be at least 12 characters.\n");exit(1);}
$s=$pdo->prepare("SELECT id FROM users WHERE login_id=?");$s->execute([$login]);if($s->fetchColumn()){fwrite(STDERR,"That username already exists.\n");exit(1);}
$s=$pdo->prepare("INSERT INTO users(login_id,display_name,password_hash,role,is_active,must_change_password,created_at,updated_at) VALUES(?,?,?,'super_admin',1,1,NOW(),NOW())");
$s->execute([$login,$name,password_hash($password,PASSWORD_DEFAULT)]);
fwrite(STDOUT,"\nAdmin created. Sign in and change the password. Delete create_admin.php from the web folder after setup.\n");
