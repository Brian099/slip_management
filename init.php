<?php
/**
 * 系统初始化脚本
 * 访问方式：访问 http://your-domain.com/init.php 进行初始化
 */

// 开启错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 防止重复初始化
define('INITIALIZED', false);

// 检查是否已安装
$config_file = dirname(__FILE__) . '/config/database.php';
$installed = file_exists($config_file);

if ($installed) {
    // 如果已安装，检查数据库连接
    require_once 'config/database.php';
    $conn = getDbConnection();
    
    // 检查表是否存在
    $tables_exist = false;
    $result = $conn->query("SHOW TABLES LIKE 'users'");
    if ($result->num_rows > 0) {
        $tables_exist = true;
    }
} else {
    $tables_exist = false;
}

// 处理初始化请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'install') {
        installSystem();
    } elseif ($action === 'reset') {
        resetSystem();
    }
}

/**
 * 安装系统
 */
function installSystem() {
    // 获取表单数据
    $db_host = $_POST['db_host'] ?? 'localhost';
    $db_name = $_POST['db_name'] ?? 'invoice_management';
    $db_user = $_POST['db_user'] ?? 'root';
    $db_pass = $_POST['db_pass'] ?? '';
    
    $admin_username = $_POST['admin_username'] ?? 'admin';
    $admin_password = $_POST['admin_password'] ?? 'admin123';
    $admin_email = $_POST['admin_email'] ?? 'admin@example.com';
    
    try {
        // 1. 创建配置文件
        createConfigFile($db_host, $db_name, $db_user, $db_pass);
        
        // 2. 创建数据库和表
        createDatabaseAndTables($db_host, $db_name, $db_user, $db_pass);
        
        // 3. 创建初始用户
        createInitialUser($admin_username, $admin_password, $admin_email);
        
        // 4. 创建上传目录
        createUploadDirectories();
        
        // 5. 标记为已初始化
        file_put_contents('config/installed.lock', date('Y-m-d H:i:s'));
        
        $success = "系统初始化成功！<br>";
        $success .= "管理员账号：{$admin_username} / {$admin_password}<br>";
        $success .= "<a href='login.php' class='btn'>立即登录</a>";
        
        header('Location: login.php?message=' . urlencode('系统初始化成功！'));
        exit;
        
    } catch (Exception $e) {
        $error = "初始化失败：" . $e->getMessage();
        showInstallForm($error);
    }
}

/**
 * 创建配置文件
 */
function createConfigFile($db_host, $db_name, $db_user, $db_pass) {
    $config_dir = dirname(__FILE__) . '/config';
    if (!is_dir($config_dir)) {
        mkdir($config_dir, 0755, true);
    }
    
    $config_content = "<?php
// 数据库配置
define('DB_HOST', '{$db_host}');
define('DB_USER', '{$db_user}');
define('DB_PASS', '{$db_pass}');
define('DB_NAME', '{$db_name}');
define('DB_CHARSET', 'utf8mb4');

// 网站配置
define('SITE_NAME', '发票管理系统');
define('SITE_URL', 'http://' . \$_SERVER['HTTP_HOST']);
define('UPLOAD_DIR', 'uploads/invoices/');

// 创建数据库连接
function getDbConnection() {
    static \$conn = null;
    
    if (\$conn === null) {
        \$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if (\$conn->connect_error) {
            die('数据库连接失败: ' . \$conn->connect_error);
        }
        
        \$conn->set_charset(DB_CHARSET);
    }
    
    return \$conn;
}

// 安全过滤输入
function sanitize(\$input) {
    \$conn = getDbConnection();
    return htmlspecialchars(strip_tags(\$conn->real_escape_string(trim(\$input))));
}
?>
";
    
    if (!file_put_contents($config_dir . '/database.php', $config_content)) {
        throw new Exception("无法创建配置文件，请检查目录权限");
    }
}

/**
 * 创建数据库和表
 */
