<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

checkRole(['sales', 'finance']);

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// 获取统计数据
if ($user_role === 'finance') {
    // 财务员看到所有统计数据
    $total_invoices = $conn->query("SELECT COUNT(*) as count FROM invoices")->fetch_assoc()['count'];
    $pending_invoices = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE finance_status = 'pending'")->fetch_assoc()['count'];
    $invoiced_invoices = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE finance_status = 'invoiced'")->fetch_assoc()['count'];
    $incorrect_invoices = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE finance_status = 'incorrect'")->fetch_assoc()['count'];
    $voided_invoices = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE is_voided = TRUE")->fetch_assoc()['count'];
} else {
    // 业务员只看到自己的统计数据
    $total_invoices = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE created_by = $user_id")->fetch_assoc()['count'];
    $pending_invoices = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE created_by = $user_id AND finance_status = 'pending'")->fetch_assoc()['count'];
    $invoiced_invoices = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE created_by = $user_id AND finance_status = 'invoiced'")->fetch_assoc()['count'];
    $incorrect_invoices = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE created_by = $user_id AND finance_status = 'incorrect'")->fetch_assoc()['count'];
}

// 获取最近发票（根据权限）
if ($user_role === 'finance') {
    // 财务员看到所有最近发票
    $recent_sql = "SELECT i.*, u.full_name as created_by_name 
                   FROM invoices i 
                   LEFT JOIN users u ON i.created_by = u.id 
                   WHERE i.is_voided = FALSE
                   ORDER BY i.created_at DESC 
                   LIMIT 10";
    $recent_invoices = $conn->query($recent_sql)->fetch_all(MYSQLI_ASSOC);
} else {
    // 业务员只看到自己的最近发票
    $recent_sql = "SELECT i.*, u.full_name as created_by_name 
                   FROM invoices i 
                   LEFT JOIN users u ON i.created_by = u.id 
                   WHERE i.created_by = ? AND i.is_voided = FALSE
                   ORDER BY i.created_at DESC 
                   LIMIT 10";
    $stmt = $conn->prepare($recent_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $recent_invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>发票管理系统 - 仪表板</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <?php include 'includes/header.php'; ?>
        
        <div class="main-content">
            <h1>仪表板</h1>
            
            <div class="stats-cards">
                <a href="invoices.php" class="card stats-total-amount" title="点击查看全部发票">
                    <h3>
                        <?php echo $user_role === 'sales' ? '我的发票总数' : '总发票数'; ?>
                    </h3>
                    <p class="stat-number"><?php echo $total_invoices; ?></p>
                </a>
                
                <a href="invoices.php?filter=pending" class="card stats-pending" title="点击查看待开票列表">
                    <h3>待开票</h3>
                    <p class="stat-number"><?php echo $pending_invoices; ?></p>
                </a>
                
                <a href="invoices.php?filter=invoiced" class="card stats-invoiced" title="点击查看已开票列表">
                    <h3>已开票</h3>
                    <p class="stat-number"><?php echo $invoiced_invoices; ?></p>
                </a>

                <a href="invoices.php?filter=incorrect" class="card stats-incorrect" title="点击查看有误发票列表">
                    <h3>发票有误</h3>
                    <p class="stat-number"><?php echo $incorrect_invoices; ?></p>
                </a>
                
                <?php if ($user_role === 'finance'): ?>
                <a href="invoices.php?filter=voided" class="card stats-voided" title="点击查看已作废列表">
                    <h3>已作废</h3>
                    <p class="stat-number"><?php echo $voided_invoices ?? 0; ?></p>
                </a>
                <?php endif; ?>
                
                <?php if ($user_role === 'sales'): ?>
                <a href="invoices.php" class="card stats-total" title="点击查看我的发票">
                    <h3>总金额</h3>
                    <p class="stat-number">
                        <?php 
                        $total_amount = $conn->query("SELECT SUM(total_amount) as total FROM invoices WHERE created_by = $user_id")->fetch_assoc()['total'];
                        echo '¥' . number_format($total_amount ?: 0, 2);
                        ?>
                    </p>
                </a>
                <?php else: ?>
                <a href="users.php" class="card stats-seller" title="点击查看用户管理">
                    <h3>业务员数</h3>
                    <p class="stat-number">
                        <?php 
                        $sales_count = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'sales'")->fetch_assoc()['count'];
                        echo $sales_count;
                        ?>
                    </p>
                </a>
                <?php endif; ?>
            </div>
            
            <div class="quick-actions">
                <?php 
                $allow_finance = getSetting($conn, 'allow_finance_create_invoice', 0);
                if ($user_role === 'sales' || ($user_role === 'finance' && $allow_finance)): 
                ?>
                <a href="add_invoice.php" class="btn btn-primary">新增发票</a>
                <?php endif; ?>
                <a href="invoices.php" class="btn btn-secondary">
                    <?php echo $user_role === 'sales' ? '我的发票' : '所有发票'; ?>
                </a>
                <?php if ($user_role === 'finance'): ?>
                <a href="invoices.php?filter=pending" class="btn btn-warning">待开票列表</a>
                <a href="invoices.php?filter=voided" class="btn btn-danger">已作废列表</a>
                <?php endif; ?>
                <a href="invoices.php?filter=incorrect" class="btn" style="background-color: #f7a35c; color: white;">有误发票列表</a>
                <a href="invoices.php?filter=invoiced" class="btn btn-success">已开票列表</a>
            </div>
            
            <div class="recent-invoices">
                <h2>
                    <?php echo $user_role === 'sales' ? '我的最近发票' : '最近发票'; ?>
                    <span class="permission-hint">
                        <?php echo $user_role === 'sales' ? '（仅显示您创建的发票）' : '（显示有效发票）'; ?>
                    </span>
                </h2>
                
                <?php if (empty($recent_invoices)): ?>
                <div class="empty-state">
                    <p>
                        <?php 
                        $allow_finance = getSetting($conn, 'allow_finance_create_invoice', 0);
                        if ($user_role === 'sales' || ($user_role === 'finance' && $allow_finance)): 
                        ?>
                        您还没有创建任何发票，<a href="add_invoice.php">点击这里创建第一张发票</a>
                        <?php else: ?>
                        暂无发票数据
                        <?php endif; ?>
                    </p>
                </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>发票号</th>
                            <th>客户名称</th>
                            <th>发票类型</th>
                            <th>总金额</th>
                            <th>状态</th>
                            <?php if ($user_role === 'finance'): ?>
                            <th>创建人</th>
                            <?php endif; ?>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_invoices as $invoice): ?>
                        <?php 
                        $is_voided = !empty($invoice['is_voided']) && $invoice['is_voided'] == 1;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                            <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                            <td><?php echo $invoice['invoice_type']; ?></td>
                            <td>¥<?php echo number_format($invoice['total_amount'], 2); ?></td>
                             <td>
                                 <span class="status <?php echo getInvoiceStatusClass($invoice); ?>">
                                     <?php echo getInvoiceStatusText($invoice); ?>
                                 </span>
                             </td>
                            <?php if ($user_role === 'finance'): ?>
                            <td><?php echo htmlspecialchars($invoice['created_by_name']); ?></td>
                            <?php endif; ?>
                            <td><?php echo date('Y-m-d H:i', strtotime($invoice['created_at'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="invoices.php?action=view&id=<?php echo $invoice['id']; ?>" class="btn btn-view">查看</a>
                                    <?php 
                                    $allow_finance = getSetting($conn, 'allow_finance_create_invoice', 0);
                                    $can_edit = ($user_role === 'sales' || ($user_role === 'finance' && $allow_finance));
                                    if ($can_edit && $invoice['created_by'] == $user_id && $invoice['finance_status'] === 'pending' && !$is_voided): 
                                    ?>
                                    <a href="edit_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-edit">编辑</a>
                                    <?php endif; ?>



                                    <?php if ($user_role === 'finance' && $invoice['finance_status'] === 'invoiced' && !$is_voided): ?>
                                    <a href="void_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-void">作废</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <style>

    </style>
</body>
</html>