<?php

declare(strict_types=1);

use App\Support\Config;

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('<h1>ยังไม่ได้ติดตั้งไลบรารีของระบบ</h1><p>เปิด Command Prompt ที่โฟลเดอร์ของระบบแล้วสั่ง <code>composer install</code> จากนั้นเปิด <a href="install.php">install.php</a> เพื่อติดตั้ง</p>');
}

require $root . '/vendor/autoload.php';

if (!Config::isInstalled()) {
    header('Location: install.php');
    exit;
}

session_name('RVCSESSID');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
]);
ini_set('session.use_strict_mode', '1');
session_start();

/** @var Slim\App $app */
$app = require $root . '/app/bootstrap.php';
$app->run();
