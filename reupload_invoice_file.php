<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

checkRole(['finance']); // 只有财务员可以重新上传附件

$invoice_id = intval($_GET['id'] ?? 0);
$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// 获取发票信息
$invoice = getInvoiceById($invoice_id);

if (!$invoice) {
    die("发票不存在");
}

// 验证发票是否可以重新上传附件
if ($invoice['finance_status'] !== 'invoiced') {
    die("只有已开票的发票可以重新上传附件");
}

if ($invoice['is_voided']) {
    die("已作废的发票不能重新上传附件");
}

$error = '';
$success = '';
$detected_invoice_number = ''; // 用于存储自动识别的发票号码

// 生成二维码数据
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'];
$path = dirname($_SERVER['PHP_SELF']);
if ($path === '/') {
    $base_url = $protocol . $domain;
} else {
    $base_url = $protocol . $domain . $path;
}

$timestamp = time();
$qr_data = [
    'invoice_number' => $invoice['invoice_number'],
    'customer_name'  => $invoice['customer_name'],
    'tax_number'     => $invoice['tax_number'] ?? '',
    'invoice_id'     => $invoice['id'],
    'timestamp'      => $timestamp,
    'action'         => 'reupload', // 标记为重新上传
    'key' => md5(
        $invoice['invoice_number'] .
        $invoice['customer_name'] .
        'ljsslipmanagement' .
        $timestamp .
        'reupload' // 添加动作标识
    )
];