function createDatabaseAndTables($db_host, $db_name, $db_user, $db_pass) {
    // 连接MySQL服务器
    $conn = new mysqli($db_host, $db_user, $db_pass);
    if ($conn->connect_error) {
        throw new Exception("数据库连接失败: " . $conn->connect_error);
    }
    
    // 创建数据库
    $sql = "CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        throw new Exception("创建数据库失败: " . $conn->error);
    }
    
    $conn->select_db($db_name);
    
    // 创建用户表
    $sql = "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `username` VARCHAR(50) UNIQUE NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('sales', 'finance') NOT NULL DEFAULT 'sales',
        `full_name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `status` ENUM('active', 'inactive') DEFAULT 'active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!$conn->query($sql)) {
        throw new Exception("创建用户表失败: " . $conn->error);
    }
    
    // 创建发票表
    $sql = "CREATE TABLE IF NOT EXISTS `invoices` (
		`id` INT PRIMARY KEY AUTO_INCREMENT,
		`invoice_number` VARCHAR(50) UNIQUE NOT NULL,
		`invoice_type` ENUM('普票', '专票') NOT NULL,
		`slip_number` VARCHAR(100) UNIQUE,
		`customer_name` VARCHAR(200) NOT NULL,
		`customer_contact` VARCHAR(200) NOT NULL,
		`tax_number` VARCHAR(50),
		`total_amount` DECIMAL(12,2) NOT NULL,
		`created_by` INT NOT NULL,
		`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		`finance_status` ENUM('pending', 'invoiced', 'incorrect') DEFAULT 'pending',
		`invoiced_at` TIMESTAMP NULL,
		`invoice_file` VARCHAR(255),
		`is_voided` BOOLEAN DEFAULT FALSE,
		`voided_at` TIMESTAMP NULL,
		`void_reason` TEXT,
		`voided_by` INT NULL,
		`remarks` TEXT,
		FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
		FOREIGN KEY (`voided_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!$conn->query($sql)) {
        throw new Exception("创建发票表失败: " . $conn->error);
    }
    
    // 创建货物详情表
    $sql = "CREATE TABLE IF NOT EXISTS `invoice_items` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `invoice_id` INT NOT NULL,
        `item_name` VARCHAR(200) NOT NULL,
        `model` VARCHAR(100),
        `unit_price` DECIMAL(10,2) NOT NULL,
        `unit` VARCHAR(20) NOT NULL,
        `quantity` DECIMAL(10,2) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!$conn->query($sql)) {
        throw new Exception("创建货物详情表失败: " . $conn->error);
    }
	
	// 创建设置表
	$sql = "CREATE TABLE IF NOT EXISTS `system_settings` (
		`id` INT PRIMARY KEY DEFAULT 1,
		`company_name` VARCHAR(100) DEFAULT '我的公司',
		`company_address` TEXT,
		`invoice_limit_number` INT DEFAULT 2000,
		`tax_rate_zhuan` DECIMAL(5,2) DEFAULT 13.00,
		`tax_rate_pu` DECIMAL(5,2) DEFAULT 3.00,
		`webhook_url` VARCHAR(1024) DEFAULT '',
		`allow_finance_create_invoice` TINYINT(1) NOT NULL DEFAULT 0,
		`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		CONSTRAINT check_single_row CHECK (id = 1)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

	if (!$conn->query($sql)) {
		throw new Exception("创建设置表失败: " . $conn->error);
	}

    // 创建入账信息表
    $sql = "CREATE TABLE IF NOT EXISTS `income_records` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `bank` VARCHAR(100) NOT NULL,
        `company` VARCHAR(200) NOT NULL,
        `account_tail` VARCHAR(20) NOT NULL,
        `transaction_date` VARCHAR(50) NOT NULL,
        `transaction_time` VARCHAR(50) NOT NULL,
        `transaction_type` VARCHAR(100) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `currency` VARCHAR(20) NOT NULL,
        `payer` VARCHAR(200) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!$conn->query($sql)) {
        throw new Exception("创建入账信息表失败: " . $conn->error);
    }

	// 立即初始化第一行数据（一行搞定）
	$initSql = "INSERT IGNORE INTO system_settings (id) VALUES (1)";
	$conn->query($initSql);

    $conn->close();
}

/**
 * 创建初始用户
 */
