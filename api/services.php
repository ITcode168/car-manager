<?php
require_once '../config.php';
$u = auth();
$sid = $u['shop_id'];
$isStaff = $u['role'] == 'staff';
$action = $_GET['a'] ?? '';

// 记一笔
if ($action === 'create') {
    $carId = (int)($_POST['car_id'] ?? 0);
    if (!$carId) json_out(['code'=>0,'msg'=>'车辆不能为空']);
    // 验证日期格式
    $serviceDate = $_POST['service_date'] ?? date('Y-m-d');
    $dt = DateTime::createFromFormat('Y-m-d', $serviceDate);
    if (!$dt) $serviceDate = date('Y-m-d');
    $items = clean_str($_POST['items'] ?? '', 500);
    $amount = (float)($_POST['amount'] ?? 0);
    $isCredit = (int)($_POST['is_credit'] ?? 0) === 1 ? 1 : 0;
    $nextMonths = max(0, (int)($_POST['next_months'] ?? 0));
    $nextKm = max(0, (int)($_POST['next_km'] ?? 0));
    $mileage = max(0, (int)($_POST['mileage'] ?? 0));
    if (mb_strlen($items) > 500) $items = mb_substr($items, 0, 500);
    
    $nextDate = null;
    if ($nextMonths > 0) {
        $nextDate = date('Y-m-d', strtotime("+$nextMonths months", strtotime($serviceDate)));
    }
    
    $photos = $_POST['photos'] ?? [];
    if (!is_array($photos)) $photos = [];
    // 只允许uploads/开头的路径，防路径穿越
    $photos = array_filter($photos, function($p){
        return is_string($p) && (strpos($p, 'uploads/') === 0 || strpos($p, '/uploads/') === 0) && strpos($p, '..') === false;
    });
    $photos = array_map(function($p){ return substr($p, 0, 255); }, $photos);
    $photosJson = implode(',', $photos);
    $stmt = db()->prepare('INSERT INTO services (shop_id, car_id, service_date, mileage, items, amount, is_credit, next_remind_date, next_remind_km, staff_name, photos) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$sid, $carId, $serviceDate, $mileage, $items, $amount, $isCredit, $nextDate, $nextKm, $u['name'], $photosJson]);
    json_out(['code'=>1,'id'=>db()->lastInsertId()]);
}

// 紧急救援
if ($action === 'rescue') {
    $plate = clean_str($_POST['plate'] ?? '', 20);
    $plate = strtoupper(str_replace(' ','',$plate));
    if (!$plate) json_out(['code'=>0,'msg'=>'车牌不能为空']);
    // 找车，没有就建
    $stmt = db()->prepare("SELECT id FROM cars WHERE shop_id=? AND plate=?");
    $stmt->execute([$sid, $plate]);
    $carId = $stmt->fetchColumn();
    if (!$carId) {
        $stmt = db()->prepare("INSERT INTO cars (shop_id, plate, owner_name, phone, created_by) VALUES (?,?,?,?,?)");
        $stmt->execute([$sid, $plate, clean_str($_POST['owner_name']??'',20), clean_phone($_POST['phone']??''), $u['id']]);
        $carId = db()->lastInsertId();
    }
    $type = clean_str($_POST['rescue_type'] ?? '', 20);
    $location = clean_str($_POST['location'] ?? '', 100);
    $note = clean_str($_POST['note'] ?? '', 500);
    $amount = (float)($_POST['amount'] ?? 0);
    $isCredit = !empty($_POST['is_credit']) ? 1 : 0;
    $items = '救援-'.$type.($location?'（地点：'.$location.'）':'');
    $photos = $_POST['photos'] ?? [];
    if (!is_array($photos)) $photos = [];
    $photos = array_filter($photos, function($p){
        return is_string($p) && (strpos($p, 'uploads/') === 0 || strpos($p, '/uploads/') === 0) && strpos($p, '..') === false;
    });
    $photos = array_map(function($p){ return substr($p, 0, 255); }, $photos);
    $photosJson = implode(',', $photos);
    $stmt = db()->prepare("INSERT INTO services (shop_id, car_id, service_date, items, amount, is_credit, rescue_type, rescue_note, staff_name, photos) VALUES (?,?,CURDATE(),?,?,?,?,?,?,?)");
    $stmt->execute([$sid, $carId, $items, $amount, $isCredit, $type, $note, $u['name'], $photosJson]);
    json_out(['code'=>1,'id'=>db()->lastInsertId()]);
}

// 记录列表
if ($action === 'list') {
    $range = $_GET['range'] ?? 'today';
    // 白名单过滤，防注入
    $allowed = ['today','week','month','all','credit'];
    if (!in_array($range, $allowed)) $range = 'today';
    $where = "s.shop_id = " . (int)$sid;
    if ($range === 'today') $where .= " AND s.service_date = CURDATE()";
    elseif ($range === 'week') $where .= " AND s.service_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    elseif ($range === 'month') $where .= " AND s.service_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    elseif ($range === 'credit') $where .= " AND s.is_credit=1 AND s.credit_settled=0";
    
    // 徒弟看不到金额
    $amountSelect = $isStaff ? "'***'" : "s.amount";
    $stmt = db()->query("SELECT s.*, c.plate, c.owner_name FROM services s JOIN cars c ON s.car_id=c.id WHERE $where ORDER BY s.service_date DESC, s.id DESC LIMIT 200");
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($isStaff) { foreach($list as &$r) $r['amount'] = '***'; }
    
    // 汇总
    $sum = ['cnt'=>0,'total'=>0,'credit'=>0];
    if ($range !== 'credit') {
        $s2 = db()->query("SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total, COALESCE(SUM(CASE WHEN is_credit=1 AND credit_settled=0 THEN amount ELSE 0 END),0) as credit FROM services s WHERE $where");
        $sum = $s2->fetch(PDO::FETCH_ASSOC);
    } else {
        $s2 = db()->query("SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM services s WHERE $where");
        $r = $s2->fetch(PDO::FETCH_ASSOC);
        $sum['cnt']=$r['cnt']; $sum['total']=$r['total']; $sum['credit']=$r['total'];
    }
    if ($isStaff) { $sum['total']='***'; $sum['credit']='***'; }
    json_out(['code'=>1,'list'=>$list,'sum'=>$sum]);
}

// 百度OCR车牌识别
if ($action === 'ocr') {
    $apiKey = db()->query("SELECT v FROM settings WHERE k='baidu_api_key'")->fetchColumn();
    $secret = db()->query("SELECT v FROM settings WHERE k='baidu_secret'")->fetchColumn();
    if (!$apiKey || !$secret) json_out(['code'=>0,'msg'=>'未配置识别功能']);
    if (!isset($_FILES['file'])) json_out(['code'=>0,'msg'=>'没有图片']);
    $img = base64_encode(file_get_contents($_FILES['file']['tmp_name']));
    // 获取token
    $ch = curl_init("https://aip.baidubce.com/oauth/2.0/token?grant_type=client_credentials&client_id=$apiKey&client_secret=$secret");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $tok = json_decode(curl_exec($ch), true);
    $token = $tok['access_token'] ?? '';
    if (!$token) json_out(['code'=>0,'msg'=>'识别服务暂不可用']);
    // 车牌识别
    $ch = curl_init("https://aip.baidubce.com/rest/2.0/ocr/v1/license_plate?access_token=$token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['image'=>$img]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/x-www-form-urlencoded']);
    $res = json_decode(curl_exec($ch), true);
    $plate = $res['words_result']['number'] ?? '';
    if ($plate) json_out(['code'=>1,'plate'=>$plate]);
    else json_out(['code'=>0,'msg'=>'没识别到车牌，请手动输入']);
}

// 标记赊账已结清
if ($action === 'settle') {
    $id = (int)($_POST['id'] ?? 0);
    // 徒弟只能结清自己记的单
    if ($isStaff) {
        $stmt = db()->prepare('SELECT staff_name FROM services WHERE id=? AND shop_id=?');
        $stmt->execute([$id, $sid]);
        $owner = $stmt->fetchColumn();
        if ($owner !== $u['name']) json_out(['code'=>0,'msg'=>'只能结清自己记的单']);
    }
    db()->prepare('UPDATE services SET credit_settled=1 WHERE id=? AND shop_id=?')->execute([$id, $sid]);
    json_out(['code'=>1]);
}

// 编辑（徒弟只能改自己记的记录，不能改金额）
if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($isStaff) {
        $stmt = db()->prepare('SELECT staff_name FROM services WHERE id=? AND shop_id=?');
        $stmt->execute([$id, $sid]);
        $owner = $stmt->fetchColumn();
        if ($owner !== $u['name']) json_out(['code'=>0,'msg'=>'只能修改自己记的记录']);
        // 徒弟只能改服务内容，不能改金额
        db()->prepare('UPDATE services SET items=? WHERE id=? AND shop_id=?')->execute([clean_str($_POST['items']??'',500), $id, $sid]);
    } else {
        db()->prepare('UPDATE services SET items=?, amount=? WHERE id=? AND shop_id=?')->execute([clean_str($_POST['items']??'',500), (float)$_POST['amount'], $id, $sid]);
    }
    json_out(['code'=>1]);
}
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($isStaff) {
        $stmt = db()->prepare('SELECT staff_name FROM services WHERE id=? AND shop_id=?');
        $stmt->execute([$id, $sid]);
        $owner = $stmt->fetchColumn();
        if ($owner !== $u['name']) json_out(['code'=>0,'msg'=>'只能删除自己记的记录']);
    }
    db()->prepare('DELETE FROM services WHERE id=? AND shop_id=?')->execute([$id, $sid]);
    json_out(['code'=>1]);
}