$qr_encoded = base64_encode(json_encode($qr_data, JSON_UNESCAPED_UNICODE));
$qr_url = rtrim($base_url, '/') . '/mobile_upload.php?data=' . urlencode($qr_encoded);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_FILES['invoice_file']) || $_FILES['invoice_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('请选择要上传的文件');
        }
        
        $file = $_FILES['invoice_file'];
        
        // 文件类型验证
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('只允许上传PDF、JPEG、PNG格式的文件');
        }
        
        // 文件大小限制 (5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception('文件大小不能超过5MB');
        }
        
        // 创建上传目录
        $uploadDir = 'uploads/invoices/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // 备份原文件
        $original_file = $invoice['invoice_file'];
        $backup_dir = 'uploads/invoices/backup/';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0777, true);
        }
        
        $backup_file = $backup_dir . date('Ymd_His_') . $original_file;
        if (file_exists($uploadDir . $original_file)) {
            copy($uploadDir . $original_file, $backup_file);
        }
        
        // 生成新文件名
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'invoice_' . $invoice['invoice_number'] . '_' . date('Ymd_His') . '.' . $extension;
        $filepath = $uploadDir . $filename;
        
        // 移动文件
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('文件上传失败');
        }
        
        // 获取表单数据
        $new_slip_number = trim($_POST['slip_number'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        
        // 如果是PDF文件，尝试自动识别发票号码
        $auto_detected_number = null;
        if ($file['type'] === 'application/pdf') {
            // 开启输出缓冲，防止解析器输出任何内容到屏幕
            ob_start();
            try {
                require_once __DIR__ . '/pdfparser-2.12.2/alt_autoload.php';
                $parser = new Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filepath);
                $text = $pdf->getText();
                
                // 清理缓冲区
                ob_end_clean();
                
                if (preg_match('/发票号码[:：]?\s*(\d{8,20})/u', $text, $m)) {
                    $auto_detected_number = $m[1];
                } elseif (preg_match('/\b\d{20}\b/', $text, $m)) {
                    $auto_detected_number = $m[0];
                }
            } catch (Throwable $e) {
                // 清理缓冲区
                ob_end_clean();
                error_log("PDF解析失败: " . $e->getMessage());
            }
            
            if ($auto_detected_number) {
                $detected_invoice_number = $auto_detected_number;
                
                // 如果用户没有输入发票号，使用自动识别的号码
                if (empty($new_slip_number)) {
                    $new_slip_number = $auto_detected_number;
                }
                // 如果用户输入了发票号但与识别结果不同，询问用户选择哪个
                elseif ($new_slip_number !== $auto_detected_number) {
                    // 在备注中记录这个差异
                    $detected_invoice_number = $auto_detected_number;
                }
            }
        }
        
        // 验证发票号码（如果提供了）
        $slip_number_changed = false;
        if (!empty($new_slip_number)) {
            // 验证格式（20位数字）
            if (strlen($new_slip_number) !== 20 || !ctype_digit($new_slip_number)) {
                throw new Exception('发票编号必须是20位数字');
            }
            
            // 检查是否已存在（排除当前发票）
            $check_sql = "SELECT id FROM invoices WHERE slip_number = ? AND id != ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param('si', $new_slip_number, $invoice_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                throw new Exception('发票编号已存在，请使用其他编号');
            }
            
            // 检查是否有变化
            if ($new_slip_number !== $invoice['slip_number']) {
                $slip_number_changed = true;
            }
        }
        
        // 更新数据库
        $remarks = "【附件重新上传】时间：" . date('Y-m-d H:i:s') . 
                   "，操作人：" . $_SESSION['full_name'] . 
                   "，原文件：" . $original_file;
        
        if (!empty($reason)) {
            $remarks .= "，原因：" . $reason;
        }
        
        if ($auto_detected_number && !empty($new_slip_number) && $auto_detected_number !== $new_slip_number) {
            $remarks .= "，自动识别到：" . $auto_detected_number . "（用户输入：" . $new_slip_number . "）";
        } elseif ($auto_detected_number && empty($invoice['slip_number']) && $new_slip_number === $auto_detected_number) {
            $remarks .= "，使用自动识别的发票编号：" . $auto_detected_number;
        }
        
        if ($slip_number_changed) {
            $remarks .= "，发票编号更新：" . $invoice['slip_number'] . "→" . $new_slip_number;
        }
        
        // 构建SQL更新语句
        $sql = "UPDATE invoices SET 
                invoice_file = ?, 
                invoiced_at = NOW(), 
                remarks = CONCAT(IFNULL(remarks, ''), '\n', ?)";
        
        // 如果需要更新发票号，添加到SQL语句
        if (!empty($new_slip_number)) {
            $sql .= ", slip_number = ?";
        }
        
        $sql .= " WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        
        if (!empty($new_slip_number)) {
            $stmt->bind_param("sssi", $filename, $remarks, $new_slip_number, $invoice_id);
        } else {
            $stmt->bind_param("ssi", $filename, $remarks, $invoice_id);
        }
        
        if ($stmt->execute()) {
            $success = '发票附件重新上传成功！';
            if ($slip_number_changed) {
                $success .= ' 发票编号已更新为：' . $new_slip_number;
            } elseif ($auto_detected_number && empty($invoice['slip_number'])) {
                $success .= ' 已自动填充发票编号：' . $auto_detected_number;
            }
            
            // 重新获取发票信息
            $invoice = getInvoiceById($invoice_id);
        } else {
            throw new Exception('更新数据库失败：' . $conn->error);
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重新上传发票附件</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* 页面标题区域样式 */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-title {
            margin: 0;
            flex: 1;
            min-width: 200px;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        /* 手机上传按钮样式 */
        .btn-mobile-upload {
            background: linear-gradient(135deg, #c51c99, #6a82fb);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 400;
            transition: all 0.3s ease;
            box-shadow: 0 3px 10px rgba(74, 108, 247, 0.3);
            font-size: 14px;
            white-space: nowrap;
        }
        
        .btn-mobile-upload:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(74, 108, 247, 0.4);
        }
        
        .btn-mobile-upload:active {
            transform: translateY(0);
        }
        
        /* 弹窗样式 */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            width: 90%;
            max-width: 500px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .qr-container {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        #qrcode {
            display: inline-block;
            background: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            min-height: 200px;
            min-width: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .qr-info {
            font-size: 12px;
            color: #666;
            margin-top: 10px;
        }
        
        .qr-info strong {
            color: #4a6cf7;
        }
        
        .steps {
            margin: 20px 0;
            padding-left: 20px;
        }
        
        .steps li {
            margin-bottom: 10px;
            line-height: 1.5;
        }
        
        .invoice-info-modal {
            background: #f5f9ff;
            border-left: 4px solid #4a6cf7;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .invoice-info-modal p {
            margin: 5px 0;
            color: #333;
        }
        
        .invoice-info-modal p strong {
            color: #4a6cf7;
            min-width: 80px;
            display: inline-block;
        }
        
        /* 自动识别区域 */
        .auto-detect-section {
            background: #f8f9ff;
            border: 1px solid #4a6cf7;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            display: none;
        }
        
        .auto-detect-section.show {
            display: block;
        }
        
        .detect-btn {
            background: #4a6cf7;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .detect-btn:hover {
            background: #3a5cd9;
            transform: translateY(-1px);
        }
        
        .detect-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
        }
        
        .detect-result {
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
            font-weight: bold;
            display: none;
        }
        
        .detect-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: block;
        }
        
        .detect-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: block;
        }
        
        .detect-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
            display: block;
        }
        
        .file-upload-area {
            border: 2px dashed #ccc;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 10px 0;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .file-upload-area:hover,
        .file-upload-area.dragover {
            border-color: #4a6cf7;
            background: #f8f9ff;
        }
        
        .upload-icon {
            font-size: 48px;
            color: #4a6cf7;
            margin-bottom: 10px;
        }
        
        .invoice-number-section {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .invoice-number-section h4 {
            margin-top: 0;
            color: #856404;
        }
        
        .form-row {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .form-row label {
            min-width: 120px;
            margin-right: 10px;
        }
        
        .input-with-button {
            flex: 1;
            display: flex;
            gap: 10px;
        }
        
        .input-with-button input {
            flex: 1;
        }
        
        .validation-status {
            margin-top: 5px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .valid-status {
            color: #28a745;
        }
        
        .invalid-status {
            color: #dc3545;
        }
        
        .auto-fill-notice {
            display: inline-block;
            margin-left: 10px;
            padding: 4px 8px;
            background: #d4edda;
            color: #155724;
            border-radius: 4px;
            font-size: 12px;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .comparison-info {
            background: #e8f4fd;
            border: 1px solid #b3e0ff;
            border-radius: 6px;
            padding: 10px;
            margin-top: 10px;
        }
        
        .current-number {
            color: #155724;
            font-weight: bold;
        }
        
        .new-number {
            color: #0c5460;
            font-weight: bold;
        }
        
        .number-change {
            color: #856404;
            font-weight: bold;
        }
        
        /* 二维码生成成功样式 */
        .qr-success {
            border: 2px solid #28a745;
            padding: 15px;
            background: #f8fff8;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #4a6cf7;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .qr-loading {
            font-size: 14px;
            color: #666;
            margin-top: 10px;
        }
        
        .expiry-timer {
            color: #dc3545;
            font-weight: bold;
            margin-top: 10px;
            padding: 5px 10px;
            background: #fff5f5;
            border-radius: 4px;
            display: inline-block;
        }
        
        /* 文字间距优化 */
        .info-grid .info-item {
            letter-spacing: 0.01em;
            line-height: 1.6;
        }
        
        h1, h3, h4 {
            letter-spacing: 0.02em;
            line-height: 1.4;
        }
        
        .warning-box, .invoice-number-section {
            letter-spacing: 0.01em;
            line-height: 1.7;
            word-spacing: 0.02em;
        }
        
        .warning-box ul {
            letter-spacing: 0.005em;
            line-height: 1.8;
        }
        
        .form-help {
            letter-spacing: 0.005em;
            line-height: 1.6;
        }
        
        .current-file p {
            letter-spacing: 0.01em;
            line-height: 1.7;
        }
        
        /* 响应式调整 */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 20px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }
        
        @media (max-width: 480px) {
            .btn-mobile-upload {
                padding: 6px 12px;
                font-size: 13px;
            }
            
            .page-title {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'includes/header.php'; ?>
        
        <div class="main-content">
            <div class="reupload-container">
                <!-- 页面标题区域 -->
                <div class="page-header">
                    <h1 class="page-title">重新上传发票附件</h1>
                    <div class="header-actions">
                        <button type="button" class="btn-mobile-upload" onclick="openMobileUploadModal()" id="mobileUploadBtn">
                            <span style="font-size: 16px;">📲</span> 手机上传专票
                        </button>
                    </div>
                </div>
                
                <?php if ($error): ?>
                <div class="alert error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert success">
                    <?php echo $success; ?>
                    <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" style="margin-left: 20px;">查看发票</a>
                </div>
                <?php endif; ?>
                
                <div class="invoice-info">
                    <h3>发票信息</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <strong>系统编号：</strong>
                            <span><?php echo $invoice['invoice_number']; ?></span>
                        </div>
                        <div class="info-item">
                            <strong>发票编号：</strong>
                            <span id="currentSlipNumberDisplay"><?php echo $invoice['slip_number'] ?: '未填写'; ?></span>
                        </div>
                        <div class="info-item">
                            <strong>客户名称：</strong>
                            <span><?php echo $invoice['customer_name']; ?></span>
                        </div>
                        <div class="info-item">
                            <strong>联系方式：</strong>
                            <span><?php echo $invoice['customer_contact']; ?></span>
                        </div>
                        <div class="info-item">
                            <strong>发票类型：</strong>
                            <span><?php echo $invoice['invoice_type']; ?></span>
                        </div>
                        <div class="info-item">
                            <strong>总金额：</strong>
                            <span>¥<?php echo number_format($invoice['total_amount'], 2); ?></span>
                        </div>
                        <div class="info-item">
                            <strong>开票时间：</strong>
                            <span><?php echo date('Y-m-d H:i', strtotime($invoice['invoiced_at'])); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="current-file" style="display: <?php echo $invoice['invoice_file'] ?: "none"; ?>">
                    <h4>当前附件文件</h4>
                    <p><strong>文件名：</strong> <?php echo $invoice['invoice_file']; ?></p>
                    <p><strong>文件大小：</strong> 
                        <?php 
                        $file_path = 'uploads/invoices/' . $invoice['invoice_file'];
                        if (file_exists($file_path)) {
                            $file_size = filesize($file_path);
                            echo formatFileSize($file_size);
                        } else {
                            echo '文件不存在';
                        }
                        ?>
                    </p>
                    <p><strong>最后修改时间：</strong> 
                        <?php 
                        if (file_exists($file_path)) {
                            echo date('Y-m-d H:i', filemtime($file_path));
                        } else {
                            echo '-';
                        }
                        ?>
                    </p>
                    <a href="uploads/invoices/<?php echo $invoice['invoice_file']; ?>" class="btn btn-primary" target="_blank" download style="margin-top: 10px;">下载当前附件</a>
                </div>
                
                <div class="warning-box">
                    <h3>⚠️ 重要提示</h3>
                    <p>重新上传附件将：</p>
                     <ul style="padding: 20px;">
                        <li>备份当前附件文件到备份目录</li>
                        <li>替换为新的附件文件</li>
                        <li>更新开票时间为当前时间</li>
                        <li>在备注中记录重传操作</li>
                        <li>原文件将被保留在备份目录中</li>
                        <li>如果是PDF文件，系统会尝试自动识别发票号码</li>
                        <li>您可以同时更新发票编号（20位数字）</li>
                    </ul>
                    <p><strong>请确认新上传的文件是正确的！</strong></p>
                </div>
                
                <?php if (!$success): ?>
                <form method="POST" action="" enctype="multipart/form-data" id="reuploadForm">
                    <!-- 发票号码更新部分 -->
                    <div class="invoice-number-section">
                        <h4>📋 发票编号更新（可选）</h4>
                        <div class="form-group">
                            <div class="form-row">
                                <label for="slip_number">新发票编号：</label>
                                <div class="input-with-button">
                                    <input type="text" 
                                           id="slip_number" 
                                           name="slip_number" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars($invoice['slip_number'] ?? ''); ?>" 
                                           placeholder="20位数字，留空表示不修改"
                                           pattern="\d{20}"
                                           title="请输入20位数字的发票编号，留空表示不修改"
                                           style="flex: 1; font-size: larger; letter-spacing: 0.1rem;">
                                </div>
                            </div>
                            
                            <!-- 验证状态显示 -->
                            <div id="validationStatus" class="validation-status"></div>
                            
                            <p class="form-help">
                                <span style="color: #4a6cf7;">💡 提示：</span>
                                输入新的20位发票编号，或留空保持原编号不变。上传PDF文件后可点击"识别发票号码"自动提取。
                            </p>
                        </div>
                    </div>
                    
                    <!-- 文件上传部分 -->
                    <div class="form-section">
                        <h3>上传新附件文件</h3>
                        <div class="form-group">
                            <label for="invoice_file">选择新文件 *</label>
                            
                            <div class="file-upload-area" id="fileUploadArea">
                                <div class="upload-icon">📄</div>
                                <p>点击选择文件或将文件拖放到此处</p>
                                <p class="form-help">支持格式：PDF、JPG、JPEG、PNG，最大5MB</p>
                                <input type="file" 
                                       id="invoice_file" 
                                       name="invoice_file" 
                                       class="form-control" 
                                       accept=".pdf,.jpg,.jpeg,.png"
                                       required
                                       style="display: none;">
                                <div id="selectedFile" class="form-help" style="margin-top: 10px;"></div>
                            </div>
                        </div>
                        
                        <!-- 自动识别区域 -->
                        <div class="auto-detect-section" id="autoDetectSection">
                            <button type="button" class="detect-btn" id="detectBtn" onclick="autoDetectInvoiceNumber()">
                                识别发票号码
                            </button>
                            <div id="detectResult" class="detect-result"></div>
                        </div>
                        
                        <!-- 发票号码对比显示 -->
                        <div id="invoiceNumberCheck" class="comparison-info" style="display: none;">
                            <h4>📋 发票号码对比</h4>
                            <p>当前编号：<span class="current-number"><?php echo htmlspecialchars($invoice['slip_number'] ?: '未填写'); ?></span></p>
                            <p>识别到：<span class="new-number" id="detectedNumber"></span></p>
                            <p id="autoFillInfo" style="display: none; color: #28a745;">✅ 已自动填充到输入框</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="reason">重传原因（可选）：</label>
                            <textarea id="reason" name="reason" rows="3" placeholder="请说明重新上传附件的原因，例如：原文件损坏、需要更新版本等..."></textarea>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-info">确认重新上传</button>
                        <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" class="btn btn-secondary">取消</a>
                    </div>
                </form>
                <?php else: ?>
                <div class="success-info">
                    <h3>✅ 附件已成功重新上传</h3>
                    <p>新文件名：<strong><?php echo $invoice['invoice_file']; ?></strong></p>
                    <p>重传时间：<?php echo date('Y-m-d H:i:s'); ?></p>
                    <p>操作人：<?php echo $_SESSION['full_name']; ?></p>
                    
                    <?php if ($detected_invoice_number): ?>
                    <div class="invoice-number-section">
                        <h4>📋 发票号码信息</h4>
                        <p>新发票编号：<strong><?php echo htmlspecialchars($invoice['slip_number'] ?: '未填写'); ?></strong></p>
                        <?php if ($detected_invoice_number && $detected_invoice_number !== $invoice['slip_number']): ?>
                        <p>自动识别到：<span class="detected"><?php echo htmlspecialchars($detected_invoice_number); ?></span></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-actions">
                        <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" class="btn btn-primary">查看发票详情</a>
                        <a href="uploads/invoices/<?php echo $invoice['invoice_file']; ?>" 
                           class="btn btn-success" target="_blank" download>下载新附件</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 手机上传弹窗 -->
    <div id="mobileUploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📲 手机上传专票</h3>
                <button class="modal-close" onclick="closeMobileUploadModal()">&times;</button>
            </div>
            <div class="modal-body">
                <!-- 发票信息 -->
                <div class="invoice-info-modal">
                    <p><strong>客户名称：</strong><?php echo htmlspecialchars($invoice['customer_name']); ?></p>
                    <p><strong>发票号码：</strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                    <p><strong>发票类型：</strong><?php echo htmlspecialchars($invoice['invoice_type']); ?></p>
                    <p><strong>总金额：</strong>¥<?php echo number_format($invoice['total_amount'], 2); ?></p>
                    <p><strong>当前发票编号：</strong><?php echo htmlspecialchars($invoice['slip_number'] ?: '未填写'); ?></p>
                </div>
                
                <!-- 使用说明 -->
                <div>
                    <h4>使用说明：</h4>
                    <ol class="steps">
                        <li>使用手机扫描下方二维码</li>
                        <li>在手机端核对发票信息</li>
                        <li>拍摄新发票照片或选择相册中的发票照片</li>
                        <li>填写新的发票编号后提交</li>
                    </ol>
                    <p style="color: #666; font-size: 14px; margin-top: 10px;">
                        💡 提示：二维码有效期为5分钟，请及时使用
                        <br>
                        📝 注意：手机上传将<b>替换</b>原有发票附件
                    </p>
                </div>
                
                <!-- 二维码区域 -->
                <div class="qr-container">
                    <div id="qrcode">
                        <!-- 二维码将在这里生成 -->
                    </div>
                    <div id="qr-info" class="qr-info">
                        <!-- 二维码信息会动态显示在这里 -->
                    </div>
                    <div id="expiryTimer" class="expiry-timer"></div>
                </div>
                
                <!-- 操作按钮 -->
                <div style="text-align: center; margin-top: 20px;">
                    <button type="button" onclick="regenerateQRCode()" class="btn btn-primary" id="regenerateBtn">
                        重新生成二维码
                    </button>
                    <button type="button" onclick="closeMobileUploadModal()" class="btn btn-cancel" style="margin-left: 10px;">
                        关闭
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="js/qrcode.min.js"></script>
    <script>
        // 二维码相关数据
        const qrData = "<?php echo $qr_encoded; ?>";
        const qrUrl = "<?php echo $qr_url; ?>";
        let expiryTimerInterval = null;
        let isQRGenerated = false;
        
        // 弹窗控制
        function openMobileUploadModal() {
            document.getElementById('mobileUploadModal').style.display = 'flex';
            
            // 如果二维码还未生成，自动生成
            if (!isQRGenerated) {
                generateQRCode();
            }
            
            // 更新按钮状态
            updateMobileUploadButton();
        }
        
        function closeMobileUploadModal() {
            document.getElementById('mobileUploadModal').style.display = 'none';
            clearInterval(expiryTimerInterval);
        }
        
        // 点击弹窗外部关闭
        document.getElementById('mobileUploadModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeMobileUploadModal();
            }
        });
        
        // ESC键关闭弹窗
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeMobileUploadModal();
            }
        });
        
        // 生成二维码
        function generateQRCode() {
            const qrcodeDiv = document.getElementById('qrcode');
            const qrInfoDiv = document.getElementById('qr-info');
            const expiryTimerDiv = document.getElementById('expiryTimer');
            const regenerateBtn = document.getElementById('regenerateBtn');
            
            // 清空之前的内容
            qrcodeDiv.innerHTML = '';
            qrInfoDiv.innerHTML = '';
            expiryTimerDiv.innerHTML = '';
            clearInterval(expiryTimerInterval);
            
            // 显示加载状态
            qrcodeDiv.innerHTML = `
                <div style="text-align: center;">
                    <div class="loading-spinner"></div>
                    <div class="qr-loading">正在生成二维码...</div>
                </div>
            `;
            
            // 更新按钮状态
            regenerateBtn.disabled = true;
            regenerateBtn.innerHTML = '生成中...';
            
            // 短暂延迟确保用户看到加载状态
            setTimeout(() => {
                try {
                    // 清空并生成新的二维码
                    qrcodeDiv.innerHTML = '';
                    
                    new QRCode(qrcodeDiv, {
                        text: qrUrl,
                        width: 200,
                        height: 200,
                        colorDark: "#000000",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.H
                    });
                    
                    // 添加成功边框
                    qrcodeDiv.classList.add('qr-success');
                    
                    // 显示生成信息
                    const now = new Date();
                    const expiryTime = new Date(now.getTime() + 5 * 60000); // 5分钟后过期
                    
                    qrInfoDiv.innerHTML = `
                        <div style="color: #28a745; font-weight: bold; margin-bottom: 5px;">✅ 二维码已生成</div>
                        <div>生成时间: ${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}:${now.getSeconds().toString().padStart(2, '0')}</div>
                        <div>有效时间: 5分钟</div>
                    `;
                    
                    // 启动倒计时
                    startExpiryTimer(expiryTime);
                    
                    // 更新按钮状态
                    regenerateBtn.disabled = false;
                    regenerateBtn.innerHTML = '重新生成二维码';
                    
                    // 标记为已生成
                    isQRGenerated = true;
                    
                } catch (error) {
                    qrcodeDiv.innerHTML = `
                        <div style="text-align: center; color: #dc3545; padding: 20px;">
                            <div style="font-size: 48px;">❌</div>
                            <div>生成二维码失败</div>
                            <div style="font-size: 12px; margin-top: 10px;">${error.message}</div>
                        </div>
                    `;
                    
                    regenerateBtn.disabled = false;
                    regenerateBtn.innerHTML = '重新生成二维码';
                }
            }, 500);
        }
        
        // 重新生成二维码
        function regenerateQRCode() {
            generateQRCode();
        }
        
        // 倒计时函数
        function startExpiryTimer(expiryTime) {
            clearInterval(expiryTimerInterval);
            
            function updateTimer() {
                const now = new Date();
                const timeLeft = expiryTime - now;
                
                if (timeLeft <= 0) {
                    document.getElementById('expiryTimer').innerHTML = '⏰ 二维码已过期，请重新生成';
                    clearInterval(expiryTimerInterval);
                    
                    // 更新二维码状态
                    document.getElementById('qrcode').classList.remove('qr-success');
                    isQRGenerated = false;
                    return;
                }
                
                const minutes = Math.floor(timeLeft / 60000);
                const seconds = Math.floor((timeLeft % 60000) / 1000);
                
                document.getElementById('expiryTimer').innerHTML = 
                    `⏳ 剩余时间: ${minutes}分${seconds.toString().padStart(2, '0')}秒`;
            }
            
            updateTimer();
            expiryTimerInterval = setInterval(updateTimer, 1000);
        }
        
        // 更新手机上传按钮状态
        function updateMobileUploadButton() {
            const btn = document.getElementById('mobileUploadBtn');
            if (isQRGenerated) {
                btn.innerHTML = '<span style="font-size: 16px;">✅</span> 二维码已生成';
                btn.style.background = 'linear-gradient(135deg, #28a745, #20c997)';
            } else {
                btn.innerHTML = '<span style="font-size: 16px;">📲</span> 手机上传专票';
                btn.style.background = 'linear-gradient(135deg, #4a6cf7, #6a82fb)';
            }
        }
        
        // 文件上传区域交互
        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileInput = document.getElementById('invoice_file');
        const selectedFileDiv = document.getElementById('selectedFile');
        const autoDetectSection = document.getElementById('autoDetectSection');
        const detectBtn = document.getElementById('detectBtn');
        const invoiceNumberCheck = document.getElementById('invoiceNumberCheck');
        const detectedNumberSpan = document.getElementById('detectedNumber');
        const autoFillInfo = document.getElementById('autoFillInfo');
        const slipNumberInput = document.getElementById('slip_number');
        const validationStatus = document.getElementById('validationStatus');
        
        // 全局变量
        let detectedInvoiceNumber = '';
        const currentInvoiceNumber = "<?php echo addslashes($invoice['slip_number'] ?: ''); ?>";
        
        // 点击上传区域触发文件选择
        fileUploadArea.addEventListener('click', () => {
            fileInput.click();
        });
        
        // 拖放功能
        fileUploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            fileUploadArea.classList.add('dragover');
        });
        
        fileUploadArea.addEventListener('dragleave', (e) => {
            e.preventDefault();
            fileUploadArea.classList.remove('dragover');
        });
        
        fileUploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            fileUploadArea.classList.remove('dragover');
            
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                handleFileSelect();
            }
        });
        
        // 文件选择处理
        fileInput.addEventListener('change', handleFileSelect);
        
        function handleFileSelect() {
            if (fileInput.files.length > 0) {
                const file = fileInput.files[0];
                const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                
                selectedFileDiv.innerHTML = `
                    <strong>已选择文件：</strong> ${file.name} 
                    <span style="color: #666;">(${fileSizeMB} MB)</span>
                    <br>
                    <span style="color: #28a745;">✓ 文件已准备就绪</span>
                `;
                
                // 如果是PDF文件，显示自动识别区域
                if (file.type === 'application/pdf') {
                    autoDetectSection.classList.add('show');
                    detectBtn.disabled = false;
                    showDetectResult('点击"识别发票号码"按钮开始自动识别', 'info');
                    
                    // 隐藏对比区域
                    invoiceNumberCheck.style.display = 'none';
                    autoFillInfo.style.display = 'none';
                } else {
                    autoDetectSection.classList.remove('show');
                    hideDetectResult();
                    invoiceNumberCheck.style.display = 'none';
                    autoFillInfo.style.display = 'none';
                }
            } else {
                selectedFileDiv.innerHTML = '';
                autoDetectSection.classList.remove('show');
                hideDetectResult();
                invoiceNumberCheck.style.display = 'none';
                autoFillInfo.style.display = 'none';
            }
        }
        
        // 自动识别发票号码
        function autoDetectInvoiceNumber() {
            if (!fileInput.files.length) {
                showDetectResult('请先选择PDF文件', 'error');
                return;
            }
            
            const file = fileInput.files[0];
            if (file.type !== 'application/pdf') {
                showDetectResult('请选择PDF格式的文件', 'error');
                return;
            }
            
            // 禁用按钮，显示加载中
            detectBtn.disabled = true;
            detectBtn.innerHTML = '<span class="spinner"></span> 识别中...';
            
            // 创建FormData
            const formData = new FormData();
            formData.append('pdf', file);
            formData.append('action', 'detect_invoice_number');
            
            // 发送AJAX请求
            fetch('api/detect_invoice.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.invoice_number) {
                    detectedInvoiceNumber = data.invoice_number;
                    showDetectResult(`✅ 成功识别到发票号码`, 'success');
                    
                    // 显示发票号码对比
                    showInvoiceNumberComparison();
                    
                    // 自动填充到输入框（如果输入框为空）
                    if (!slipNumberInput.value.trim()) {
                        slipNumberInput.value = detectedInvoiceNumber;
                        slipNumberInput.dispatchEvent(new Event('input'));
                        showAutoFillInfo();
                    }
                    
                    // 3秒后隐藏成功提示
                    setTimeout(() => {
                        hideDetectResult();
                    }, 3000);
                } else {
                    showDetectResult(data.message || '未识别到发票号码', 'error');
                }
            })
            .catch(error => {
                showDetectResult('识别失败: ' + error.message, 'error');
            })
            .finally(() => {
                detectBtn.disabled = false;
                detectBtn.innerHTML = '识别发票号码';
            });
        }
        
        // 显示发票号码对比
        function showInvoiceNumberComparison() {
            if (!detectedInvoiceNumber) return;
            
            detectedNumberSpan.textContent = detectedInvoiceNumber;
            invoiceNumberCheck.style.display = 'block';
            
            // 如果识别到的号码与当前号码不同，高亮显示
            if (currentInvoiceNumber && detectedInvoiceNumber !== currentInvoiceNumber) {
                invoiceNumberCheck.style.backgroundColor = '#fff5f5';
                invoiceNumberCheck.style.borderColor = '#f8d7da';
            }
        }
        
        // 显示自动填充信息
        function showAutoFillInfo() {
            autoFillInfo.style.display = 'block';
            
            // 5秒后隐藏
            setTimeout(() => {
                autoFillInfo.style.display = 'none';
            }, 5000);
        }
        
        // 显示识别结果
        function showDetectResult(message, type = 'info') {
            const resultDiv = document.getElementById('detectResult');
            resultDiv.innerHTML = message;
            resultDiv.className = `detect-result detect-${type}`;
        }
        
        // 隐藏识别结果
        function hideDetectResult() {
            const resultDiv = document.getElementById('detectResult');
            resultDiv.style.display = 'none';
            resultDiv.className = 'detect-result';
        }
        
        // 输入框实时验证
        slipNumberInput.addEventListener('input', function() {
            const value = this.value.trim();
            
            // 如果输入框为空，不验证
            if (!value) {
                this.style.borderColor = '#ced4da';
                this.style.backgroundColor = '';
                validationStatus.innerHTML = '<span class="valid-status">留空表示不修改发票编号</span>';
                return;
            }
            
            const isValid = value.length === 20 && /^\d+$/.test(value);
            
            // 更新输入框样式
            this.style.borderColor = isValid ? '#28a745' : '#dc3545';
            this.style.backgroundColor = isValid ? '#f8fff8' : '#fff8f8';
            
            // 更新验证状态显示
            updateValidationStatus(value, isValid);
        });
        
        // 更新验证状态显示
        function updateValidationStatus(value, isValid) {
            if (!value) {
                validationStatus.innerHTML = '<span class="valid-status">留空表示不修改发票编号</span>';
                return;
            }
            
            if (isValid) {
                validationStatus.innerHTML = `
                    <span class="valid-status">✓ 格式正确 (20位数字)</span>
                `;
            } else {
                if (value.length !== 20) {
                    validationStatus.innerHTML = `
                        <span class="invalid-status">✗ 长度应为20位，当前${value.length}位</span>
                    `;
                } else if (!/^\d+$/.test(value)) {
                    validationStatus.innerHTML = `
                        <span class="invalid-status">✗ 只能包含数字</span>
                    `;
                }
            }
        }
        
        // 表单验证
        document.getElementById('reuploadForm')?.addEventListener('submit', function(e) {
            // 检查文件是否选择
            if (!fileInput || !fileInput.files.length) {
                alert('请选择要上传的文件');
                e.preventDefault();
                return false;
            }
            
            const file = fileInput.files[0];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (file.size > maxSize) {
                alert('文件大小不能超过5MB');
                e.preventDefault();
                return false;
            }
            
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
            if (!allowedTypes.includes(file.type)) {
                alert('只允许上传PDF、JPEG、PNG格式的文件');
                e.preventDefault();
                return false;
            }
            
            // 验证发票号码（如果填写了）
            const slipNumber = slipNumberInput.value.trim();
            if (slipNumber && (slipNumber.length !== 20 || !/^\d+$/.test(slipNumber))) {
                alert('发票编号必须是20位数字，或留空不修改');
                e.preventDefault();
                return false;
            }
            
            // 显示确认对话框
            let confirmMessage = '确定要重新上传附件吗？\n\n原文件将被备份到备份目录。';
            
            if (slipNumber && slipNumber !== currentInvoiceNumber) {
                confirmMessage += `\n\n发票编号将更新为：${slipNumber}`;
            }
            
            if (detectedInvoiceNumber && slipNumber && detectedInvoiceNumber !== slipNumber) {
                confirmMessage += `\n\n注意：识别到发票号码 ${detectedInvoiceNumber}，但您输入的是 ${slipNumber}，以输入为准。`;
            }
            
            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // 页面加载完成后的初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 如果已有发票编号，验证格式
            if (slipNumberInput.value.trim()) {
                slipNumberInput.dispatchEvent(new Event('input'));
            }
            
            // 添加加载动画样式
            const style = document.createElement('style');
            style.textContent = `
                .spinner {
                    display: inline-block;
                    width: 12px;
                    height: 12px;
                    border: 2px solid rgba(255,255,255,.3);
                    border-radius: 50%;
                    border-top-color: #fff;
                    animation: spin 1s ease-in-out infinite;
                    margin-right: 5px;
                    vertical-align: middle;
                }
                
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
                
                .detect-btn .spinner {
                    border: 2px solid rgba(255,255,255,.3);
                    border-top-color: #fff;
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>