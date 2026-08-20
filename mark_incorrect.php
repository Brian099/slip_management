<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

checkRole(['sales', 'finance']);

$invoice_id = intval($_GET['id'] ?? 0);
$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// 获取发票信息
$invoice = getInvoiceById($invoice_id);

if (!$invoice) {
    die("发票不存在");
}

// 验证权限：只能标记自己创建的已开票且未作废的发票为有误
if (!canMarkIncorrect($invoice, $user_id)) {
    die("无权对此发票进行该操作，或发票当前状态不符合要求（必须为您创建的已开票未作废发票）");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
        try {
            $conn->begin_transaction();
            
            // 更新发票为“发票有误”状态
            $stmt = $conn->prepare("UPDATE invoices SET 
                finance_status = 'incorrect'
                WHERE id = ? AND finance_status = 'invoiced' AND (is_voided = FALSE OR is_voided IS NULL)");
            
            $stmt->bind_param("i", $invoice_id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $conn->commit();
                    $success = '发票已成功标记为“有误”！请通知财务人员确认并退回。';
                    
                    // 发送 Webhook 通知
                    sendWebhook('invoice_marked_incorrect', [
                        'invoice_number' => $invoice['invoice_number'],
                        'customer_name' => $invoice['customer_name'],
                        'total_amount' => $invoice['total_amount']
                    ]);
                    
                    // 重新获取发票信息
                    $invoice = getInvoiceById($invoice_id);
                } else {
                    throw new Exception('标记失败，可能发票状态已变更');
                }
            } else {
                throw new Exception('数据库更新失败：' . $conn->error);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
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
    <title>标记发票有误</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .mark-container {
            max-width: 800px;
            margin: 30px auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .invoice-summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .summary-item {
            margin-bottom: 10px;
        }
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'includes/header.php'; ?>
        
        <div class="main-content">
            <div class="mark-container">
                <h1>标记发票有误</h1>
                
                <?php if ($error): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert success">
                    <?php echo htmlspecialchars($success); ?>
                    <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" style="margin-left: 20px;" class="btn btn-secondary">查看发票详情</a>
                </div>
                <?php endif; ?>
                
                <div class="invoice-summary">
                    <h3>发票基本信息</h3>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <strong>系统编号：</strong>
                            <span><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>发票编号：</strong>
                            <span><?php echo htmlspecialchars($invoice['slip_number'] ?: '无'); ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>客户名称：</strong>
                            <span><?php echo htmlspecialchars($invoice['customer_name']); ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>总金额：</strong>
                            <span style="color: #28a745; font-weight: bold;">¥<?php echo number_format($invoice['total_amount'], 2); ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>开票时间：</strong>
                            <span><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($invoice['invoiced_at']))); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="warning-box">
                    <h3>⚠️ 注意事项</h3>
                    <p>您正在将该发票标记为<strong>“发票有误”</strong>。此操作将通知财务人员进行处理：</p>
                    <ul style="padding: 15px 25px;">
                        <li>状态变更为“发票有误”，发票暂时无法正常作废或下载。</li>
                        <li>财务人员确认有误后，会<strong>物理删除已上传的电子发票附件</strong>，并将状态退回到<strong>待开票</strong>。</li>
                        <li>状态回退到待开票后，您将可以重新修改或删除该发票。</li>
                    </ul>
                </div>
                
                <?php if (!$success): ?>
                <form method="POST" action="">
                    <input type="hidden" name="confirm" value="yes">
                    
                    <p style="color: #dc3545; font-weight: bold; margin-bottom: 20px;">
                        请确认该发票（系统编号：<?php echo htmlspecialchars($invoice['invoice_number']); ?>）确实开具错误并需要重新处理：
                    </p>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-danger">确认标记为有误</button>
                        <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" class="btn btn-secondary">取消</a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
