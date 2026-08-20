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

$error = '';
$success = '';
$items = [['item_name' => '', 'model' => '', 'unit_price' => '', 'unit' => '个', 'quantity' => 1, 'amount' => '']];

// 获取税率设置用于显示
$tax_rate_zhuan = getSetting($conn, 'tax_rate_zhuan', '');
$tax_rate_pu = getSetting($conn, 'tax_rate_pu', '');
$invoice_limit_number = getSetting($conn, 'invoice_limit_number', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $toMoney2 = function($v) {
            if ($v === '' || $v === null) return null;
            if (is_string($v)) $v = trim($v);
            if ($v === '') return null;
            if (!is_numeric($v)) return null;
            return round((float)$v, 2);
        };

        // 基础验证
        if (empty($_POST['customer_name'])) {
            throw new Exception('请输入客户名称');
        }
        
        // 验证货物数据
        $valid_items = 0;
        foreach ($_POST['items'] as $item) {
            $hasAmount = $toMoney2($item['amount'] ?? null) !== null;
            if (!empty($item['item_name']) && !empty($item['quantity']) && (!empty($item['unit_price']) || $hasAmount)) {
                $valid_items++;
            }
        }
        
        if ($valid_items === 0) {
            throw new Exception('请填写至少一行完整的货物信息');
        }
        
        // 生成发票号
        $invoice_number = generateInvoiceNumber();
        
        // 获取税率设置进行验证
        $invoice_type = $_POST['invoice_type'];
        $tax_rate_zhuan = getSetting($conn, 'tax_rate_zhuan', 13);
        $tax_rate_pu = getSetting($conn, 'tax_rate_pu', 3);
        $invoice_limit_number = getSetting($conn, 'invoice_limit_number', 2000);
        
        // 开始事务
        $conn->begin_transaction();
        
        // 计算总金额（先计算，用于验证）
        $total_amount = 0;
        foreach ($_POST['items'] as $item) {
            if (!empty($item['item_name'])) {
                $quantity = floatval($item['quantity']);
                $amount = $toMoney2($item['amount'] ?? null);
                if ($amount === null) {
                    $amount = round(floatval($item['unit_price']) * $quantity, 2);
                }
                $total_amount += $amount;
            }
        }

        // 插入发票主表
        $stmt = $conn->prepare("INSERT INTO invoices (invoice_number, invoice_type, customer_name, customer_contact, tax_number, total_amount, created_by, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $customer_name = $_POST['customer_name'];
        $customer_contact = $_POST['customer_contact'] ?? '';
        $tax_number = $_POST['tax_number'] ?? '';
        $remarks = $_POST['remarks'] ?? '';
        $user_id = $_SESSION['user_id'];
        
        $stmt->bind_param("sssssdis", 
            $invoice_number,
            $invoice_type,
            $customer_name,
            $customer_contact,
            $tax_number,
            $total_amount,
            $user_id,
            $remarks
        );
        
        if (!$stmt->execute()) {
            throw new Exception("插入发票失败: " . $conn->error);
        }
        
        $invoice_id = $stmt->insert_id;
        
        // 插入货物详情
        $itemStmt = $conn->prepare("INSERT INTO invoice_items (invoice_id, item_name, model, unit_price, unit, quantity, amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($_POST['items'] as $item) {
            if (!empty($item['item_name'])) {
                $item_name = $item['item_name'];
                $model = $item['model'] ?? '';
                $unit = $item['unit'] ?? '个';
                $quantity = floatval($item['quantity']);
                $amount = $toMoney2($item['amount'] ?? null);
                $unit_price = floatval($item['unit_price']);
                if ($amount === null) {
                    $amount = round($unit_price * $quantity, 2);
                }
                
                $itemStmt->bind_param("issdsdd",
                    $invoice_id,
                    $item_name,
                    $model,
                    $unit_price,
                    $unit,
                    $quantity,
                    $amount
                );
                
                if (!$itemStmt->execute()) {
                    throw new Exception("插入货物详情失败: " . $conn->error);
                }
            }
        }
        
        // 提交事务
        $conn->commit();
        
        // 发送 Webhook 通知
        sendWebhook('invoice_created', [
            'customer_name' => $customer_name,
            'total_amount' => $total_amount,
            'remarks' => $remarks
        ]);
        
        $success = '发票添加成功！发票号：' . $invoice_number;
        
        // 清除表单数据
        $items = [['item_name' => '', 'model' => '', 'unit_price' => '', 'unit' => '个', 'quantity' => 1, 'amount' => '']];
        $_POST = []; // 清除POST数据
        
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
        }
        $error = '添加失败：' . $e->getMessage();
        
        // 保留用户输入的数据
        if (isset($_POST['items'])) {
            $items = $_POST['items'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新增发票</title>
    <link rel="stylesheet" href="styles.css">
    <style>
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }
    
    .modal-content {
        background-color: #fff;
        margin: 10% auto;
        padding: 20px;
        border-radius: 5px;
        width: 80%;
        max-width: 600px;
        max-height: 80vh;
        overflow-y: auto;
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px solid #ddd;
    }
    
    .modal-title {
        font-size: 18px;
        font-weight: bold;
        color: #333;
    }
    
    .close {
        cursor: pointer;
        font-size: 24px;
        color: #999;
    }
    
    .close:hover {
        color: #333;
    }
    
    .pending-invoice-item {
        padding: 10px;
        border: 1px solid #ddd;
        margin-bottom: 10px;
        border-radius: 4px;
        background-color: #f9f9f9;
    }
    
    .invoice-summary {
        background-color: #f5f5f5;
        padding: 15px;
        border-radius: 4px;
        margin: 15px 0;
    }
    
    .modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
    }
    
    .permission-hint {
        font-size: 14px;
        color: #666;
        margin-left: 20px;
        font-weight: normal;
    }
    
    .searching {
        padding: 10px;
        background-color: #f0f8ff;
        border-left: 4px solid #1890ff;
        margin: 10px 0;
        display: none;
    }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'includes/header.php'; ?>
        
        <div class="main-content">
            <h1>新增发票
                <span class="permission-hint">
                    普票税率：<?php echo htmlspecialchars($tax_rate_pu);?>%
                    专票税率：<?php echo htmlspecialchars($tax_rate_zhuan);?>%
                    专票最小开票金额：<?php echo htmlspecialchars($invoice_limit_number); ?>元
                </span>
            </h1>
            
            <?php if ($error): ?>
            <div class="alert error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert success">
                <?php echo $success; ?>
                <a href="add_invoice.php" style="margin-left: 20px;">继续添加</a>
                <a href="invoices.php" style="margin-left: 10px;">查看所有发票</a>
            </div>
            <?php endif; ?>
            
            <div id="searchingMessage" class="searching">
                正在查询客户的待开发票...
            </div>
            
            <!-- 合并发票确认模态框 -->
            <div id="mergeModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="modal-title">发现待开发票</div>
                        <span class="close" onclick="closeMergeModal()">&times;</span>
                    </div>
                    
                    <div id="mergeModalContent">
                        <!-- 内容将通过JavaScript动态填充 -->
                    </div>
                </div>
            </div>
            
            <form method="POST" action="" class="invoice-form" id="invoiceForm" onsubmit="return validateForm()">
                <div class="form-section">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="customer_name">客户名称 *</label>
                            <input type="text" id="customer_name" name="customer_name" required 
                                   onchange="checkCustomerInfo()">
                        </div>
                        
                        <div class="form-group">
                            <label for="tax_number">税号</label>
                            <input type="text" id="tax_number" name="tax_number" 
                                   onchange="checkCustomerInfo()">
                        </div>
                        
                        <div class="form-group">
                            <label for="customer_contact">联系方式</label>
                            <input type="text" id="customer_contact" name="customer_contact">
                        </div>
                        
                        <div class="form-group">
                            <label for="invoice_type">发票类型 *</label>
                            <select id="invoice_type" name="invoice_type" required 
                                    onchange="onInvoiceTypeChange()">
                                <option value="">选择发票类型</option>
                                <option value="普票">普通发票</option>
                                <option value="专票">专用发票</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <div class="table-responsive">
                        <table class="items-table" id="itemsTable">
                            <thead>
                                <tr>
                                    <th width="25%">货物名称 *</th>
                                    <th width="20%">型号</th>
                                    <th width="10%">单价 *</th>
                                    <th width="10%">单位</th>
                                    <th width="10%">数量 *</th>
                                    <th width="15%">金额</th>
                                    <th width="15%">操作</th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <?php foreach ($items as $index => $item): ?>
                                <tr class="item-row">
                                    <td><input type="text" name="items[<?php echo $index; ?>][item_name]" value="<?php echo $item['item_name']; ?>" class="item-name" required></td>
                                    <td><input type="text" name="items[<?php echo $index; ?>][model]" value="<?php echo $item['model']; ?>"></td>
                                    <td><input type="number" name="items[<?php echo $index; ?>][unit_price]" value="<?php echo $item['unit_price']; ?>" step="any" min="0" class="unit-price" required></td>
                                    <td>
                                        <select name="items[<?php echo $index; ?>][unit]" class="unit">
                                            <option value="个" <?php echo $item['unit'] === '个' ? 'selected' : ''; ?>>个</option>
                                            <option value="台" <?php echo $item['unit'] === '台' ? 'selected' : ''; ?>>台</option>
                                            <option value="套" <?php echo $item['unit'] === '套' ? 'selected' : ''; ?>>套</option>
                                            <option value="米" <?php echo $item['unit'] === '米' ? 'selected' : ''; ?>>米</option>
                                            <option value="千克" <?php echo $item['unit'] === '千克' ? 'selected' : ''; ?>>千克</option>
                                            <option value="升" <?php echo $item['unit'] === '升' ? 'selected' : ''; ?>>升</option>
                                        </select>
                                    </td>
                                    <td><input type="number" name="items[<?php echo $index; ?>][quantity]" value="<?php echo $item['quantity']; ?>" step="1" min="1" class="quantity" required></td>
                                    <td>
                                        <div style="display:flex; gap:6px; align-items:center;">
                                            <input type="text" inputmode="decimal" name="items[<?php echo $index; ?>][amount]" class="amount">
                                            <button type="button" class="btn btn-secondary" onclick="calcUnitPriceFromAmount(this)">计算单价</button>
                                        </div>
                                    </td>
                                    <td><button type="button" class="btn btn-remove" onclick="removeRow(this)">删除</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" style="text-align: right;"><strong>总计：</strong></td>
                                    <td><input type="text" id="totalAmount" name="total_amount" readonly></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        <button type="button" class="btn btn-add-item" onclick="addRow()">+ 添加一行</button>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>备注</h3>
                    <div class="form-group">
                        <textarea id="remarks" name="remarks" rows="3" placeholder="可填写额外信息..."></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">提交发票</button>
                    <button type="reset" class="btn btn-secondary" onclick="resetForm()">重置</button>
                    <a href="dashboard.php" class="btn btn-cancel">取消</a>
                </div>
            </form>
        </div>
    </div>
    
<script>
let rowIndex = <?php echo count($items); ?>;
let customerInfoChecked = false;
let pendingInvoices = null;

// 检查客户信息是否完整
function checkCustomerInfo() {
    const customerName = document.getElementById('customer_name').value.trim();
    const taxNumber = document.getElementById('tax_number').value.trim();
    
    if (customerName) {
        customerInfoChecked = true;
    }
}

// 发票类型改变时触发查询
function onInvoiceTypeChange() {
    const invoiceType = document.getElementById('invoice_type').value;
    const customerName = document.getElementById('customer_name').value.trim();
    const taxNumber = document.getElementById('tax_number').value.trim();
    
    if (!customerName) {
        alert('请先填写客户名称');
        return;
    }
    
    if (!invoiceType) {
        return;
    }
    
    // 显示查询中消息
    document.getElementById('searchingMessage').style.display = 'block';
    
    // 发送AJAX请求查询待开发票
    fetch('check_pending_invoices.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            customer_name: customerName,
            tax_number: taxNumber,
            invoice_type: invoiceType
        })
    })
    .then(response => response.json())
    .then(data => {
        // 隐藏查询中消息
        document.getElementById('searchingMessage').style.display = 'none';
        
        console.log('查询结果:', data);
        
        if (data.success && data.data && data.data.length > 0) {
            pendingInvoices = data.data;
            showSelectInvoiceModal(pendingInvoices);
        } else {
            pendingInvoices = null;
        }
    })
    .catch(error => {
        console.error('查询错误:', error);
        document.getElementById('searchingMessage').style.display = 'none';
    });
}

