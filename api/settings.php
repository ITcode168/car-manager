<?php
require_once '../config.php';

// 公开接口：首页轮播图（不需要登录）
if (($_GET['a'] ?? '') === 'banners_public') {
    $banners = db()->query("SELECT image_url, link_url FROM banners WHERE status=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    json_out(['code'=>1,'list'=>$banners]);
}

$u = auth();
$sid = (int)$u['shop_id'];
$action = $_GET['a'] ?? '';

if ($action === 'get') {
    json_out(['code'=>1,'default_months'=>$u['default_months'],'default_km'=>$u['default_km'],'shop_name'=>$u['shop_name'],'expire_date'=>$u['expire_date'],'role'=>$u['role'],'name'=>$u['name']]);
}
if ($action === 'save') {
    if ($u['role'] == 'staff') json_out(['code'=>0,'msg'=>'只有老板能修改店铺设置']);
    $months = max(0, min(36, (int)($_POST['default_months'] ?? 6)));
    $km = max(0, min(999999, (int)($_POST['default_km'] ?? 5000)));
    $shopName = clean_str($_POST['shop_name'] ?? $u['shop_name'], 50);
    db()->prepare('UPDATE users SET default_months=?, default_km=? WHERE id=?')->execute([$months,$km,$u['id']]);
    db()->prepare('UPDATE shops SET name=? WHERE id=?')->execute([$shopName,$sid]);
    json_out(['code'=>1]);
}
if ($action === 'change_pwd') {
    $old = (string)($_POST['old_pwd'] ?? '');
    $new = (string)($_POST['new_pwd'] ?? '');
    if (!password_verify($old, $u['password_hash'])) json_out(['code'=>0,'msg'=>'原密码错误']);
    if (strlen($new) < 6) json_out(['code'=>0,'msg'=>'新密码至少6位']);
    db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$u['id']]);
    json_out(['code'=>1]);
}

// 公告
if ($action === 'announcements') {
    $scroll = db()->query("SELECT content FROM announcements WHERE type='scroll' AND expire_date>=CURDATE() ORDER BY id DESC LIMIT 1")->fetchColumn();
    $popup = db()->query("SELECT id, content FROM announcements WHERE type='popup' AND expire_date>=CURDATE() ORDER BY id DESC LIMIT 1")->fetch();
    json_out(['code'=>1,'scroll'=>$scroll,'popup'=>$popup]);
}
