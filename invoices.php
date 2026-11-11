<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

checkRole(['sales', 'finance']);

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// 处理一键标记所有未开发票操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_mark_invoiced'])) {
    if ($user_role === 'finance') {
        batchMarkAllPendingAsInvoiced($conn, $user_id);
    }
}

// 获取过滤条件
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$invoice_type = $_GET['invoice_type'] ?? ''; // 新增：发票类型筛选
$salesperson_id = $_GET['salesperson_id'] ?? ''; // 新增：业务员筛选
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;

// 构建查询条件
$where = [];
$params = [];
$types = '';

// 业务员只能看到自己的发票，财务员可以看到所有（支持选定业务员筛选）
if ($user_role === 'sales') {
    $where[] = "i.created_by = ?";
    $params[] = $user_id;
    $types .= 'i';
} elseif ($user_role === 'finance' && !empty($salesperson_id)) {
    $where[] = "i.created_by = ?";
    $params[] = intval($salesperson_id);
    $types .= 'i';
}

// 发票类型筛选
if (!empty($invoice_type)) {
    $where[] = "i.invoice_type = ?";
    $params[] = $invoice_type;
    $types .= 's';
}

// 应用额外的过滤条件
if ($filter === 'pending') {
    $where[] = "i.finance_status = 'pending'";
    $where[] = "(i.is_voided IS NULL OR i.is_voided = FALSE)";
} elseif ($filter === 'invoiced') {
    $where[] = "i.finance_status = 'invoiced'";
    $where[] = "(i.is_voided IS NULL OR i.is_voided = FALSE)";
} elseif ($filter === 'incorrect') {
    $where[] = "i.finance_status = 'incorrect'";
    $where[] = "(i.is_voided IS NULL OR i.is_voided = FALSE)";
} elseif ($filter === 'voided') {
    $where[] = "i.is_voided = TRUE";
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

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// 获取待开票统计信息（只统计当前筛选条件下的）
$pending_stats = getPendingStats($conn, $where, $params, $types, $user_role, $user_id);

// 获取总数
if ($where_sql) {
    $count_sql = "SELECT COUNT(*) as total FROM invoices i " . $where_sql;

    if ($params) {
        $stmt = $conn->prepare($count_sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $count_data = $result->fetch_assoc();
            $total = $count_data['total'];
        } else {
            die("准备总数查询失败: " . $conn->error);
        }
    } else {
        $result = $conn->query($count_sql);
        if ($result) {
            $count_data = $result->fetch_assoc();
            $total = $count_data['total'];
        } else {
            die("总数查询失败: " . $conn->error);
        }
    }
} else {
    $count_sql = "SELECT COUNT(*) as total FROM invoices";
    $result = $conn->query($count_sql);
    if ($result) {
        $count_data = $result->fetch_assoc();
        $total = $count_data['total'];
    } else {
        die("总数查询失败: " . $conn->error);
    }
}

$total_pages = ceil($total / $per_page);
$offset = ($page - 1) * $per_page;

// 获取发票列表
$sql = "SELECT i.*, u.full_name as created_by_name 
        FROM invoices i 
        LEFT JOIN users u ON i.created_by = u.id";

if ($where_sql) {
    $sql .= " " . $where_sql;
}

$sql .= " ORDER BY i.created_at DESC LIMIT ? OFFSET ?";

// 准备查询
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("准备SQL语句失败: " . $conn->error . "<br>SQL: " . htmlspecialchars($sql));
}

if ($params) {
    // 如果有搜索参数，添加分页参数
    $params[] = $per_page;
    $params[] = $offset;
    $types .= 'ii';

    if (!$stmt->bind_param($types, ...$params)) {
        die("绑定参数失败: " . $stmt->error);
    }
} else {
    // 没有参数的情况
    if (!$stmt->bind_param("ii", $per_page, $offset)) {
        die("绑定分页参数失败: " . $stmt->error);
    }
}

if (!$stmt->execute()) {
    die("执行查询失败: " . $stmt->error);
}

$result = $stmt->get_result();
$invoices = $result->fetch_all(MYSQLI_ASSOC);

// 查看详情
if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
    $invoice = getInvoiceById($invoice_id);

    if (!$invoice) {
        die("发票不存在");
    }

    // 权限检查：业务员只能查看自己的发票
    if ($user_role === 'sales' && $invoice['created_by'] != $user_id) {
        die("无权查看他人创建的发票");
    }
}