// 显示选择发票模态框
function showSelectInvoiceModal(invoices) {
    let modalContent = `
        <p>找到 <strong>${invoices.length}</strong> 张相同客户的待开发票：</p>
        
        <div class="invoice-summary">
            <p><strong>客户：</strong>${invoices[0].customer_name}</p>
            <p><strong>税号：</strong>${invoices[0].tax_number || '无'}</p>
            <p><strong>发票类型：</strong>${invoices[0].invoice_type}</p>
        </div>
        
        <h4>选择一张发票：</h4>
        
        <div style="max-height: 300px; overflow-y: auto; margin: 15px 0;">
    `;
    
    invoices.forEach((invoice, index) => {
        const itemCount = invoice.items ? invoice.items.length : 0;
        const totalAmountDec = parseDecimal(String(invoice.total_amount ?? 0));
        const totalAmount = totalAmountDec ? formatDecimalMin2(totalAmountDec) : '0.00';
        const createdTime = invoice.created_at;
        
        modalContent += `
            <div class="pending-invoice-item" style="margin-bottom: 10px; cursor: pointer;" onclick="selectInvoice(${invoice.id})">
                <div style="display: flex; align-items: center;">
                    <input type="radio" name="selectInvoice" value="${invoice.id}" 
                           id="invoice_${invoice.id}" style="margin-right: 10px;">
                    <label for="invoice_${invoice.id}" style="flex-grow: 1; cursor: pointer;">
                        <div style="display: flex; justify-content: space-between;">
                            <div>
                                <p><strong>发票号：</strong>${invoice.invoice_number}</p>
                                <p><strong>金额：</strong>${totalAmount} 元</p>
                                <p><strong>货物数量：</strong>${itemCount} 项</p>
                            </div>
                            <div style="text-align: right;">
                                <p><strong>创建时间：</strong>${createdTime}</p>
                                <p><strong>状态：</strong>待开票</p>
                            </div>
                        </div>
                    </label>
                </div>
            </div>
        `;
    });
    
    modalContent += `
        </div>
        
        <div class="modal-actions">
            <button type="button" class="btn btn-primary" onclick="editSelectedInvoice()">追加到选中的发票上</button>
            <button type="button" class="btn btn-secondary" onclick="closeModalAndContinue()">创建新发票</button>
        </div>
    `;
    
    document.getElementById('mergeModalContent').innerHTML = modalContent;
    document.getElementById('mergeModal').style.display = 'block';
    
    // 默认选中第一个
    if (invoices.length > 0) {
        document.getElementById(`invoice_${invoices[0].id}`).checked = true;
        selectInvoice(invoices[0].id); // 应用选中样式
    }
}

