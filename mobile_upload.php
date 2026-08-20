<?php
// mobile_upload.php
// 这个页面专门用于手机端上传

// 允许跨域访问（手机端需要）
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

// 设置移动端响应
header("Content-Type: text/html; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 获取二维码数据
$encoded_data = $_GET['data'] ?? '';
if (empty($encoded_data)) {
    die('请使用有效的二维码访问');
}

// 解码数据
$json_data = base64_decode($encoded_data);
$qr_data = json_decode($json_data, true);

if (!$qr_data || !isset($qr_data['invoice_number'])) {
    die('二维码数据无效');
}

$expected_key = md5(
    $qr_data['invoice_number'] .
    $qr_data['customer_name'] .
    'ljsslipmanagement' .
    $qr_data['timestamp']
);

if (!isset($qr_data['key']) || !hash_equals($expected_key, $qr_data['key'])) {
    die('二维码验证失败');
}


// 检查二维码是否过期（5分钟内有效）
$qr_timestamp = $qr_data['timestamp'] ?? 0;
$current_time = time();
$time_diff = $current_time - $qr_timestamp;

if ($time_diff > 300) { // 5分钟 = 300秒
    die('二维码已过期，请重新扫描');
}

$invoice_info = $qr_data;
$error = '';
$success = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 连接数据库
        require_once 'config/database.php';
        $conn = getDbConnection();
        
        // 再次验证二维码是否过期
        if ($time_diff > 300) {
            throw new Exception('二维码已过期，请重新扫描');
        }
        
        // 获取表单数据
        $slip_number = trim($_POST['slip_number'] ?? '');
        $image_base64 = $_POST['image_base64'] ?? '';
        $invoice_id = intval($invoice_info['invoice_id'] ?? 0);
        
        // 验证发票编号
        if (empty($slip_number)) {
            throw new Exception('请填写发票编号');
        }
        
        if (strlen($slip_number) !== 20 || !ctype_digit($slip_number)) {
            throw new Exception('发票编号必须是20位数字');
        }
        
        // 检查发票编号是否重复
        $check_sql = "SELECT id FROM invoices WHERE slip_number = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('s', $slip_number);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            throw new Exception('发票编号已存在，请使用其他编号');
        }
        
        // 验证Base64图片
        if (empty($image_base64)) {
            throw new Exception('请拍摄发票照片');
        }
        
        // 检查图片格式
        if (!preg_match('/^data:image\/(jpeg|png|jpg);base64,/', $image_base64)) {
            throw new Exception('只支持JPEG、PNG格式的图片');
        }
        
        // 获取发票信息进行二次验证
        $invoice_sql = "SELECT id, invoice_number, customer_name, tax_number, finance_status 
                       FROM invoices WHERE id = ?";
        $invoice_stmt = $conn->prepare($invoice_sql);
        $invoice_stmt->bind_param('i', $invoice_id);
        $invoice_stmt->execute();
        $invoice_result = $invoice_stmt->get_result();
        $db_invoice = $invoice_result->fetch_assoc();
        
        if (!$db_invoice) {
            throw new Exception('发票不存在');
        }
        
        // 验证发票信息是否匹配
        if ($db_invoice['invoice_number'] !== $invoice_info['invoice_number'] ||
            $db_invoice['customer_name'] !== $invoice_info['customer_name'] ||
            $db_invoice['tax_number'] !== $invoice_info['tax_number']) {
            throw new Exception('发票信息不匹配，请重新扫描');
        }
        
        // 检查发票状态
        if ($db_invoice['finance_status'] === 'invoiced') {
            throw new Exception('该发票已开票，无需重复操作');
        }
        
        // 处理Base64图片
        $image_data = str_replace(['data:image/jpeg;base64,', 'data:image/png;base64,', 'data:image/jpg;base64,'], '', $image_base64);
        $image_data = str_replace(' ', '+', $image_data);
        $image_data = base64_decode($image_data);
        
        // 检查图片大小（最大5MB）
        if (strlen($image_data) > 5 * 1024 * 1024) {
            throw new Exception('图片大小不能超过5MB');
        }
        
        // 创建上传目录
        $upload_dir = 'uploads/invoices/mobile/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // 生成文件名
        $extension = 'jpg'; // 默认为jpg
        if (strpos($image_base64, 'image/png') !== false) {
            $extension = 'png';
        }
        
        $filename = 'mobile_' . $invoice_info['invoice_number'] . '_' . time() . '.' . $extension;
        $filepath = $upload_dir . $filename;
        
        // 保存图片
        if (!file_put_contents($filepath, $image_data)) {
            throw new Exception('图片保存失败');
        }
        
        // 更新数据库
        $update_sql = "UPDATE invoices SET 
                      finance_status = 'invoiced', 
                      invoiced_at = NOW(), 
                      slip_number = ?,
                      invoice_file = ?,
                      remarks = CONCAT(IFNULL(remarks, ''), '\n【手机上传】时间：', NOW(), '，来源：二维码扫描')
                      WHERE id = ?";
        
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param('ssi', $slip_number, $filename, $invoice_id);
        
        if ($update_stmt->execute()) {
            $success = '发票上传成功！';
        } else {
            throw new Exception('更新失败: ' . $conn->error);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>手机上传发票</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 16px;
            color: #333;
            line-height: 1.5;
        }
        
        .container {
            max-width: 500px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 16px;
            padding: 12px 0;
			display: none;
        }
        
        .header h1 {
            font-size: 20px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .header-subtitle {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 6px;
        }
        
        .time-info {
            font-size: 12px;
            background: rgba(255,255,255,0.15);
            padding: 4px 10px;
            border-radius: 12px;
            display: inline-block;
        }
        
        .countdown {
            font-weight: bold;
            color: #ffd700;
            margin-left: 4px;
        }
        
        /* 简化版发票信息样式 */
        .invoice-info-compact {
            background: rgba(255,255,255,0.95);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .invoice-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 8px;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .invoice-item:last-child {
            margin-bottom: 0;
        }
        
        .invoice-label {
            color: #4a6cf7;
            font-weight: 500;
            min-width: 65px;
            flex-shrink: 0;
        }
        
        .invoice-value {
            flex: 1;
            word-break: break-all;
            color: #333;
        }
        
        .status-badge {
            display: inline-block;
            font-size: 11px;
            padding: 2px 8px;
            background: #28a745;
            color: white;
            border-radius: 10px;
            margin-left: 8px;
        }
        
        /* 卡片样式保持 */
        .card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .card-title {
            color: #4a6cf7;
            margin-bottom: 12px;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .card-title:before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 16px;
            background: #4a6cf7;
            border-radius: 2px;
            margin-right: 8px;
        }
        
        /* 拍照区域优化 */
        .camera-area {
            text-align: center;
            margin: 0;
        }
        
        .camera-preview {
            width: 100%;
            height: 200px; /* 适当降低高度 */
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 12px;
            overflow: hidden;
            position: relative;
            border: 2px dashed #dee2e6;
        }
        
        .camera-preview.has-image {
            border-color: #28a745;
            border-style: solid;
        }
        
        .camera-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
        }
        
        .camera-placeholder {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #adb5bd;
            text-align: center;
            width: 100%;
            padding: 0 16px;
        }
        
        .camera-icon {
            font-size: 36px;
            margin-bottom: 8px;
            opacity: 0.6;
        }
        
        .camera-text {
            font-size: 14px;
            line-height: 1.4;
        }
        
        /* 二维码扫描区域 */
        .qr-scan-section {
            margin-top: 12px;
            text-align: center;
        }
        
        .qr-scan-btn {
            background: #20c997;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            transition: all 0.2s;
        }
        
        .qr-scan-btn:before {
            content: '🔍';
            font-size: 16px;
        }
        
        .qr-scan-btn:hover {
            background: #1ba87e;
            transform: translateY(-1px);
        }
        
        .qr-scan-btn:disabled {
            background: #adb5bd;
            cursor: not-allowed;
            transform: none;
        }
        
        .camera-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .camera-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .camera-btn:before {
            font-size: 16px;
        }
        
        .btn-take {
            background: #4a6cf7;
            color: white;
        }
        
        .btn-take:before {
            content: '📸';
        }
        
        .btn-upload {
            background: #e9ecef;
            color: #495057;
            border: 1px solid #dee2e6;
        }
        
        .btn-upload:before {
            content: '📁';
        }
        
        .camera-btn:hover {
            transform: translateY(-1px);
        }
        
        .camera-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        /* 发票编号输入优化 */
        .slip-input-group {
            position: relative;
            margin-top: 8px;
        }
        
        .slip-input {
            width: 100%;
            padding: 14px 50px 14px 14px; /* 右边留出空间给扫描按钮 */
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 16px;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
            transition: all 0.2s;
        }
        
        .slip-input:focus {
            border-color: #4a6cf7;
            outline: none;
            box-shadow: 0 0 0 3px rgba(74, 108, 247, 0.1);
        }
        
        .slip-input.valid {
            border-color: #28a745;
            background-color: #f8fff9;
        }
        
        .slip-input.invalid {
            border-color: #dc3545;
            background-color: #fff8f8;
        }
        
        .scan-input-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: #20c997;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .scan-input-btn:before {
            content: '🔍';
        }
        
        .scan-input-btn:disabled {
            background: #adb5bd;
            cursor: not-allowed;
        }
        
        .validation-msg {
            font-size: 12px;
            margin-top: 6px;
            padding: 4px 8px;
            border-radius: 4px;
            display: none;
        }
        
        .valid-msg {
            background: #d4edda;
            color: #155724;
            display: block;
        }
        
        .invalid-msg {
            background: #f8d7da;
            color: #721c24;
            display: block;
        }
        
        /* 二维码扫描器样式 */
        .qr-scanner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 1000;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .scanner-container {
            width: 90%;
            max-width: 400px;
            background: black;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        
        #reader {
            width: 100%;
            height: 300px;
        }
        
        .scanner-instruction {
            color: white;
            text-align: center;
            margin-top: 20px;
            padding: 0 20px;
        }
        
        .scanner-controls {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-scanner-close {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        /* 提交按钮优化 */
        .form-actions {
            margin-top: 20px;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-submit:before {
            content: '📤';
            font-size: 18px;
        }
        
        .btn-submit:disabled {
            background: #adb5bd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-submit:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.3);
        }
        
        /* 消息提示优化 */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
            text-align: center;
        }
        
        .alert-error {
            background: #fff5f5;
            color: #c92a2a;
            border: 1px solid #ffc9c9;
        }
        
        .alert-success {
            background: #ebfbee;
            color: #2b8a3e;
            border: 1px solid #b2f2bb;
        }
        
        .alert-success:before {
            content: '✅ ';
        }
        
        .alert-error:before {
            content: '❌ ';
        }
        
        /* 响应式调整 */
        @media (max-width: 375px) {
            .container {
                padding: 12px;
            }
            
            .camera-preview {
                height: 180px;
            }
            
            .invoice-item {
                font-size: 13px;
            }
        }
        
        /* 成功页面优化 */
        .success-content {
            text-align: center;
            padding: 40px 20px;
        }
        
        .success-icon {
            font-size: 48px;
            margin-bottom: 20px;
            animation: bounce 0.5s;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
    </style>
    <!-- 引入html5-qrcode库 -->
    <script src="js/html5-qrcode.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📱 上传发票</h1>
            <div class="header-subtitle">核对信息后拍照上传</div>
            <div class="time-info">
                生成时间: <?php echo date('H:i:s', $qr_timestamp); ?>
                <span class="countdown" id="countdown">5:00</span>
            </div>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="card">
            <div class="success-content">
                <div class="success-icon">✅</div>
                <h2 style="color: #28a745; margin-bottom: 10px;">上传成功</h2>
                <p><?php echo $success; ?></p>
                <p style="margin-top: 15px; color: #666; font-size: 14px;">3秒后自动关闭...</p>
            </div>
        </div>
        <script>
            setTimeout(() => {
                if (window.close) window.close();
                else window.history.back();
            }, 3000);
        </script>
        <?php else: ?>
        
        <!-- 简化版发票信息 -->
        <div class="invoice-info-compact">
            <div class="invoice-item">
                <span class="invoice-label">系统编号</span>
                <span class="invoice-value"><?php echo htmlspecialchars($invoice_info['invoice_number']); ?></span>
                <span class="status-badge">已验证</span>
            </div>
            <div class="invoice-item">
                <span class="invoice-label">客户名称</span>
                <span class="invoice-value"><?php echo htmlspecialchars($invoice_info['customer_name']); ?></span>
            </div>
            <?php if (!empty($invoice_info['tax_number'])): ?>
            <div class="invoice-item">
                <span class="invoice-label">税号</span>
                <span class="invoice-value"><?php echo htmlspecialchars($invoice_info['tax_number']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <form id="uploadForm" method="POST" action="">
            <!-- 拍照区域 -->
            <div class="card">
                <div class="card-title">拍摄发票照片</div>
                <div class="camera-area">
                    <div class="camera-preview" id="cameraPreview">
                        <div class="camera-placeholder">
                            <div class="camera-icon">📷</div>
                            <div class="camera-text">点击下方按钮拍照<br><small>或从相册选择</small></div>
                        </div>
                        <img id="previewImage" alt="发票预览">
                    </div>
                    
                    <div class="camera-controls">
                        <button type="button" class="camera-btn btn-take" onclick="openCamera()">
                            拍照
                        </button>
                        <button type="button" class="camera-btn btn-upload" onclick="openFilePicker()">
                            相册
                        </button>
                    </div>
                    
                    <!-- 二维码扫描按钮 -->
                    <div class="qr-scan-section">
                        <button type="button" class="qr-scan-btn" id="qrScanBtn" onclick="startQRScanner()">
                            扫描发票二维码获取编号
                        </button>
                    </div>
                    
                    <input type="file" id="fileInput" accept="image/*" capture="environment" style="display: none;">
                    <input type="hidden" id="imageBase64" name="image_base64">
                </div>
            </div>
            
            <!-- 发票编号输入 -->
            <div class="card">
                <div class="card-title">填写发票编号</div>
                <div class="slip-input-group">
                    <input type="text" 
                           id="slip_number" 
                           name="slip_number" 
                           class="slip-input" 
                           placeholder="请输入20位数字发票编号"
                           maxlength="20"
                           inputmode="numeric"
                           pattern="\d{20}"
                           required>

                    <div id="validationMsg" class="validation-msg"></div>
                </div>
                <div style="font-size: 12px; color: #666; margin-top: 8px; text-align: center;">
                    💡 可手动输入或扫描发票上的二维码自动获取
                </div>
            </div>
            
            <!-- 提交按钮 -->
            <div class="form-actions">
                <button type="submit" class="btn-submit" id="submitBtn" disabled>
                    确认上传
                </button>
            </div>
        </form>
        
        <?php endif; ?>
    </div>
    
    <!-- 二维码扫描器 -->
    <div class="qr-scanner" id="qrScanner">
        <div class="scanner-container">
            <div id="reader"></div>
        </div>
        <div class="scanner-instruction">
            请将发票上的二维码对准扫描框
        </div>
        <div class="scanner-controls">
            <button class="btn-scanner-close" onclick="stopQRScanner()">
                关闭扫描
            </button>
        </div>
    </div>
    
    <script>
    // 全局变量
    let hasImage = false;
    let hasValidNumber = false;
    const totalTime = 300; // 5分钟 = 300秒
    let remainingTime = <?php echo max(0, 300 - $time_diff); ?>;
    let qrScanner = null;
    
    // DOM元素
    const cameraPreview = document.getElementById('cameraPreview');
    const previewImage = document.getElementById('previewImage');
    const fileInput = document.getElementById('fileInput');
    const imageBase64 = document.getElementById('imageBase64');
    const slipNumberInput = document.getElementById('slip_number');
    const submitBtn = document.getElementById('submitBtn');
    const validationMsg = document.getElementById('validationMsg');
    const countdownEl = document.getElementById('countdown');
    const qrScannerElement = document.getElementById('qrScanner');
    const qrScanBtn = document.getElementById('qrScanBtn');
    const scanInputBtn = document.getElementById('scanInputBtn');
    
    // 从二维码数据中提取发票号码
    function extractInvoiceNumberFromQR(qrData) {
        console.log("QR原始数据:", qrData);
        
        // 格式: 01,32,,25447000001649186922,14.87,20251208,,B5C1
        // 第三个和第四个之间的内容是发票编号: 25447000001649186922
        
        try {
            // 按逗号分割
            const parts = qrData.split(',');
            console.log("分割后的数据:", parts);
            
            if (parts.length >= 4) {
                // 第三个位置是空字符串，第四个位置是发票编号
                const invoiceNumber = parts[3];
                console.log("提取到的发票编号:", invoiceNumber);
                
                // 验证是否是20位数字
                if (invoiceNumber && invoiceNumber.length === 20 && /^\d+$/.test(invoiceNumber)) {
                    return invoiceNumber;
                }
            }
            
            // 如果上述格式不匹配，尝试其他常见格式
            // 1. 直接匹配20位数字
            const directMatch = qrData.match(/\d{20}/);
            if (directMatch) {
                console.log("直接匹配到20位数字:", directMatch[0]);
                return directMatch[0];
            }
            
            // 2. 尝试匹配"发票号码:"格式
            const patternMatch = qrData.match(/发票号码[:：]?\s*(\d{20})/i);
            if (patternMatch) {
                console.log("通过发票号码模式匹配:", patternMatch[1]);
                return patternMatch[1];
            }
            
        } catch (error) {
            console.error("解析二维码数据时出错:", error);
        }
        
        return null;
    }
    
    // 启动二维码扫描器
    function startQRScanner() {
        // 显示扫描器
        qrScannerElement.style.display = 'flex';
        
        // 创建扫描器实例
        qrScanner = new Html5Qrcode("reader");
        
        // 配置并启动扫描
        const qrCodeSuccessCallback = (decodedText, decodedResult) => {
            console.log("扫描到二维码:", decodedText);
            
            // 提取发票号码
            const invoiceNumber = extractInvoiceNumberFromQR(decodedText);
            
            if (invoiceNumber) {
                // 自动填充发票编号
                slipNumberInput.value = invoiceNumber;
                validateSlipNumber();
                
                // 显示成功提示
                showToast(`✅ 已识别到发票编号: ${invoiceNumber}`);
                
                // 停止扫描
                stopQRScanner();
                
                // 如果还没有图片，提示用户拍照
                if (!hasImage) {
                    setTimeout(() => {
                        showToast('请拍摄发票照片');
                    }, 500);
                }
            } else {
                showToast('未识别到有效发票编号，请重新扫描');
            }
        };
        
        const qrCodeErrorCallback = (error) => {
            // 可以忽略扫描错误，它们通常是无害的
            console.log("扫描错误:", error);
        };
        
        const config = {
            fps: 10,
            qrbox: { width: 250, height: 250 },
            aspectRatio: 1.0
        };
        
        // 开始扫描
        qrScanner.start(
            { facingMode: "environment" }, // 使用后置摄像头
            config,
            qrCodeSuccessCallback,
            qrCodeErrorCallback
        ).catch(err => {
            console.error("无法启动摄像头:", err);
            showToast('无法访问摄像头，请检查权限');
            stopQRScanner();
        });
    }
    
    // 停止二维码扫描器
    function stopQRScanner() {
        if (qrScanner) {
            qrScanner.stop().then(() => {
                qrScanner.clear();
                qrScanner = null;
                qrScannerElement.style.display = 'none';
            }).catch(err => {
                console.error("停止扫描器失败:", err);
                qrScannerElement.style.display = 'none';
                qrScanner = null;
            });
        } else {
            qrScannerElement.style.display = 'none';
        }
    }
    
    // 倒计时
    function updateCountdown() {
        if (remainingTime <= 0) {
            countdownEl.textContent = '已过期';
            countdownEl.style.color = '#dc3545';
            showToast('二维码已过期，请重新扫描');
            return;
        }
        
        const minutes = Math.floor(remainingTime / 60);
        const seconds = remainingTime % 60;
        countdownEl.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        remainingTime--;
    }
    
    // 显示提示
    function showToast(message, type = 'info') {
        // 创建提示元素
        const toast = document.createElement('div');
        toast.className = 'alert ' + (type === 'error' ? 'alert-error' : 'alert-success');
        toast.innerHTML = message;
        toast.style.position = 'fixed';
        toast.style.top = '20px';
        toast.style.left = '50%';
        toast.style.transform = 'translateX(-50%)';
        toast.style.zIndex = '1001';
        toast.style.maxWidth = '90%';
        toast.style.animation = 'fadeIn 0.3s';
        
        // 添加动画样式
        if (!document.querySelector('#toast-style')) {
            const style = document.createElement('style');
            style.id = 'toast-style';
            style.textContent = `
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
                    to { opacity: 1; transform: translateX(-50%) translateY(0); }
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(toast);
        
        // 3秒后移除
        setTimeout(() => {
            toast.style.animation = 'fadeOut 0.3s';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 3000);
    }
    
    // 每秒更新倒计时
    setInterval(updateCountdown, 1000);
    updateCountdown(); // 立即执行一次
    
    // 打开相机
    function openCamera() {
        fileInput.setAttribute('capture', 'environment');
        fileInput.click();
    }
    
    // 打开文件选择器
    function openFilePicker() {
        fileInput.removeAttribute('capture');
        fileInput.click();
    }
    
    // 处理文件选择
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        // 检查文件类型
        if (!file.type.match('image/jpeg') && !file.type.match('image/png') && !file.type.match('image/jpg')) {
            showToast('请选择JPEG或PNG格式的图片', 'error');
            return;
        }
        
        // 检查文件大小（5MB）
        if (file.size > 5 * 1024 * 1024) {
            showToast('图片大小不能超过5MB', 'error');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            // 显示预览
            previewImage.src = e.target.result;
            previewImage.style.display = 'block';
            cameraPreview.querySelector('.camera-placeholder').style.display = 'none';
            cameraPreview.classList.add('has-image');
            
            // 保存Base64数据
            imageBase64.value = e.target.result;
            hasImage = true;
            checkFormValidity();
            showToast('✅ 图片已选择，请填写发票编号');
            
            // 尝试从图片中识别二维码
            try {
                const tempScanner = new Html5Qrcode("reader");
                tempScanner.scanFile(file, true)
                    .then(decodedText => {
                        const invoiceNumber = extractInvoiceNumberFromQR(decodedText);
                        if (invoiceNumber) {
                            slipNumberInput.value = invoiceNumber;
                            validateSlipNumber();
                            showToast(`✅ 已从图片识别到发票编号: ${invoiceNumber}`);
                        }
                    })
                    .catch(err => {
                        // 图片中可能没有二维码，这是正常情况
                        console.log("图片中没有二维码:", err);
                    });
            } catch (err) {
                console.log("二维码识别功能不可用:", err);
            }
        };
        reader.readAsDataURL(file);
    });
    
    // 验证发票编号
    function validateSlipNumber() {
        const value = slipNumberInput.value.trim();
        
        if (!value) {
            validationMsg.className = 'validation-msg';
            validationMsg.textContent = '';
            slipNumberInput.classList.remove('valid', 'invalid');
            hasValidNumber = false;
            return;
        }
        
        const isValid = value.length === 20 && /^\d+$/.test(value);
        
        if (isValid) {
            validationMsg.className = 'validation-msg valid-msg';
            validationMsg.textContent = '✅ 格式正确';
            slipNumberInput.classList.add('valid');
            slipNumberInput.classList.remove('invalid');
            hasValidNumber = true;
        } else {
            if (value.length !== 20) {
                validationMsg.className = 'validation-msg invalid-msg';
                validationMsg.textContent = `✗ 需要20位数字，当前${value.length}位`;
            } else if (!/^\d+$/.test(value)) {
                validationMsg.className = 'validation-msg invalid-msg';
                validationMsg.textContent = '✗ 只能包含数字';
            }
            slipNumberInput.classList.add('invalid');
            slipNumberInput.classList.remove('valid');
            hasValidNumber = false;
        }
        
        checkFormValidity();
    }
    
    // 检查表单是否有效
    function checkFormValidity() {
        const isValid = hasImage && hasValidNumber && remainingTime > 0;
        submitBtn.disabled = !isValid;
        
        if (isValid) {
            submitBtn.innerHTML = '📤 确认上传';
        }
    }
    
    // 表单提交
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (remainingTime <= 0) {
            showToast('二维码已过期，请重新扫描', 'error');
            return;
        }
        
        if (!hasImage) {
            showToast('请拍摄发票照片', 'error');
            return;
        }
        
        if (!hasValidNumber) {
            showToast('请输入正确的发票编号', 'error');
            return;
        }
        
        if (!confirm('确认上传发票信息吗？上传后不可修改。')) {
            return;
        }
        
        // 显示加载中
        submitBtn.disabled = true;
        submitBtn.innerHTML = '⏳ 上传中...';
        
        // 提交表单
        setTimeout(() => {
            this.submit();
        }, 300);
    });
    
    // 输入框事件监听
    slipNumberInput.addEventListener('input', validateSlipNumber);
    slipNumberInput.addEventListener('blur', validateSlipNumber);
    
    // 页面加载完成后的初始化
    document.addEventListener('DOMContentLoaded', function() {
        validateSlipNumber();
        
        
        // 检查摄像头权限并提示
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(() => {
                    console.log("摄像头权限已授予");
                })
                .catch(err => {
                    console.log("摄像头权限未授予:", err);
                    showToast('请允许访问摄像头以使用二维码扫描功能', 'error');
                });
        }
    });
    </script>
</body>
</html>