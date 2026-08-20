<?php
/**
 * 文件下载接口
 * 访问地址：download.php?url=文件URL地址
 * 示例：download.php?url=https://example.com/file.png
 */

// 启用错误显示（生产环境应关闭）
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 设置响应头
header('Content-Type: text/html; charset=utf-8');

/**
 * 检测是否为微信内置浏览器
 * @return bool
 */
function isWeixinBrowser() {
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        return strpos($userAgent, 'MicroMessenger') !== false;
    }
    return false;
}

/**
 * 验证URL是否合法
 * @param string $url
 * @return bool
 */
function isValidUrl($url) {
    // 基础URL验证
    if (empty($url)) {
        return false;
    }
    
    // 检查URL格式
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    // 可选：限制允许的协议（只允许http/https）
    $parsedUrl = parse_url($url);
    if (!isset($parsedUrl['scheme']) || 
        !in_array(strtolower($parsedUrl['scheme']), ['http', 'https'])) {
        return false;
    }
    
    return true;
}

/**
 * 安全获取文件名
 * @param string $url
 * @return string
 */
function getSafeFileName($url) {
    $fileName = basename(parse_url($url, PHP_URL_PATH) ?: 'download');
    
    // 清理文件名，只保留安全字符
    $fileName = preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $fileName);
    
    // 如果文件名太长，截断
    if (strlen($fileName) > 100) {
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $fileName = substr($name, 0, 95) . '.' . $ext;
    }
    
    return $fileName;
}

/**
 * 生成页面HTML
 * @param string $downloadUrl
 * @param bool $isWeixin
 * @return string
 */