// 选择发票（点击整行时选中radio）
function selectInvoice(invoiceId) {
    document.getElementById(`invoice_${invoiceId}`).checked = true;
    
    // 添加选中样式
    document.querySelectorAll('.pending-invoice-item').forEach(item => {
        item.style.backgroundColor = '';
        item.style.borderColor = '#ddd';
    });
    
    const selectedItem = document.querySelector(`.pending-invoice-item[onclick="selectInvoice(${invoiceId})"]`);
    if (selectedItem) {
        selectedItem.style.backgroundColor = '#f0f8ff';
        selectedItem.style.borderColor = '#1890ff';
    }
}

// 编辑选中的发票
function editSelectedInvoice() {
    const selectedRadio = document.querySelector('input[name="selectInvoice"]:checked');
    
    if (!selectedRadio) {
        alert('请先选择一张要编辑的发票');
        return;
    }
    
    const invoiceId = selectedRadio.value;
    
    // 找到选中的发票
    const selectedInvoice = pendingInvoices.find(inv => inv.id == invoiceId);
    
    if (!selectedInvoice) {
        alert('选择的发票不存在');
        return;
    }
    
    // 显示确认信息
    const selectedTotalDec = parseDecimal(String(selectedInvoice.total_amount ?? 0));
    const selectedTotalAmount = selectedTotalDec ? formatDecimalMin2(selectedTotalDec) : '0.00';
    const confirmMessage = `您将跳转到编辑发票页面编辑发票：\n` +
                         `发票号：${selectedInvoice.invoice_number}\n` +
                         `金额：${selectedTotalAmount}元\n` +
                         `创建时间：${selectedInvoice.created_at}\n\n` +
                         `确定要编辑这张发票吗？`;
    
    if (confirm(confirmMessage)) {
        // 跳转到编辑页面
        window.location.href = `edit_invoice.php?id=${invoiceId}`;
    }
}

