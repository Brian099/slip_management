<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$conn = getDbConnection();
$allow_finance = getSetting($conn, 'allow_finance_create_invoice', 0);
if ($allow_finance) {
    checkRole(['sales', 'finance']);
} else {
    checkRole(['sales']);
}

$invoice_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

// 获取发票信息
$stmt = $conn->prepare("SELECT i.*, u.username as created_by_name 
                       FROM invoices i 
                       LEFT JOIN users u ON i.created_by = u.id 
                       WHERE i.id = ?");
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    die("发票不存在");
}

// 验证权限：只能删除自己的未开票发票
if ($invoice['created_by'] != $user_id) {
    die("无权删除他人创建的发票");
}

if ($invoice['finance_status'] !== 'pending') {
    die("只有待开票状态的发票可以删除");
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
        try {
            $conn->begin_transaction();
            
            // 删除货物详情
            $stmt = $conn->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
            $stmt->bind_param("i", $invoice_id);
            $stmt->execute();
            
            // 删除发票
            $stmt = $conn->prepare("DELETE FROM invoices WHERE id = ? AND created_by = ?");
            $stmt->bind_param("ii", $invoice_id, $user_id);
            
            if ($stmt->execute()) {
                $conn->commit();
                
                // 发送 Webhook 通知
                sendWebhook('invoice_deleted', [
                    'invoice_number' => $invoice['invoice_number'],
                    'customer_name' => $invoice['customer_name'],
                    'total_amount' => $invoice['total_amount']
                ]);
                
                // 删除电子发票文件（如果存在）
                if ($invoice['invoice_file'] && file_exists('uploads/invoices/' . $invoice['invoice_file'])) {
                    unlink('uploads/invoices/' . $invoice['invoice_file']);
                }
                
                header('Location: invoices.php?message=' . urlencode('发票删除成功'));
                exit;
            } else {
                throw new Exception('删除失败');
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = '删除失败：' . $e->getMessage();
        }
    } else {
        header('Location: invoices.php?action=view&id=' . $invoice_id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>删除发票</title>
    <link rel="stylesheet" href="styles.css">
    <style>

    </style>
</head>
<body>
<div class="container">
    <?php include 'includes/header.php'; ?>
    
    <div class="main-content">
        <div class="delete-confirm">
            <h1>确认删除发票</h1>
            
            <?php if ($error): ?>
            <div class="alert error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="warning-box">
                <h3>⚠️ 警告</h3>
                <p>此操作将永久删除该发票及其所有相关数据，包括货物信息和电子发票文件（如果存在）。此操作不可恢复！</p>
            </div>
            
            <div class="invoice-info">
                <h3>要删除的发票信息：</h3>
                <p><strong>发票号：</strong> <?php echo $invoice['invoice_number']; ?></p>
                <p><strong>客户名称：</strong> <?php echo $invoice['customer_name']; ?></p>
				<p><strong>联系方式：</strong> <?php echo $invoice['customer_contact']; ?></p>
                <p><strong>发票类型：</strong> <?php echo $invoice['invoice_type']; ?></p>
                <p><strong>总金额：</strong> ¥<?php echo number_format($invoice['total_amount'], 2); ?></p>
                <p><strong>创建人：</strong> <?php echo $invoice['created_by_name']; ?></p>
                <p><strong>创建时间：</strong> <?php echo date('Y-m-d H:i', strtotime($invoice['created_at'])); ?></p>
            </div>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="confirm" value="yes" required>
                        我确认要删除这张发票，并理解此操作不可恢复
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="reason">删除原因（可选）：</label>
                    <textarea id="reason" name="reason" rows="3" placeholder="请输入删除原因..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-danger">确认删除</button>
                    <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" class="btn btn-secondary">取消</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelector('form').addEventListener('submit', function(e) {
    if (!this.querySelector('input[name="confirm"]').checked) {
        alert('请先确认要删除发票');
        e.preventDefault();
        return false;
    }
    
    if (!confirm('确定要永久删除这张发票吗？此操作不可撤销！')) {
        e.preventDefault();
        return false;
    }
});
</script>
</body>
</html>
