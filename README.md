# Fanyi2 - AI 智能翻译

WordPress 多语言翻译插件，支持 6 种 AI 引擎、28 种语言、前端可视化编辑、WooCommerce 深度集成。

> **版本** 2.0.0 · **PHP** ≥ 7.4 · **WordPress** ≥ 5.6 · **WooCommerce** ≥ 5.0（可选）

---

## 功能概览

| 分类 | 特性 |
|------|------|
| **AI 翻译** | DeepSeek / 通义千问 / OpenAI / Claude / Google Translate / 自定义 OpenAI 兼容接口 |
| **翻译方式** | 前端可视化编辑器 · 后台翻译管理 · 整站批量预翻译 |
| **URL 模式** | 参数模式 `?lang=en` · 子目录模式 `/en/` |
| **WooCommerce** | 产品 · 购物车 · 结账 · 账户 · 属性 · 分类 · 面包屑 · 邮件 · Tab · HPOS 兼容 |
| **语言切换** | 内置浮动切换器（3 种样式 × 5 种位置）· 导航菜单集成 · 短代码 · 模板函数 |
| **SEO** | 自动 hreflang · html lang 属性 · RTL 自动适配 |
| **性能** | 三级缓存（内存 / Object Cache / Transient）· 全量预加载 · 占位符防链式替换 |
| **货币** | 与 woo-huilv 汇率插件联动，语言切换自动切货币 |

---

## 安装

1. 将 `woo-fanyi-main` 文件夹上传至 `/wp-content/plugins/`
2. 在后台 **插件** 页面激活
3. 进入 **Fanyi2 翻译 > 设置** 配置 AI 引擎和语言

---

## 快速开始

### 1. 配置 AI 引擎

**Fanyi2 翻译 > 设置 > AI引擎**：选择引擎、填入 API Key、点击 **测试连接**。

支持的引擎：

| 引擎 | 默认模型 | 可选模型 |
|------|---------|---------|
| DeepSeek | `deepseek-chat` | `deepseek-reasoner` |
| 通义千问 | `qwen-turbo` | `qwen-plus` · `qwen-max` |
| OpenAI | `gpt-4o-mini` | `gpt-4o` · `gpt-4-turbo` · `gpt-3.5-turbo` |
| Claude | `claude-sonnet-4-20250514` | `claude-3-5-sonnet-20241022` · `claude-3-haiku-20240307` |
| Google Translate | — | 机器翻译 API |
| 自定义 | 自行填写 | 任何 OpenAI 兼容接口 |

所有引擎均支持自定义 API URL，可用于中转代理。

### 2. 设置语言

**Fanyi2 翻译 > 设置 > 语言设置**：
- 选择默认语言（网站主语言）
- 勾选要启用的目标语言

### 3. 翻译内容

**方式一：前端可视化编辑器**

访问任意前台页面 → 点击右下角 **🌐 翻译** → 进入编辑器模式：
- **📥 抓取所有文本**：扫描当前页面文本入库
- **🤖 一键抓取并翻译**：抓取 + AI 批量翻译一步完成
- **点击翻译**：点击页面上任意文本，弹出翻译面板单独编辑

**方式二：后台翻译管理**

**Fanyi2 翻译 > 翻译管理**：
- 搜索 / 筛选字符串（按状态、语言、来源）
- 点击语言徽章直接编辑翻译
- 支持 AI 翻译单条

**方式三：整站批量预翻译**

**Fanyi2 翻译 > 整站翻译**：
1. 点击 **扫描整个站点**（自动抓取所有页面、产品、分类、菜单等文本）
2. 选择目标语言和批量大小
3. 点击 **开始翻译** 或 **翻译所有语言**

---

## 语言切换器

### 内置浮动切换器

后台 **设置 > 高级设置** 中配置：

| 设置 | 选项 |
|------|------|
| 样式 | `dropdown`（下拉菜单）· `flags`（旗帜图标）· `minimal`（原生下拉框） |
| 位置 | 右下 · 左下 · 右上 · 左上 |
| 显示 | 开 / 关（关闭后仍触发 `fanyi2_language_switcher` hook） |

### 短代码

