<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

checkRole(['finance']); // 只有财务人员能确认有误并退回发票

$invoice_id = intval($_GET['id'] ?? 0);
$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// 获取发票信息
$invoice = getInvoiceById($invoice_id);

if (!$invoice) {
    die("发票不存在");
}

// 验证发票是否可以确认有误并退回
if (!canConfirmIncorrect($invoice, $user_role)) {
    die("发票状态不符合要求（必须是标记为有误且未作废的发票）");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
        try {
            $conn->begin_transaction();
            
            // 1. 获取要删除的发票文件并删除文件
            $invoice_file = $invoice['invoice_file'];
            $file_deleted = false;
            
            if ($invoice_file) {
                $file_path = 'uploads/invoices/' . $invoice_file;
                if (file_exists($file_path)) {
                    if (unlink($file_path)) {
                        $file_deleted = true;
                    } else {
                        throw new Exception("发票文件删除失败，请检查文件权限！");
                    }
                } else {
                    $file_deleted = true; // 文件在磁盘上本身就不存在，可认为删除成功
                }
            } else {
                $file_deleted = true; // 发票没有附件，不用删除
            }
            
            if ($file_deleted) {
                // 2. 更新发票状态回到待开票，清空发票附件名称、发票编码和开票时间
                $stmt = $conn->prepare("UPDATE invoices SET 
                    finance_status = 'pending',
                    invoice_file = NULL,
                    slip_number = NULL,
                    invoiced_at = NULL
                    WHERE id = ? AND finance_status = 'incorrect' AND (is_voided = FALSE OR is_voided IS NULL)");
                
                $stmt->bind_param("i", $invoice_id);
                
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $conn->commit();
                        $success = '已成功确认发票有误！电子发票文件已删除，发票状态已退回到“待开票”状态。';
                        
                        // 发送 Webhook 通知
                        sendWebhook('invoice_reverted_to_pending', [
                            'invoice_number' => $invoice['invoice_number'],
                            'customer_name' => $invoice['customer_name'],
                            'total_amount' => $invoice['total_amount']
                        ]);
                        
                        // 重新获取发票信息以更新显示
                        $invoice = getInvoiceById($invoice_id);
                    } else {
                        throw new Exception('数据库更新失败，发票状态可能发生改变');
                    }
                } else {
                    throw new Exception('更新发票数据库记录失败：' . $conn->error);
                }
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
    <title>确认发票有误并退回</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .confirm-container {
            max-width: 800px;
            margin: 30px auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .warning-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
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
            <div class="confirm-container">
                <h1>确认发票有误并退回</h1>
                
                <?php if ($error): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert success">
                    <?php echo htmlspecialchars($success); ?>
                    <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" style="margin-left: 20px;" class="btn btn-secondary">查看发票详情</a>
                    <a href="invoices.php" style="margin-left: 10px;" class="btn btn-primary">返回发票列表</a>
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
                            <strong>创建人：</strong>
                            <span><?php echo htmlspecialchars($invoice['created_by_name']); ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>发票附件：</strong>
                            <span><?php echo htmlspecialchars($invoice['invoice_file'] ?: '无'); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="warning-box">
                    <h3>⚠️ 重要警告</h3>
                    <p>您正在执行<strong>“确认有误并退回待开票”</strong>操作。此操作将对该发票执行以下动作：</p>
                    <ul style="padding: 15px 25px;">
                        <li><strong>永久物理删除</strong>已经上传的电子发票附件（<?php echo htmlspecialchars($invoice['invoice_file'] ?: '无附件'); ?>），删除后不可恢复。</li>
                        <li>清除发票号码（slip_number）和已开票时间记录。</li>
                        <li>将发票的系统状态<strong>改回到待开票（pending）</strong>。</li>
                        <li>回退后，业务员可以像往常一样对该发票进行<strong>修改或删除</strong>操作。</li>
                    </ul>
                </div>
                
                <?php if (!$success): ?>
                <form method="POST" action="">
                    <input type="hidden" name="confirm" value="yes">
                    
                    <p style="color: #dc3545; font-weight: bold; margin-bottom: 20px;">
                        请确认要退回此发票并删除其开票文件吗？
                    </p>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-danger">确认并退回</button>
                        <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" class="btn btn-secondary">取消</a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
