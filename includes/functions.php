<?php
// 防止重复包含
if (defined('FUNCTIONS_LOADED')) {
    return;
}
define('FUNCTIONS_LOADED', true);

// 生成唯一的发票号
function generateInvoiceNumber() {
    $prefix = 'INV';
    $date = date('Ymd');
    $random = rand(1000, 9999);
    return $prefix . $date . $random;
}

// 计算总金额
function calculateTotal($items) {
    $total = 0;
    foreach ($items as $item) {
        if (isset($item['amount'])) {
            $total += floatval($item['amount']);
        }
    }
    return $total;
}

// 获取用户信息
function getUserById($id) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id, username, full_name, role, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// 获取所有发票（根据权限）
function getAllInvoices($filter = null, $user_id = null, $user_role = null) {
    $conn = getDbConnection();
    
    $sql = "SELECT i.*, 
            u.full_name as created_by_name,
            v.full_name as voided_by_name
            FROM invoices i 
            LEFT JOIN users u ON i.created_by = u.id 
            LEFT JOIN users v ON i.voided_by = v.id";
    
    $where = [];
    $params = [];
    $types = '';
    
    // 业务员只能看到自己的发票
    if ($user_role === 'sales' && $user_id) {
        $where[] = "i.created_by = ?";
        $params[] = $user_id;
        $types .= 'i';
    }
    
    // 应用过滤条件
    if ($filter === 'pending') {
        $where[] = "i.finance_status = 'pending' AND i.is_voided = FALSE";
    } elseif ($filter === 'invoiced') {
        $where[] = "i.finance_status = 'invoiced' AND i.is_voided = FALSE";
    } elseif ($filter === 'voided') {
        $where[] = "i.is_voided = TRUE";
    } elseif ($filter === 'active') {
        $where[] = "i.is_voided = FALSE";
    }
    
    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    
    $sql .= " ORDER BY i.created_at DESC";
    
    if ($params) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
    
    $invoices = [];
    
    while ($row = $result->fetch_assoc()) {
        $invoices[] = $row;
    }
    
    return $invoices;
}

// 获取发票详情
function getInvoiceById($id) {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT i.*, 
        u.full_name as created_by_name,
        v.full_name as voided_by_name
        FROM invoices i 
        LEFT JOIN users u ON i.created_by = u.id 
        LEFT JOIN users v ON i.voided_by = v.id 
        WHERE i.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    
    if ($invoice) {
        $stmt2 = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        $items = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $invoice['items'] = $items;
    }
    
    return $invoice;
}

// 获取用户发票统计
function getUserInvoiceStats($user_id) {
    $conn = getDbConnection();
    
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN finance_status = 'pending' AND is_voided = FALSE THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN finance_status = 'invoiced' AND is_voided = FALSE THEN 1 ELSE 0 END) as invoiced,
            SUM(CASE WHEN is_voided = TRUE THEN 1 ELSE 0 END) as voided,
            SUM(total_amount) as total_amount
        FROM invoices 
        WHERE created_by = $user_id
    ")->fetch_assoc();
    
    return [
        'total' => $stats['total'] ?? 0,
        'pending' => $stats['pending'] ?? 0,
        'invoiced' => $stats['invoiced'] ?? 0,
        'voided' => $stats['voided'] ?? 0,
        'total_amount' => $stats['total_amount'] ?? 0
    ];
}

// 获取系统统计信息（财务员用）
function getSystemStats() {
    $conn = getDbConnection();
    
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN finance_status = 'pending' AND is_voided = FALSE THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN finance_status = 'invoiced' AND is_voided = FALSE THEN 1 ELSE 0 END) as invoiced,
            SUM(CASE WHEN is_voided = TRUE THEN 1 ELSE 0 END) as voided,
            COUNT(DISTINCT created_by) as active_users
        FROM invoices
    ")->fetch_assoc();
    
    return [
        'total' => $stats['total'] ?? 0,
        'pending' => $stats['pending'] ?? 0,
        'invoiced' => $stats['invoiced'] ?? 0,
        'voided' => $stats['voided'] ?? 0,
        'active_users' => $stats['active_users'] ?? 0
    ];
}