function createInitialUser($username, $password, $email) {
    require_once 'config/database.php';
    $conn = getDbConnection();
    
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    $sql = "INSERT INTO `users` (`username`, `password`, `role`, `full_name`, `email`) 
            VALUES (?, ?, 'finance', '系统管理员', ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $username, $hashed_password, $email);
    
    if (!$stmt->execute()) {
        // 如果用户已存在，更新密码
        if ($conn->errno == 1062) {
            $sql = "UPDATE `users` SET `password` = ? WHERE `username` = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $hashed_password, $username);
            if (!$stmt->execute()) {
                throw new Exception("更新用户密码失败: " . $conn->error);
            }
        } else {
            throw new Exception("创建用户失败: " . $conn->error);
        }
    }
    
    // 创建示例用户
    $users = [
        ['sales1', 'sales123', 'sales', '业务员张三', 'sales1@example.com'],
        ['finance1', 'finance123', 'finance', '财务员李四', 'finance1@example.com']
    ];
    
    foreach ($users as $user) {
        $hashed_pass = password_hash($user[1], PASSWORD_DEFAULT);
        $sql = "INSERT IGNORE INTO `users` (`username`, `password`, `role`, `full_name`, `email`) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssss", $user[0], $hashed_pass, $user[2], $user[3], $user[4]);
        $stmt->execute();
    }
}

/**
 * 创建上传目录
 */
function createUploadDirectories() {
    $dirs = [
        'uploads',
        'uploads/invoices',
        'uploads/temp'
    ];
    
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception("无法创建上传目录: {$dir}");
            }
        }
        
        // 创建 .htaccess 防止直接访问
        $htaccess = "Order Deny,Allow\nDeny from all";
        file_put_contents($dir . '/.htaccess', $htaccess);
    }
    
    // 创建 index.html 防止目录列表
    foreach ($dirs as $dir) {
        file_put_contents($dir . '/index.html', '<html><body><h1>禁止访问</h1></body></html>');
    }
}

/**
 * 重置系统
 */
function resetSystem() {
    require_once 'config/database.php';
    $conn = getDbConnection();
    
    // 删除所有表
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    
    $tables = ['invoice_items', 'invoices', 'users'];
    foreach ($tables as $table) {
        $conn->query("DROP TABLE IF EXISTS `{$table}`");
    }
    
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    
    // 删除配置文件
    if (file_exists('config/database.php')) {
        unlink('config/database.php');
    }
    
    if (file_exists('config/installed.lock')) {
        unlink('config/installed.lock');
    }
    
    header('Location: init.php?message=' . urlencode('系统已重置，请重新安装'));
    exit;
}

/**
 * 显示安装表单
 */
