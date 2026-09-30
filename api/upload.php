<?php
require_once '../config.php';
$action = $_POST['a'] ?? '';

// 超管后台上传（轮播图），用admin_token验证
$isAdminUpload = isset($_GET['admin']);
if ($isAdminUpload) {
    is_admin(); // 超管验证，不通过会直接退出
} else {
    auth();
}

// 上传图片，返回URL
if ($action === 'upload') {
    if (!isset($_FILES['photo'])) json_out(['code'=>0,'msg'=>'没有文件']);
    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) json_out(['code'=>0,'msg'=>'上传失败']);
    if ($file['size'] > 5*1024*1024) json_out(['code'=>0,'msg'=>'图片不能超过5M']);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) json_out(['code'=>0,'msg'=>'只支持jpg/png']);
    // 验证确实是图片，防伪装
    $imgInfo = @getimagesize($file['tmp_name']);
    if (!$imgInfo) json_out(['code'=>0,'msg'=>'文件不是有效图片']);
    $dir = __DIR__.'/../uploads/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = date('Ymd').'_'.bin2hex(random_bytes(8)).'.'.$ext;
    move_uploaded_file($file['tmp_name'], $dir.$name);
    json_out(['code'=>1,'url'=>'/uploads/'.$name]);
}

// 车牌识别
if ($action === 'recognize_plate') {
    if (!isset($_FILES['photo'])) json_out(['code'=>0,'msg'=>'没有文件']);
    // 读超管配置
    $key = db()->query("SELECT v FROM settings WHERE k='baidu_api_key'")->fetchColumn();
    $secret = db()->query("SELECT v FROM settings WHERE k='baidu_secret_key'")->fetchColumn();
    if (!$key || !$secret) json_out(['code'=>0,'msg'=>'未配置百度API，请手动输入车牌']);
    // 取token
    $ch = curl_init("https://aip.baidubce.com/oauth/2.0/token?grant_type=client_credentials&client_id=$key&client_secret=$secret");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $tokenRes = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $accessToken = $tokenRes['access_token'] ?? '';
    if (!$accessToken) json_out(['code'=>0,'msg'=>'百度API鉴权失败，请手动输入']);
    // 调车牌识别
    $img = base64_encode(file_get_contents($_FILES['photo']['tmp_name']));
    $ch = curl_init("https://aip.baidubce.com/rest/2.0/ocr/v1/license_plate?access_token=$accessToken");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['image'=>$img]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (!empty($res['words_result']['number'])) {
        json_out(['code'=>1,'plate'=>$res['words_result']['number'],'color'=>$res['words_result']['color']??'']);
    }
    json_out(['code'=>0,'msg'=>'识别失败，请手动输入车牌']);
}

