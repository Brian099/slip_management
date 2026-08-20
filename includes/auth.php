<?php
session_start();

// 检查用户是否登录
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// 检查用户角色
function checkRole($allowedRoles = []) {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
    
    if (!empty($allowedRoles) && !in_array($_SESSION['role'], $allowedRoles)) {
        header('Location: dashboard.php');
        exit;
    }
}

// 登录验证
function login($username, $password) {
    $conn = getDbConnection();
    
    $username = sanitize($username);
    $stmt = $conn->prepare("SELECT id, username, password, role, full_name FROM users WHERE username = ? AND status = 'active'");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['full_name'] = $row['full_name'];
            return true;
        }
    }
    
    return false;
}

// 注销
function logout() {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>