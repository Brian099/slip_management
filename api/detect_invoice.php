<?php
// api/detect_invoice.php
// 开启输出缓冲，防止BOM或警告信息破坏JSON结构
ob_start();

header('Content-Type: application/json; charset=utf-8');

// 允许跨域请求（如果需要）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// 辅助函数：发送JSON响应
function sendResponse($success, $message, $data = []) {
    // 清除缓冲区中的所有非预期输出
    ob_clean();
    
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    
    echo json_encode($response);
    // 刷新缓冲区并关闭
    ob_end_flush();
    exit;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendResponse(false, '只允许POST请求');
}

// 检查文件上传
if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    sendResponse(false, '请上传PDF文件');
}

$file = $_FILES['pdf'];

// 验证文件类型
if ($file['type'] !== 'application/pdf') {
    sendResponse(false, '请上传PDF格式的文件');
}

// 文件大小限制
if ($file['size'] > 5 * 1024 * 1024) {
    sendResponse(false, '文件大小不能超过5MB');
}

try {
    // 检查必要的扩展
    if (!extension_loaded('mbstring')) {
        throw new Exception('系统缺少mbstring扩展，无法解析PDF');
    }

    // 引入PDF解析器
    $pdfParserPath = dirname(__DIR__) . '/pdfparser-2.12.2/alt_autoload.php';
    if (!file_exists($pdfParserPath)) {
        throw new Exception('PDF解析器未找到');
    }
    
    require_once $pdfParserPath;
    
    // 解析PDF
    $parser = new Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($file['tmp_name']);
    $text = $pdf->getText();
    
    // 提取发票号码
    $invoiceNumber = null;
    
    // 优先匹配“发票号码”
    if (preg_match('/发票号码[:：]?\s*(\d{8,20})/u', $text, $m)) {
        $invoiceNumber = $m[1];
    }
    // 兜底：20位数字
    elseif (preg_match('/\b\d{20}\b/', $text, $m)) {
        $invoiceNumber = $m[0];
    }
    
    if ($invoiceNumber) {
        sendResponse(true, '识别成功', ['invoice_number' => $invoiceNumber]);
    } else {
        sendResponse(false, '未识别到发票号码');
    }
    
} catch (Throwable $e) {
    // 记录详细错误日志
    error_log('PDF Parse Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    sendResponse(false, 'PDF解析失败: ' . $e->getMessage());
}