// 关闭模态框并继续创建新发票（修改版：不清空内容）
function closeModalAndContinue() {
    closeMergeModal();
    // 不移除已填写的内容，让用户继续编辑当前表单
}

// 关闭模态框
function closeMergeModal() {
    document.getElementById('mergeModal').style.display = 'none';
    pendingInvoices = null;
}

// 其他原有函数保持不变
function addRow() {
    const tbody = document.getElementById('itemsBody');
    const newRow = document.createElement('tr');
    newRow.className = 'item-row';
    newRow.innerHTML = `
        <td><input type="text" name="items[${rowIndex}][item_name]" class="item-name" required></td>
        <td><input type="text" name="items[${rowIndex}][model]"></td>
        <td><input type="number" name="items[${rowIndex}][unit_price]" step="any" min="0" class="unit-price" required></td>
        <td>
            <select name="items[${rowIndex}][unit]" class="unit">
                <option value="个">个</option>
                <option value="台">台</option>
                <option value="套">套</option>
                <option value="米">米</option>
                <option value="千克">千克</option>
                <option value="升">升</option>
            </select>
        </td>
        <td><input type="number" name="items[${rowIndex}][quantity]" step="1" min="1" class="quantity" value="1" required></td>
        <td>
            <div style="display:flex; gap:6px; align-items:center;">
                <input type="text" inputmode="decimal" name="items[${rowIndex}][amount]" class="amount">
                <button type="button" class="btn btn-secondary" onclick="calcUnitPriceFromAmount(this)">计算单价</button>
            </div>
        </td>
        <td><button type="button" class="btn btn-remove" onclick="removeRow(this)">删除</button></td>
    `;
    tbody.appendChild(newRow);
    rowIndex++;
    attachCalculationEvents(newRow);
}

