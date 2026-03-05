# Fanyi2 - AI 智能翻译 WordPress 插件

类似 TranslatePress 的WordPress多语言翻译插件，支持前端可视化翻译、AI自动翻译、IP语言检测和WooCommerce货币联动。

## 功能特性

### 🌐 多语言翻译
- 支持28种语言
- 基于数据库的翻译存储，高效查询
- 输出缓冲自动替换翻译内容

### 🖊️ 可视化翻译编辑器
- 在前端打开编辑器模式
- **点选翻译**: 点击任意文本元素，弹出翻译面板
- **一键抓取**: 自动抓取页面所有文本内容
- **批量AI翻译**: 将抓取的文本通过AI一键翻译

### 🤖 AI翻译引擎
- **DeepSeek**: 性价比高，中文翻译效果出色
- **通义千问 (Qwen)**: 阿里云大模型，多语言支持
- 所有API均兼容OpenAI格式
- 支持自定义API URL（可用中转代理）

### 🌍 IP自动语言检测
- 根据访客IP自动判断所在国家
- 自动切换对应语言
- 用户可手动切换，偏好通过Cookie记住
- 支持Cloudflare等CDN环境

### 💰 WooCommerce货币联动
- 语言切换时自动切换对应货币
- 自动获取实时汇率
- 支持手动设置固定汇率
- 价格自动转换

### 📡 批量预翻译
- 扫描全站页面自动抓取文本
- 一键预翻译所有未翻译内容
- 支持选择单一语言或所有语言
- 分批处理，避免超时

## 安装方法

1. 将 `fanyi2` 文件夹上传到 `/wp-content/plugins/` 目录
2. 在WordPress后台"插件"页面激活插件
3. 进入 "Fanyi2 翻译" 菜单进行设置

## 配置步骤

### 1. 设置API Key
前往 **Fanyi2 翻译 > 设置 > AI引擎** 标签：
- 选择翻译引擎（DeepSeek 或 千问）
- 填写对应的API Key
- 点击"测试连接"验证

### 2. 配置语言
前往 **设置 > 语言设置** 标签：
- 设置默认语言（网站主要语言）
- 勾选要支持的翻译语言

### 3. 翻译内容
**方法一：可视化编辑器**
1. 访问前端任意页面
2. 点击右下角"🌐 翻译"按钮进入编辑器
3. 点击"📥 抓取所有文本"
4. 选择目标语言
5. 点击"🤖 AI翻译全部"
6. 点击"💾 保存全部翻译"

**方法二：点选翻译**
1. 在编辑器模式下点击任意文本
2. 在右侧面板中查看原文
3. 点击"🤖 AI翻译"或手动输入翻译
4. 点击"💾 保存"

**方法三：批量预翻译**
1. 前往 **Fanyi2 翻译 > 批量翻译**
2. 点击"扫描整个站点"
3. 选择目标语言
4. 点击"开始预翻译"

### 4. 语言切换器
插件自动在页面右下角显示语言切换器。也可使用短代码：
```
[fanyi2_switcher]
```

## 目录结构

```
fanyi2/
├── fanyi2.php              # 主插件文件
├── README.md
├── admin/
│   └── class-fanyi2-admin.php    # 后台管理
├── includes/
│   ├── class-fanyi2-database.php  # 数据库操作
│   ├── class-fanyi2-translator.php # 翻译替换
│   ├── class-fanyi2-ai-engine.php  # AI翻译引擎
│   ├── class-fanyi2-ip-detector.php # IP检测
│   ├── class-fanyi2-currency.php   # 货币切换
│   ├── class-fanyi2-frontend.php   # 前端功能
│   ├── class-fanyi2-ajax.php       # AJAX处理
│   └── class-fanyi2-batch.php      # 批量翻译
├── assets/
│   ├── css/
│   │   ├── fanyi2-admin.css
│   │   ├── fanyi2-frontend.css
│   │   └── fanyi2-editor.css
│   └── js/
│       ├── fanyi2-admin.js
│       ├── fanyi2-frontend.js
│       └── fanyi2-editor.js
├── languages/
└── templates/
```

## 系统要求

- WordPress 5.6+
- PHP 7.4+
- WooCommerce 5.0+（货币切换功能需要）

## License

GPL v2 or later