```
[fanyi2_switcher]
[fanyi2_switcher style="flags"]
[fanyi2_switcher style="minimal"]
```

### 导航菜单集成

**外观 > 菜单** → 左侧 **Fanyi2 语言切换器** 面板 → 添加到菜单。

各语言选项自动作为子菜单显示，完全继承主题菜单样式，无额外 CSS。

也可手动添加自定义链接，URL 填 `#fanyi2-language-switcher`。

### 模板函数

```php
// 在主题模板中渲染切换器
fanyi2_language_switcher('dropdown'); // dropdown | flags | minimal
```

---

## URL 模式

| 模式 | 示例 | 说明 |
|------|------|------|
| **参数模式**（默认） | `example.com/product/?lang=en` | 简单，无需特殊服务器配置 |
| **子目录模式** | `example.com/en/product/` | SEO 友好，默认语言不加前缀 |

子目录模式下，插件自动：
- 注册 WordPress 重写规则
- 重写所有内部链接（`href` / `action`）
- 保护切换器链接和 hreflang 标签不被重写
- 处理 canonical 重定向

---

## WooCommerce 集成

### 翻译覆盖范围

| 区域 | 翻译内容 |
|------|---------|
| 产品 | 名称 · 短描述 · 完整描述 · 购买说明 |
| 属性 | 属性标签 · 变体选项名 · 分类法术语 |
| 购物车 | 商品名 · 数量标签 · 运费标签 · 优惠券标签 |
| 结账 | 支付方式标题/描述 · 下单按钮 · 字段 label/placeholder |
| 账户 | 菜单项 · 端点标题 |
| 其他 | 面包屑 · 产品 Tab · 分类 Widget · 订单邮件主题/标题 |

### gettext 拦截

自动翻译 `__()` · `_e()` · `_x()` · `_n()` 中 domain 为 `woocommerce` 和 `default` 的文本。

### 兼容性

- ✅ HPOS (High-Performance Order Storage)
- ✅ Cart & Checkout Blocks
- ✅ WooCommerce 5.0 – 9.6

### 货币联动

通过 `woo_huilv_current_language` filter 与 woo-huilv 汇率插件桥接，语言切换时自动切换对应货币和价格。

---

## 性能架构

### 三级翻译缓存

| 层级 | 存储 | TTL | 用途 |
|------|------|-----|------|
| L1 | PHP 内存（`$gettext_cache`） | 单次请求 | O(1) MD5 哈希查找 |
| L2 | Object Cache（Redis / Memcached） | 5 分钟 | 跨请求共享 |
| L3 | WordPress Transient | 5 分钟 | 无 Object Cache 时的后备 |

首次请求通过一条 SQL 查询加载当前语言的全部翻译，后续请求从缓存读取。翻译保存/删除时自动清除对应语言缓存。

### 安全替换机制

采用两阶段占位符替换，防止链式替换（翻译 A 的结果包含原文 B 时不会被二次替换）。替换前按原文长度降序排列，避免短文本截断长文本。

---

## 开发者接口

### PHP 函数

```php
// 获取当前语言代码
$lang = Fanyi2::get_current_language();                // 'en'

// 获取指定语言的当前页面 URL
$url = Fanyi2_Frontend::get_language_url('en');         // 基于当前页面
$url = Fanyi2_Frontend::get_language_url('en', $base);  // 基于指定 URL

// 获取所有支持的语言
$langs = Fanyi2::get_supported_languages();             // ['zh' => '中文', ...]

// 获取语言名称 / 旗帜 emoji
$names = Fanyi2_Frontend::get_language_names();         // ['zh' => '中文', ...]
$flags = Fanyi2_Frontend::get_language_flags();         // ['zh' => '🇨🇳', ...]

// RTL 检测
$is_rtl = Fanyi2_IP_Detector::is_rtl('ar');             // true
$rtl_langs = Fanyi2_IP_Detector::get_rtl_languages();   // ['ar','he','fa','ur']

// 手动清除翻译缓存
Fanyi2_Translator::clear_translation_cache('en');       // 清除特定语言
Fanyi2_Translator::clear_translation_cache();           // 清除所有语言
```

### Hooks

