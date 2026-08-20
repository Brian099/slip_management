<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// 只有管理员和财务可以访问系统设置
checkRole(['admin', 'finance']);

$conn = getDbConnection();
$user_id = $_SESSION['user_id'];

// 获取当前设置
$settings = [];
$sql = "SELECT * FROM system_settings WHERE id = 1";
$result = $conn->query($sql);
if ($result && $row = $result->fetch_assoc()) {
    $settings = $row;
}

// 处理保存设置
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        // 收集表单数据
        $data = [
            'company_name' => trim($_POST['company_name']),
            'company_address' => trim($_POST['company_address']),
            'invoice_limit_number' => intval($_POST['invoice_limit_number']),
            'tax_rate_zhuan' => floatval($_POST['tax_rate_zhuan']),
            'tax_rate_pu' => floatval($_POST['tax_rate_pu']),
            'webhook_url' => trim($_POST['webhook_url'] ?? ''),
            'allow_finance_create_invoice' => isset($_POST['allow_finance_create_invoice']) ? 1 : 0
        ];
        
        // 验证数据
        if (empty($data['company_name'])) {
            throw new Exception('公司名称不能为空');
        }
        
        if ($data['invoice_limit_number'] < 1) {
            throw new Exception('开票最小金额必须大于0');
        }
        
        if ($data['tax_rate_zhuan'] < 0 || $data['tax_rate_zhuan'] > 100) {
            throw new Exception('专票税率必须在0-100之间');
        }
        
        if ($data['tax_rate_pu'] < 0 || $data['tax_rate_pu'] > 100) {
            throw new Exception('普票税率必须在0-100之间');
        }
        
        // 更新数据库
        $sql = "UPDATE system_settings SET 
                company_name = ?,
                company_address = ?,
                invoice_limit_number = ?,
                tax_rate_zhuan = ?,
                tax_rate_pu = ?,
                webhook_url = ?,
                allow_finance_create_invoice = ?,
                updated_at = NOW()
                WHERE id = 1";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('数据库操作失败');
        }
        
        $stmt->bind_param('ssidssi',
            $data['company_name'],
            $data['company_address'],
            $data['invoice_limit_number'],
            $data['tax_rate_zhuan'],
            $data['tax_rate_pu'],
            $data['webhook_url'],
            $data['allow_finance_create_invoice']
        );
        
        if ($stmt->execute()) {
            $success = '系统设置已成功保存';
            // 更新当前设置
            $settings = array_merge($settings, $data);
        } else {
            throw new Exception('保存失败: ' . $stmt->error);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统设置</title>
    <link rel="stylesheet" href="styles.css">
    <style>

    </style>
</head>
<body>
    <div class="container">
        <?php include 'includes/header.php'; ?>
        
        <div class="main-content">
            <div class="system-settings">
                <div class="settings-header">
                    <h1>系统设置</h1>
                </div>
                
                <?php if ($error): ?>
                <div class="alert error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <div class="settings-content">
                    <!-- 公司信息设置 -->
                    <div class="settings-card">
                        <form method="POST" action="">
                            <div class="form-grid" style="display: block;">
                                <div class="form-group">
                                    <label for="company_name">公司名称 *</label>
                                    <input type="text" 
                                           id="company_name"
                                           name="company_name"
                                           class="form-control"
                                           value="<?php echo htmlspecialchars($settings['company_name'] ?? '我的公司'); ?>"
                                           required
                                           placeholder="请输入公司全称">
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="company_address">公司地址</label>
                                    <textarea id="company_address"
                                              name="company_address"
                                              class="form-control"
                                              rows="3"
                                              placeholder="请输入完整的公司注册地址或办公地址"><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></textarea>
                                </div>

                                <div class="form-group full-width">
                                    <label for="webhook_url">Webhook 通知地址</label>
                                    <input type="text" 
                                           id="webhook_url"
                                           name="webhook_url"
                                           class="form-control"
                                           value="<?php echo htmlspecialchars($settings['webhook_url'] ?? ''); ?>"
                                           placeholder="请输入企业微信或其他平台的 Webhook URL">
                                    <p class="help-text">用于接收系统通知（发票变动、审核、入账等）。</p>
                                </div>
                            </div>
                            
                            <!-- 税率设置 -->
                            <div class="form-group">
                                <label>税率设置</label>
                                <div class="tax-rate-container">
                                    <div class="tax-rate-box zhuan">
                                        <h4>专票税率 (%)</h4>
                                        <input type="number" 
                                               name="tax_rate_zhuan"
                                               class="form-control"
                                               value="<?php echo htmlspecialchars($settings['tax_rate_zhuan'] ?? 13.00); ?>"
                                               step="0.01"
                                               min="0"
                                               max="100"
                                               required>
                                        <p class="help-text">增值税专用发票税率，通常为13%</p>
                                    </div>
                                    
                                    <div class="tax-rate-box pu">
                                        <h4>普票税率 (%)</h4>
                                        <input type="number" 
                                               name="tax_rate_pu"
                                               class="form-control"
                                               value="<?php echo htmlspecialchars($settings['tax_rate_pu'] ?? 3.00); ?>"
                                               step="0.01"
                                               min="0"
                                               max="100"
                                               required>
                                        <p class="help-text">普通发票税率，通常为3%</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- 开票限制设置 -->
                            <div class="form-group">
                                <label>开票限制</label>
                                <div class="limit-box">
                                    <h4>专票开票最小金额</h4>
                                    <input type="number" 
                                           name="invoice_limit_number"
                                           class="form-control"
                                           value="<?php echo htmlspecialchars($settings['invoice_limit_number'] ?? 2000); ?>"
                                           min="1"
                                           required
                                           placeholder="请输入专票最小开票金额">
                                    <p class="help-text">
                                        专票金额低于此值时会在列表中高亮显示，但不影响开票，仅作为提醒。<br>
                                    </p>
                                </div>
                            </div>

                            <!-- 财务员权限设置 -->
                            <div class="form-group">
                                <label>权限设置</label>
                                <div class="limit-box">
                                    <h4>财务员新增发票权限</h4>
                                    <div style="margin-top: 10px; display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" 
                                               id="allow_finance_create_invoice" 
                                               name="allow_finance_create_invoice" 
                                               value="1"
                                               style="width: auto;"
                                               <?php echo (isset($settings['allow_finance_create_invoice']) && $settings['allow_finance_create_invoice'] == 1) ? 'checked' : ''; ?>>
                                        <label for="allow_finance_create_invoice" style="margin-bottom: 0; font-weight: normal; cursor: pointer;">
                                            允许财务员/管理员角色创建和新增发票
                                        </label>
                                    </div>
                                    <p class="help-text" style="margin-top: 5px;">
                                        启用此选项后，财务人员和系统管理员也拥有新增发票以及修改/删除自己所创建发票的权限。
                                    </p>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="save_settings" class="btn btn-primary">保存设置</button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- 当前设置信息 -->
                    <div class="current-settings">
                        <h3>当前设置信息</h3>
                        <div class="settings-info-grid">
                            <div class="setting-item">
                                <span class="setting-item-label">公司名称</span>
                                <span class="setting-item-value"><?php echo htmlspecialchars($settings['company_name'] ?? '我的公司'); ?></span>
                            </div>
                            
                            <div class="setting-item">
                                <span class="setting-item-label">专票税率</span>
                                <span class="setting-item-value"><?php echo htmlspecialchars($settings['tax_rate_zhuan'] ?? 13.00); ?>%</span>
                            </div>
                            
                            <div class="setting-item">
                                <span class="setting-item-label">普票税率</span>
                                <span class="setting-item-value"><?php echo htmlspecialchars($settings['tax_rate_pu'] ?? 3.00); ?>%</span>
                            </div>
                            
                            <div class="setting-item">
                                <span class="setting-item-label">专票最小金额</span>
                                <span class="setting-item-value">¥<?php echo number_format($settings['invoice_limit_number'] ?? 2000, 2); ?></span>
                            </div>

                            <div class="setting-item">
                                <span class="setting-item-label">财务员建票权限</span>
                                <span class="setting-item-value">
                                    <?php echo (isset($settings['allow_finance_create_invoice']) && $settings['allow_finance_create_invoice'] == 1) ? '已允许' : '已禁止'; ?>
                                </span>
                            </div>
                            
                            <div class="setting-item">
                                <span class="setting-item-label">最后更新时间</span>
                                <span class="setting-item-value">
                                    <?php echo isset($settings['updated_at']) ? date('Y-m-d H:i', strtotime($settings['updated_at'])) : '从未更新'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>