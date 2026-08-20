<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

checkRole(['sales', 'finance']);

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// 获取导出参数
$filter = $_POST['filter'] ?? 'all';
$search = $_POST['search'] ?? '';
$invoice_type = $_POST['invoice_type'] ?? ''; // 关键：接收发票类型
$format = $_POST['format'] ?? 'excel';
$fields = $_POST['fields'] ?? [];
$include_items = isset($_POST['include_items']) && $_POST['include_items'] == '1';
$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';
$salesperson_id = $_POST['salesperson_id'] ?? '';

// 验证字段选择
if (empty($fields)) {
    die("请选择要导出的字段");
}

// 字段映射
$field_mapping = [
    'invoice_number' => '发票号',
    'customer_name' => '客户名称',
    'customer_contact' => '联系方式',
    'tax_number' => '税号',
    'invoice_type' => '发票类型',
    'total_amount' => '总金额',
    'finance_status' => '状态',
    'created_by_name' => '创建人',
    'created_at' => '创建时间',
    'invoiced_at' => '开票时间',
    'remarks' => '备注'
];

// 选择用户实际请求的字段
$selected_fields = [];
$field_list_parts = [];
foreach ($fields as $field) {
    if (isset($field_mapping[$field])) {
        $selected_fields[$field] = $field_mapping[$field];
        // 特殊处理创建人字段
        if ($field === 'created_by_name') {
            $field_list_parts[] = 'u.full_name as created_by_name';
        } else {
            $field_list_parts[] = "i.$field";
        }
    }
}

if (empty($field_list_parts)) {
    die("无效的字段选择");
}

$field_list = implode(', ', $field_list_parts);

// 构建查询条件
$where = [];
$params = [];
$types = '';

// 权限控制
if ($user_role === 'sales') {
    $where[] = "i.created_by = ?";
    $params[] = $user_id;
    $types .= 'i';
} elseif ($user_role === 'finance' && !empty($salesperson_id)) {
    $where[] = "i.created_by = ?";
    $params[] = intval($salesperson_id);
    $types .= 'i';
}
if (!empty($invoice_type)) {
    $where[] = "i.invoice_type = ?";
    $params[] = $invoice_type;
    $types .= 's';
}
// 应用过滤条件
if ($filter === 'pending') {
    $where[] = "i.finance_status = 'pending'";
    $where[] = "(i.is_voided IS NULL OR i.is_voided = 0)";
} elseif ($filter === 'invoiced') {
    $where[] = "i.finance_status = 'invoiced'";
    $where[] = "(i.is_voided IS NULL OR i.is_voided = 0)";
} elseif ($filter === 'incorrect') {
    $where[] = "i.finance_status = 'incorrect'";
    $where[] = "(i.is_voided IS NULL OR i.is_voided = 0)";
} elseif ($filter === 'voided') {
    $where[] = "i.is_voided = 1";
}

// 搜索条件
if (!empty($search)) {
    $where[] = "(i.invoice_number LIKE ? OR i.customer_name LIKE ? OR i.customer_contact LIKE ? OR i.slip_number LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
	$params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ssss';
}

// 时间范围条件
if (!empty($start_date) && !empty($end_date)) {
    $where[] = "DATE(i.created_at) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// 构建SQL查询
$sql = "SELECT i.id, $field_list 
        FROM invoices i 
        LEFT JOIN users u ON i.created_by = u.id";

if ($where_sql) {
    $sql .= " " . $where_sql;
}

$sql .= " ORDER BY i.created_at DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("准备SQL语句失败: " . $conn->error . "<br>SQL: " . htmlspecialchars($sql));
}

// 只有当有参数时才绑定参数
if (!empty($params)) {
    // bind_param 要求 types 字符串长度等于参数数量
    if (!$stmt->bind_param($types, ...$params)) {
        die("绑定参数失败: " . $stmt->error);
    }
}

if (!$stmt->execute()) {
    die("执行查询失败: " . $stmt->error);
}

$result = $stmt->get_result();
$invoices = $result->fetch_all(MYSQLI_ASSOC);

