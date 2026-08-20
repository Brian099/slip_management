<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

checkRole(['finance']); // 只有财务员可以管理用户

$conn = getDbConnection();
$error = '';
$success = '';

// 添加用户
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        $username = sanitize($_POST['username']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $full_name = sanitize($_POST['full_name']);
        $email = sanitize($_POST['email']);
        $role = $_POST['role'];
        
        // 验证数据
        if (empty($username) || empty($password) || empty($full_name)) {
            throw new Exception('请填写所有必填字段');
        }
        
        if ($password !== $confirm_password) {
            throw new Exception('两次输入的密码不一致');
        }
        
        if (strlen($password) < 6) {
            throw new Exception('密码至少需要6个字符');
        }
        
        // 检查用户名是否已存在
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            throw new Exception('用户名已存在');
        }
        
        // 添加用户
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, role, full_name, email) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $username, $hashed_password, $role, $full_name, $email);
        
        if ($stmt->execute()) {
            $success = '用户添加成功！';
        } else {
            throw new Exception('添加用户失败：' . $conn->error);
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 更新用户状态
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status' && isset($_GET['id'])) {
    $user_id = intval($_GET['id']);
    
    // 不能禁用自己
    if ($user_id != $_SESSION['user_id']) {
        $stmt = $conn->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        header('Location: users.php?message=' . urlencode('用户状态已更新'));
        exit;
    }
}

// 删除用户
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = intval($_POST['user_id']);
    
    // 不能删除自己
    if ($user_id != $_SESSION['user_id']) {
        // 检查用户是否有发票记录
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM invoices WHERE created_by = ?");
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $error = '该用户有发票记录，不能删除';
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                $success = '用户删除成功！';
            }
        }
    } else {
        $error = '不能删除自己的账户';
    }
}

// 重置密码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $user_id = intval($_POST['user_id']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($new_password !== $confirm_password) {
        $error = '两次输入的密码不一致';
    } elseif (strlen($new_password) < 6) {
        $error = '密码至少需要6个字符';
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed_password, $user_id);
        if ($stmt->execute()) {
            $success = '密码重置成功！';
        }
    }
}

// 获取所有用户
$users = $conn->query("SELECT * FROM users ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

// 获取用户统计信息
$user_stats = [];
foreach ($users as $user) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM invoices WHERE created_by = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $user_stats[$user['id']] = $result['count'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户管理</title>
    <link rel="stylesheet" href="styles.css">
    <style>

    </style>
</head>
<body>
    <div class="container">
        <?php include 'includes/header.php'; ?>
        
        <div class="main-content">
            <h1>用户管理</h1>
            
            <?php if ($error): ?>
            <div class="alert error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($_GET['message'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_GET['message']); ?></div>
            <?php endif; ?>
            
            <div class="user-management">
                <!-- 添加用户表单 -->
                <div class="add-user-form">
                    <h2 style="margin-bottom: 20px;">添加新用户</h2>
                    <form method="POST" action="">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="username">用户名 *</label>
                                <input type="text" id="username" name="username" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="full_name">姓名 *</label>
                                <input type="text" id="full_name" name="full_name" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">邮箱</label>
                                <input type="email" id="email" name="email">
                            </div>
                            
                            <div class="form-group">
                                <label for="role">角色 *</label>
                                <select id="role" name="role" required>
                                    <option value="sales">业务员</option>
                                    <option value="finance">财务员</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">密码 *</label>
                                <input type="password" id="password" name="password" required minlength="6">
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">确认密码 *</label>
                                <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="add_user" class="btn btn-primary">添加用户</button>
                        </div>
                    </form>
                </div>
                
                <!-- 用户列表 -->
                <div class="user-list">
                    <h2 style="margin-bottom: 20px;">用户列表 (<?php echo count($users); ?>)</h2>
                    
                    <div class="user-stats">
                        <p>业务员：<?php echo count(array_filter($users, fn($u) => $u['role'] === 'sales')); ?> 人</p>
                        <p>财务员：<?php echo count(array_filter($users, fn($u) => $u['role'] === 'finance')); ?> 人</p>
                    </div>
                    
                    <div class="user-items">
                        <?php foreach ($users as $user): ?>
                        <div class="user-item">
                            <div class="user-info">
                                <h4>
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                    <span class="role-badge"><?php echo $user['role'] === 'sales' ? '业务员' : '财务员'; ?></span>
                                    <span class="status-badge <?php echo 'status-' . $user['status']; ?>">
                                        <?php echo $user['status'] === 'active' ? '使用中' : '禁用'; ?>
                                    </span>
                                </h4>
                                <div class="user-meta">
                                    <p>用户名：<?php echo htmlspecialchars($user['username']); ?></p>
                                    <p>邮箱：<?php echo htmlspecialchars($user['email'] ?: '未设置'); ?></p>
                                    <p>创建时间：<?php echo date('Y-m-d', strtotime($user['created_at'])); ?></p>
                                    <p>发票数量：<?php echo $user_stats[$user['id']] ?? 0; ?> 张</p>
                                </div>
                                
                                <!-- 密码重置表单 -->
                                <div class="password-form" id="password-form-<?php echo $user['id']; ?>">
                                    <form method="POST" action="" class="password-reset-form">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label>新密码</label>
                                                <input type="password" name="new_password" required minlength="6">
                                            </div>
                                            <div class="form-group">
                                                <label>确认密码</label>
                                                <input type="password" name="confirm_password" required minlength="6">
                                            </div>
                                        </div>
                                        <div style="margin-top: 10px;">
                                            <button type="submit" name="reset_password" class="btn btn-sm btn-primary">重置</button>
                                            <button type="button" class="btn btn-sm btn-secondary" 
                                                    onclick="togglePasswordForm(<?php echo $user['id']; ?>)">取消</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <div class="user-actions">
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <button type="button" class="btn btn-sm btn-warning" 
                                        onclick="togglePasswordForm(<?php echo $user['id']; ?>)">重置密码</button>
                                
                                <a href="users.php?action=toggle_status&id=<?php echo $user['id']; ?>" 
                                   class="btn btn-sm <?php echo $user['status'] === 'active' ? 'btn-secondary' : 'btn-success'; ?>"
                                   onclick="return confirm('确定要<?php echo $user['status'] === 'active' ? '禁用' : '启用'; ?>该用户吗？')">
                                    <?php echo $user['status'] === 'active' ? '禁用' : '启用'; ?>
                                </a>
                                
                                <form method="POST" action="" style="display: inline;" 
                                      onsubmit="return confirm('确定要删除用户 <?php echo htmlspecialchars($user['full_name']); ?> 吗？')">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" name="delete_user" class="btn btn-sm btn-danger">删除</button>
                                </form>
                                <?php else: ?>
                                <span class="current-user">当前用户</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function togglePasswordForm(userId) {
        const form = document.getElementById('password-form-' + userId);
        form.style.display = form.style.display === 'block' ? 'none' : 'block';
    }

    // 密码重置表单验证
    document.querySelectorAll('.password-reset-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const newPassword = this.querySelector('input[name="new_password"]').value;
            const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
            
            if (newPassword !== confirmPassword) {
                alert('两次输入的密码不一致');
                e.preventDefault();
                return false;
            }
            
            if (newPassword.length < 6) {
                alert('密码至少需要6个字符');
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    });
    </script>
</body>
</html>