// 函数：获取待开票统计信息
function getPendingStats($conn, $where, $params, $types, $user_role, $user_id)
{
    // 复制查询条件，只统计待开票
    $pending_where = $where;
    $pending_params = $params;
    $pending_types = $types;

    // 添加待开票条件
    $pending_where[] = "i.finance_status = 'pending'";
    $pending_where[] = "(i.is_voided IS NULL OR i.is_voided = FALSE)";

    $pending_where_sql = 'WHERE ' . implode(' AND ', $pending_where);

    // 获取待开票数量和总金额
    $sql = "SELECT COUNT(*) as count, SUM(i.total_amount) as total_amount 
            FROM invoices i 
            LEFT JOIN users u ON i.created_by = u.id 
            " . $pending_where_sql;

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($pending_params) {
            $stmt->bind_param($pending_types, ...$pending_params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        return [
            'count' => $stats['count'] ?? 0,
            'total_amount' => $stats['total_amount'] ?? 0
        ];
    }

    return ['count' => 0, 'total_amount' => 0];
}

// 函数：一键标记所有未开发票为已开票
function batchMarkAllPendingAsInvoiced($conn, $user_id)
{
    // 开始事务
    $conn->begin_transaction();

    try {
        // 获取所有待开票发票
        $sql = "SELECT id, total_amount FROM invoices 
                WHERE finance_status = 'pending' 
                AND (is_voided IS NULL OR is_voided = 0)";

        $result = $conn->query($sql);

        if (!$result) {
            throw new Exception("查询待开票发票失败: " . $conn->error);
        }

        $invoice_ids = [];
        $total_amount = 0;

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $invoice_ids[] = (int) $row['id'];
                $total_amount += (float) $row['total_amount'];
            }
        }

        if (empty($invoice_ids)) {
            $_SESSION['batch_message'] = [
                'type' => 'warning',
                'text' => '没有找到待开票的发票'
            ];
            return false;
        }

        // 批量更新状态 - 只更新finance_status和invoiced_at字段
        $placeholders = implode(',', array_fill(0, count($invoice_ids), '?'));

        $update_sql = "UPDATE invoices 
                      SET finance_status = 'invoiced', 
                          invoiced_at = NOW()
                      WHERE id IN ($placeholders)";

        $stmt = $conn->prepare($update_sql);

        if (!$stmt) {
            throw new Exception("准备更新语句失败: " . $conn->error);
        }

        // 绑定参数
        $types = str_repeat('i', count($invoice_ids));
        $stmt->bind_param($types, ...$invoice_ids);

        if (!$stmt->execute()) {
            throw new Exception("执行更新失败: " . $stmt->error);
        }

        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        // 提交事务
        $conn->commit();

        // 发送 Webhook 通知
        sendWebhook('batch_audited', [
            'count' => $affected_rows,
            'amount' => $total_amount
        ]);

        $_SESSION['batch_message'] = [
            'type' => 'success',
            'text' => "成功批量标记 {$affected_rows} 张发票为已开票，总金额：" . number_format($total_amount, 2) . "元"
        ];

        // 重定向到当前页面
        header("Location: invoices.php");
        exit;

    } catch (Exception $e) {
        // 回滚事务
        $conn->rollback();

        $_SESSION['batch_message'] = [
            'type' => 'error',
            'text' => "批量标记失败: " . $e->getMessage()
        ];

        // 重定向到当前页面
        header("Location: invoices.php");
        exit;
    }
}