// 获取货物明细（如果需要）
$invoice_items = [];
if ($include_items && !empty($invoices)) {
    $invoice_ids = array_column($invoices, 'id');
    if (!empty($invoice_ids)) {
        // 创建占位符
        $placeholders = implode(',', array_fill(0, count($invoice_ids), '?'));
        $items_sql = "SELECT * FROM invoice_items WHERE invoice_id IN ($placeholders) ORDER BY invoice_id, id";
        $items_stmt = $conn->prepare($items_sql);
        if (!$items_stmt) {
            die("准备货物明细查询失败: " . $conn->error);
        }
        
        // 绑定参数
        $param_types = str_repeat('i', count($invoice_ids));
        $items_stmt->bind_param($param_types, ...$invoice_ids);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        while ($item = $items_result->fetch_assoc()) {
            $invoice_items[$item['invoice_id']][] = $item;
        }
        $items_stmt->close();
    }
}

$stmt->close();
$conn->close();

// 辅助格式化函数
function fmtAmount($n) {
    if ($n === '' || $n === null) return '';
    return number_format((float)$n, 2, '.', ',');
}
function fmtDate($d) {
    if (!$d) return '';
    return date('Y-m-d H:i', strtotime($d));
}

// 生成导出
switch ($format) {
    case 'excel':
        exportToExcel($invoices, $selected_fields, $invoice_items);
        break;
    case 'csv':
        exportToCSV($invoices, $selected_fields, $invoice_items);
        break;
    case 'pdf':
        exportToPDF($invoices, $selected_fields, $invoice_items);
        break;
    default:
        exportToExcel($invoices, $selected_fields, $invoice_items);
}

/**
 * 导出为 Excel（使用 HTML 表格兼容 Excel）
 * 改进点：
 * - 顶部公司信息与 logo（占位）
 * - 列宽与单元格对齐优化
 * - 金额列使用 mso-number-format 提示 Excel 按数字处理
 * - 每行斑马线、状态颜色、导出汇总（总条数、总金额）
 * - 如果包含明细，明细表头更紧凑并显示小计
 */
function exportToExcel($data, $fields, $items) {
    $filename = "发票导出_" . date('Ymd_His') . ".xls";
    
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // 计算汇总
    $grandTotal = 0;
    foreach ($data as $row) {
        $grandTotal += isset($row['total_amount']) ? floatval($row['total_amount']) : 0;
    }

    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>发票导出</title>
        <style>
body { 
    font-family:"Microsoft YaHei", Arial; 
    font-weight:600; 
    color:#333; 
}

/* 主表 */
table { 
    border-collapse:collapse; 
    width:100%; 
    table-layout:fixed; 
    word-wrap:break-word; 
}
th, td {
    border:1px solid #e6e6e6; 
    padding:10px 8px; 
    vertical-align:middle; 
    height:32px;            /* 主表行高统一 */
}
thead th { 
    background:#2E86C1; 
    color:#fff; 
    text-align:center; 
}

/* 金额、日期格式 */
.amount { text-align:right; mso-number-format:"#\,##0.00"; }
.currency { mso-number-format:"\00A5#,##0.00"; }
.date { text-align:center; mso-number-format:"yyyy\-mm\-dd hh:mm"; }

/* 明细表 */
.items-table { 
    margin-top:8px; 
    border:1px solid #ccc; 
}
.items-table th { 
    background:#5a92b8; 
    color:#fff; 
    padding:10px 8px; 
    height:32px;            /* 明细表头行高统一 */
}
.items-table td { 
    padding:10px 8px; 
    height:32px;            /* 明细内容行高统一 */
}

.items-subtotal { 
    font-weight:700; 
}

        </style>
    </head>
    <body>
    <div class="container">
    ';

    // 每份发票单独输出一次表头 & 数据
    foreach ($data as $index => $invoice) {
        echo '<table><thead><tr>';
        foreach ($fields as $label) {
            echo '<th>' . htmlspecialchars($label) . '</th>';
        }
        echo '</tr></thead><tbody>';

        echo '<tr>';
        foreach (array_keys($fields) as $field) {
            $value = $invoice[$field] ?? '';
            $cell = htmlspecialchars($value);
            $class = '';

            if ($field === 'total_amount') {
                $cell = fmtAmount($value);
                $class = 'amount currency';
            } elseif ($field === 'created_at' || $field === 'invoiced_at') {
                $cell = fmtDate($value);
                $class = 'date';
            }elseif ($field === 'finance_status') {
                // 状态字段翻译
                if (!empty($invoice['is_voided']) && $invoice['is_voided'] == 1) {
                    $cell = '已作废';
                } elseif ($value === 'pending') {
                    $cell = '待开票';
                } elseif ($value === 'incorrect') {
                    $cell = '发票有误';
                } elseif ($value === 'invoiced') {
                    $cell = '已开票';
                } else {
                    $cell = '未知';
                }
                $class = 'status';
            }

            echo '<td class="'.$class.'">'.$cell.'</td>';
        }
        echo '</tr>';

        // 明细部分
        if (isset($items[$invoice['id']])) {
            $it = $items[$invoice['id']];
            echo '<tr><td colspan="'.count($fields).'">';

            echo '<table class="items-table">
                <thead>
                    <tr>
                        <th>货物名称</th>
                        <th>型号</th>
                        <th style="text-align:right;">单价</th>
                        <th>单位</th>
                        <th style="text-align:right;">数量</th>
                        <th style="text-align:right;">金额</th>
                    </tr>
                </thead>
                <tbody>';

            $subtotal = 0;
            foreach ($it as $itm) {
                $subtotal += floatval($itm['amount']);
                echo '<tr>
                        <td>' . htmlspecialchars($itm['item_name']) . '</td>
                        <td>' . htmlspecialchars($itm['model']) . '</td>
                        <td style="text-align:right;">' . fmtAmount($itm['unit_price']) . '</td>
                        <td>' . htmlspecialchars($itm['unit']) . '</td>
                        <td style="text-align:right;">' . fmtAmount($itm['quantity']) . '</td>
                        <td style="text-align:right;">' . fmtAmount($itm['amount']) . '</td>
                    </tr>';
            }

            echo '<tr class="items-subtotal">
                    <td colspan="5" style="text-align:right;">小计：</td>
                    <td style="text-align:right;">' . fmtAmount($subtotal) . '</td>
                  </tr>';

            echo '</tbody></table>';
            echo '</td></tr>';
        }

        echo '</tbody></table><br><br>';
    }

    // 汇总
    echo '
    <div>
        <strong>总记录数：</strong>' . count($data) . '<br>
        <strong>总金额：</strong>¥' . fmtAmount($grandTotal) . '
    </div>';

    echo '</div></body></html>';
    exit;
}


