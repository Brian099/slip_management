<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

// 记录退出日志
if (isset($_SESSION['user_id'])) {
    $conn = getDbConnection();
    $user_id = $_SESSION['user_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $logout_time = date('Y-m-d H:i:s');
    
    // 可以在这里添加退出日志记录
    // $conn->query("INSERT INTO login_logs (user_id, ip_address, logout_time) VALUES ($user_id, '$ip_address', '$logout_time')");
}

// 清除所有会话变量
$_SESSION = array();

// 删除会话cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 销毁会话
session_destroy();

// 重定向到登录页面
header('Location: login.php?message=' . urlencode('您已成功退出登录'));
exit;
?>