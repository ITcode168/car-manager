<?php
require_once '../config.php';
$u = auth();
$sid = (int)$u['shop_id'];
$isStaff = $u['role'] == 'staff';

// 徒弟只统计自己的单数，老板统计全店
$ws = $isStaff ? " AND staff_name=" . db()->quote($u['name']) : "";

// 30天单数
$stmt = db()->prepare("SELECT COUNT(*) FROM services WHERE shop_id=? AND service_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)$ws");
$stmt->execute([$sid]);
$cnt = $stmt->fetchColumn();

// 今日单数
$stmt = db()->prepare("SELECT COUNT(*) FROM services WHERE shop_id=? AND service_date=CURDATE()$ws");
$stmt->execute([$sid]);
$todayCnt = $stmt->fetchColumn();

// 金额：徒弟不显示
if ($isStaff) {
    $total = '***';
    $todayTotal = '***';
    $credit = '***';
} else {
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM services WHERE shop_id=? AND service_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
    $stmt->execute([$sid]);
    $total = $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM services WHERE shop_id=? AND service_date=CURDATE()");
    $stmt->execute([$sid]);
    $todayTotal = $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM services WHERE shop_id=? AND is_credit=1 AND credit_settled=0");
    $stmt->execute([$sid]);
    $credit = $stmt->fetchColumn();
}

json_out(['code'=>1,'monthly'=>['cnt'=>$cnt,'total'=>$total,'credit'=>$credit],'today'=>['cnt'=>$todayCnt,'total'=>$todayTotal]]);
