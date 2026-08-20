# 发票管理系统 (Invoice Management System)

基于 PHP + MySQL 开发的轻量级企业发票管理系统，支持发票录入、状态流转、PDF智能识别、移动端上传及数据导出等功能。

## ✨ 主要功能

- **多角色权限管理**
  - **业务员 (Sales)**: 申请开票、上传发票文件、查看自己提交的记录。
  - **财务员 (Finance)**: 审核开票、标记已开票状态、作废发票、系统设置、用户管理。

- **发票全生命周期管理**
  - 待开票 -> 已开票 -> (可选) 已作废
  - 支持上传 PDF 发票原件，并自动识别发票号码。
  - 支持手机端扫码/访问页面快速上传发票文件。

- **智能辅助**
  - **PDF 解析**: 集成 `Smalot/PdfParser`，自动从上传的 PDF 文件中提取发票号码，减少人工录入错误。
  - **查重机制**: 防止重复录入发票。

- **数据统计与导出**
  - 仪表盘 (Dashboard) 实时展示开票统计。
  - 支持按条件导出 Excel 报表。

## 🛠️ 技术栈

- **后端**: PHP (原生开发，无重型框架依赖)
- **数据库**: MySQL 5.7 / 8.0
- **PDF解析**: `smalot/pdfparser`
- **前端**: HTML5, CSS3, JavaScript (原生 + jQuery)

## 📋 环境要求

- **操作系统**: Linux (推荐 Ubuntu/CentOS) 或 Windows
- **PHP 版本**: PHP 7.4 或更高版本
- **PHP 扩展**:
  - `mysqli` (数据库连接)
  - `mbstring` (PDF解析必须，**重要**)
  - `gd` (可选，用于图片处理)
  - `zip` (用于导出功能)
- **Web 服务器**: Apache 或 Nginx

## 🚀 安装部署指南

### 1. 代码部署
将项目代码下载至 Web 服务器根目录（如 `/var/www/html/invoice`）。

```bash
git clone https://github.com/your-repo/invoice-system.git .
```

### 2. 目录权限设置
确保 Web 服务器用户（如 `www-data`）对以下目录拥有**写入权限**：

```bash
chmod -R 755 config/
chmod -R 755 uploads/
chown -R www-data:www-data config/ uploads/
```

### 3. 系统初始化
1. 在浏览器中访问 `http://your-domain.com/init.php`。
2. 填写数据库连接信息（主机、数据库名、用户名、密码）。
3. 设置管理员账号（默认角色为财务员）。
4. 点击“安装系统”，系统将自动创建配置文件和数据库表结构。

> **注意**: 初始化完成后，建议删除 `init.php` 或将其权限设置为不可访问，以保证安全。

### 4. Nginx 配置示例 (可选)
如果您使用 Nginx，可以参考以下配置：

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/html/invoice;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
    }

    # 禁止访问敏感目录
    location ^~ /config/ {
        deny all;
    }
    location ^~ /includes/ {
        deny all;
    }
}
```

## 📂 目录结构说明

```
/
├── api/                # API 接口 (如 PDF 识别接口)
├── config/             # 配置文件 (自动生成)
├── includes/           # 公共函数库、权限验证、头部模板
├── js/                 # JavaScript 资源
├── pdfparser-2.12.2/   # PDF 解析库
├── uploads/            # 上传文件存储目录
├── add_invoice.php     # 新增发票页面
├── dashboard.php       # 仪表盘
├── init.php            # 初始化安装脚本
├── invoices.php        # 发票列表/管理
├── login.php           # 登录页
└── ...
```

## ⚠️ 常见问题

**Q: 上传 PDF 时提示“系统缺少 mbstring 扩展”？**
A: PDF 解析功能依赖 `mbstring` 扩展。请在服务器上安装该扩展：
- Ubuntu: `sudo apt-get install php-mbstring`
- CentOS: `sudo yum install php-mbstring`
安装后记得重启 PHP 服务 (e.g., `sudo service php-fpm restart`).

**Q: 初始化后如何修改数据库配置？**
A: 修改 `config/database.php` 文件中的常量定义即可。

## 📄 许可证

本项目仅供内部使用或学习交流。
