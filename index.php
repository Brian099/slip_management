<?php
// 首先检查配置文件是否存在
$config_file = __DIR__ . '/config/database.php';
if (!file_exists($config_file)) {
    // 如果配置文件不存在，跳转到初始化页面
    header('Location: init.php');
    exit;
}

// 配置文件存在，继续包含
require_once 'config/database.php';

// 检查是否定义了必要的常量
if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_NAME')) {
    // 配置文件不完整，也跳转到初始化页面
    header('Location: init.php');
    exit;
}

// 尝试建立数据库连接以验证配置
try {
    $conn = getDbConnection();
    
    // 检查连接是否成功
    if ($conn->connect_error) {
        // 连接失败，跳转到初始化页面
        header('Location: init.php?error=db_connection_failed');
        exit;
    }
    
    // 检查必要的表是否存在
    $result = $conn->query("SHOW TABLES LIKE 'users'");
    if (!$result || $result->num_rows == 0) {
        // 表不存在，系统未完全初始化
        header('Location: init.php?error=not_fully_initialized');
        exit;
    }
    
    // 所有检查通过，继续验证用户登录状态
    require_once 'includes/auth.php';
    require_once 'includes/functions.php';
    
    if (isLoggedIn()) {
        header('Location: dashboard.php');
        exit;
    } else {
        header('Location: login.php');
        exit;
    }
    
} catch (Exception $e) {
    // 发生异常，跳转到初始化页面
    error_log("Database connection error: " . $e->getMessage());
    header('Location: init.php?error=exception');
    exit;
}