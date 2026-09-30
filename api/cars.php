<?php
require_once '../config.php';
$u = auth();
$sid = (int)$u['shop_id'];
$action = $_GET['a'] ?? '';

// 搜索车辆
if ($action === 'search') {
    $kw = clean_str($_GET['kw'] ?? '', 30);
    $kw = str_replace(' ', '', $kw);
    if (!$kw) json_out(['code'=>1,'list'=>[]]);
    $like = "%".mb_strtoupper($kw,'UTF-8')."%";
    $stmt = db()->prepare('SELECT id, plate, owner_name, car_info, tags FROM cars WHERE shop_id=? AND (UPPER(plate) LIKE ? OR owner_name LIKE ?) ORDER BY id DESC LIMIT 20');
    $stmt->execute([$sid, $like, $like]);
    json_out(['code'=>1,'list'=>$stmt->fetchAll()]);
}

// 新建车辆
if ($action === 'create') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $plate = strtoupper(clean_str($input['plate'] ?? '', 20));
    if (!$plate) json_out(['code'=>0,'msg'=>'车牌必填']);
    // 检查是否已存在
    $chk = db()->prepare('SELECT id FROM cars WHERE shop_id=? AND plate=?');
    $chk->execute([$sid, $plate]);
    if ($chk->fetch()) json_out(['code'=>0,'msg'=>'该车已存在']);
    
    $stmt = db()->prepare('INSERT INTO cars (shop_id, plate, owner_name, phone, car_info, created_by) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $sid, $plate,
        clean_str($_POST['owner_name'] ?? '', 50),
        clean_phone($_POST['phone'] ?? ''),
        clean_str($_POST['car_info'] ?? '', 100),
        $u['id']
    ]);
    json_out(['code'=>1,'id'=>db()->lastInsertId()]);
}

// 车辆详情
if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT c.*, u.name as created_by_name FROM cars c LEFT JOIN users u ON c.created_by=u.id WHERE c.id=? AND c.shop_id=?');
    $stmt->execute([$id, $sid]);
    $car = $stmt->fetch();
    if (!$car) json_out(['code'=>0,'msg'=>'车辆不存在']);
    $car['can_edit'] = ($u['role']==='boss' || (int)$car['created_by']===(int)$u['id']) ? 1 : 0;
    
    $stmt = db()->prepare('SELECT * FROM services WHERE car_id=? ORDER BY service_date DESC, id DESC');
    $stmt->execute([$id]);
    $car['records'] = $stmt->fetchAll();
    
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) as debt FROM services WHERE car_id=? AND is_credit=1 AND credit_settled=0');
    $stmt->execute([$id]);
    $car['debt'] = $u['role']=='staff' ? '***' : (float)$stmt->fetch()['debt'];
    json_out(['code'=>1,'car'=>$car]);
}

// 更新车辆
if ($action === 'update') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['id'] ?? 0);
    // 徒弟只能改自己创建的车
    if ($u['role'] == 'staff') {
        $chk = db()->prepare('SELECT created_by FROM cars WHERE id=? AND shop_id=?');
        $chk->execute([$id, $sid]);
        $creator = (int)$chk->fetchColumn();
        if ($creator !== (int)$u['id']) json_out(['code'=>0,'msg'=>'你无权修改别人添加的车辆']);
    }
    $stmt = db()->prepare('UPDATE cars SET plate=?, owner_name=?, phone=?, car_info=?, tags=? WHERE id=? AND shop_id=?');
    $stmt->execute([
        strtoupper(clean_str($input['plate'] ?? '', 20)),
        clean_str($input['owner_name'] ?? '', 50),
        clean_phone($input['phone'] ?? ''),
        clean_str($input['car_info'] ?? '', 100),
        clean_str($input['tags'] ?? '', 200),
        $id, $sid
    ]);
    json_out(['code'=>1]);
}

// 车辆列表
if ($action === 'list') {
    $stmt = db()->prepare('SELECT c.*, u.name as created_by_name, (SELECT MAX(service_date) FROM services s WHERE s.car_id=c.id) as last_date FROM cars c LEFT JOIN users u ON c.created_by=u.id WHERE c.shop_id=? ORDER BY last_date DESC');
    $stmt->execute([$sid]);
    json_out(['code'=>1,'list'=>$stmt->fetchAll()]);
}

// 删除车辆
if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['id'] ?? 0);
    // 徒弟只能删自己创建的车
    if ($u['role'] == 'staff') {
        $chk = db()->prepare('SELECT created_by FROM cars WHERE id=? AND shop_id=?');
        $chk->execute([$id, $sid]);
        $creator = (int)$chk->fetchColumn();
        if ($creator !== (int)$u['id']) json_out(['code'=>0,'msg'=>'你无权删除别人添加的车辆']);
    }
    db()->prepare('DELETE FROM services WHERE car_id=?')->execute([$id]);
    db()->prepare('DELETE FROM cars WHERE id=? AND shop_id=?')->execute([$id, $sid]);
    json_out(['code'=>1]);
}
