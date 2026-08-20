<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Only finance personnel can access
// checkRole(['finance']);

$conn = getDbConnection();
$error = '';
$success = '';

// Add Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    try {
        $bank = sanitize($_POST['bank']);
        $company = sanitize($_POST['company']);
        $account_tail = sanitize($_POST['account_tail']);
        $date = sanitize($_POST['date']);
        $time = sanitize($_POST['time']);
        $transaction_type = sanitize($_POST['transaction_type']);
        $amount = floatval($_POST['amount']);
        $currency = sanitize($_POST['currency']);
        $payer = sanitize($_POST['payer']);
        
        if (empty($bank) || empty($company) || empty($amount)) {
            throw new Exception('请填写必要字段');
        }
        
        $stmt = $conn->prepare("INSERT INTO income_records (bank, company, account_tail, transaction_date, transaction_time, transaction_type, amount, currency, payer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssdss", $bank, $company, $account_tail, $date, $time, $transaction_type, $amount, $currency, $payer);
        
        if ($stmt->execute()) {
            // 发送 Webhook 通知
            sendWebhook('income_added', [
                'company' => $company,
                'amount' => $amount,
                'currency' => $currency,
                'bank' => $bank,
                'payer' => $payer,
                'date' => $date,
                'time' => $time
            ]);

            $success = '入账信息添加成功！';
        } else {
            throw new Exception('添加失败：' . $conn->error);
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Delete Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record'])) {
    $record_id = intval($_POST['record_id']);
    
    $stmt = $conn->prepare("DELETE FROM income_records WHERE id = ?");
    $stmt->bind_param("i", $record_id);
    
    if ($stmt->execute()) {
        // 发送 Webhook 通知
        sendWebhook('income_deleted', ['id' => $record_id]);
        
        $success = '记录删除成功！';
    } else {
        $error = '删除失败：' . $conn->error;
    }
}