// 检查发票是否可以作废
function canVoidInvoice($invoice) {
    return $invoice['finance_status'] === 'invoiced' && !$invoice['is_voided'];
}

// 检查发票是否可以编辑
function canEditInvoice($invoice, $user_id, $user_role) {
    if ($invoice['is_voided']) {
        return false;
    }
    
    if ($invoice['finance_status'] !== 'pending') {
        return false;
    }
    
    if ($user_role === 'sales' && $invoice['created_by'] != $user_id) {
        return false;
    }
    
    return true;
}

// 检查发票是否可以删除
function canDeleteInvoice($invoice, $user_id, $user_role) {
    if ($invoice['is_voided']) {
        return false;
    }
    
    if ($invoice['finance_status'] !== 'pending') {
        return false;
    }
    
    if ($user_role === 'sales' && $invoice['created_by'] != $user_id) {
        return false;
    }
    
    return true;
}

// 检查发票是否可以开票
function canUploadInvoiceFile($invoice, $user_role) {
    if ($invoice['is_voided']) {
        return false;
    }
    
    if ($invoice['finance_status'] !== 'pending') {
        return false;
    }
    
    if ($user_role !== 'finance') {
        return false;
    }
    
    return true;
}

// 检查发票是否可以标记为有误
function canMarkIncorrect($invoice, $user_id) {
    if (!empty($invoice['is_voided']) && $invoice['is_voided']) {
        return false;
    }
    
    if ($invoice['finance_status'] !== 'invoiced') {
        return false;
    }
    
    if ($invoice['created_by'] != $user_id) {
        return false;
    }
    
    return true;
}

// 检查发票是否可以确认有误并退回
function canConfirmIncorrect($invoice, $user_role) {
    if (!empty($invoice['is_voided']) && $invoice['is_voided']) {
        return false;
    }
    
    if ($invoice['finance_status'] !== 'incorrect') {
        return false;
    }
    
    if ($user_role !== 'finance') {
        return false;
    }
    
    return true;
}

// 获取最近发票（根据权限）
function getRecentInvoices($limit = 10, $user_id = null, $user_role = null) {
    $conn = getDbConnection();
    
    $sql = "SELECT i.*, 
            u.full_name as created_by_name,
            v.full_name as voided_by_name
            FROM invoices i 
            LEFT JOIN users u ON i.created_by = u.id 
            LEFT JOIN users v ON i.voided_by = v.id";
    
    $where = [];
    $params = [];
    $types = '';
    
    // 业务员只能看到自己的发票
    if ($user_role === 'sales' && $user_id) {
        $where[] = "i.created_by = ?";
        $params[] = $user_id;
        $types .= 'i';
    }
    
    // 只显示有效发票
    $where[] = "i.is_voided = FALSE";
    
    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    
    $sql .= " ORDER BY i.created_at DESC LIMIT ?";
    $params[] = $limit;
    $types .= 'i';
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $invoices = [];
    
    while ($row = $result->fetch_assoc()) {
        $invoices[] = $row;
    }
    
    return $invoices;
}

// 格式化金额显示
function formatCurrency($amount) {
    return '¥' . number_format($amount, 2);
}

// 格式化日期时间显示
function formatDateTime($datetime, $format = 'Y-m-d H:i') {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    return date($format, strtotime($datetime));
}

// 获取发票状态文本
function getInvoiceStatusText($invoice) {
    if (!empty($invoice['is_voided']) && $invoice['is_voided']) {
        return '作废';
    } elseif ($invoice['finance_status'] === 'pending') {
        return '待开';
    } elseif ($invoice['finance_status'] === 'incorrect') {
        return '有误';
    } elseif ($invoice['finance_status'] === 'invoiced') {
        return '已开';
    }
    return '未知';
}


// 获取发票状态CSS类
function getInvoiceStatusClass($invoice) {
    if (!empty($invoice['is_voided']) && $invoice['is_voided']) {
        return 'voided';
    } elseif ($invoice['finance_status'] === 'pending') {
        return 'pending';
    } elseif ($invoice['finance_status'] === 'incorrect') {
        return 'incorrect';
    } elseif ($invoice['finance_status'] === 'invoiced') {
        return 'invoiced';
    }
    return '';
}

