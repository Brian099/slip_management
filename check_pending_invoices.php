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

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('请求方法不正确');
    }
    
    $customer_name = trim($_POST['customer_name'] ?? '');
    $tax_number = trim($_POST['tax_number'] ?? '');
    $invoice_type = trim($_POST['invoice_type'] ?? '');
    
    if (empty($customer_name)) {
        throw new Exception('客户名称不能为空');
    }
    
    if (empty($invoice_type)) {
        throw new Exception('发票类型不能为空');
    }
    
    $user_id = $_SESSION['user_id'];
    
    // 构建查询条件
    $conditions = [];
    $params = [];
    $types = '';
    
    // 优先使用税号查询
    if (!empty($tax_number)) {
        $conditions[] = "tax_number = ?";
        $params[] = $tax_number;
        $types .= "s";
    } else {
        // 如果没有税号，使用客户名称查询
        $conditions[] = "customer_name = ?";
        $params[] = $customer_name;
        $types .= "s";
    }
    
    // 添加发票类型条件
    $conditions[] = "invoice_type = ?";
    $params[] = $invoice_type;
    $types .= "s";
    
    // 只查询待开票状态
    $conditions[] = "finance_status = 'pending'";
    
    // 只查询未作废的发票
    $conditions[] = "is_voided = FALSE";
    
    // 只查询当前用户创建的发票（如果需要跨用户合并，可以移除这个条件）
    $conditions[] = "created_by = ?";
    $params[] = $user_id;
    $types .= "i";
    
    $whereClause = implode(" AND ", $conditions);
    
    // 查询待开发票
    $sql = "SELECT * FROM invoices WHERE $whereClause ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $invoices = [];
    while ($invoice = $result->fetch_assoc()) {
        // 查询每个发票的货物详情
        $itemStmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
        $itemStmt->bind_param("i", $invoice['id']);
        $itemStmt->execute();
        $itemsResult = $itemStmt->get_result();
        
        $items = [];
        while ($item = $itemsResult->fetch_assoc()) {
            $items[] = $item;
        }
        
        $invoice['items'] = $items;
        $invoices[] = $invoice;
    }
    
    $response['success'] = true;
    $response['data'] = $invoices;
    $response['message'] = count($invoices) . ' 张待开发票';
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);