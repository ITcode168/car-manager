<?php
require_once '../config.php';
$u = is_boss();
$sid = (int)$u['shop_id'];
$action = $_GET['a'] ?? '';

if ($action === 'list') {
    $stmt = db()->prepare('SELECT id,phone,name,role,disabled,created_at FROM users WHERE shop_id=?');
    $stmt->execute([$sid]);
    $list = $stmt->fetchAll();
    json_out(['code'=>1,'list'=>$list]);
}
if ($action === 'add') {
    $phone = clean_phone($_POST['phone'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $name = clean_str($_POST['name'] ?? '徒弟', 20);
    if (strlen($phone) !== 11) json_out(['code'=>0,'msg'=>'请输入11位手机号']);
    if (strlen($password) < 6) json_out(['code'=>0,'msg'=>'密码至少6位']);
    $chk = db()->prepare('SELECT id FROM users WHERE phone=?');
    $chk->execute([$phone]);
    if ($chk->fetch()) json_out(['code'=>0,'msg'=>'该手机号已被注册']);
    db()->prepare('INSERT INTO users (shop_id,phone,password_hash,name,role,token) VALUES (?,?,?,?,?,NULL)')
        ->execute([$sid,$phone,password_hash($password,PASSWORD_DEFAULT),$name,'staff']);
    json_out(['code'=>1]);
}
if ($action === 'reset_pwd') {
    $id = (int)($_POST['id'] ?? 0);
    $pwd = (string)($_POST['password'] ?? '');
    if (strlen($pwd) < 6) json_out(['code'=>0,'msg'=>'密码至少6位']);
    db()->prepare('UPDATE users SET password_hash=?, token=NULL WHERE id=? AND shop_id=? AND role="staff"')->execute([password_hash($pwd,PASSWORD_DEFAULT),$id,$sid]);
    json_out(['code'=>1]);
}
if ($action === 'disable') {
    $id = (int)($_POST['id'] ?? 0);
    db()->prepare('UPDATE users SET token=NULL, disabled=1 WHERE id=? AND shop_id=? AND role="staff"')->execute([$id,$sid]);
    json_out(['code'=>1]);
}
if ($action === 'enable') {
    $id = (int)($_POST['id'] ?? 0);
    db()->prepare('UPDATE users SET disabled=0 WHERE id=? AND shop_id=? AND role="staff"')->execute([$id,$sid]);
    json_out(['code'=>1]);
}