// 今日待提醒
if ($action === 'remind_today') {
    $stmt = db()->prepare("
        SELECT s.id, s.car_id, s.service_date, s.next_remind_date, s.next_remind_km, s.mileage,
               c.plate, c.owner_name
        FROM services s JOIN cars c ON s.car_id=c.id
        WHERE s.shop_id=? AND s.remind_done=0
        AND (s.next_remind_date IS NOT NULL AND s.next_remind_date <= CURDATE())
        ORDER BY s.next_remind_date ASC
    ");
    $stmt->execute([$sid]);
    json_out(['code'=>1,'list'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// 标记提醒已处理
if ($action === 'remind_done') {
    $id = (int)($_POST['id'] ?? 0);
    $newDate = trim($_POST['new_date'] ?? '');
    if ($newDate) {
        db()->prepare('UPDATE services SET next_remind_date=?, remind_done=0 WHERE id=? AND shop_id=?')->execute([$newDate, $id, $sid]);
    } else {
        db()->prepare('UPDATE services SET remind_done=1 WHERE id=? AND shop_id=?')->execute([$id, $sid]);
    }
    json_out(['code'=>1]);
}

// 今日待收款
if ($action === 'debt') {
    if ($isStaff) json_out(['code'=>1,'list'=>[]]);
    $stmt = db()->prepare("
        SELECT s.id, s.amount, s.items, s.service_date, c.plate, c.owner_name
        FROM services s JOIN cars c ON s.car_id=c.id
        WHERE s.shop_id=? AND s.is_credit=1 AND s.credit_settled=0
        ORDER BY s.service_date DESC
    ");
    $stmt->execute([$sid]);
    json_out(['code'=>1,'list'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
}