// 显示批量操作消息
if (isset($_SESSION['batch_message'])) {
    $batch_message = $_SESSION['batch_message'];
    unset($_SESSION['batch_message']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>发票管理</title>
    <link rel="stylesheet" href="styles.css">
    <script src="js/qrcode.min.js"></script>
    <style>
    </style>
</head>

<body>
    <div class="container">
        <?php include 'includes/header.php'; ?>

        <div class="main-content">
            <?php if (isset($_GET['message'])): ?>
                <div class="alert success">
                    <?php echo htmlspecialchars($_GET['message']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($batch_message)): ?>
                <div class="batch-message batch-message-<?php echo $batch_message['type']; ?>">
                    <?php echo htmlspecialchars($batch_message['text']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['action']) && $_GET['action'] === 'view'): ?>
                <!-- 发票详情页面 -->
                <?php
                // 生成发票文件URL
                $invoice_file_url = $invoice['invoice_file'] ?
                    "uploads/invoices/" . $invoice['invoice_file'] : '';
                $full_invoice_url = $invoice_file_url ?
                    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . 'is_wx.php?url=' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . $invoice_file_url : '';
                ?>
                <div class="invoice-detail-container">
                    <div class="detail-header">
                        <h1>发票详情</h1>
                        <div class="header-actions">
                            <a href="invoices.php" class="btn btn-secondary">返回列表</a>
                            <?php
                            $allow_finance = getSetting($conn, 'allow_finance_create_invoice', 0);
                            $can_edit = ($user_role === 'sales' || ($user_role === 'finance' && $allow_finance));
                            if ($can_edit && $invoice['created_by'] == $user_id && $invoice['finance_status'] === 'pending'):
                                ?>
                                <a href="edit_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-primary">编辑</a>
                            <?php endif; ?>
                            <?php if ($user_role === 'finance' && $invoice['finance_status'] === 'pending'): ?>
                                <a href="upload_invoice_file.php?id=<?php echo $invoice['id']; ?>"
                                    class="btn btn-success">标记开票</a>
                            <?php endif; ?>
                            <?php if ($invoice['created_by'] == $user_id && $invoice['finance_status'] === 'invoiced' && (empty($invoice['is_voided']) || $invoice['is_voided'] == 0)): ?>
                                <a href="mark_incorrect.php?id=<?php echo $invoice['id']; ?>" class="btn btn-warning">标记有误</a>
                            <?php endif; ?>
                            <?php if ($user_role === 'finance' && $invoice['finance_status'] === 'incorrect' && (empty($invoice['is_voided']) || $invoice['is_voided'] == 0)): ?>
                                <a href="confirm_incorrect.php?id=<?php echo $invoice['id']; ?>"
                                    class="btn btn-danger">确认有误并退回</a>
                            <?php endif; ?>
                            <?php if ($user_role === 'finance' && $invoice['finance_status'] === 'invoiced' && (empty($invoice['is_voided']) || $invoice['is_voided'] == 0)): ?>
                                <a href="void_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-danger">作废</a>
                                <?php if ($invoice['invoice_file']): ?>
                                    <a href="reupload_invoice_file.php?id=<?php echo $invoice['id']; ?>"
                                        class="btn btn-info">重传附件</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-sections">
                        <!-- 基本信息 -->
                        <div class="detail-section">
                            <h2>基本信息</h2>
                            <div class="info-grid">
                                <div class="info-item">
                                    <strong>系统编号：</strong>
                                    <span><?php echo $invoice['invoice_number']; ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>发票类型：</strong>
                                    <span><?php echo $invoice['invoice_type']; ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>客户名称：</strong>
                                    <span><?php echo $invoice['customer_name']; ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>税号：</strong>
                                    <span><?php echo $invoice['tax_number'] ?: '无'; ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>联系方式：</strong>
                                    <span><?php echo $invoice['customer_contact'] ?: '无'; ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>总金额：</strong>
                                    <span class="amount">¥<?php echo number_format($invoice['total_amount'], 2); ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>状态：</strong>
                                    <span class="status <?php echo getInvoiceStatusClass($invoice); ?>">
                                        <?php echo getInvoiceStatusText($invoice); ?>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <strong>发票编号：</strong>
                                    <span><?php echo $invoice['slip_number']; ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>创建人：</strong>
                                    <span><?php echo $invoice['created_by_name']; ?></span>
                                </div>
                                <div class="info-item">
                                    <strong>创建时间：</strong>
                                    <span><?php echo date('Y-m-d H:i', strtotime($invoice['created_at'])); ?></span>
                                </div>
                                <?php if ($invoice['finance_status'] === 'invoiced'): ?>
                                    <div class="info-item">
                                        <strong>开票时间：</strong>
                                        <span><?php echo date('Y-m-d H:i', strtotime($invoice['invoiced_at'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($invoice['is_voided']) && $invoice['is_voided'] == 1): ?>
                            <!-- 作废信息 -->
                            <div class="detail-section" style="border-left: 4px solid #dc3545;">
                                <h2 style="color: #dc3545;">作废信息</h2>
                                <div class="info-grid">
                                    <?php if (!empty($invoice['voided_at'])): ?>
                                        <div class="info-item">
                                            <strong>作废时间：</strong>
                                            <span><?php echo date('Y-m-d H:i', strtotime($invoice['voided_at'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($invoice['void_reason'])): ?>
                                        <div class="info-item">
                                            <strong>作废原因：</strong>
                                            <span><?php echo nl2br(htmlspecialchars($invoice['void_reason'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- 货物信息 -->
                        <div class="detail-section">
                            <h2>货物信息</h2>
                            <div class="table-responsive">
                                <table class="items-table">
                                    <thead>
                                        <tr>
                                            <th>货物名称</th>
                                            <th>型号</th>
                                            <th>单价</th>
                                            <th>单位</th>
                                            <th>数量</th>
                                            <th>金额</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($invoice['items'] as $item): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                <td><?php echo htmlspecialchars($item['model']); ?></td>
                                                <td>¥<?php echo number_format($item['unit_price'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                                <td><?php echo number_format($item['quantity'], 2); ?></td>
                                                <td>¥<?php echo number_format($item['amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="5" style="text-align: right;"><strong>总计：</strong></td>
                                            <td><strong>¥<?php echo number_format($invoice['total_amount'], 2); ?></strong>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- 备注信息 -->
                        <?php if ($invoice['remarks']): ?>
                            <div class="detail-section">
                                <h2>备注</h2>
                                <div class="remarks">
                                    <?php echo nl2br(htmlspecialchars($invoice['remarks'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- 发票文件 -->
                        <?php if ($invoice['finance_status'] === 'invoiced' && $invoice['invoice_file']): ?>
                            <div class="detail-section">
                                <h2>电子发票</h2>
                                <div class="invoice-file">
                                    <p>已上传电子发票文件：<strong><?php echo $invoice['invoice_file']; ?></strong></p>
                                    <div class="file-actions" style="margin-bottom: 20px;">
                                        <a href="uploads/invoices/<?php echo $invoice['invoice_file']; ?>"
                                            class="btn btn-primary" target="_blank" download>下载查看</a>

                                        <button type="button" class="btn btn-share"
                                            onclick="copyInvoiceUrl('<?php echo htmlspecialchars($full_invoice_url); ?>')">
                                            分享下载地址
                                        </button>
                                    </div>


                                    <!-- 自动触发下载 -->
                                    <iframe src="uploads/invoices/<?php echo urlencode($invoice['invoice_file']); ?>" style="display: none;"></iframe>

                                    <!-- 直接显示二维码区域 -->
                                    <div class="qrcode-display">
                                        <h3>发票下载二维码</h3>
                                        <p class="qrcode-hint">扫描二维码查看和下载发票</p>
                                        <div class="qrcode-container" id="qrcode-container">
                                            <!-- 二维码将在这里生成 -->
                                        </div>
                                        <p class="qrcode-instruction">使用手机扫描二维码，在浏览器中打开地址查看和下载</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- 发票列表页面 -->
                <div class="list-header">
                    <h1>发票管理
                        <?php if ($user_role === 'sales'): ?>
                            <span class="permission-hint">（仅显示您创建的发票）</span>
                        <?php else: ?>
                            <span class="permission-hint">（显示所有发票）</span>
                        <?php endif; ?>
                    </h1>

                    <div class="list-controls">
                        <form method="GET" class="search-form">
                            <input type="text" name="search" placeholder="搜索系统编号，发票编号或客户名称，客户联系方式"
                                value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-secondary">搜索</button>
                            <?php if ($search): ?>
                                <a href="invoices.php" class="btn btn-cancel">清除</a>
                            <?php endif; ?>
                            <!-- 添加导出按钮 -->
                            <button type="button" class="btn btn-export" onclick="showExportModal()">
                                导出筛选结果
                            </button>
                        </form>

                        <!-- 修改 filter-tabs 部分 -->
                        <div class="filter-tabs">
                            <!-- 发票类型下拉筛选 -->
                            <?php
                            // 获取当前选中的类型
                            $selected_type = $_GET['invoice_type'] ?? '';

                            // 获取数据库中实际的发票类型列表
                            $type_query = "SELECT DISTINCT invoice_type FROM invoices ORDER BY invoice_type";
                            $type_result = $conn->query($type_query);
                            $invoice_types = [];
                            while ($row = $type_result->fetch_assoc()) {
                                if (!empty($row['invoice_type'])) {
                                    $invoice_types[] = $row['invoice_type'];
                                }
                            }
                            ?>

                            <div class="type-filter-dropdown">
                                <select name="invoice_type" class="invoice-type-select"
                                    onchange="filterInvoices('invoice_type', this.value)">
                                    <option value="">所有类型</option>
                                    <?php foreach ($invoice_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $selected_type === $type ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if ($user_role === 'finance'): ?>
                                <?php
                                // 获取当前开启状态
                                $allow_finance = getSetting($conn, 'allow_finance_create_invoice', 0);

                                // 根据是否允许财务创建发票动态决定筛选的用户角色
                                if ($allow_finance) {
                                    $sp_query = "SELECT id, full_name, username, role FROM users WHERE role IN ('sales', 'finance') AND status = 'active' ORDER BY role DESC, full_name";
                                } else {
                                    $sp_query = "SELECT id, full_name, username, role FROM users WHERE role = 'sales' AND status = 'active' ORDER BY full_name";
                                }
                                $sp_result = $conn->query($sp_query);
                                $salespersons = $sp_result ? $sp_result->fetch_all(MYSQLI_ASSOC) : [];
                                ?>
                                <div class="type-filter-dropdown" style="margin-left: 10px;">
                                    <select name="salesperson_id" class="invoice-type-select"
                                        onchange="filterInvoices('salesperson_id', this.value)">
                                        <option value=""><?php echo $allow_finance ? '所有创建人' : '所有业务员'; ?></option>
                                        <?php foreach ($salespersons as $sp): ?>
                                            <option value="<?php echo $sp['id']; ?>" <?php echo $salesperson_id == $sp['id'] ? 'selected' : ''; ?>>
                                                <?php
                                                $role_label = ($sp['role'] === 'finance') ? '[财务] ' : '[业务] ';
                                                echo htmlspecialchars($role_label . $sp['full_name'] . ' (' . $sp['username'] . ')');
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <?php
                            // 构建标签链接，保持现有的筛选条件
                            $query_params = [];
                            if ($search)
                                $query_params['search'] = $search;
                            if ($selected_type)
                                $query_params['invoice_type'] = $selected_type;
                            if ($salesperson_id)
                                $query_params['salesperson_id'] = $salesperson_id;
                            ?>

                            <a href="invoices.php?<?php echo http_build_query($query_params); ?>"
                                class="tab <?php echo $filter === 'all' ? 'active' : ''; ?>">全部</a>

                            <?php
                            $query_params['filter'] = 'pending';
                            ?>
                            <a href="invoices.php?<?php echo http_build_query($query_params); ?>"
                                class="tab <?php echo $filter === 'pending' ? 'active' : ''; ?>">待开票</a>

                            <?php
                            $query_params['filter'] = 'invoiced';
                            ?>
                            <a href="invoices.php?<?php echo http_build_query($query_params); ?>"
                                class="tab <?php echo $filter === 'invoiced' ? 'active' : ''; ?>">已开票</a>

                            <?php
                            $query_params['filter'] = 'incorrect';
                            ?>
                            <a href="invoices.php?<?php echo http_build_query($query_params); ?>"
                                class="tab <?php echo $filter === 'incorrect' ? 'active' : ''; ?>">发票有误</a>

                            <?php if ($user_role === 'finance'): ?>
                                <?php
                                $query_params['filter'] = 'voided';
                                ?>
                                <a href="invoices.php?<?php echo http_build_query($query_params); ?>"
                                    class="tab <?php echo $filter === 'voided' ? 'active' : ''; ?>">已作废</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 批量操作区域 -->
                <?php if ($user_role === 'finance' && $pending_stats['count'] > 0): ?>
                    <div class="batch-action-section">
                        <div class="batch-info">
                            <div class="batch-stats">
                                <div class="stat-item">
                                    <span class="stat-label">待开票数量</span>
                                    <span class="stat-value"><?php echo $pending_stats['count']; ?> 张</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">待开票总金额</span>
                                    <span
                                        class="stat-value">¥<?php echo number_format($pending_stats['total_amount'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="batch-actions" style="display: none;">
                            <button type="button" class="btn-batch btn-batch-warning" onclick="showBatchMarkConfirm()">
                                一键标记所有为已开票
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($user_role === 'sales'): ?>
                    <div class="stats-cards" style="margin-bottom: 20px; display: none;">
                        <?php
                        // 获取业务员的统计信息
                        $sales_stats_sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN finance_status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN finance_status = 'invoiced' THEN 1 ELSE 0 END) as invoiced,
                        SUM(CASE WHEN finance_status = 'incorrect' THEN 1 ELSE 0 END) as incorrect,
                        SUM(total_amount) as total_amount
                        FROM invoices 
                        WHERE created_by = $user_id";
                        $sales_stats_result = $conn->query($sales_stats_sql);
                        $sales_stats = $sales_stats_result ? $sales_stats_result->fetch_assoc() : [];
                        ?>
                        <div class="card">
                            <h3>我的发票总数</h3>
                            <p class="stat-number"><?php echo $sales_stats['total'] ?? 0; ?></p>
                        </div>
                        <div class="card">
                            <h3>待开票</h3>
                            <p class="stat-number"><?php echo $sales_stats['pending'] ?? 0; ?></p>
                        </div>
                        <div class="card">
                            <h3>已开票</h3>
                            <p class="stat-number"><?php echo $sales_stats['invoiced'] ?? 0; ?></p>
                        </div>
                        <div class="card">
                            <h3>发票有误</h3>
                            <p class="stat-number"><?php echo $sales_stats['incorrect'] ?? 0; ?></p>
                        </div>
                        <div class="card">
                            <h3>总金额</h3>
                            <p class="stat-number">¥<?php echo number_format($sales_stats['total_amount'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>系统编号</th>
                                <th>客户名称</th>
                                <th>发票类型</th>
                                <th>税号</th>
                                <th>总金额</th>
                                <th>状态</th>
                                <?php if ($user_role === 'finance'): ?>
                                    <th>创建人</th>
                                <?php endif; ?>
                                <th>创建时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr>
                                    <td colspan="<?php echo $user_role === 'finance' ? 8 : 7; ?>"
                                        style="text-align: center; padding: 40px;">
                                        <div class="empty-state">
                                            <?php
                                            $allow_finance = getSetting($conn, 'allow_finance_create_invoice', 0);
                                            if ($user_role === 'sales' || ($user_role === 'finance' && $allow_finance)):
                                                ?>
                                                您还没有创建任何发票，<a href="add_invoice.php">点击这里创建第一张发票</a>
                                            <?php else: ?>
                                                暂无发票数据
                                            <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $invoice): ?>
                                    <?php
                                    $is_voided = !empty($invoice['is_voided']) && $invoice['is_voided'] == 1;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                        <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                                        <td>
                                            <?php
                                            $type = htmlspecialchars($invoice['invoice_type']);
                                            $color = 'black'; // 默认颜色
                                            if (strpos($invoice['invoice_type'], '普票') !== false) {
                                                $color = 'green';
                                            } elseif (strpos($invoice['invoice_type'], '专票') !== false) {
                                                $color = 'red';
                                            }
                                            ?>
                                            <span style="color: <?php echo $color; ?>;"><?php echo $type; ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($invoice['tax_number'] ?: '无'); ?></td>
                                        <td>
                                            <?php
                                            $amount = number_format($invoice['total_amount'], 2);
                                            $is_special = strpos($invoice['invoice_type'], '专票') !== false;
                                            $min_limited = getSetting($conn, 'invoice_limit_number', 2000);
                                            $needs_highlight = $is_special && $invoice['total_amount'] < $min_limited;
                                            ?>

                                            <?php if ($needs_highlight): ?>
                                                <span class="highlight-amount" title="专票且金额小于<?php echo $min_limited; ?>>元">
                                                    ¥<?php echo $amount; ?>
                                                </span>
                                            <?php else: ?>
                                                ¥<?php echo $amount; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status <?php echo getInvoiceStatusClass($invoice); ?>">
                                                <?php echo getInvoiceStatusText($invoice); ?>
                                            </span>
                                        </td>
                                        <?php if ($user_role === 'finance'): ?>
                                            <td><?php echo htmlspecialchars($invoice['created_by_name']); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo date('Y-m-d H:i', strtotime($invoice['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="invoices.php?action=view&id=<?php echo $invoice['id']; ?>"
                                                    class="btn btn-view">查看</a>

                                                <?php
                                                $allow_finance = getSetting($conn, 'allow_finance_create_invoice', 0);
                                                $can_edit = ($user_role === 'sales' || ($user_role === 'finance' && $allow_finance));
                                                if ($can_edit && $invoice['created_by'] == $user_id && $invoice['finance_status'] === 'pending' && !$is_voided):
                                                    ?>
                                                    <a href="edit_invoice.php?id=<?php echo $invoice['id']; ?>"
                                                        class="btn btn-edit">编辑</a>
                                                    <a href="delete_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-delete"
                                                        onclick="return confirm('确定要删除这张发票吗？')">删除</a>
                                                <?php endif; ?>

                                                <?php if ($user_role === 'finance' && $invoice['finance_status'] === 'pending' && !$is_voided): ?>
                                                    <a href="upload_invoice_file.php?id=<?php echo $invoice['id']; ?>"
                                                        class="btn btn-success">开票</a>
                                                <?php endif; ?>



                                                <?php if ($user_role === 'finance' && $invoice['finance_status'] === 'invoiced' && !$is_voided): ?>
                                                    <a href="void_invoice.php?id=<?php echo $invoice['id']; ?>"
                                                        class="btn btn-void">作废</a>
                                                <?php endif; ?>
                                                <?php if ($user_role === 'finance' && $invoice['finance_status'] === 'invoiced' && !$is_voided && $invoice['invoice_file']): ?>
                                                    <a href="reupload_invoice_file.php?id=<?php echo $invoice['id']; ?>"
                                                        class="btn btn-info">重传附件</a>
                                                <?php endif; ?>
                                                <?php if ($user_role === 'finance' && $invoice['finance_status'] === 'invoiced' && !$is_voided && !$invoice['invoice_file']): ?>
                                                    <a href="reupload_invoice_file.php?id=<?php echo $invoice['id']; ?>"
                                                        class="btn btn-info">上传附件</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- 分页 -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="invoices.php?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">首页</a>
                                <a
                                    href="invoices.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">上一页</a>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $start + 4);
                            for ($i = $start; $i <= $end; $i++):
                                ?>
                                <a href="invoices.php?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                    class="<?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a
                                    href="invoices.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">下一页</a>
                                <a
                                    href="invoices.php?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">末页</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 批量标记确认模态框 -->
    <div id="batchMarkConfirmModal" class="batch-confirm-modal">
        <div class="batch-modal-content">
            <div class="batch-modal-header">
                <h3>确认批量标记为已开票</h3>
            </div>
            <div class="batch-modal-body">
                <p><strong>您确定要执行批量标记操作吗？</strong></p>
                <p>此操作将一次性将所有待开票的发票标记为"已开票"状态。</p>

                <div class="batch-summary">
                    <h4>操作摘要：</h4>
                    <ul>
                        <li>发票数量：<strong><?php echo $pending_stats['count']; ?> 张</strong></li>
                        <li>总金额：<strong>¥<?php echo number_format($pending_stats['total_amount'], 2); ?></strong></li>
                        <li>操作类型：<strong>仅标记状态，不上传发票文件</strong></li>
                    </ul>
                </div>

                <p style="color: #f44336; font-weight: 500;">
                    ⚠️ 注意：此操作不可逆，请谨慎操作！<br>
                    （如需上传发票文件，请使用"开票"按钮单独处理）
                </p>
            </div>
            <div class="batch-modal-footer">
                <button type="button" class="btn-batch" onclick="hideBatchMarkConfirm()"
                    style="background: #6c757d; color: white;">
                    取消
                </button>
                <form method="POST" style="margin: 0;">
                    <button type="submit" name="batch_mark_invoiced" class="btn-batch btn-batch-warning">
                        确认批量标记
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- 导出模态框 -->
    <div id="exportModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3>导出发票数据</h3>
                <span class="close" onclick="closeExportModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="export-options">
                    <div class="export-info">
                        <p>当前筛选条件：</p>
                        <ul>
                            <li>状态：<?php
                            if ($filter === 'all')
                                echo '全部';
                            elseif ($filter === 'pending')
                                echo '待开票';
                            elseif ($filter === 'invoiced')
                                echo '已开票';
                            elseif ($filter === 'voided')
                                echo '已作废';
                            else
                                echo '全部';
                            ?></li>
                            <?php if ($invoice_type): ?>
                                <li>发票类型：<?php echo htmlspecialchars($invoice_type); ?></li>
                            <?php endif; ?>
                            <?php if (isset($salesperson_id) && $salesperson_id):
                                $sp_user = getUserById(intval($salesperson_id));
                                if ($sp_user):
                                    $role_label = ($sp_user['role'] === 'finance') ? '财务员' : '业务员';
                                    ?>
                                    <li><?php echo $role_label; ?>：<?php echo htmlspecialchars($sp_user['full_name']); ?></li>
                                <?php endif; endif; ?>
                            <?php if ($search): ?>
                                <li>搜索关键词：<?php echo htmlspecialchars($search); ?></li>
                            <?php endif; ?>
                            <li>数据总数：<?php echo $total; ?> 条</li>
                            <li>导出范围：当前筛选条件下的所有数据</li>
                        </ul>
                    </div>

                    <form id="exportForm" action="export_invoices.php" method="POST">
                        <!-- 关键：传递所有筛选参数 -->
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                        <input type="hidden" name="invoice_type" value="<?php echo htmlspecialchars($invoice_type); ?>">
                        <input type="hidden" name="salesperson_id"
                            value="<?php echo htmlspecialchars($salesperson_id); ?>">
                        <input type="hidden" name="user_role" value="<?php echo $user_role; ?>">
                        <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">

                        <div class="form-group">
                            <label for="export_format">导出格式：</label>
                            <select id="export_format" name="format" class="form-control">
                                <option value="excel">Excel (.xlsx)</option>
                                <option value="csv">CSV (.csv)</option>
                                <option value="pdf">PDF (.pdf)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="export_fields">包含字段：</label>
                            <div class="fields-checkboxes">
                                <label><input type="checkbox" name="fields[]" value="invoice_number"> 系统编号</label>
                                <label><input type="checkbox" name="fields[]" value="customer_name" checked>
                                    客户名称</label>
                                <label><input type="checkbox" name="fields[]" value="customer_contact"> 联系方式</label>
                                <label><input type="checkbox" name="fields[]" value="tax_number" checked> 税号</label>
                                <label><input type="checkbox" name="fields[]" value="invoice_type" checked> 发票类型</label>
                                <label><input type="checkbox" name="fields[]" value="total_amount" checked> 总金额</label>
                                <label><input type="checkbox" name="fields[]" value="finance_status" checked> 状态</label>
                                <?php if ($user_role === 'finance'): ?>
                                    <label><input type="checkbox" name="fields[]" value="created_by_name"> 创建人</label>
                                <?php endif; ?>
                                <label><input type="checkbox" name="fields[]" value="created_at"> 创建时间</label>
                                <label><input type="checkbox" name="fields[]" value="invoiced_at"> 开票时间</label>
                                <label><input type="checkbox" name="fields[]" value="remarks"> 备注</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="include_items" value="1" checked> 包含货物明细
                            </label>
                            <small class="text-muted">（勾选后将导出每张发票的货物明细）</small>
                        </div>

                    </form>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeExportModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="submitExport()">开始导出</button>
            </div>
        </div>
    </div>

    <script>
        // 统一过滤跳转函数（保留其他筛选参数）
        function filterInvoices(key, value) {
            const urlParams = new URLSearchParams(window.location.search);
            if (value) {
                urlParams.set(key, value);
            } else {
                urlParams.delete(key);
            }
            urlParams.delete('page'); // 重置页码为第一页
            window.location.search = urlParams.toString();
        }

        // 确认删除
        function confirmDelete(invoiceId, invoiceNumber) {
            if (confirm('确定要删除发票 "' + invoiceNumber + '" 吗？此操作不可恢复！')) {
                window.location.href = 'delete_invoice.php?id=' + invoiceId;
            }
            return false;
        }

        // 搜索表单提交
        document.querySelector('.search-form')?.addEventListener('submit', function (e) {
            const searchInput = this.querySelector('input[name="search"]');
            if (searchInput.value.trim() === '') {
                e.preventDefault();
                window.location.href = 'invoices.php';
            }
        });

        // 复制发票地址
        function copyInvoiceUrl(url) {
            if (!url || url === '') {
                alert('发票地址为空，无法复制');
                return;
            }

            const textToCopy = "发票下载地址：" + url + "\n\n请在浏览器中打开地址查看和下载";

            // 使用现代 Clipboard API
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy)
                    .then(() => {
                        alert('发票地址已复制到剪贴板！\n请在浏览器中打开地址查看和下载');
                    })
                    .catch(err => {
                        console.error('复制失败:', err);
                        fallbackCopyTextToClipboard(textToCopy);
                    });
            } else {
                // 降级方案
                fallbackCopyTextToClipboard(textToCopy);
            }
        }

        // 降级的复制方法
        function fallbackCopyTextToClipboard(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.left = "-999999px";
            textArea.style.top = "-999999px";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                const successful = document.execCommand('copy');
                const msg = successful ? '成功' : '失败';
                if (successful) {
                    alert('发票地址已复制到剪贴板！\n请在浏览器中打开地址查看和下载');
                } else {
                    alert('复制失败，请手动复制地址：\n\n' + text);
                }
            } catch (err) {
                console.error('复制失败:', err);
                alert('复制失败，请手动复制地址：\n\n' + text);
            }

            document.body.removeChild(textArea);
        }

        // 生成二维码
        let qrcodeInstance = null;

        function generateQRCode() {
            const invoiceUrl = '<?php echo isset($full_invoice_url) ? $full_invoice_url : ""; ?>';

            if (!invoiceUrl) {
                return;
            }

            const container = document.getElementById('qrcode-container');
            if (!container) {
                return;
            }

            container.innerHTML = '';

            if (typeof QRCode === 'undefined') {
                console.error('QRCode library not loaded');
                container.innerHTML = '<div class="qrcode-error">二维码库加载失败，请刷新页面</div>';
                return;
            }

            try {
                if (qrcodeInstance) {
                    container.innerHTML = '';
                    qrcodeInstance = null;
                }

                qrcodeInstance = new QRCode(container, {
                    text: invoiceUrl,
                    width: 180,
                    height: 180,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });

            } catch (error) {
                console.error('生成二维码失败:', error);
                container.innerHTML = '<div class="qrcode-error">二维码生成失败</div>';
            }
        }

        // 页面加载完成后生成二维码
        document.addEventListener('DOMContentLoaded', function () {
            const urlParams = new URLSearchParams(window.location.search);
            const action = urlParams.get('action');
            const id = urlParams.get('id');

            if (action === 'view' && id) {
                setTimeout(() => {
                    generateQRCode();
                }, 500);
            }

            // 自动隐藏批量操作消息
            setTimeout(() => {
                const batchMessages = document.querySelectorAll('.batch-message');
                batchMessages.forEach(message => {
                    message.style.opacity = '0';
                    message.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => {
                        if (message.parentNode) {
                            message.parentNode.removeChild(message);
                        }
                    }, 500);
                });
            }, 3000);
        });

        // 显示批量标记确认模态框
        function showBatchMarkConfirm() {
            document.getElementById('batchMarkConfirmModal').classList.add('active');
        }

        // 隐藏批量标记确认模态框
        function hideBatchMarkConfirm() {
            document.getElementById('batchMarkConfirmModal').classList.remove('active');
        }

        // 显示导出模态框
        function showExportModal() {
            const modal = document.getElementById('exportModal');
            if (modal) {
                modal.style.display = 'block';

                // 获取当前页面的筛选参数
                const urlParams = new URLSearchParams(window.location.search);
                const currentFilter = urlParams.get('filter') || 'all';
                const currentSearch = urlParams.get('search') || '';
                const currentInvoiceType = urlParams.get('invoice_type') || '';

                // 设置导出表单中的隐藏字段
                document.querySelector('input[name="filter"]').value = currentFilter;
                document.querySelector('input[name="search"]').value = currentSearch;
                document.querySelector('input[name="invoice_type"]').value = currentInvoiceType;

                const currentSalespersonId = urlParams.get('salesperson_id') || '';
                const salespersonInput = document.querySelector('input[name="salesperson_id"]');
                if (salespersonInput) {
                    salespersonInput.value = currentSalespersonId;
                }

            }
        }
        // 关闭导出模态框
        function closeExportModal() {
            const modal = document.getElementById('exportModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        // 提交导出
        function submitExport() {
            const form = document.getElementById('exportForm');
            if (!form) return;

            const checkedFields = form.querySelectorAll('input[name="fields[]"]:checked');
            if (checkedFields.length === 0) {
                alert('请至少选择一个要导出的字段！');
                return;
            }

            const exportBtn = document.querySelector('#exportModal .modal-footer .btn-primary');
            const originalText = exportBtn.textContent;
            exportBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 正在导出...';
            exportBtn.disabled = true;

            form.submit();

            setTimeout(() => {
                exportBtn.textContent = originalText;
                exportBtn.disabled = false;
            }, 5000);
        }

        // 点击模态框外部关闭
        window.onclick = function (event) {
            const exportModal = document.getElementById('exportModal');
            const batchModal = document.getElementById('batchMarkConfirmModal');

            if (event.target == exportModal) {
                closeExportModal();
            }
            if (event.target == batchModal) {
                hideBatchMarkConfirm();
            }
        }

        // 按ESC键关闭模态框
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeExportModal();
                hideBatchMarkConfirm();
            }
        });
    </script>

</body>

</html>