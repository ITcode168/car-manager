<?php
require_once '../config.php';
$u = auth();
$sid = (int)$u['shop_id'];
$action = $_GET['a'] ?? '';

if ($action === 'list') {
    $stmt = db()->prepare('SELECT i.id, i.name, i.price, i.created_by, u.name as created_by_name FROM items i LEFT JOIN users u ON i.created_by=u.id WHERE i.shop_id=? ORDER BY i.sort_order, i.id');
    $stmt->execute([$sid]);
    $list = $stmt->fetchAll();
    foreach($list as &$row) {
        $row['can_edit'] = ($u['role']==='boss' || (int)$row['created_by']===(int)$u['id']) ? 1 : 0;
    }
    json_out(['code'=>1,'list'=>$list]);
}
if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $name = clean_str($_POST['name'] ?? '', 50);
    $price = max(0, (float)($_POST['price'] ?? 0));
    if (!$name) json_out(['code'=>0,'msg'=>'项目名不能为空']);
    if ($id) {
        // 徒弟只能改自己加的项目
        if ($u['role'] == 'staff') {
            $chk = db()->prepare('SELECT created_by FROM items WHERE id=? AND shop_id=?');
            $chk->execute([$id,$sid]);
            $creator = (int)$chk->fetchColumn();
            if ($creator !== (int)$u['id']) json_out(['code'=>0,'msg'=>'只能修改自己添加的项目']);
        }
        db()->prepare('UPDATE items SET name=?, price=? WHERE id=? AND shop_id=?')->execute([$name,$price,$id,$sid]);
    } else {
        db()->prepare('INSERT INTO items (shop_id,name,price,created_by) VALUES (?,?,?,?)')->execute([$sid,$name,$price,$u['id']]);
    }
    json_out(['code'=>1]);
}
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    // 徒弟只能删自己加的项目
    if ($u['role'] == 'staff') {
        $chk = db()->prepare('SELECT created_by FROM items WHERE id=? AND shop_id=?');
        $chk->execute([$id,$sid]);
        $creator = (int)$chk->fetchColumn();
        if ($creator !== (int)$u['id']) json_out(['code'=>0,'msg'=>'只能删除自己添加的项目']);
    }
    db()->prepare('DELETE FROM items WHERE id=? AND shop_id=?')->execute([$id,$sid]);
    json_out(['code'=>1]);
}
