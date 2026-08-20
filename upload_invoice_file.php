<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

checkRole(['finance']);

$invoice_id = $_GET['id'] ?? 0;
$invoice = getInvoiceById($invoice_id);
$error = '';
$success = '';
$auto_detected_slip = ''; // 用于存储自动识别的发票号码

if (!$invoice) {
    header('Location: invoices.php');
    exit;
}

// ================================
// PDF解析函数（用于自动识别发票号码）
// ================================
function extractInvoiceNumberFromPDF($pdfFilePath) {
    // 开启输出缓冲，防止解析器输出任何内容到屏幕
    ob_start();
    try {
        // 引入PDF解析器
        $pdfParserPath = __DIR__ . '/pdfparser-2.12.2/alt_autoload.php';
        if (!file_exists($pdfParserPath)) {
            // 清理缓冲区
            ob_end_clean();
            return null; 
        }
        
        require_once $pdfParserPath;
        
        // 使用PDF解析器
        $parser = new Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($pdfFilePath);
        $text = $pdf->getText();
        
        // 清理缓冲区（丢弃任何输出）
        ob_end_clean();
        
        // 优先匹配"发票号码"
        if (preg_match('/发票号码[:：]?\s*(\d{8,20})/u', $text, $m)) {
            return $m[1];
        }
        // 兜底：20位数字
        elseif (preg_match('/\b\d{20}\b/', $text, $m)) {
            return $m[0];
        }
        
        return null;
        
    } catch (Throwable $e) {
        // 清理缓冲区
        ob_end_clean();
        // 记录错误但不影响主流程
        error_log("PDF解析失败: " . $e->getMessage());
        return null;
    }
}

// ================================
// 处理表单提交
// ================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDbConnection();
        $filename = $invoice['invoice_file']; // 保持原有文件名
        
        // 验证 slip_number
        $slip_number = trim($_POST['slip_number'] ?? '');
        if (empty($slip_number)) {
            throw new Exception('发票编号不能为空');
        }
        
        // 验证 slip_number 格式（20位数字）
        if (strlen($slip_number) !== 20 || !ctype_digit($slip_number)) {
            throw new Exception('发票编号必须是20位数字');
        }
        
        // 检查 slip_number 是否已存在（排除当前发票）
        $check_sql = "SELECT id FROM invoices WHERE slip_number = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('si', $slip_number, $invoice_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            throw new Exception('发票编号已存在，请使用其他编号');
        }
        
        // 如果有文件上传
        $hasNewFile = false;
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['invoice_file'];
            $hasNewFile = true;
            
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
            
            // 生成唯一文件名
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'invoice_' . $invoice['invoice_number'] . '_' . time() . '.' . $extension;
            $filepath = $uploadDir . $filename;
            
            // 移动文件
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new Exception('文件上传失败');
            }
            
            // 如果是PDF文件，尝试自动识别发票号码
            if ($file['type'] === 'application/pdf') {
                $auto_detected = extractInvoiceNumberFromPDF($filepath);
                if ($auto_detected && $auto_detected === $slip_number) {
                    $success .= ' (系统已自动验证发票号码)';
                } elseif ($auto_detected) {
                    // 如果识别出的号码与输入的号码不一致，可以在这里处理
                    // 例如：记录日志或给出提示
                }
            }
        }
        
        // 更新数据库 - 添加 slip_number
        $stmt = $conn->prepare("UPDATE invoices SET 
                                finance_status = 'invoiced', 
                                invoiced_at = NOW(), 
                                slip_number = ?,
                                invoice_file = IF(?, ?, invoice_file) 
                                WHERE id = ?");
        
        $stmt->bind_param("sssi", $slip_number, $hasNewFile, $filename, $invoice_id);
        
        if (!$stmt->execute()) {
            throw new Exception('更新失败: ' . $stmt->error);
        }
        
        // 发送 Webhook 通知
        sendWebhook('invoice_audited', [
            'invoice_number' => $invoice['invoice_number'],
            'customer_name' => $invoice['customer_name'],
            'total_amount' => $invoice['total_amount'],
            'slip_number' => $slip_number
        ]);
        
        $success = '发票标记为已开票成功！' . ($success ?? '');
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'];

// 获取当前页面的基础路径（去掉文件名）
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
    'key' => md5(
        $invoice['invoice_number'] .
        $invoice['customer_name'] .
        'ljsslipmanagement' .
        $timestamp
    )
];