| Hook | 类型 | 说明 |
|------|------|------|
| `fanyi2_language_switcher` | Action | 语言切换器渲染后触发，可用于自定义位置渲染 |
| `woo_huilv_current_language` | Filter | 向汇率插件提供当前语言 |

### JavaScript

前端 `fanyi2_vars` 全局对象可用：

```javascript
fanyi2_vars.current_language  // 'en'
fanyi2_vars.default_language  // 'zh'
fanyi2_vars.enabled_languages // ['zh','en','ja',...]
fanyi2_vars.url_mode          // 'parameter' | 'subdirectory'
fanyi2_vars.home_url          // 'https://example.com/'
fanyi2_vars.is_rtl            // '0' | '1'
fanyi2_vars.ajax_url          // admin-ajax.php URL
fanyi2_vars.nonce             // AJAX nonce
```

---

## 支持的语言（28 种）

🇨🇳 中文 · 🇺🇸 English · 🇯🇵 日本語 · 🇰🇷 한국어 · 🇫🇷 Français · 🇩🇪 Deutsch · 🇪🇸 Español · 🇷🇺 Русский · 🇸🇦 العربية · 🇧🇷 Português · 🇮🇹 Italiano · 🇹🇭 ไทย · 🇻🇳 Tiếng Việt · 🇮🇩 Bahasa Indonesia · 🇲🇾 Bahasa Melayu · 🇹🇷 Türkçe · 🇵🇱 Polski · 🇳🇱 Nederlands · 🇸🇪 Svenska · 🇩🇰 Dansk · 🇫🇮 Suomi · 🇳🇴 Norsk · 🇺🇦 Українська · 🇨🇿 Čeština · 🇬🇷 Ελληνικά · 🇮🇱 עברית · 🇮🇳 हिन्दी · 🇧🇩 বাংলা

RTL 自动适配：阿拉伯语、希伯来语、波斯语、乌尔都语。

---

## 数据库

插件创建两张表：

**`{prefix}fanyi2_strings`** — 源字符串

| 字段 | 说明 |
|------|------|
| `original_string` | 原文 |
| `string_hash` | MD5 哈希（索引，快速查找） |
| `domain` | 来源域（`general` / `woocommerce`） |
| `page_url` | 来源页面 |
| `element_type` | 元素类型（`text` / `post_title` / `content` / `menu_item` / `gettext`） |

**`{prefix}fanyi2_translations`** — 翻译

| 字段 | 说明 |
|------|------|
| `string_id` | 关联源字符串 |
| `language` | 目标语言 |
| `translated_string` | 译文 |
| `translation_source` | 来源（`manual` / `ai`） |
| `status` | 状态（`draft` / `published`） |

---

## 目录结构

```
woo-fanyi-main/
├── fanyi2.php                          # 主插件文件、路由、子目录处理
├── README.md
├── admin/
│   └── class-fanyi2-admin.php          # 后台页面（控制台/翻译管理/整站翻译/设置）
├── assets/
│   ├── css/
│   │   ├── fanyi2-admin.css            # 后台样式
│   │   ├── fanyi2-editor.css           # 可视化编辑器样式
│   │   └── fanyi2-frontend.css         # 前端切换器样式
│   └── js/
│       ├── fanyi2-admin.js             # 后台交互（翻译管理/批量翻译/设置）
│       ├── fanyi2-editor.js            # 可视化编辑器（点选/抓取/翻译面板）
│       └── fanyi2-frontend.js          # 前端切换器逻辑
└── includes/
    ├── class-fanyi2-ai-engine.php      # AI 翻译引擎（6 种）
    ├── class-fanyi2-ajax.php           # 16 个 AJAX 端点
    ├── class-fanyi2-batch.php          # 整站扫描与批量预翻译
    ├── class-fanyi2-currency.php       # woo-huilv 货币联动桥接
    ├── class-fanyi2-database.php       # 数据库 CRUD
    ├── class-fanyi2-frontend.php       # 语言检测/切换器/hreflang/菜单集成
    ├── class-fanyi2-ip-detector.php    # IP 语言检测/RTL 检测
    └── class-fanyi2-translator.php     # 翻译引擎（缓存/输出缓冲/gettext/URL重写）
```

---

## 许可证

GPL v2 or later