function generatePage($downloadUrl, $isWeixin) {
    $fileName = getSafeFileName($downloadUrl);
    $encodedUrl = htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8');
    $encodedFileName = htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8');
    
    $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . ($isWeixin ? '请在浏览器中打开' : '文件下载') . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
			line-break: anywhere;
        }
        
        body {
            font-family: "Segoe UI", "Microsoft YaHei", sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        
        .icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        .weixin-icon {
            color: #07C160;
        }
        
        .browser-icon {
            color: #4285F4;
        }
        
        h1 {
            color: #333;
            margin-bottom: 15px;
            font-size: 24px;
        }
        
        .error {
            color: #d32f2f;
            background: #ffebee;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            text-align: left;
        }
        
        p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 25px;
            font-size: 16px;
        }
        
        .url-box {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            word-break: break-all;
            font-family: "Courier New", monospace;
            font-size: 14px;
            color: #333;
            text-align: left;
        }
        
        .copy-btn {
            background: #4285F4;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }
        
        .copy-btn:hover {
            background: #3367D6;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(66, 133, 244, 0.3);
        }
        
        .download-btn {
            background: #07C160;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            margin-top: 20px;
        }
        
        .download-btn:hover {
            background: #06AD56;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(7, 193, 96, 0.3);
        }
        
        .instructions {

            border-radius: 8px;
            padding: 15px;
            margin-top: 25px;
            text-align: center;
            font-size: 14px;
        }
        
        .success-message {
            color: #07C160;
            font-size: 14px;
            margin-top: 10px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #4285F4;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .auto-download {
            margin-top: 15px;
            color: #666;
            font-size: 14px;
        }
        
        .url-params {
            margin-top: 20px;
            padding: 10px;
            background: #f0f0f0;
            border-radius: 5px;
            font-size: 14px;
            color: #666;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="container">';
    
    if ($isWeixin) {
        $html .= '
        <div class="icon weixin-icon">📱</div>
        <h1>请在浏览器中打开</h1>
        <p>检测到您正在使用微信访问，微信内置浏览器限制了文件下载功能。</p>
        <p>请点击右上角 ⠇选择 “在浏览器中打开”</p>
        
        <div class="url-box" id="urlBox">' . $encodedUrl . '</div>
        
        <button class="copy-btn" onclick="copyUrl()">
            📋 复制链接
        </button>
        <div class="success-message" id="copySuccess">链接已复制到剪贴板！</div>
        
        <div class="instructions">
            <p><strong>恭喜发财</strong></p>
        </div>
        
        <script>
            function copyUrl() {
                const url = "' . $encodedUrl . '";
                if (navigator.clipboard && window.isSecureContext) {
                    // 使用现代剪贴板API
                    navigator.clipboard.writeText(url)
                        .then(() => showSuccess())
                        .catch(err => {
                            console.error("剪贴板API失败:", err);
                            fallbackCopy(url);
                        });
                } else {
                    // 降级方案
                    fallbackCopy(url);
                }
            }
            
            function fallbackCopy(text) {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed";
                textArea.style.opacity = "0";
                document.body.appendChild(textArea);
                textArea.select();
                
                try {
                    const successful = document.execCommand("copy");
                    if (successful) {
                        showSuccess();
                    }
                } catch (err) {
                    console.error("复制失败:", err);
                    alert("复制失败，请手动选择复制");
                }
                
                document.body.removeChild(textArea);
            }
            
            function showSuccess() {
                const successMsg = document.getElementById("copySuccess");
                successMsg.style.opacity = "1";
                setTimeout(() => {
                    successMsg.style.opacity = "0";
                }, 3000);
            }
        </script>';
    } else {
        $html .= '
        <div class="icon browser-icon">🌐</div>
        <h1>文件下载中...</h1>
        <p>正在为您下载文件：<strong>' . $encodedFileName . '</strong></p>
        
        <div class="spinner"></div>
        
        <p class="auto-download">如果下载没有自动开始，请点击下方按钮：</p>
        <a href="' . $encodedUrl . '" class="download-btn" download="' . $encodedFileName . '">
            ⬇️ 手动下载文件
        </a>
        
        <script>
            // 自动开始下载
            window.onload = function() {
                setTimeout(function() {
                    const link = document.createElement("a");
                    link.href = "' . $encodedUrl . '";
                    link.download = "' . $encodedFileName . '";
                    link.style.display = "none";
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }, 1000);
            };
        </script>';
    }
    
    // 调试信息（仅开发环境显示）
    if (isset($_GET['debug'])) {
        $html .= '
        <div class="url-params">
            <strong>调试信息：</strong><br>
            URL参数：' . htmlspecialchars($_GET['url'] ?? '未提供', ENT_QUOTES, 'UTF-8') . '<br>
            是否为微信：' . ($isWeixin ? '是' : '否') . '<br>
            文件名：' . $encodedFileName . '
        </div>';
    }
    
    $html .= '
    </div>
</body>
</html>';
    
    return $html;
}

// 主程序逻辑
try {
    // 获取URL参数
    $downloadUrl = $_GET['url'] ?? '';
    
    // 验证URL参数
    if (empty($downloadUrl)) {
        throw new Exception('请提供下载URL参数，例如：?url=https://example.com/file.png');
    }
    
    if (!isValidUrl($downloadUrl)) {
        throw new Exception('提供的URL格式不正确，请确保是完整的http或https链接');
    }
    
    // 检测浏览器类型
    $isWeixin = isWeixinBrowser();
    
    // 生成并输出页面
    echo generatePage($downloadUrl, $isWeixin);
    
} catch (Exception $e) {
    // 错误处理
    echo '<!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>错误提示</title>
        <style>
            body {
                font-family: "Segoe UI", "Microsoft YaHei", sans-serif;
                background: #f5f7fa;
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            .error-container {
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                padding: 40px;
                max-width: 500px;
                width: 100%;
                text-align: center;
            }
            .error-icon {
                font-size: 64px;
                color: #d32f2f;
                margin-bottom: 20px;
            }
            h1 {
                color: #333;
                margin-bottom: 15px;
            }
            .error-message {
                color: #d32f2f;
                background: #ffebee;
                padding: 15px;
                border-radius: 8px;
                margin: 15px 0;
            }
            .usage {
                background: #e3f2fd;
                padding: 15px;
                border-radius: 8px;
                margin-top: 20px;
                text-align: left;
            }
            code {
                background: #f5f5f5;
                padding: 2px 6px;
                border-radius: 4px;
                font-family: "Courier New", monospace;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1>参数错误</h1>
            <div class="error-message">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>
            <div class="usage">
                <strong>使用说明：</strong>
                <p>请在URL中添加<code>url</code>参数指定要下载的文件地址：</p>
                <p><code>download.php?url=文件URL地址</code></p>
                <p><strong>示例：</strong></p>
                <p><code>download.php?url=https://example.com/files/document.pdf</code></p>
                <p><strong>完整示例：</strong></p>
                <p><code>https://yourdomain.com/download.php?url=https://ljs.fun:25128/uploads/invoices/invoice.png</code></p>
            </div>
        </div>
    </body>
    </html>';
}