function showInstallForm($error = null, $message = null) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>发票管理系统 - 初始化安装</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Microsoft YaHei', Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            
            .install-container {
                background: white;
                border-radius: 10px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                width: 100%;
                max-width: 800px;
                overflow: hidden;
            }
            
            .install-header {
                background: #2c3e50;
                color: white;
                padding: 30px;
                text-align: center;
            }
            
            .install-header h1 {
                font-size: 28px;
                margin-bottom: 10px;
            }
            
            .install-header p {
                color: #bdc3c7;
                font-size: 16px;
            }
            
            .install-content {
                padding: 40px;
            }
            
            .alert {
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 30px;
                border: 1px solid transparent;
            }
            
            .alert-success {
                background-color: #d4edda;
                border-color: #c3e6cb;
                color: #155724;
            }
            
            .alert-error {
                background-color: #f8d7da;
                border-color: #f5c6cb;
                color: #721c24;
            }
            
            .install-steps {
                display: flex;
                justify-content: space-between;
                margin-bottom: 30px;
                position: relative;
            }
            
            .install-steps:before {
                content: '';
                position: absolute;
                top: 15px;
                left: 0;
                right: 0;
                height: 2px;
                background: #eee;
                z-index: 1;
            }
            
            .step {
                text-align: center;
                position: relative;
                z-index: 2;
                flex: 1;
            }
            
            .step-number {
                width: 30px;
                height: 30px;
                background: #eee;
                color: #666;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 10px;
                font-weight: bold;
            }
            
            .step.active .step-number {
                background: #007bff;
                color: white;
            }
            
            .step-label {
                font-size: 14px;
                color: #666;
            }
            
            .step.active .step-label {
                color: #007bff;
                font-weight: bold;
            }
            
            .form-section {
                margin-bottom: 30px;
                padding: 25px;
                background: #f8f9fa;
                border-radius: 8px;
            }
            
            .form-section h3 {
                margin-bottom: 20px;
                color: #2c3e50;
                padding-bottom: 10px;
                border-bottom: 2px solid #e9ecef;
            }
            
            .form-group {
                margin-bottom: 20px;
            }
            
            .form-row {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #495057;
            }
            
            .form-group input {
                width: 100%;
                padding: 12px;
                border: 1px solid #ced4da;
                border-radius: 4px;
                font-size: 16px;
                transition: border-color 0.3s;
            }
            
            .form-group input:focus {
                outline: none;
                border-color: #007bff;
                box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
            }
            
            .form-help {
                font-size: 13px;
                color: #6c757d;
                margin-top: 5px;
            }
            
            .form-actions {
                display: flex;
                justify-content: center;
                gap: 20px;
                margin-top: 40px;
                padding-top: 30px;
                border-top: 1px solid #eee;
            }
            
            .btn {
                padding: 12px 40px;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                text-decoration: none;
                display: inline-block;
                text-align: center;
            }
            
            .btn-primary {
                background: #007bff;
                color: white;
            }
            
            .btn-primary:hover {
                background: #0056b3;
            }
            
            .btn-secondary {
                background: #6c757d;
                color: white;
            }
            
            .btn-secondary:hover {
                background: #545b62;
            }
            
            .btn-danger {
                background: #dc3545;
                color: white;
            }
            
            .btn-danger:hover {
                background: #bd2130;
            }
            
            .system-check {
                margin-bottom: 30px;
            }
            
            .check-item {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                border-bottom: 1px solid #eee;
            }
            
            .check-status {
                font-weight: bold;
            }
            
            .check-status.pass {
                color: #28a745;
            }
            
            .check-status.fail {
                color: #dc3545;
            }
            
            .check-status.warning {
                color: #ffc107;
            }
            
            @media (max-width: 768px) {
                .install-content {
                    padding: 20px;
                }
                
                .form-row {
                    grid-template-columns: 1fr;
                }
                
                .install-steps {
                    flex-direction: column;
                    align-items: center;
                    gap: 20px;
                }
                
                .install-steps:before {
                    display: none;
                }
                
                .step {
                    width: 100%;
                }
            }
        </style>
    </head>
    <body>
        <div class="install-container">
            <div class="install-header">
                <h1>发票信息管理系统</h1>
                <p>系统初始化安装向导</p>
            </div>
            
            <div class="install-content">
                <?php if ($message): ?>
                <div class="alert alert-success">
                    <?php echo $message; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo $error; ?>
                </div>
                <?php endif; ?>
                
                <div class="install-steps">
                    <div class="step active">
                        <div class="step-number">1</div>
                        <div class="step-label">环境检查</div>
                    </div>
                    <div class="step active">
                        <div class="step-number">2</div>
                        <div class="step-label">数据库配置</div>
                    </div>
                    <div class="step active">
                        <div class="step-number">3</div>
                        <div class="step-label">管理员设置</div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-label">完成安装</div>
                    </div>
                </div>
                
<div class="system-check">
    <h3>系统环境检查</h3>
    <?php
    // 检查 PHP 版本
    $php_version_ok = version_compare(PHP_VERSION, '7.0.0', '>=');
    
    // 检查目录权限（更健壮的检查）
    $config_dir = dirname(__FILE__) . '/config';
    $config_writable = false;
    
    if (is_dir($config_dir)) {
        $config_writable = is_writable($config_dir);
    } else {
        // 尝试创建目录
        if (@mkdir($config_dir, 0755, true)) {
            $config_writable = is_writable($config_dir);
        }
    }
    
    // 检查上传目录权限
    $upload_dir = dirname(__FILE__) . '/uploads';
    $upload_writable = false;
    
    if (is_dir($upload_dir)) {
        $upload_writable = is_writable($upload_dir);
    } else {
        // 尝试创建父目录的权限检查
        $upload_writable = is_writable(dirname(__FILE__));
    }
    
    $requirements = [
        'PHP版本 >= 7.0' => $php_version_ok,
        'MySQLi扩展' => extension_loaded('mysqli'),
        'GD库支持' => extension_loaded('gd'),
        '文件上传支持' => ini_get('file_uploads'),
        '配置文件目录可写' => $config_writable,
        '上传目录可写' => $upload_writable,
    ];
    
    $all_pass = true;
    foreach ($requirements as $requirement => $status) {
        $status_class = $status ? 'pass' : 'fail';
        $status_text = $status ? '✓ 通过' : '✗ 失败';
        if (!$status) $all_pass = false;
        echo "<div class='check-item'>";
        echo "<span>{$requirement}</span>";
        echo "<span class='check-status {$status_class}'>{$status_text}</span>";
        echo "</div>";
    }
    ?>