// 添加在 functions.php 文件中
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    
    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

// 检查是否可以重新上传附件
function canReuploadInvoiceFile($invoice, $user_role) {
    if ($invoice['is_voided']) {
        return false;
    }
    
    if ($invoice['finance_status'] !== 'invoiced') {
        return false;
    }
    
    if (empty($invoice['invoice_file'])) {
        return false;
    }
    
    if ($user_role !== 'finance') {
        return false;
    }
    
    return true;
}

// 获取单个设置值
function getSetting($conn, $key, $default = null) {
    static $settings = null;
    
    // 一次性获取所有设置，避免多次查询
    if ($settings === null) {
        $settings = [];
        $sql = "SELECT * FROM system_settings WHERE id = 1";
        $result = $conn->query($sql);
        
        if ($result && $row = $result->fetch_assoc()) {
            $settings = $row;
        }
    }
    
    // 返回对应的设置值，如果不存在则返回默认值
    if (isset($settings[$key])) {
        $value = $settings[$key];
        // 根据字段类型转换
        if (is_numeric($value)) {
            return floatval($value);
        }
        return $value;
    }
    
    return $default;
}

// 发送 Webhook 通知
function sendWebhook($type, $data) {
    $conn = getDbConnection();
    $webhookUrl = getSetting($conn, 'webhook_url');
    
    if (empty($webhookUrl)) {
        return;
    }
    
    // 构建消息内容
    $content = '';
    $creator = $_SESSION['full_name'] ?? '未知用户';
    
    switch ($type) {
        case 'invoice_created':
            $content = "{$creator} [新增] 了发票！\n- 客户：{$data['customer_name']}\n- 金额：¥" . number_format($data['total_amount'], 2) . "\n- 备注：{$data['remarks']}";
            break;
        case 'invoice_updated':
            $content = "{$creator} [修改] 了发票！\n- 发票号：{$data['invoice_number']}\n- 客户：{$data['customer_name']}\n- 新金额：¥" . number_format($data['total_amount'], 2);
            break;
        case 'invoice_deleted':
            $content = "{$creator} [删除] 了发票！\n- 发票号：{$data['invoice_number']}\n- 客户：{$data['customer_name']}\n- 金额：¥" . number_format($data['total_amount'], 2);
            break;
        case 'invoice_audited':
            $content = "{$creator} [审核] 了发票（标记开票）！\n- 发票号：{$data['invoice_number']}\n- 客户：{$data['customer_name']}\n- 金额：¥" . number_format($data['total_amount'], 2) . "\n- 发票代码：{$data['slip_number']}";
            break;
        case 'batch_audited':
            $content = "{$creator} [批量审核] 了发票！\n- 数量：{$data['count']} 张\n- 总金额：¥" . number_format($data['amount'], 2);
            break;
        case 'income_added':
            $content = "{$creator} [新增] 入账记录！\n- 公司：{$data['company']}\n- 金额：{$data['currency']} " . number_format($data['amount'], 2) . "\n- 银行：{$data['bank']}\n- 付款方：{$data['payer']}\n- 时间：{$data['date']} {$data['time']}";
            break;
        case 'income_updated':
            $content = "{$creator} [修改] 入账记录！\n- 公司：{$data['company']}\n- 金额：{$data['currency']} " . number_format($data['amount'], 2) . "\n- 付款方：{$data['payer']}\n- 时间：{$data['date']} {$data['time']}";
            break;
        case 'income_deleted':
            $content = "{$creator} [删除] 入账记录！\n- ID：{$data['id']}";
            break;
    }
    
    if (empty($content)) {
        return;
    }

    // 构造企业微信 Webhook 格式
    $webhookData = [
        'msgtype' => 'text',
        'text' => [
            'content' => $content
        ]
    ];
    
    // 初始化 cURL
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhookData));
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 设置超时
    
    // 执行请求
    curl_exec($ch);
    curl_close($ch);
}
?>