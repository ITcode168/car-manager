<?php
require_once '../config.php';
$action = $_GET['a'] ?? '';

// 注册
if ($action === 'register') {
    $phone = clean_phone($_POST['phone'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $name = clean_str($_POST['name'] ?? '', 20);
    if ($name === 'undefined' || $name === 'null') $name = '';
    
    if (strlen($phone) !== 11) json_out(['code'=>0,'msg'=>'请输入11位手机号']);
    if (strlen($password) < 6) json_out(['code'=>0,'msg'=>'密码至少6位']);
    
    $stmt = db()->prepare('SELECT id FROM users WHERE phone=?');
    $stmt->execute([$phone]);
    if ($stmt->fetch()) json_out(['code'=>0,'msg'=>'该手机号已注册']);
    
    db()->beginTransaction();
    // 读取超管设置的默认套餐
    $plan = db()->query("SELECT v FROM settings WHERE k='default_plan'")->fetchColumn() ?: 'free';
    $expireDate = null;
    if ($plan === 'trial7') $expireDate = date('Y-m-d', strtotime('+7 days'));
    elseif ($plan === 'trial30') $expireDate = date('Y-m-d', strtotime('+30 days'));
    // free=永久免费 expireDate=null
    // none=注册即过期（当天）
    if ($plan === 'none') $expireDate = date('Y-m-d');

    $name = trim($_POST['name'] ?? '');
    if ($name === 'undefined' || $name === 'null') $name = '';
    $shopName = $name ? $name.'的店' : '我的店';
    $stmt = db()->prepare('INSERT INTO shops (name, phone, expire_date) VALUES (?,?,?)');
    $stmt->execute([$shopName, $phone, $expireDate]);
    $shopId = db()->lastInsertId();
    
    $token = bin2hex(random_bytes(16));
    $stmt = db()->prepare('INSERT INTO users (shop_id, phone, password_hash, name, role, token) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$shopId, $phone, password_hash($password, PASSWORD_DEFAULT), $name ?: '老板', 'boss', $token]);
    
    $items = [
        ['小保养',380],['大保养',580],['补胎',30],['换轮胎',200],
        ['洗车',30],['打蜡',150],['镀晶',800],['贴膜',1500],
        ['空调清洗',150],['换刹车片',300]
    ];
    $stmt = db()->prepare('INSERT INTO items (shop_id, name, price) VALUES (?,?,?)');
    foreach ($items as $i) $stmt->execute([$shopId, $i[0], $i[1]]);
    
    db()->commit();
    json_out(['code'=>1,'token'=>$token,'msg'=>'注册成功']);
}

// 登录
if ($action === 'login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lockKey = 'login_lock_' . md5($ip);
    $lockUntil = db()->query("SELECT v FROM settings WHERE k=".db()->quote($lockKey))->fetchColumn();
    if ($lockUntil && $lockUntil > time()) {
        json_out(['code'=>0,'msg'=>'失败次数过多，请'.ceil(($lockUntil-time())/60).'分钟后再试']);
    }
    $phone = clean_phone($_POST['phone'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if (strlen($phone) !== 11) json_out(['code'=>0,'msg'=>'手机号格式不对']);
    
    $stmt = db()->prepare('SELECT u.*, s.name as shop_name, s.status as shop_status, s.expire_date FROM users u JOIN shops s ON u.shop_id=s.id WHERE u.phone=?');
    $stmt->execute([$phone]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        // 记录失败
        $failKey = 'login_fail_' . md5($ip);
        $fails = (int)db()->query("SELECT v FROM settings WHERE k=".db()->quote($failKey))->fetchColumn() + 1;
        db()->exec("REPLACE INTO settings (k,v) VALUES (".db()->quote($failKey).",".db()->quote($fails).")");
        if ($fails >= 5) {
            db()->exec("REPLACE INTO settings (k,v) VALUES (".db()->quote($lockKey).",".db()->quote(time()+300).")");
            db()->exec("DELETE FROM settings WHERE k=".db()->quote($failKey));
            json_out(['code'=>0,'msg'=>'失败5次，锁定5分钟']);
        }
        json_out(['code'=>0,'msg'=>'手机号或密码错误，还有'.(5-$fails).'次机会']);
    }
    db()->exec("DELETE FROM settings WHERE k=".db()->quote('login_fail_'.md5($ip)));
    if ($u['shop_status'] == 0) json_out(['code'=>0,'msg'=>'店铺已停用']);
    if ($u['expire_date'] && $u['expire_date'] < date('Y-m-d')) json_out(['code'=>0,'msg'=>'店铺已到期，请联系续费']);
    if (isset($u['disabled']) && $u['disabled'] == 1) json_out(['code'=>0,'msg'=>'该账号已被停用，请联系老板']);
    
    $token = bin2hex(random_bytes(16));
    db()->prepare('UPDATE users SET token=?, last_ip=?, last_active=NOW() WHERE id=?')->execute([$token, $ip, $u['id']]);
    json_out(['code'=>1,'token'=>$token,'role'=>$u['role'],'name'=>$u['name'],'shop_name'=>$u['shop_name']]);
}