// Update Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_record'])) {
    try {
        $id = intval($_POST['record_id']);
        $bank = sanitize($_POST['bank']);
        $company = sanitize($_POST['company']);
        $account_tail = sanitize($_POST['account_tail']);
        $date = sanitize($_POST['date']);
        $time = sanitize($_POST['time']);
        $transaction_type = sanitize($_POST['transaction_type']);
        $amount = floatval($_POST['amount']);
        $currency = sanitize($_POST['currency']);
        $payer = sanitize($_POST['payer']);
        
        $stmt = $conn->prepare("UPDATE income_records SET bank=?, company=?, account_tail=?, transaction_date=?, transaction_time=?, transaction_type=?, amount=?, currency=?, payer=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("ssssssdssi", $bank, $company, $account_tail, $date, $time, $transaction_type, $amount, $currency, $payer, $id);
        
        if ($stmt->execute()) {
            // 发送 Webhook 通知
            sendWebhook('income_updated', [
                'company' => $company,
                'amount' => $amount,
                'currency' => $currency,
                'payer' => $payer,
                'date' => $date,
                'time' => $time
            ]);
            
            $success = '记录更新成功！';
        } else {
            throw new Exception('更新失败：' . $conn->error);
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Search/Filter
$search_query = "";
$search_params = [];
$search_types = "";
$where_clauses = [];

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = "%" . sanitize($_GET['search']) . "%";
    $where_clauses[] = "(company LIKE ? OR payer LIKE ? OR bank LIKE ? OR amount LIKE ?)";
    $search_params[] = $search;
    $search_params[] = $search;
    $search_params[] = $search;
    $search_params[] = $search;
    $search_types .= "ssss";
}

$sql = "SELECT * FROM income_records";
if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($search_params)) {
    $stmt->bind_param($search_types, ...$search_params);
}
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>入账信息管理</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .record-list {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            width: 90%;
            max-width: 960px;
            border-radius: 8px;
            position: relative;
        }
        .close {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 24px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'includes/header.php'; ?>
        
        <div class="main-content">
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1>入账信息管理</h1>
				<?php if ($_SESSION['role'] === 'finance'): ?>
                <button class="btn btn-primary" onclick="openAddModal()">新增入账记录</button>
				<?php endif; ?>
            </div>
            
            <?php if ($error): ?>
            <div class="alert error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <div class="filter-section">
                <form method="GET" action="" style="display: flex; gap: 10px;">
                    <input type="text" name="search" placeholder="搜索公司、付款方、银行或金额..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" style="padding: 8px; min-width: 360px;">
                    <button type="submit" class="btn btn-secondary">查询</button>
                    <?php if (isset($_GET['search'])): ?>
                    <a href="income_records.php" class="btn btn-secondary">重置</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- 智能解析区域 -->
            <div class="filter-section" style="margin-top: 20px; display: none;">
                <h3>银行短信智能解析</h3>
                <div style="margin-top: 10px; display: flex; gap: 10px; flex-direction: column;">
                    <textarea id="smsInput" placeholder="请粘贴银行短信内容..." style="width: 100%; height: 80px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; resize: vertical;"></textarea>
                    <div style="text-align: right;">
                        <button type="button" class="btn btn-primary" onclick="handleExtract()">解析并填入表单</button>
                    </div>
                    <div id="parseResult" style="color: red; display: none;"></div>
                </div>
            </div>
            
            <div class="record-list">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>银行</th>
                            <th>公司</th>
                            <th>账号后四位</th>
                            <th>日期</th>
                            <th>时间</th>
                            <th>交易类型</th>
                            <th>金额</th>
                            <th>币种</th>
                            <th>付款方</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($records) > 0): ?>
                            <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?php echo $record['id']; ?></td>
                                <td><?php echo htmlspecialchars($record['bank']); ?></td>
                                <td><?php echo htmlspecialchars($record['company']); ?></td>
                                <td><?php echo htmlspecialchars($record['account_tail']); ?></td>
                                <td><?php echo htmlspecialchars($record['transaction_date']); ?></td>
                                <td><?php echo htmlspecialchars($record['transaction_time']); ?></td>
                                <td><?php echo htmlspecialchars($record['transaction_type']); ?></td>
                                <td><?php echo number_format($record['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($record['currency']); ?></td>
                                <td><?php echo htmlspecialchars($record['payer']); ?></td>
                                <td>
                                    <button class="btn btn-primary" onclick='openEditModal(<?php echo json_encode($record); ?>)'>编辑</button>
                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('确定要删除这条记录吗？')">
                                        <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                        <button type="submit" name="delete_record" class="btn btn-sm btn-danger">删除</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" style="text-align: center;">暂无记录</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="recordModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle" style="margin-bottom: 20px;">新增入账记录</h2>
            
            <!-- Modal内的智能解析区域 -->
            <div id="modalSmsSection" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px; border: 1px solid #e9ecef;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057;">银行短信智能解析</label>
                <textarea id="modalSmsInput" placeholder="在此粘贴银行短信内容，点击解析自动填充..." style="width: 100%; height: 80px; padding: 10px; border: 1px solid #ced4da; border-radius: 4px; resize: vertical; margin-bottom: 10px; font-family: inherit;"></textarea>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div id="modalParseResult" style="color: #dc3545; font-size: 14px; display: none;"></div>
                    <button type="button" class="btn btn-primary" onclick="handleModalExtract()" style="margin-left: auto;">解析并填充</button>
                </div>
            </div>

            <form method="POST" action="" id="recordForm">
                <input type="hidden" name="record_id" id="record_id">
                <div class="form-grid">
                    <div class="form-group">
                        <label>银行</label>
                        <input type="text" name="bank" id="bank" required>
                    </div>
                    <div class="form-group">
                        <label>公司</label>
                        <input type="text" name="company" id="company" required>
                    </div>
                    <div class="form-group">
                        <label>账号后四位</label>
                        <input type="text" name="account_tail" id="account_tail">
                    </div>
                    <div class="form-group">
                        <label>日期</label>
                        <input type="text" name="date" id="date" placeholder="2025年01月20日">
                    </div>
                    <div class="form-group">
                        <label>时间</label>
                        <input type="text" name="time" id="time" placeholder="18:23">
                    </div>
                    <div class="form-group">
                        <label>交易类型</label>
                        <input type="text" name="transaction_type" id="transaction_type">
                    </div>
                    <div class="form-group">
                        <label>金额</label>
                        <input type="number" step="0.01" name="amount" id="amount" required>
                    </div>
                    <div class="form-group">
                        <label>币种</label>
                        <input type="text" name="currency" id="currency" value="RMB">
                    </div>
                    <div class="form-group">
                        <label>付款方</label>
                        <input type="text" name="payer" id="payer">
                    </div>
                </div>
                <div class="form-actions" style="margin-top: 20px; text-align: right;">
                    <button type="submit" name="add_record" id="submitBtn" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function parseDate(dateStr) {
            const now = new Date();
            const currentYear = now.getFullYear();
            const currentMonth = now.getMonth() + 1;
            
            let month = currentMonth;
            let day = '';
            
            // Check for MM月DD日
            const monthMatch = dateStr.match(/(\d+)月(\d+)日/);
            if (monthMatch) {
                month = parseInt(monthMatch[1], 10);
                day = parseInt(monthMatch[2], 10);
            } else {
                // Check for DD日
                const dayMatch = dateStr.match(/(\d+)日/);
                if (dayMatch) {
                    day = parseInt(dayMatch[1], 10);
                } else {
                    return dateStr;
                }
            }
            
            const pad = (n) => n < 10 ? '0' + n : '' + n;
            return `${currentYear}年${pad(month)}月${pad(day)}日`;
        }

        function parseTime(timeStr) {
            // Check for HH时MM分
            const cnMatch = timeStr.match(/(\d+)时(\d+)分/);
            if (cnMatch) {
                const hour = cnMatch[1].padStart(2, '0');
                const minute = cnMatch[2].padStart(2, '0');
                return `${hour}:${minute}`;
            }
            
            // Check for HH:MM
            const colonMatch = timeStr.match(/(\d+):(\d+)/);
            if (colonMatch) {
                 const hour = colonMatch[1].padStart(2, '0');
                 const minute = colonMatch[2].padStart(2, '0');
                 return `${hour}:${minute}`;
            }
            
            return timeStr;
        }

        function extractInfo(text) {
            // 模板一：平安银行 
            const pattern1 = /【(.+?)】贵公司尾号(\d+)账户\((.+?)\)(\d+月\d+日)(\d+时\d+分)(.+?)RMB([\d,]+\.\d+)元，付款方(.+)/;
        
            // 模板二：工商银行 
            const pattern2 = /(.+?)，贵单位尾号为(\d+)账户(\d+日)(\d+:\d+)(.+?)收入（(.+?)）([\d,]+(?:\.\d+)?)元.*【(.+?)】/;
        
            let match;
        
            // 尝试平安银行 
            match = text.match(pattern1);
            if (match) {
                return {
                    bank: match[1],
                    company: match[3],
                    account_tail: match[2],
                    date: parseDate(match[4]),
                    time: parseTime(match[5]),
                    transaction_type: match[6],
                    amount: match[7].replace(/,/g, ''),
                    currency: 'RMB',
                    payer: match[8].trim()
                };
            }
        
            // 尝试工商银行 
            match = text.match(pattern2);
            if (match) {
                return {
                    bank: match[8],
                    company: match[1],
                    account_tail: match[2],
                    date: parseDate(match[3]),
                    time: parseTime(match[4]),
                    transaction_type: '收入', // 工商银行统一标记为收入
                    remark: match[6],
                    amount: match[7].replace(/,/g, ''),
                    currency: 'RMB',
                    payer: '' // 工商银行短信未直接提供付款方
                };
            }
        
            return null;
        }

        function handleModalExtract() {
            const text = document.getElementById('modalSmsInput').value.trim();
            const resultEl = document.getElementById('modalParseResult');
            
            if (!text) {
                resultEl.textContent = '请输入短信内容';
                resultEl.style.display = 'block';
                return;
            }

            const data = extractInfo(text);

            if (!data) {
                resultEl.textContent = '❌ 未识别的短信格式';
                resultEl.style.display = 'block';
                return;
            }
            
            resultEl.textContent = '✅ 解析成功，已填充';
            resultEl.style.color = '#28a745';
            resultEl.style.display = 'block';
            setTimeout(() => { resultEl.style.display = 'none'; }, 3000);
            
            // 填充表单
            document.getElementById('bank').value = data.bank || '';
            document.getElementById('company').value = data.company || '';
            document.getElementById('account_tail').value = data.account_tail || '';
            document.getElementById('date').value = data.date || '';
            document.getElementById('time').value = data.time || '';
            document.getElementById('transaction_type').value = data.transaction_type || '';
            document.getElementById('amount').value = data.amount || '';
            document.getElementById('currency').value = data.currency || 'RMB';
            document.getElementById('payer').value = data.payer || '';
            
            if (data.remark) {
                 document.getElementById('transaction_type').value += ' (' + data.remark + ')';
            }
        }

        function handleExtract() {
             // 保留旧函数以防万一，但主页面区域已隐藏
        }

        function openAddModal() {
            document.getElementById('modalTitle').innerText = '新增入账记录';
            document.getElementById('submitBtn').name = 'add_record';
            document.getElementById('recordForm').reset();
            document.getElementById('record_id').value = '';
            document.getElementById('modalSmsInput').value = ''; // 清空短信输入框
            document.getElementById('modalParseResult').style.display = 'none';
            document.getElementById('modalSmsSection').style.display = 'block'; // 显示解析区域
            document.getElementById('recordModal').style.display = 'block';
        }

        function openEditModal(record) {
            document.getElementById('modalTitle').innerText = '编辑入账记录';
            document.getElementById('submitBtn').name = 'edit_record';
            
            document.getElementById('record_id').value = record.id;
            document.getElementById('bank').value = record.bank;
            document.getElementById('company').value = record.company;
            document.getElementById('account_tail').value = record.account_tail;
            document.getElementById('date').value = record.transaction_date;
            document.getElementById('time').value = record.transaction_time;
            document.getElementById('transaction_type').value = record.transaction_type;
            document.getElementById('amount').value = record.amount;
            document.getElementById('currency').value = record.currency;
            document.getElementById('payer').value = record.payer;
            
            document.getElementById('modalSmsSection').style.display = 'none'; // 编辑时隐藏解析区域
            document.getElementById('recordModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('recordModal').style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('recordModal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>