function removeRow(button) {
    const row = button.closest('.item-row');
    if (document.querySelectorAll('.item-row').length > 1) {
        row.remove();
        calculateTotal();
    } else {
        alert('至少需要一行货物信息');
    }
}

const pow10Cache = new Map([[0, 1n]]);

function pow10(n) {
    if (pow10Cache.has(n)) return pow10Cache.get(n);
    const value = 10n ** BigInt(n);
    pow10Cache.set(n, value);
    return value;
}

function parseDecimal(value) {
    let s = String(value ?? '').trim();
    if (!s) return null;
    if (!/^[+-]?\d+(\.\d+)?$/.test(s)) return null;

    let sign = 1n;
    if (s.startsWith('-')) {
        sign = -1n;
        s = s.slice(1);
    } else if (s.startsWith('+')) {
        s = s.slice(1);
    }

    let scale = 0;
    if (s.includes('.')) {
        const [intPart, fracPart = ''] = s.split('.');
        scale = fracPart.length;
        s = intPart + fracPart;
    }

    s = s.replace(/^0+(?=\d)/, '');
    const intValue = BigInt(s || '0') * sign;
    return { int: intValue, scale };
}

function formatDecimalMin2(dec) {
    let intValue = dec.int;
    const scale = dec.scale;

    let sign = '';
    if (intValue < 0n) {
        sign = '-';
        intValue = -intValue;
    }

    let digits = intValue.toString();

    if (scale > 0) {
        if (digits.length <= scale) {
            digits = '0'.repeat(scale - digits.length + 1) + digits;
        }
        const pos = digits.length - scale;
        digits = digits.slice(0, pos) + '.' + digits.slice(pos);
    }

    let i = digits;
    let d = '';

    if (digits.includes('.')) {
        [i, d = ''] = digits.split('.');
    }

    while (d.length > 2 && d.endsWith('0')) d = d.slice(0, -1);
    if (d.length === 0) d = '00';
    else if (d.length === 1) d = d + '0';
    return sign + i + '.' + d;
}

function addDecimals(a, b) {
    const scale = Math.max(a.scale, b.scale);
    const aInt = a.int * pow10(scale - a.scale);
    const bInt = b.int * pow10(scale - b.scale);
    return { int: aInt + bInt, scale };
}