</div>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="install">
                    
                    <div class="form-section">
                        <h3>数据库配置</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="db_host">数据库主机</label>
                                <input type="text" id="db_host" name="db_host" value="localhost" required>
                                <p class="form-help">通常为 localhost 或 127.0.0.1</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="db_name">数据库名称</label>
                                <input type="text" id="db_name" name="db_name" value="invoice_management" required>
                                <p class="form-help">将创建此数据库</p>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="db_user">数据库用户名</label>
                                <input type="text" id="db_user" name="db_user" value="root" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="db_pass">数据库密码</label>
                                <input type="password" id="db_pass" name="db_pass">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>管理员账户设置</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="admin_username">管理员用户名</label>
                                <input type="text" id="admin_username" name="admin_username" value="admin" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="admin_password">管理员密码</label>
                                <input type="password" id="admin_password" name="admin_password" value="admin123" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="admin_email">管理员邮箱</label>
                                <input type="email" id="admin_email" name="admin_email" value="admin@example.com" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <?php if ($all_pass): ?>
                        <button type="submit" class="btn btn-primary">开始安装</button>
                        <?php else: ?>
                        <button type="button" class="btn btn-primary" disabled>请先满足所有环境要求</button>
                        <?php endif; ?>
                        
                        <?php if (file_exists('config/database.php')): ?>
                        <button type="submit" name="action" value="reset" class="btn btn-danger" 
                                onclick="return confirm('警告：这将删除所有数据！确定要重置系统吗？')">
                            重置系统
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
        // 表单验证
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const password = document.getElementById('admin_password').value;
                if (password.length < 6) {
                    alert('管理员密码至少需要6个字符');
                    e.preventDefault();
                    return false;
                }
                
                if (!confirm('确定要开始安装吗？安装过程可能需要几分钟。')) {
                    e.preventDefault();
                    return false;
                }
            });
        });
        </script>
    </body>
    </html>
    <?php
}

// 显示安装界面
if (!$installed || !$tables_exist) {
    $message = isset($_GET['message']) ? urldecode($_GET['message']) : null;
    showInstallForm(null, $message);
} else {
    // 如果已安装，显示信息
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>系统已安装</title>
        <style>
            body {
                font-family: 'Microsoft YaHei', Arial, sans-serif;
                background: #f5f5f5;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
            }
            
            .message-box {
                background: white;
                padding: 40px;
                border-radius: 10px;
                box-shadow: 0 5px 20px rgba(0,0,0,0.1);
                text-align: center;
                max-width: 500px;
            }
            
            .success-icon {
                color: #28a745;
                font-size: 60px;
                margin-bottom: 20px;
            }
            
            h1 {
                color: #28a745;
                margin-bottom: 20px;
            }
            
            p {
                color: #666;
                margin-bottom: 30px;
                line-height: 1.6;
            }
            
            .btn {
                display: inline-block;
                padding: 12px 30px;
                background: #007bff;
                color: white;
                text-decoration: none;
                border-radius: 5px;
                margin: 0 10px;
                transition: background 0.3s;
            }
            
            .btn:hover {
                background: #0056b3;
            }
            
            .btn-secondary {
                background: #6c757d;
            }
            
            .btn-secondary:hover {
                background: #545b62;
            }
            
            .btn-danger {
                background: #dc3545;
            }
            
            .btn-danger:hover {
                background: #bd2130;
            }
        </style>
    </head>
    <body>
        <div class="message-box">
            <div class="success-icon">✓</div>
            <h1>系统已安装</h1>
            <p>发票管理系统已经成功安装并可以正常使用。</p>
            
            <div style="margin-top: 30px;">
                <a href="login.php" class="btn">登录系统</a>
                <a href="dashboard.php" class="btn btn-secondary">进入控制台</a>
                <br><br>
                <a href="init.php?action=reset" class="btn btn-danger" 
                   onclick="return confirm('警告：这将删除所有数据！确定要重置系统吗？')">
                    重置系统
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>