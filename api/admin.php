<?php
require_once '../config.php';
$action = $_GET['a'] ?? '';
// 登录和改密码不需要token验证
if (!in_array($action, ['login','change_pwd'])) {
    is_admin();
}

if ($action === 'overview') {
    $shops = db()->query("SELECT COUNT(*) FROM shops WHERE status=1")->fetchColumn();
    $todayOrders = db()->query("SELECT COUNT(*) FROM services WHERE service_date=CURDATE()")->fetchColumn();
    $monthRevenue = db()->query("SELECT COALESCE(SUM(amount),0) FROM services WHERE service_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
    $expiring = db()->query("SELECT COUNT(*) FROM shops WHERE status=1 AND expire_date IS NOT NULL AND expire_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
    json_out(['code'=>1,'shops'=>(int)$shops,'todayOrders'=>(int)$todayOrders,'monthRevenue'=>(float)$monthRevenue,'expiring'=>(int)$expiring]);
}

// 近30天图表数据
if ($action === 'chart') {
    $days = (int)($_GET['days'] ?? 30);
    $rows = db()->query("SELECT service_date, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM services WHERE service_date>=DATE_SUB(CURDATE(),INTERVAL $days DAY) GROUP BY service_date ORDER BY service_date")->fetchAll(PDO::FETCH_ASSOC);
    // 补全日期
    $dates=[];$cnts=[];$totals=[];
    for($i=$days-1;$i>=0;$i--){
        $d=date('Y-m-d',strtotime("-$i days"));
        $dates[]=substr($d,5);
        $cnts[]=0;$totals[]=0;
    }
    foreach($rows as $r){
        $key=substr($r['service_date'],5);
        $idx=array_search($key,$dates);
        if($idx!==false){$cnts[$idx]=(int)$r['cnt'];$totals[$idx]=(float)$r['total'];}
    }
    // 店铺增长
    $shopRows=db()->query("SELECT DATE(created_at) as d, COUNT(*) as c FROM shops WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL $days DAY) GROUP BY DATE(created_at) ORDER BY d")->fetchAll(PDO::FETCH_ASSOC);
    $shopTrend=[];
    $cum=db()->query("SELECT COUNT(*) FROM shops WHERE created_at<DATE_SUB(CURDATE(),INTERVAL $days DAY)")->fetchColumn();
    $shopMap=[];
    foreach($shopRows as $r){$shopMap[substr($r['d'],5)]=(int)$r['c'];}
    for($i=$days-1;$i>=0;$i--){
        $d=substr(date('Y-m-d',strtotime("-$i days")),5);
        $cum += $shopMap[$d] ?? 0;
        $shopTrend[]=$cum;
    }
    json_out(['code'=>1,'dates'=>$dates,'orders'=>$cnts,'revenue'=>$totals,'shops'=>$shopTrend]);
}

if ($action === 'shops') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $size = 15;
    $total = db()->query("SELECT COUNT(*) FROM shops")->fetchColumn();
    $offset = ($page-1)*$size;
    $list = db()->query("SELECT s.id, s.name, s.phone, s.expire_date, s.status, s.created_at,
        (SELECT COUNT(*) FROM users u WHERE u.shop_id=s.id) as user_cnt,
        (SELECT COUNT(*) FROM cars c WHERE c.shop_id=s.id) as car_cnt,
        (SELECT u.last_ip FROM users u WHERE u.shop_id=s.id AND u.role='boss' LIMIT 1) as last_ip,
        (SELECT u.last_active FROM users u WHERE u.shop_id=s.id AND u.role='boss' LIMIT 1) as last_active
        FROM shops s ORDER BY s.id DESC LIMIT $size OFFSET $offset")->fetchAll();
    foreach($list as &$r){
      $r['today_income']=0;$r['total_income']=0;$r['debt']=0;
    }
    json_out(['code'=>1,'list'=>$list,'total'=>(int)$total,'page'=>$page,'pages'=>ceil($total/$size)]);
}

if ($action === 'shop_get') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT * FROM shops WHERE id=?");
    $stmt->execute([$id]);
    json_out(['code'=>1,'info'=>$stmt->fetch()]);
}

if ($action === 'shop_status') {
    $id = (int)$_POST['id'];
    $status = (int)$_POST['status'] ? 1 : 0;
    $expire = $_POST['expire_date'] ?? null;
    if ($expire && !DateTime::createFromFormat('Y-m-d', $expire)) $expire = null;
    db()->prepare("UPDATE shops SET status=?, expire_date=? WHERE id=?")->execute([$status, $expire, $id]);
    // 停用店铺时，清空活跃时间（不改token，让前端能收到403提示）
    if($status==0){
      db()->prepare("UPDATE users SET last_active=NULL WHERE shop_id=?")->execute([$id]);
    }
    json_out(['code'=>1]);
}

// 编辑店铺/老板资料
if ($action === 'shop_edit') {
    $id = (int)$_POST['id'];
    $name = clean_str($_POST['name'] ?? '', 50);
    $phone = clean_phone($_POST['phone'] ?? '');
    if ($name) db()->prepare("UPDATE shops SET name=? WHERE id=?")->execute([$name, $id]);
    if ($phone) db()->prepare("UPDATE users SET phone=? WHERE shop_id=? AND role='boss'")->execute([$phone, $id]);
    json_out(['code'=>1]);
}

// 读取/设置默认套餐
if ($action === 'get_plan') {
    $plan = db()->query("SELECT v FROM settings WHERE k='default_plan'")->fetchColumn() ?: 'free';
    json_out(['code'=>1,'plan'=>$plan]);
}
if ($action === 'set_plan') {
    $plan = $_POST['plan'] ?? 'free';
    $allowed = ['free','trial7','trial30','none'];
    if (!in_array($plan, $allowed)) $plan = 'free';
    db()->prepare("REPLACE INTO settings (k,v) VALUES ('default_plan',?)")->execute([$plan]);
    json_out(['code'=>1]);
}

// 验证token
if ($action === 'verify') {
    json_out(['code'=>1]);
}
// 超管登录验证
if ($action === 'login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lockKey = 'admin_login_lock_' . md5($ip);
    $lockUntil = db()->query("SELECT v FROM settings WHERE k=".db()->quote($lockKey))->fetchColumn();
    if ($lockUntil && $lockUntil > time()) {
        $wait = ceil(($lockUntil - time()) / 60);
        json_out(['code'=>0,'msg'=>'失败次数过多，请'.$wait.'分钟后再试']);
    }
    $username = trim($_POST['username'] ?? '');
    if ($username !== 'admin') json_out(['code'=>0,'msg'=>'账号错误']);
    $pwd = $_POST['pwd'] ?? '';
    $saved = db()->query("SELECT v FROM settings WHERE k='admin_pwd'")->fetchColumn();
    if (!$saved) {
        $saved = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = db()->prepare("REPLACE INTO settings (k,v) VALUES ('admin_pwd',?)");
        $stmt->execute([$saved]);
    }
    if (password_verify($pwd, $saved)) {
        $stmt = db()->prepare("DELETE FROM settings WHERE k=?");
        $stmt->execute([$lockKey]);
        $sessionToken = bin2hex(random_bytes(32));
        $stmt = db()->prepare("REPLACE INTO settings (k,v) VALUES ('admin_token',?)");
        $stmt->execute([$sessionToken]);
        json_out(['code'=>1,'token'=>$sessionToken]);
    }
    // 记录失败次数
    $failKey = 'admin_login_fail_' . md5($ip);
    $fails = (int)db()->query("SELECT v FROM settings WHERE k=".db()->quote($failKey))->fetchColumn();
    $fails++;
    $stmt = db()->prepare("REPLACE INTO settings (k,v) VALUES (?,?)");
    $stmt->execute([$failKey, $fails]);
    if ($fails >= 5) {
        $stmt = db()->prepare("REPLACE INTO settings (k,v) VALUES (?,?)");
        $stmt->execute([$lockKey, time()+300]);
        $stmt = db()->prepare("DELETE FROM settings WHERE k=?");
        $stmt->execute([$failKey]);
        json_out(['code'=>0,'msg'=>'失败5次，锁定5分钟']);
    }
    json_out(['code'=>0,'msg'=>'密码错误，还有'.(5-$fails).'次机会']);
}
// 修改超管密码
if ($action === 'change_pwd') {
    $pwd = $_POST['pwd'] ?? '';
    if (strlen($pwd) < 4) json_out(['code'=>0,'msg'=>'密码至少4位']);
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    db()->prepare("REPLACE INTO settings (k,v) VALUES ('admin_pwd',?)")->execute([$hash]);
    json_out(['code'=>1]);
}
// 百度API配置
if ($action === 'set_baidu') {
    $key = trim($_POST['key'] ?? '');
    $secret = trim($_POST['secret'] ?? '');
    db()->prepare("REPLACE INTO settings (k,v) VALUES ('baidu_api_key',?)")->execute([$key]);
    db()->prepare("REPLACE INTO settings (k,v) VALUES ('baidu_secret_key',?)")->execute([$secret]);
    json_out(['code'=>1]);
}
if ($action === 'get_baidu') {
    json_out(['code'=>1,
        'key'=>db()->query("SELECT v FROM settings WHERE k='baidu_api_key'")->fetchColumn(),
        'secret'=>db()->query("SELECT v FROM settings WHERE k='baidu_secret_key'")->fetchColumn()]);
}

// 给店铺续费/延期
if ($action === 'shop_extend') {
    $id = (int)$_POST['id'];
    $days = (int)$_POST['days'];
    if ($days < 1) json_out(['code'=>0,'msg'=>'天数不对']);
    // 从当前到期日往后加，没到期就从今天加
    $cur = db()->query("SELECT expire_date FROM shops WHERE id=".$id)->fetchColumn();
    $base = ($cur && $cur > date('Y-m-d')) ? $cur : date('Y-m-d');
    $new = date('Y-m-d', strtotime("+$days days", strtotime($base)));
    db()->prepare("UPDATE shops SET expire_date=? WHERE id=?")->execute([$new, $id]);
    json_out(['code'=>1,'new_expire'=>$new]);
}

// 查看某店所有工单记录
if ($action === 'shop_records') {
    $sid = (int)$_GET['id'];
    $rows = db()->query("SELECT s.service_date, s.items, s.amount, s.is_credit, s.staff_name, s.photos, c.plate, c.owner_name
        FROM services s JOIN cars c ON s.car_id=c.id
        WHERE s.shop_id=$sid ORDER BY s.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    json_out(['code'=>1,'list'=>$rows]);
}

if ($action === 'shop_cars') {
    $sid = (int)$_GET['id'];
    $rows = db()->query("SELECT plate, owner_name, created_at FROM cars WHERE shop_id=$sid ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    json_out(['code'=>1,'list'=>$rows]);
}

// 查看某店徒弟账号
if ($action === 'shop_staff') {
    $sid = (int)$_GET['id'];
    $list = db()->query("SELECT id,phone,name,role,created_at FROM users WHERE shop_id=$sid ORDER BY role DESC, id")->fetchAll();
    json_out(['code'=>1,'list'=>$list]);
}
// 删除徒弟（不能删老板）
if ($action === 'staff_del') {
    $id = (int)$_POST['id'];
    db()->prepare("DELETE FROM users WHERE id=? AND role='staff'")->execute([$id]);
    json_out(['code'=>1]);
}
// 重置密码（老板和徒弟都能，自定义密码）
if ($action === 'staff_reset_pwd') {
    $id = (int)$_POST['id'];
    $newPwd = trim($_POST['password'] ?? '123456');
    if (strlen($newPwd) < 6) json_out(['code'=>0,'msg'=>'密码至少6位']);
    $pwd = password_hash($newPwd, PASSWORD_DEFAULT);
    db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$pwd, $id]);
    json_out(['code'=>1,'msg'=>'密码已修改']);
}

if ($action === 'send_announcement') {
    $type = $_POST['type'] === 'popup' ? 'popup' : 'scroll';
    $content = clean_str($_POST['content'] ?? '', 1000);
    $days = max(1, min(90, (int)$_POST['days']));
    $expire = date('Y-m-d', strtotime("+$days days"));
    db()->prepare("INSERT INTO announcements (type,content,expire_date) VALUES (?,?,?)")->execute([$type,$content,$expire]);
    json_out(['code'=>1]);
}

// 轮播图管理
if ($action === 'banners') {
    $list = db()->query("SELECT * FROM banners ORDER BY sort_order ASC, id ASC")->fetchAll();
    json_out(['code'=>1,'list'=>$list]);
}
if ($action === 'banner_add') {
    $img = clean_str($_POST['image_url'] ?? '', 255);
    $link = clean_str($_POST['link_url'] ?? '', 255);
    $sort = (int)($_POST['sort_order'] ?? 0);
    db()->prepare("INSERT INTO banners (image_url,link_url,sort_order) VALUES (?,?,?)")->execute([$img,$link,$sort]);
    json_out(['code'=>1]);
}
if ($action === 'banner_del') {
    $id = (int)$_POST['id'];
    db()->prepare("DELETE FROM banners WHERE id=?")->execute([$id]);
    json_out(['code'=>1]);
}
if ($action === 'banner_toggle') {
    $id = (int)$_POST['id'];
    db()->prepare("UPDATE banners SET status=1-status WHERE id=?")->execute([$id]);
    json_out(['code'=>1]);
}