function multiplyDecimals(a, b) {
    return { int: a.int * b.int, scale: a.scale + b.scale };
}

function divideDecimals(a, b, maxScale) {
    if (b.int === 0n) return null;

    const negative = (a.int < 0n) !== (b.int < 0n);
    const aInt = a.int < 0n ? -a.int : a.int;
    const bInt = b.int < 0n ? -b.int : b.int;

    const numerator = aInt * pow10(maxScale + b.scale);
    const denominator = bInt * pow10(a.scale);

    let q = numerator / denominator;
    const r = numerator % denominator;
    if (r * 2n >= denominator) q += 1n;

    return { int: negative ? -q : q, scale: maxScale };
}

function roundDecimal(dec, maxScale) {
    if (dec.scale <= maxScale) return dec;
    const diff = dec.scale - maxScale;
    const factor = pow10(diff);

    const negative = dec.int < 0n;
    const absInt = negative ? -dec.int : dec.int;

    let q = absInt / factor;
    const r = absInt % factor;
    if (r * 2n >= factor) q += 1n;

    return { int: negative ? -q : q, scale: maxScale };
}

function formatDecimalFixed(dec, fixedScale) {
    let d = roundDecimal(dec, fixedScale);
    let intValue = d.int;
    const scale = d.scale;

    let sign = '';
    if (intValue < 0n) {
        sign = '-';
        intValue = -intValue;
    }

    let digits = intValue.toString();
    if (scale > 0) {
        if (digits.length <= scale) {
            digits = '0'.repeat(scale - digits.length + 1) + digits;
        }
        const pos = digits.length - scale;
        digits = digits.slice(0, pos) + '.' + digits.slice(pos);
    }

    if (!digits.includes('.')) return sign + digits + '.' + '0'.repeat(fixedScale);
    const [i, frac = ''] = digits.split('.');
    return sign + i + '.' + frac.padEnd(fixedScale, '0');
}

function normalizeMoneyInput(input) {
    const dec = parseDecimal(input.value);
    if (!dec) return;
    input.value = formatDecimalMin2(dec);
}

function normalizeMoneyInputMaxScale(input, maxScale) {
    const dec = parseDecimal(input.value);
    if (!dec) return;
    input.value = formatDecimalMin2(roundDecimal(dec, maxScale));
}

function normalizeMoneyInputFixedScale(input, fixedScale) {
    const dec = parseDecimal(input.value);
    if (!dec) return;
    input.value = formatDecimalFixed(dec, fixedScale);
}

function calculateRow(row) {
    const unitPriceInput = row.querySelector('.unit-price');
    const quantityInput = row.querySelector('.quantity');
    const amountInput = row.querySelector('.amount');

    const unitPriceDec = parseDecimal(unitPriceInput.value);
    const quantityDec = parseDecimal(quantityInput.value);

    const amountLocked = row.dataset.amountLocked === '1';
    if (amountLocked) {
        const amountDec = parseDecimal(amountInput.value);
        if (!amountDec) return { int: 0n, scale: 2 };
        return roundDecimal(amountDec, 2);
    }

    if (unitPriceDec && quantityDec) {
        const amountDec = multiplyDecimals(unitPriceDec, quantityDec);
        const amount2 = roundDecimal(amountDec, 2);
        amountInput.value = formatDecimalFixed(amount2, 2);
        return amount2;
    }

    const amountDec = parseDecimal(amountInput.value);
    return amountDec ? roundDecimal(amountDec, 2) : { int: 0n, scale: 2 };
}

function calculateTotal() {
    let totalDec = { int: 0n, scale: 0 };
    document.querySelectorAll('.item-row').forEach(row => {
        totalDec = addDecimals(totalDec, calculateRow(row));
    });
    const total2 = roundDecimal(totalDec, 2);
    document.getElementById('totalAmount').value = formatDecimalFixed(total2, 2);
    return totalDec;
}