/**
 * CSV 导出
 * 改进点：
 * - 输出 BOM
 * - 每张发票后跟随明细（若有）
 * - 导出末尾追加汇总行：总记录数、总金额
 */
function exportToCSV($data, $fields, $items) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment;filename="发票导出_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    // UTF-8 BOM
    fwrite($output, "\xEF\xBB\xBF");

    // 表头
    fputcsv($output, array_values($fields));

    $grandTotal = 0;
    foreach ($data as $row) {
        $csv_row = [];
        foreach (array_keys($fields) as $field) {
            $value = $row[$field] ?? '';
            if ($field === 'finance_status') {
                $value = $value === 'pending' ? '待开票' : ($value === 'invoiced' ? '已开票' : ($value === 'voided' ? '已作废' : $value));
            }
            if ($field === 'total_amount') {
                $value = fmtAmount($value);
                $grandTotal += floatval($row['total_amount'] ?? 0);
            }
            if (($field === 'created_at' || $field === 'invoiced_at') && $value) {
                $value = fmtDate($value);
            }
            $csv_row[] = $value;
        }
        fputcsv($output, $csv_row);

        if (isset($items[$row['id']])) {
            fputcsv($output, ['货物明细:']);
            fputcsv($output, ['货物名称', '型号', '单价', '单位', '数量', '金额']);
            foreach ($items[$row['id']] as $item) {
                fputcsv($output, [
                    $item['item_name'],
                    $item['model'],
                    fmtAmount($item['unit_price']),
                    $item['unit'],
                    fmtAmount($item['quantity']),
                    fmtAmount($item['amount'])
                ]);
            }
            // 空行分隔
            fputcsv($output, []);
        }
    }

    // 汇总
    fputcsv($output, []);
    fputcsv($output, ['导出汇总']);
    fputcsv($output, ['总记录数', count($data)]);
    fputcsv($output, ['总金额', fmtAmount($grandTotal)]);

    fclose($output);
    exit;
}

/**
 * 简洁的 PDF(HTML) 导出（用户可另存为 PDF）
 * 改进点：
 * - 打印友好样式（A4 页面设置）
 * - 对每张发票的明细显示小计并分页
 */
