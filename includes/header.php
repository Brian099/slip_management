<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$conn = getDbConnection();
$company_name = getSetting($conn, 'company_name', '');
$company_address = getSetting($conn, 'company_address', '');

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user_role = $_SESSION['role'];
?>
<!-- 这是一个包含文件，不包含完整的HTML结构 -->
<!-- 侧边栏 -->
<div class="sidebar">

    <div class="sidebar-header">
        <h2>发票管理系统</h2>
        <div class="user-info">
            <p><?php echo $_SESSION['full_name']; ?></p>
            <p><?php echo $_SESSION['role'] === 'sales' ? '业务员' : '财务员'; ?></p>
        </div>
    </div>
        <!-- 公司信息底部区域 -->
    <div class="sidebar-footer">
        <div class="company-info">
            <?php if (!empty($company_name)): ?>
            <div class="company-name">
                <i class="icon-building">🏢</i>
                <span class="company-name-text" title="<?php echo htmlspecialchars($company_name); ?>">
                    <?php 
                    if (mb_strlen($company_name, 'UTF-8') > 12) {
                        echo htmlspecialchars(mb_substr($company_name, 0, 12, 'UTF-8')) . '...';
                    } else {
                        echo htmlspecialchars($company_name);
                    }
                    ?>
                </span>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($company_address)): ?>
            <div class="company-address">
                <i class="icon-location">📍</i>
                <span class="address-text" title="<?php echo htmlspecialchars($company_address); ?>">
                    <?php 
                    if (mb_strlen($company_address, 'UTF-8') > 15) {
                        echo htmlspecialchars(mb_substr($company_address, 0, 15, 'UTF-8')) . '...';
                    } else {
                        echo htmlspecialchars($company_address);
                    }
                    ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <ul class="nav-menu">
        <li><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">仪表板</a></li>
        <li><a href="invoices.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'invoices.php' ? 'active' : ''; ?>">发票管理</a></li>
        <li><a href="income_records.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'income_records.php' ? 'active' : ''; ?>">入账信息</a></li>
		
        <?php 
        $allow_finance = getSetting($conn, 'allow_finance_create_invoice', 0);
        if ($_SESSION['role'] === 'sales' || ($_SESSION['role'] === 'finance' && $allow_finance)): 
        ?>
        <li><a href="add_invoice.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'add_invoice.php' ? 'active' : ''; ?>">新增发票</a></li>
        <?php endif; ?>
        
        <?php if ($_SESSION['role'] === 'finance'): ?>
        <!-- 只有财务员能看到待开票，用户管理和系统设置 -->
        <li><a href="invoices.php?filter=pending" class="<?php echo isset($_GET['filter']) && $_GET['filter'] == 'pending' ? 'active' : ''; ?>">待开票</a></li>
        
        <li><a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">用户管理</a></li>
        <li><a href="system_settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'system_settings.php' ? 'active' : ''; ?>">系统设置</a></li>
        <?php endif; ?>
        
        <li><a href="logout.php" onclick="return confirm('确定要退出登录吗？')">退出登录</a></li>
    </ul>
	

</div>

<style>
/* 公司信息底部区域 */
.sidebar-footer {
    padding: 15px 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(0, 0, 0, 0.2);
}

.company-info {
    font-size: 12px;
}

.company-name {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
    color: white;
    font-weight: 500;
}

.company-name-text {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.company-address {
    display: flex;
    align-items: flex-start;
    color: rgba(255, 255, 255, 0.8);
    line-height: 1.4;
}

.address-text {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.icon-building, .icon-location {
    margin-right: 8px;
    font-size: 14px;
    flex-shrink: 0;
}
</style>
