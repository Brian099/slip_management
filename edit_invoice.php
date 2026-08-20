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

$invoice_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

// 获取发票信息
$invoice = getInvoiceById($invoice_id);

if (!$invoice) {
    die("发票不存在");
}

// 验证权限：只能编辑自己的未开票发票
if ($invoice['created_by'] != $user_id) {
    die("无权编辑他人创建的发票");
}

if ($invoice['finance_status'] === 'invoiced') {
    die("已开票的发票不能编辑");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $toMoney2 = function($v) {
            if ($v === '' || $v === null) return null;
            if (is_string($v)) $v = trim($v);
            if ($v === '') return null;
            if (!is_numeric($v)) return null;
            return round((float)$v, 2);
        };

        $conn->begin_transaction();
        
        // 验证数据
        if (empty($_POST['customer_name'])) {
            throw new Exception('请输入客户名称');
        }
        
        // 更新发票主表 - 添加更新时间字段
        $stmt = $conn->prepare("UPDATE invoices SET 
            invoice_type = ?, 
            customer_name = ?, 
            customer_contact = ?, 
            tax_number = ?, 
            total_amount = ?, 
            remarks = ?,
            created_at = CURRENT_TIMESTAMP  -- 更新创建时间为当前时间
            WHERE id = ? AND created_by = ?");
        
        $stmt->bind_param("ssssdsii", 
            $_POST['invoice_type'],
            $_POST['customer_name'],
            $_POST['customer_contact'],
            $_POST['tax_number'],
            $_POST['total_amount'],
            $_POST['remarks'],
            $invoice_id,
            $user_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception('更新失败: ' . $conn->error);
        }
        
        // 删除旧的货物详情
        $conn->query("DELETE FROM invoice_items WHERE invoice_id = $invoice_id");
        
        // 插入新的货物详情
        $itemStmt = $conn->prepare("INSERT INTO invoice_items (invoice_id, item_name, model, unit_price, unit, quantity, amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $total_amount = 0;
        foreach ($_POST['items'] as $item) {
            if (!empty($item['item_name'])) {
                $quantity = floatval($item['quantity']);
                $amount = $toMoney2($item['amount'] ?? null);
                if ($amount === null) {
                    $amount = round(floatval($item['unit_price']) * $quantity, 2);
                }
                $itemStmt->bind_param("issdsdd",
                    $invoice_id,
                    $item['item_name'],
                    $item['model'],
                    $item['unit_price'],
                    $item['unit'],
                    $item['quantity'],
                    $amount
                );
                if (!$itemStmt->execute()) {
                    throw new Exception('保存货物信息失败: ' . $conn->error);
                }
                $total_amount += $amount;
            }
        }
        
        // 更新总金额
        $conn->query("UPDATE invoices SET total_amount = $total_amount WHERE id = $invoice_id");
        
        $conn->commit();
        
        // 发送 Webhook 通知
        sendWebhook('invoice_updated', [
            'invoice_number' => $invoice['invoice_number'],
            'customer_name' => $_POST['customer_name'],
            'total_amount' => $total_amount
        ]);
        
        $success = '发票更新成功！';
        
        // 重新获取数据
        $invoice = getInvoiceById($invoice_id);
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = '更新失败：' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑发票</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <?php include 'includes/header.php'; ?>
    
    <div class="main-content">
        <h1>编辑发票</h1>
        
        <?php if ($error): ?>
        <div class="alert error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert success">
            <?php echo $success; ?>
            <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" style="margin-left: 20px;">查看发票</a>
            <a href="invoices.php" style="margin-left: 10px;">返回列表</a>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="" class="invoice-form" onsubmit="return validateForm()">
            <div class="form-section">
                <div class="form-grid">
                    
                    <div class="form-group">
                        <label for="customer_name">客户名称 *</label>
                        <input type="text" id="customer_name" name="customer_name" 
                               value="<?php echo htmlspecialchars($invoice['customer_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="customer_contact">联系方式</label>
                        <input type="text" id="customer_contact" name="customer_contact" 
                               value="<?php echo htmlspecialchars($invoice['customer_contact']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="tax_number">税号</label>
                        <input type="text" id="tax_number" name="tax_number" 
                               value="<?php echo htmlspecialchars($invoice['tax_number']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="invoice_type">发票类型 *</label>
                        <select id="invoice_type" name="invoice_type" required>
                            <option value="普票" <?php echo $invoice['invoice_type'] === '普票' ? 'selected' : ''; ?>>普通发票</option>
                            <option value="专票" <?php echo $invoice['invoice_type'] === '专票' ? 'selected' : ''; ?>>专用发票</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>创建时间<span class="permission-hint">保存后将更新为当前时间</span></label>
                        <input type="text" 
                               value="<?php 
                                   // 显示当前的创建时间
                                   echo date('Y-m-d H:i', strtotime($invoice['created_at'])); 
                               ?>" 
                               readonly>

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
                            <?php foreach ($invoice['items'] as $index => $item): ?>
                            <tr class="item-row">
                                <td>
                                    <input type="text" name="items[<?php echo $index; ?>][item_name]" 
                                           value="<?php echo htmlspecialchars($item['item_name']); ?>" 
                                           class="item-name" required>
                                </td>
                                <td>
                                    <input type="text" name="items[<?php echo $index; ?>][model]" 
                                           value="<?php echo htmlspecialchars($item['model']); ?>">
                                </td>
                                <td>
                                    <input type="number" name="items[<?php echo $index; ?>][unit_price]" 
                                           value="<?php echo $item['unit_price']; ?>" 
                                           step="any" min="0" class="unit-price" required>
                                </td>
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
                                <td>
                                    <input type="number" name="items[<?php echo $index; ?>][quantity]" 
                                           value="<?php echo $item['quantity']; ?>" 
                                           step="1" min="1" class="quantity" required>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <input type="text" inputmode="decimal" name="items[<?php echo $index; ?>][amount]" 
                                               class="amount" 
                                               value="<?php echo number_format($item['amount'], 2); ?>">
                                        <button type="button" class="btn btn-secondary" onclick="calcUnitPriceFromAmount(this)">计算单价</button>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-remove" onclick="removeRow(this)">删除</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" style="text-align: right;"><strong>总计：</strong></td>
                                <td><input type="text" id="totalAmount" name="total_amount" 
                                         value="<?php echo number_format($invoice['total_amount'], 2); ?>" readonly></td>
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
                    <textarea id="remarks" name="remarks" rows="3" placeholder="可填写额外信息..."><?php echo htmlspecialchars($invoice['remarks']); ?></textarea>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">保存修改</button>
                <a href="invoices.php?action=view&id=<?php echo $invoice_id; ?>" class="btn btn-secondary">取消</a>
                <a href="delete_invoice.php?id=<?php echo $invoice_id; ?>" 
                   class="btn btn-danger" 
                   onclick="return confirm('确定要删除这张发票吗？')">删除发票</a>
            </div>
        </form>
    </div>
</div>

<script>
let rowIndex = <?php echo count($invoice['items']); ?>;

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

// 初始化事件监听
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.item-row').forEach(row => {
        attachCalculationEvents(row);
    });
    calculateTotal();
});
</script>
</body>
</html>