function calcUnitPriceFromAmount(button) {
    const row = button.closest('.item-row');
    if (!row) return;

    const unitPriceInput = row.querySelector('.unit-price');
    const quantityInput = row.querySelector('.quantity');
    const amountInput = row.querySelector('.amount');

    const quantityDec = parseDecimal(quantityInput.value);
    const amountDec = parseDecimal(amountInput.value);

    if (!quantityDec || quantityDec.int === 0n) {
        alert('请先填写数量（且不能为0）');
        return;
    }

    if (!amountDec) {
        alert('请先填写总价金额');
        return;
    }

    const amount2 = roundDecimal(amountDec, 2);
    amountInput.value = formatDecimalFixed(amount2, 2);
    row.dataset.amountLocked = '1';

    const unitPriceDec = divideDecimals(amount2, quantityDec, 10);
    if (!unitPriceDec) {
        alert('计算失败，请检查数量和总价');
        return;
    }

    unitPriceInput.value = formatDecimalMin2(unitPriceDec);
    normalizeMoneyInputMaxScale(unitPriceInput, 10);
    calculateTotal();
}

function attachCalculationEvents(row) {
    const inputs = row.querySelectorAll('.unit-price, .quantity, .amount');
    inputs.forEach(input => {
        input.addEventListener('input', calculateTotal);
    });

    const unitPriceInput = row.querySelector('.unit-price');
    if (unitPriceInput) {
        unitPriceInput.addEventListener('input', function() {
            row.dataset.amountLocked = '0';
        });
        unitPriceInput.addEventListener('blur', function() {
            normalizeMoneyInputMaxScale(unitPriceInput, 10);
            calculateTotal();
        });
    }

    const quantityInput = row.querySelector('.quantity');
    if (quantityInput) {
        quantityInput.addEventListener('input', function() {
            row.dataset.amountLocked = '0';
        });
    }

    const amountInput = row.querySelector('.amount');
    if (amountInput) {
        amountInput.addEventListener('input', function() {
            row.dataset.amountLocked = '1';
        });
        amountInput.addEventListener('blur', function() {
            normalizeMoneyInputFixedScale(amountInput, 2);
            calculateTotal();
        });
    }
}

function validateForm() {
    document.querySelectorAll('.item-row').forEach(row => {
        const unitPriceInput = row.querySelector('.unit-price');
        const quantityInput = row.querySelector('.quantity');
        const amountInput = row.querySelector('.amount');

        if (unitPriceInput && !unitPriceInput.value.trim() && quantityInput?.value.trim() && amountInput?.value.trim()) {
            const quantityDec = parseDecimal(quantityInput.value);
            const amountDec = parseDecimal(amountInput.value);
            if (quantityDec && quantityDec.int !== 0n && amountDec) {
                const amount2 = roundDecimal(amountDec, 2);
                amountInput.value = formatDecimalFixed(amount2, 2);
                row.dataset.amountLocked = '1';
                const unitPriceDec = divideDecimals(amount2, quantityDec, 10);
                if (unitPriceDec) unitPriceInput.value = formatDecimalMin2(unitPriceDec);
            }
        }

        if (unitPriceInput) normalizeMoneyInputMaxScale(unitPriceInput, 10);
        if (amountInput) normalizeMoneyInputFixedScale(amountInput, 2);
    });
    calculateTotal();

    // 验证客户名称
    const customerName = document.getElementById('customer_name').value.trim();
    if (!customerName) {
        alert('请填写客户名称');
        return false;
    }
    
    // 验证发票类型
    const invoiceType = document.getElementById('invoice_type').value;
    if (!invoiceType) {
        alert('请选择发票类型');
        return false;
    }
    
    // 验证至少有一行货物信息
    const itemRows = document.querySelectorAll('.item-row');
    let hasValidItem = false;
    
    itemRows.forEach(row => {
        const itemName = row.querySelector('.item-name').value.trim();
        const unitPrice = row.querySelector('.unit-price').value.trim();
        const quantity = row.querySelector('.quantity').value.trim();
        
        if (itemName && unitPrice && quantity) {
            hasValidItem = true;
        }
    });
    
    if (!hasValidItem) {
        alert('请填写至少一行完整的货物信息');
        return false;
    }
    
    return true;
}

// 重置表单
function resetForm() {
    customerInfoChecked = false;
    pendingInvoices = null;
    document.getElementById('mergeModal').style.display = 'none';
}

// 初始化事件监听
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.item-row').forEach(row => {
        attachCalculationEvents(row);
    });
    calculateTotal();
    
    // 点击模态框外部关闭
    document.getElementById('mergeModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeMergeModal();
        }
    });
});
</script>
</body>
</html>
