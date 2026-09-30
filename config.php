<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('html_errors', 0);
set_error_handler(function(){return true;});
register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code'=>0,'msg'=>'服务器错误']);
        exit;
    }
});

define('DB_HOST', '127.0.0.1');
define('DB_NAME', '数据库');
define('DB_USER', '数据库');
define('DB_PASS', '数据库');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$rawInput = file_get_contents('php://input');
if ($rawInput) {
    $data = json_decode($rawInput, true);
    if (is_array($data)) $_POST = array_merge($_POST, $data);
}

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function json_out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function clean_str($s, $max = 255) {
    $s = trim((string)$s);
    $s = strip_tags($s);
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

function clean_phone($p) {
    $p = preg_replace('/\D/', '', $p);
    if (strlen($p) > 11) $p = substr($p, 0, 11);
    return $p;
}

function auth() {
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    if (!$token || strlen($token) < 10) json_out(['code'=>401,'msg'=>'未登录']);
    $stmt = db()->prepare('SELECT u.*, s.name as shop_name, s.status as shop_status, s.expire_date FROM users u JOIN shops s ON u.shop_id=s.id WHERE u.token=?');
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) json_out(['code'=>401,'msg'=>'登录失效']);
    if (!empty($user['token']) && !empty($user['last_active'])) {
        if (strtotime($user['last_active']) < time() - 2592000) {
            db()->prepare('UPDATE users SET token=NULL WHERE id=?')->execute([$user['id']]);
            json_out(['code'=>401,'msg'=>'登录已过期']);
        }
    }
    if ($user['shop_status']==0) json_out(['code'=>403,'msg'=>'店铺已停用']);
    if (isset($user['disabled']) && $user['disabled']==1) json_out(['code'=>403,'msg'=>'账号已被停用']);
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = trim(explode(',', $ip)[0]);
        db()->prepare('UPDATE users SET last_active=NOW(), last_ip=? WHERE id=?')->execute([$ip, $user['id']]);
    } catch(Exception $e) {}
    if ($user['expire_date'] && $user['expire_date'] < date('Y-m-d')) {
        json_out(['code'=>402,'msg'=>'店铺已到期']);
    }
    return $user;
}

function is_boss() {
    $u = auth();
    if ($u['role'] !== 'boss') json_out(['code'=>403,'msg'=>'仅老板可用']);
    return $u;
}

function is_admin() {
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $saved = db()->query("SELECT v FROM settings WHERE k='admin_token'")->fetchColumn();
    if (!$saved) {
        $saved = bin2hex(random_bytes(16));
        db()->prepare("REPLACE INTO settings (k,v) VALUES ('admin_token',?)")->execute([$saved]);
    }
    if (!hash_equals($saved, $token)) {
        json_out(['code'=>401,'msg'=>'无权限']);
    }
    return true;
}
