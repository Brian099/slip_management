<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

checkRole(['finance']); // 只有财务员可以作废发票

$invoice_id = intval($_GET['id'] ?? 0);
$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// 获取发票信息
$invoice = getInvoiceById($invoice_id);

if (!$invoice) {
    die("发票不存在");
}

// 验证发票是否可以作废
if ($invoice['finance_status'] !== 'invoiced') {
    die("只能作废已开票的发票");
}

if ($invoice['is_voided']) {
    die("发票已作废");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $void_reason = trim($_POST['void_reason'] ?? '');
    
    if (empty($void_reason)) {
        $error = '请填写作废原因';
    } else {
        try {
            $conn->begin_transaction();
            
            // 更新发票为作废状态
            $stmt = $conn->prepare("UPDATE invoices SET 
                is_voided = TRUE,
                voided_at = NOW(),
                void_reason = ?,
                voided_by = ?
                WHERE id = ? AND finance_status = 'invoiced'");
            
            $stmt->bind_param("sii", $void_reason, $user_id, $invoice_id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $conn->commit();
                    $success = '发票作废成功！';
                    
                    // 重新获取发票信息
                    $invoice = getInvoiceById($invoice_id);
                } else {
                    throw new Exception('作废失败，可能发票状态已变更');
                }
            } else {
                throw new Exception('作废失败：' . $conn->error);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>作废发票</title>
    <link rel="stylesheet" href="styles.css">
    <style>

    </style>
</head>
<body>
    <div class="container">
        <?php include 'includes/header.php'; ?>
        
        <div class="main-content">
            <div class="void-container">
                <h1>作废发票</h1>
                
                <?php if ($error): ?>
                <div class="alert error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert success">
                    <?php echo $success; ?>
                    <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" style="margin-left: 20px;">查看发票</a>
                </div>
                <?php endif; ?>
                
                <div class="invoice-summary">
                    <h3>要作废的发票信息</h3>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <strong>发票号：</strong>
                            <span><?php echo $invoice['invoice_number']; ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>客户名称：</strong>
                            <span><?php echo $invoice['customer_name']; ?></span>
                        </div>
						<div class="summary-item">
                            <strong>联系方式：</strong>
                            <span><?php echo $invoice['customer_contact']; ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>发票类型：</strong>
                            <span><?php echo $invoice['invoice_type']; ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>总金额：</strong>
                            <span>¥<?php echo number_format($invoice['total_amount'], 2); ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>开票时间：</strong>
                            <span><?php echo date('Y-m-d H:i', strtotime($invoice['invoiced_at'])); ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>开票人：</strong>
                            <span><?php echo $invoice['created_by_name']; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="warning-box">
                    <h3>⚠️ 重要提示</h3>
                    <p>作废发票是一个不可逆的操作。作废后：</p>
                    <ul style="padding: 20px;">
                        <li>发票将被标记为"已作废"状态</li>
                        <li>作废记录将永久保存</li>
                        <li>财务统计中将不包括已作废发票</li>
                        <li>作废原因将记录在案</li>
                    </ul>
                    <p><strong>请谨慎操作！</strong></p>
                </div>
                
                <?php if (!$success): ?>
                <form method="POST" action="">
                    <div class="form-section">
                        <h3>作废信息</h3>
                        <div class="form-group">
                            <label for="void_reason">作废原因 *</label>
                            <textarea id="void_reason" name="void_reason" 
                                      placeholder="请详细说明作废原因，例如：客户取消订单、发票信息错误、重复开票等..." 
                                      required><?php echo htmlspecialchars($_POST['void_reason'] ?? ''); ?></textarea>
                            <p class="form-help">请详细填写作废原因，这将是重要的财务记录。</p>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-danger">确认作废</button>
                        <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" class="btn btn-secondary">取消</a>
                    </div>
                </form>
                <?php else: ?>
                <div class="void-success">
                    <h3>✅ 发票已成功作废</h3>
                    <p>作废时间：<?php echo date('Y-m-d H:i', strtotime($invoice['voided_at'])); ?></p>
                    <p>作废人：<?php echo $_SESSION['full_name']; ?></p>
                    <p>作废原因：<?php echo nl2br(htmlspecialchars($invoice['void_reason'])); ?></p>
                    
                    <div class="form-actions">
                        <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" class="btn btn-primary">查看发票详情</a>
                        <a href="invoices.php?filter=voided" class="btn btn-secondary">查看已作废发票</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    document.querySelector('form')?.addEventListener('submit', function(e) {
        const reason = this.querySelector('#void_reason').value.trim();
        
        if (reason.length < 2) {
            alert('作废原因至少需要2个字符');
            e.preventDefault();
            return false;
        }
        
        if (!confirm('确定要作废这张发票吗？此操作不可撤销！')) {
            e.preventDefault();
            return false;
        }
        
        return true;
    });
    </script>
</body>
</html>