function exportToPDF($data, $fields, $items) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment;filename="发票导出_' . date('Ymd_His') . '.html"');

    $grandTotal = 0;
    foreach ($data as $row) {
        $grandTotal += isset($row['total_amount']) ? floatval($row['total_amount']) : 0;
    }

    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>发票导出报表</title>
        <style>
            @media print {
                .page-break { page-break-after: always; }
                table { page-break-inside: avoid; }
            }
            body { font-family: "Microsoft YaHei", Arial, sans-serif; color:#222; padding:20px; }
            .header { text-align:center; margin-bottom:12px; }
            .header h1 { margin:0; font-size:20px; color:#1b4f72; }
            .meta { text-align:center; color:#666; margin-bottom:18px; }
            table { width:100%; border-collapse: collapse; }
            th, td { border:1px solid #ddd; padding:8px; vertical-align:middle;color: darkslateblue; }
            th { background:#2E86C1; font-weight:600; }
            .right { text-align:right; }
            .items { margin-bottom:20px; }
            .items th { background:#e9ecef; }
            .subtotal { font-weight:700; background:#f8f9fa; }
            .footer-summary { margin-top:18px; padding:12px; background:#f8f9fa; border:1px solid #e9ecef; }
			.row-display{display: flex;gap: 20px;border: 1px solid #e9ecef;padding: 10px;color: darkslateblue;}
			.field-label{font-weight: 700;}
        </style>
    </head>
    <body>
        <div class="header">
            <h1>发票导出报表</h1>
        </div>
        <div class="meta">导出时间：' . date('Y-m-d H:i:s') . ' &nbsp;&nbsp; 共 ' . count($data) . ' 条 &nbsp;&nbsp; 总金额：¥' . fmtAmount($grandTotal) . '</div>';

    foreach ($data as $invoice) {
        echo '<section class="invoice">';
echo '<div class="row-display">';
foreach ($fields as $field => $label) {
    $value = $invoice[$field] ?? '';
    
    // 特殊字段处理
    if ($field === 'finance_status') {
        if (!empty($invoice['is_voided']) && $invoice['is_voided'] == 1) {
            $value = '已作废';
        } else {
            $value = $value === 'pending' ? '待开票' : ($value === 'invoiced' ? '已开票' : ($value === 'incorrect' ? '发票有误' : $value));
        }
    }
    if ($field === 'total_amount') {
        $value = '¥' . fmtAmount($value);
    }
    if (($field === 'created_at' || $field === 'invoiced_at') && $value) {
        $value = fmtDate($value);
    }
    
    echo '<div class="field-item">';
    echo '<span class="field-label">' . htmlspecialchars($label) . ':</span>&nbsp';
    echo '<span class="field-value">' . htmlspecialchars($value) . '</span>';
    echo '</div>';
}
echo '</div>';

        if (isset($items[$invoice['id']])) {
            echo '<div class="items">';
            echo '<table><thead><tr>
                    <th>货物名称</th>
                    <th>型号</th>
                    <th class="right">单价</th>
                    <th>单位</th>
                    <th class="right">数量</th>
                    <th class="right">金额</th>
                </tr></thead><tbody>';
            $subtotal = 0;
            foreach ($items[$invoice['id']] as $it) {
                $subtotal += floatval($it['amount']);
                echo '<tr>
                        <td>' . htmlspecialchars($it['item_name']) . '</td>
                        <td>' . htmlspecialchars($it['model']) . '</td>
                        <td class="right">¥' . fmtAmount($it['unit_price']) . '</td>
                        <td>' . htmlspecialchars($it['unit']) . '</td>
                        <td class="right">' . fmtAmount($it['quantity']) . '</td>
                        <td class="right">¥' . fmtAmount($it['amount']) . '</td>
                    </tr>';
            }
            echo '<tr class="subtotal">
                    <td colspan="5" class="right">小计：</td>
                    <td class="right">¥' . fmtAmount($subtotal) . '</td>
                </tr>';
            echo '</tbody></table>';
            echo '</div>';
        }

        echo '</section>';
        echo '<div class="page-break"></div>';
    }

    echo '<div class="footer-summary">
            <strong>导出统计</strong>
            <div>总记录数：' . count($data) . ' 条</div>
            <div>总金额：¥' . fmtAmount($grandTotal) . '</div>
            <div>导出时间：' . date('Y-m-d H:i:s') . '</div>
        </div>';

    echo '</body></html>';
    exit;
}
?>