$qr_encoded = base64_encode(json_encode($qr_data, JSON_UNESCAPED_UNICODE));
$qr_url = rtrim($base_url, '/') . '/mobile_upload.php?data=' . urlencode($qr_encoded);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>上传发票文件</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* 页面标题区域样式 */
        .page-header {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-title {
            margin: 0;
            min-width: 200px;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
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
            min-height: 260px;
            min-width: 260px;
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
        
        .expiry-timer {
            color: #dc3545;
            font-weight: bold;
            margin-top: 10px;
            padding: 5px 10px;
            background: #fff5f5;
            border-radius: 4px;
            display: inline-block;
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
        
        /* 二维码生成成功样式 */
        .qr-success {
            border: 2px solid #28a745;
            padding: 15px;
            background: #f8fff8;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'includes/header.php'; ?>
        
        <div class="main-content">
            <!-- 修改页面标题区域 -->
            <div class="page-header">
                <h1 class="page-title">标记发票为已开票</h1>
                <div class="header-actions">
                    <button type="button" class="btn-mobile-upload" onclick="openMobileUploadModal()" id="mobileUploadBtn">
                        <span style="font-size: 16px;">📲</span> 手机上传专票
                    </button>
                </div>
            </div>
            
            <div class="invoice-info">
                <h3>发票信息</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <strong>客户名称：</strong>
                        <span><?php echo htmlspecialchars($invoice['customer_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>税号：</strong>
                        <span><?php echo htmlspecialchars($invoice['tax_number']); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>总金额：</strong>
                        <span class="amount">¥<?php echo number_format($invoice['total_amount'], 2); ?></span>
                    </div>
                    <div class="info-item">
                        <strong>发票类型：</strong>
                        <span><?php echo htmlspecialchars($invoice['invoice_type']); ?></span>
                    </div>
                </div>
            </div>
            
            <?php if ($error): ?>
            <div class="alert error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert success">
                <?php echo $success; ?>
                <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" style="margin-left: 20px;" class="btn">查看发票</a>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data" class="upload-form" id="uploadForm">
                
                <!-- 文件上传部分 -->
                <div class="form-section">
                    <h3>电子发票文件（可选）</h3>
                    <div class="form-group">
                        <label for="invoice_file">选择电子发票文件</label>
                        
                        <div class="file-upload-area" id="fileUploadArea">
                            <div class="upload-icon">📄</div>
                            <p>点击选择文件或将文件拖放到此处</p>
                            <p class="form-help">支持格式：PDF、JPG、JPEG、PNG，最大5MB</p>
                            <input type="file" 
                                   id="invoice_file" 
                                   name="invoice_file" 
                                   class="form-control" 
                                   accept=".pdf,.jpg,.jpeg,.png"
                                   style="display: none;">
                            <div id="selectedFile" class="form-help" style="margin-top: 10px;"></div>
                        </div>
                        
                        <?php if (!empty($invoice['invoice_file'])): ?>
                        <div class="current-file">
                            <strong>已上传文件：</strong> 
                            <a href="uploads/invoices/<?php echo htmlspecialchars($invoice['invoice_file']); ?>" 
                               target="_blank" 
                               style="color: #4a6cf7; text-decoration: none;">
                                <?php echo htmlspecialchars($invoice['invoice_file']); ?>
                            </a>
                            <p class="form-help" style="margin-top: 5px;">重新上传将覆盖原文件</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 自动识别区域 -->
                <div class="auto-detect-section" id="autoDetectSection">
                    <button type="button" class="detect-btn" id="detectBtn" onclick="autoDetectInvoiceNumber()">
                        识别发票号码
                    </button>
                    <div id="detectResult" class="detect-result"></div>
                </div>
                
                <!-- 发票编号输入部分 -->
                <div class="form-section">
                    <h3>发票编号信息</h3>
                    <div class="form-group">
                        <div class="form-row">
                            <label for="slip_number" class="required">发票编号:</label>
                            <input type="text" 
                                   id="slip_number" 
                                   name="slip_number" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($invoice['slip_number'] ?? ''); ?>" 
                                   placeholder="请输入20位发票编号"
                                   required
                                   pattern="\d{20}"
                                   title="请输入20位数字的发票编号"
                                   style="flex: 1; font-size: larger; letter-spacing: 0.1rem;">
                        </div>
                        
                        <!-- 验证状态显示 -->
                        <div id="validationStatus" class="validation-status"></div>
                        
                        <p class="form-help">
                            <span style="color: #4a6cf7;">💡 提示：</span>
                            上传PDF文件后点击"识别发票号码"可自动提取
                        </p>
                    </div>
                    
                    <div class="form-group"  style="display:none;">
                        <label>当前状态：</label>
                        <span class="status <?php echo $invoice['finance_status']; ?>">
                            <?php echo $invoice['finance_status'] === 'pending' ? '待开票' : '已开票'; ?>
                        </span>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">标记为已开票</button>
                    <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" class="btn btn-cancel">返回</a>
                </div>
            </form>
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
                </div>
                
                <!-- 使用说明 -->
                <div>
                    <h4>使用说明：</h4>
                    <ol class="steps">
                        <li>使用手机扫描下方二维码</li>
                        <li>拍照并上传照片和发票编号</li>
                    </ol>
                    <p style="color: #666; font-size: 14px; margin-top: 10px;">
                        💡 提示：二维码有效期为5分钟，请及时使用
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
        const slipNumberInput = document.getElementById('slip_number');
        const validationStatus = document.getElementById('validationStatus');
        
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
                } else {
                    autoDetectSection.classList.remove('show');
                    hideDetectResult();
                }
            } else {
                selectedFileDiv.innerHTML = '';
                autoDetectSection.classList.remove('show');
                hideDetectResult();
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
                    const detectedNumber = data.invoice_number;
                    showDetectResult(`✅ 成功识别到发票号码`, 'success');
                    
                    // 自动填充到输入框
                    slipNumberInput.value = detectedNumber;
                    slipNumberInput.dispatchEvent(new Event('input'));
                    
                    // 显示自动填充提示
                    showAutoFillNotice(detectedNumber);
                    
                    // 3秒后隐藏成功提示
                    setTimeout(() => {
                        hideDetectResult();
                    }, 3000);
                } else {
                    showDetectResult(data.message || '未识别到发票号码，请手动输入', 'error');
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
        
        // 显示自动填充提示
        function showAutoFillNotice(invoiceNumber) {
            if (validationStatus.querySelector('.auto-fill-notice')) {
                validationStatus.querySelector('.auto-fill-notice').remove();
            }
            
            const notice = document.createElement('span');
            notice.className = 'auto-fill-notice';
            notice.innerHTML = `已自动填充: ${invoiceNumber}`;
            validationStatus.appendChild(notice);
            
            // 5秒后移除提示
            setTimeout(() => {
                if (notice.parentNode) {
                    notice.remove();
                }
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
            const isValid = value.length === 20 && /^\d+$/.test(value);
            
            // 更新输入框样式
            if (value) {
                this.style.borderColor = isValid ? '#28a745' : '#dc3545';
                this.style.backgroundColor = isValid ? '#f8fff8' : '#fff8f8';
            } else {
                this.style.borderColor = '#ced4da';
                this.style.backgroundColor = '';
            }
            
            // 更新验证状态显示
            updateValidationStatus(value, isValid);
        });
        
        // 更新验证状态显示
        function updateValidationStatus(value, isValid) {
            if (!value) {
                validationStatus.innerHTML = '';
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
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const slipNumber = slipNumberInput.value.trim();
            
            if (!slipNumber) {
                alert('请输入发票编号');
                e.preventDefault();
                return false;
            }
            
            // 检查是否为20位数字
            if (slipNumber.length !== 20 || !/^\d+$/.test(slipNumber)) {
                alert('发票编号必须是20位数字');
                e.preventDefault();
                return false;
            }
            
            // 检查文件大小
            if (fileInput.files.length > 0) {
                const file = fileInput.files[0];
                const maxSize = 5 * 1024 * 1024; // 5MB
                
                if (file.size > maxSize) {
                    alert('文件大小不能超过5MB');
                    e.preventDefault();
                    return false;
                }
            }
            
            // 显示提交中状态
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> 提交中...';
            
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