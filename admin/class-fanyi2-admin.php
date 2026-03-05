<?php
/**
 * 后台管理类
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_Admin {

    /**
     * 初始化
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_filter('plugin_action_links_' . FANYI2_PLUGIN_BASENAME, array(__CLASS__, 'add_plugin_links'));
    }

    /**
     * 添加菜单
     */
    public static function add_admin_menu() {
        add_menu_page(
            'Fanyi2 翻译管理',
            'Fanyi2 翻译',
            'manage_options',
            'fanyi2',
            array(__CLASS__, 'render_dashboard_page'),
            'dashicons-translation',
            80
        );

        add_submenu_page(
            'fanyi2',
            '控制台',
            '控制台',
            'manage_options',
            'fanyi2',
            array(__CLASS__, 'render_dashboard_page')
        );

        add_submenu_page(
            'fanyi2',
            '翻译管理',
            '翻译管理',
            'manage_options',
            'fanyi2-translations',
            array(__CLASS__, 'render_translations_page')
        );

        add_submenu_page(
            'fanyi2',
            '批量翻译',
            '批量翻译',
            'manage_options',
            'fanyi2-batch',
            array(__CLASS__, 'render_batch_page')
        );

        add_submenu_page(
            'fanyi2',
            '设置',
            '设置',
            'manage_options',
            'fanyi2-settings',
            array(__CLASS__, 'render_settings_page')
        );
    }

    /**
     * 加载后台资源
     */
    public static function enqueue_admin_assets($hook) {
        if (strpos($hook, 'fanyi2') === false) {
            return;
        }

        wp_enqueue_style(
            'fanyi2-admin',
            FANYI2_PLUGIN_URL . 'assets/css/fanyi2-admin.css',
            array(),
            FANYI2_VERSION
        );

        wp_enqueue_script(
            'fanyi2-admin',
            FANYI2_PLUGIN_URL . 'assets/js/fanyi2-admin.js',
            array('jquery'),
            FANYI2_VERSION,
            true
        );

        wp_localize_script('fanyi2-admin', 'fanyi2_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('fanyi2_nonce'),
            'languages' => Fanyi2::get_supported_languages(),
            'settings' => array(
                'default_language'  => get_option('fanyi2_default_language', 'zh'),
                'enabled_languages' => get_option('fanyi2_enabled_languages', array()),
                'ai_engine'         => get_option('fanyi2_ai_engine', 'deepseek'),
                'auto_detect_browser' => get_option('fanyi2_auto_detect_browser', '1'),
            ),
        ));
    }

    /**
     * 添加插件链接
     */
    public static function add_plugin_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=fanyi2-settings') . '">设置</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * 渲染控制台页面
     */
    public static function render_dashboard_page() {
        $stats = Fanyi2_Database::get_stats();
        $language_names = Fanyi2_Frontend::get_language_names();
        ?>
        <div class="wrap fanyi2-admin-wrap">
            <h1>🌐 Fanyi2 翻译管理</h1>
            
            <div class="fanyi2-dashboard-grid">
                <!-- 统计卡片 -->
                <div class="fanyi2-card">
                    <h2>📊 翻译统计</h2>
                    <div class="fanyi2-stats-grid">
                        <div class="fanyi2-stat-item">
                            <span class="fanyi2-stat-number"><?php echo number_format($stats['total_strings']); ?></span>
                            <span class="fanyi2-stat-label">总字符串数</span>
                        </div>
                        <div class="fanyi2-stat-item">
                            <span class="fanyi2-stat-number"><?php echo number_format($stats['total_translations']); ?></span>
                            <span class="fanyi2-stat-label">总翻译数</span>
                        </div>
                    </div>
                </div>

                <!-- 各语言翻译进度 -->
                <div class="fanyi2-card">
                    <h2>📈 语言翻译进度</h2>
                    <div class="fanyi2-lang-progress">
                        <?php foreach ($stats['languages'] as $lang => $lang_stat): 
                            $lang_name = isset($language_names[$lang]) ? $language_names[$lang] : $lang;
                        ?>
                        <div class="fanyi2-lang-progress-item">
                            <div class="fanyi2-lang-progress-header">
                                <span><?php echo esc_html($lang_name); ?> (<?php echo esc_html($lang); ?>)</span>
                                <span><?php echo $lang_stat['translated']; ?>/<?php echo $lang_stat['total']; ?> (<?php echo $lang_stat['percent']; ?>%)</span>
                            </div>
                            <div class="fanyi2-progress-bar-bg">
                                <div class="fanyi2-progress-bar-fill" style="width: <?php echo $lang_stat['percent']; ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 快捷操作 -->
                <div class="fanyi2-card">
                    <h2>🚀 快捷操作</h2>
                    <div class="fanyi2-quick-actions">
                        <a href="<?php echo esc_url(home_url('?fanyi2_editor=1')); ?>" class="button button-primary button-hero" target="_blank">
                            🖊️ 打开可视化编辑器
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=fanyi2-batch'); ?>" class="button button-secondary button-hero">
                            🤖 批量AI翻译
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=fanyi2-settings'); ?>" class="button button-secondary button-hero">
                            ⚙️ 插件设置
                        </a>
                    </div>
                </div>

                <!-- 使用指南 -->
                <div class="fanyi2-card">
                    <h2>📖 使用指南</h2>
                    <ol class="fanyi2-guide-list">
                        <li><strong>配置API:</strong> 前往设置页面，填写DeepSeek或千问的API Key</li>
                        <li><strong>抓取文本:</strong> 打开可视化编辑器，点击"抓取所有文本"自动采集页面内容</li>
                        <li><strong>表格翻译:</strong> 抓取后会弹出表格，可一键 AI 翻译或手动编辑，便于复制汉化</li>
                        <li><strong>点选翻译:</strong> 在编辑器模式下，点击任意文本元素即可单独翻译</li>
                        <li><strong>批量翻译:</strong> 在批量翻译页面，扫描全站后一键预翻译所有内容</li>
                        <li><strong>浏览器语言:</strong> 启用后自动根据访客浏览器语言切换对应语言</li>
                        <li><strong>汇率转换:</strong> 请安装并配置 woo-huilv 汇率插件，本插件会自动将当前语言传递给它</li>
                    </ol>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染翻译管理页面
     */
    public static function render_translations_page() {
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $language_names = Fanyi2_Frontend::get_language_names();
        $enabled_languages = get_option('fanyi2_enabled_languages', array());
        $default_lang = get_option('fanyi2_default_language', 'zh');

        $result = Fanyi2_Database::get_all_strings(array(
            'page'     => $page,
            'per_page' => 20,
            'search'   => $search,
        ));
        ?>
        <div class="wrap fanyi2-admin-wrap">
            <h1>📝 翻译管理</h1>

            <div class="fanyi2-toolbar">
                <form method="get" class="fanyi2-search-form">
                    <input type="hidden" name="page" value="fanyi2-translations">
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="搜索字符串..." class="regular-text">
                    <button type="submit" class="button">搜索</button>
                </form>
                <span class="fanyi2-total-count">共 <?php echo $result['total']; ?> 条字符串</span>
            </div>

            <table class="wp-list-table widefat fixed striped fanyi2-table">
                <thead>
                    <tr>
                        <th style="width:5%">ID</th>
                        <th style="width:35%">原文</th>
                        <th style="width:35%">已翻译语言</th>
                        <th style="width:10%">类型</th>
                        <th style="width:15%">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($result['items'])): ?>
                    <tr><td colspan="5" style="text-align:center;padding:20px;">暂无翻译数据。请先使用可视化编辑器抓取文本。</td></tr>
                    <?php else: ?>
                    <?php foreach ($result['items'] as $item): ?>
                    <tr data-string-id="<?php echo $item->id; ?>">
                        <td><?php echo $item->id; ?></td>
                        <td>
                            <div class="fanyi2-original-text"><?php echo esc_html(mb_strimwidth($item->original_string, 0, 80, '...')); ?></div>
                        </td>
                        <td>
                            <?php 
                            $translated_langs = $item->translated_languages ? explode(',', $item->translated_languages) : array();
                            foreach ($enabled_languages as $lang):
                                if ($lang === $default_lang) continue;
                                $is_translated = in_array($lang, $translated_langs);
                            ?>
                                <span class="fanyi2-lang-badge <?php echo $is_translated ? 'translated' : 'untranslated'; ?>"
                                      title="<?php echo esc_attr($language_names[$lang] ?? $lang); ?>">
                                    <?php echo esc_html($lang); ?>
                                </span>
                            <?php endforeach; ?>
                        </td>
                        <td><span class="fanyi2-type-badge"><?php echo esc_html($item->element_type); ?></span></td>
                        <td>
                            <button class="button button-small fanyi2-edit-string" data-id="<?php echo $item->id; ?>">编辑</button>
                            <button class="button button-small fanyi2-delete-string" data-id="<?php echo $item->id; ?>">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($result['pages'] > 1): ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links(array(
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total'     => $result['pages'],
                        'current'   => $page,
                    ));
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 编辑弹窗 -->
            <div id="fanyi2-edit-modal" class="fanyi2-modal" style="display:none;">
                <div class="fanyi2-modal-content">
                    <div class="fanyi2-modal-header">
                        <h2>编辑翻译</h2>
                        <button class="fanyi2-modal-close">&times;</button>
                    </div>
                    <div class="fanyi2-modal-body">
                        <div class="fanyi2-field">
                            <label>原文:</label>
                            <textarea id="fanyi2-modal-original" readonly rows="3"></textarea>
                        </div>
                        <div id="fanyi2-modal-translations"></div>
                    </div>
                    <div class="fanyi2-modal-footer">
                        <button id="fanyi2-modal-ai-translate" class="button button-primary">🤖 AI翻译所有</button>
                        <button id="fanyi2-modal-save" class="button button-primary">💾 保存</button>
                        <button class="button fanyi2-modal-close">取消</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染批量翻译页面
     */
    public static function render_batch_page() {
        $language_names = Fanyi2_Frontend::get_language_names();
        $enabled_languages = get_option('fanyi2_enabled_languages', array());
        $default_lang = get_option('fanyi2_default_language', 'zh');
        $batch_size = get_option('fanyi2_batch_size', 10);
        ?>
        <div class="wrap fanyi2-admin-wrap">
            <h1>🤖 批量翻译</h1>

            <div class="fanyi2-dashboard-grid">
                <!-- 扫描站点 -->
                <div class="fanyi2-card">
                    <h2>📡 扫描站点文本</h2>
                    <p>自动扫描站点所有页面，抓取需要翻译的文本内容。</p>
                    <button id="fanyi2-scan-site" class="button button-primary button-hero">
                        🔍 扫描整个站点
                    </button>
                    <div id="fanyi2-scan-progress" style="display:none;margin-top:15px;">
                        <div class="fanyi2-progress-bar-bg">
                            <div class="fanyi2-progress-bar-fill" style="width:0%"></div>
                        </div>
                        <p class="fanyi2-scan-status">正在扫描...</p>
                    </div>
                </div>

                <!-- 预翻译 -->
                <div class="fanyi2-card">
                    <h2>🌍 AI预翻译</h2>
                    <p>使用AI自动翻译所有未翻译的字符串。</p>
                    <div class="fanyi2-field" style="margin-bottom:15px;">
                        <label for="fanyi2-batch-target-lang">目标语言:</label>
                        <select id="fanyi2-batch-target-lang">
                            <?php foreach ($enabled_languages as $lang): 
                                if ($lang === $default_lang) continue;
                            ?>
                                <option value="<?php echo esc_attr($lang); ?>">
                                    <?php echo esc_html($language_names[$lang] ?? $lang); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fanyi2-field" style="margin-bottom:15px;">
                        <label for="fanyi2-batch-size">每批数量:</label>
                        <input type="number" id="fanyi2-batch-size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="50" style="width:80px;">
                    </div>
                    <button id="fanyi2-start-pretranslate" class="button button-primary button-hero">
                        🚀 开始预翻译
                    </button>
                    <button id="fanyi2-translate-all-langs" class="button button-secondary button-hero">
                        🌐 翻译所有语言
                    </button>
                    <div id="fanyi2-pretranslate-progress" style="display:none;margin-top:15px;">
                        <div class="fanyi2-progress-bar-bg">
                            <div class="fanyi2-progress-bar-fill" style="width:0%"></div>
                        </div>
                        <p class="fanyi2-pretranslate-status">准备中...</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染设置页面
     */
    public static function render_settings_page() {
        $all_languages = Fanyi2::get_supported_languages();
        $enabled_languages = get_option('fanyi2_enabled_languages', array('zh', 'en'));
        $default_language = get_option('fanyi2_default_language', 'zh');
        $ai_engine = get_option('fanyi2_ai_engine', 'deepseek');
        ?>
        <div class="wrap fanyi2-admin-wrap">
            <h1>⚙️ Fanyi2 设置</h1>

            <form id="fanyi2-settings-form">
                <div class="fanyi2-settings-tabs">
                    <a href="#" class="fanyi2-tab active" data-tab="languages">🌍 语言设置</a>
                    <a href="#" class="fanyi2-tab" data-tab="ai">🤖 AI引擎</a>
                    <a href="#" class="fanyi2-tab" data-tab="advanced">🔧 高级设置</a>
                </div>

                <!-- 语言设置 -->
                <div class="fanyi2-tab-content active" id="tab-languages">
                    <div class="fanyi2-card">
                        <h2>默认语言</h2>
                        <select name="fanyi2_default_language" id="fanyi2-default-language">
                            <?php foreach ($all_languages as $code => $name): ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($default_language, $code); ?>>
                                    <?php echo esc_html($name); ?> (<?php echo esc_html($code); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">您网站的主要语言，翻译时作为源语言。</p>
                    </div>

                    <div class="fanyi2-card">
                        <h2>启用的语言</h2>
                        <p class="description">选择要支持的翻译语言。</p>
                        <div class="fanyi2-language-grid">
                            <?php foreach ($all_languages as $code => $name): ?>
                                <label class="fanyi2-language-checkbox">
                                    <input type="checkbox" name="fanyi2_enabled_languages[]" 
                                           value="<?php echo esc_attr($code); ?>"
                                           <?php checked(in_array($code, $enabled_languages)); ?>>
                                    <?php echo esc_html($name); ?> (<?php echo esc_html($code); ?>)
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- AI引擎设置 -->
                <div class="fanyi2-tab-content" id="tab-ai">
                    <div class="fanyi2-card">
                        <h2>翻译引擎</h2>
                        <div class="fanyi2-radio-group">
                            <label>
                                <input type="radio" name="fanyi2_ai_engine" value="deepseek" <?php checked($ai_engine, 'deepseek'); ?>>
                                <strong>DeepSeek</strong> - 性价比高，中文翻译效果好
                            </label>
                            <label>
                                <input type="radio" name="fanyi2_ai_engine" value="qwen" <?php checked($ai_engine, 'qwen'); ?>>
                                <strong>通义千问 (Qwen)</strong> - 阿里云大模型，多语言支持
                            </label>
                        </div>
                    </div>

                    <div class="fanyi2-card" id="deepseek-settings">
                        <h2>DeepSeek 设置</h2>
                        <table class="form-table">
                            <tr>
                                <th>API Key</th>
                                <td>
                                    <input type="password" name="fanyi2_deepseek_api_key" 
                                           value="<?php echo esc_attr(get_option('fanyi2_deepseek_api_key', '')); ?>"
                                           class="regular-text" placeholder="sk-...">
                                    <p class="description">在 <a href="https://platform.deepseek.com/api_keys" target="_blank">DeepSeek平台</a> 获取API Key</p>
                                </td>
                            </tr>
                            <tr>
                                <th>模型</th>
                                <td>
                                    <select name="fanyi2_deepseek_model">
                                        <option value="deepseek-chat" <?php selected(get_option('fanyi2_deepseek_model', 'deepseek-chat'), 'deepseek-chat'); ?>>deepseek-chat</option>
                                        <option value="deepseek-reasoner" <?php selected(get_option('fanyi2_deepseek_model', 'deepseek-chat'), 'deepseek-reasoner'); ?>>deepseek-reasoner</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>API URL</th>
                                <td>
                                    <input type="url" name="fanyi2_deepseek_api_url" 
                                           value="<?php echo esc_attr(get_option('fanyi2_deepseek_api_url', 'https://api.deepseek.com/v1/chat/completions')); ?>"
                                           class="regular-text">
                                    <p class="description">如使用中转或自建代理可修改此地址</p>
                                </td>
                            </tr>
                            <tr>
                                <th>测试连接</th>
                                <td>
                                    <button type="button" id="fanyi2-test-deepseek" class="button">🔗 测试DeepSeek连接</button>
                                    <span id="fanyi2-deepseek-test-result"></span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="fanyi2-card" id="qwen-settings">
                        <h2>通义千问 设置</h2>
                        <table class="form-table">
                            <tr>
                                <th>API Key</th>
                                <td>
                                    <input type="password" name="fanyi2_qwen_api_key" 
                                           value="<?php echo esc_attr(get_option('fanyi2_qwen_api_key', '')); ?>"
                                           class="regular-text" placeholder="sk-...">
                                    <p class="description">在 <a href="https://dashscope.console.aliyun.com/apiKey" target="_blank">阿里云DashScope控制台</a> 获取API Key</p>
                                </td>
                            </tr>
                            <tr>
                                <th>模型</th>
                                <td>
                                    <select name="fanyi2_qwen_model">
                                        <option value="qwen-turbo" <?php selected(get_option('fanyi2_qwen_model', 'qwen-turbo'), 'qwen-turbo'); ?>>qwen-turbo (快速)</option>
                                        <option value="qwen-plus" <?php selected(get_option('fanyi2_qwen_model', 'qwen-turbo'), 'qwen-plus'); ?>>qwen-plus (增强)</option>
                                        <option value="qwen-max" <?php selected(get_option('fanyi2_qwen_model', 'qwen-turbo'), 'qwen-max'); ?>>qwen-max (旗舰)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>API URL</th>
                                <td>
                                    <input type="url" name="fanyi2_qwen_api_url" 
                                           value="<?php echo esc_attr(get_option('fanyi2_qwen_api_url', 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions')); ?>"
                                           class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>测试连接</th>
                                <td>
                                    <button type="button" id="fanyi2-test-qwen" class="button">🔗 测试千问连接</button>
                                    <span id="fanyi2-qwen-test-result"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- 高级设置 -->
                <div class="fanyi2-tab-content" id="tab-advanced">
                    <div class="fanyi2-card">
                        <h2>🌐 语言切换器设置</h2>
                        <table class="form-table">
                            <tr>
                                <th>显示内置切换器</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="fanyi2_switcher_visible" value="1"
                                               <?php checked(get_option('fanyi2_switcher_visible', '1'), '1'); ?>>
                                        在前台显示浮动语言切换按钮
                                    </label>
                                    <p class="description">隐藏后可通过短代码 <code>[fanyi2_switcher]</code> 或模板函数 <code>&lt;?php fanyi2_language_switcher(); ?&gt;</code> 自定义位置。</p>
                                </td>
                            </tr>
                            <tr>
                                <th>切换器位置</th>
                                <td>
                                    <select name="fanyi2_switcher_position">
                                        <option value="bottom-right" <?php selected(get_option('fanyi2_switcher_position', 'bottom-right'), 'bottom-right'); ?>>右下角</option>
                                        <option value="bottom-left" <?php selected(get_option('fanyi2_switcher_position', 'bottom-right'), 'bottom-left'); ?>>左下角</option>
                                        <option value="top-right" <?php selected(get_option('fanyi2_switcher_position', 'bottom-right'), 'top-right'); ?>>右上角</option>
                                        <option value="top-left" <?php selected(get_option('fanyi2_switcher_position', 'bottom-right'), 'top-left'); ?>>左上角</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>切换器样式</th>
                                <td>
                                    <select name="fanyi2_switcher_style">
                                        <option value="dropdown" <?php selected(get_option('fanyi2_switcher_style', 'dropdown'), 'dropdown'); ?>>下拉菜单</option>
                                        <option value="flags" <?php selected(get_option('fanyi2_switcher_style', 'dropdown'), 'flags'); ?>>国旗图标</option>
                                        <option value="minimal" <?php selected(get_option('fanyi2_switcher_style', 'dropdown'), 'minimal'); ?>>简约下拉框</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <p class="description">
                            <strong>自定义位置钩子：</strong><br>
                            • 短代码：<code>[fanyi2_switcher style="dropdown"]</code><br>
                            • 模板函数：<code>&lt;?php fanyi2_language_switcher('flags'); ?&gt;</code><br>
                            • Action Hook：<code>do_action('fanyi2_language_switcher')</code>（在切换器渲染时触发）
                        </p>
                    </div>

                    <div class="fanyi2-card">
                        <h2>浏览器语言自动检测</h2>
                        <p>
                            <label>
                                <input type="checkbox" name="fanyi2_auto_detect_browser" value="1"
                                       <?php checked(get_option('fanyi2_auto_detect_browser', '1'), '1'); ?>>
                                根据访问者浏览器语言自动切换网站语言
                            </label>
                        </p>
                        <p class="description">首次访问时根据浏览器语言偏好自动设置网站语言，用户可手动切换后记住偏好。</p>
                    </div>

                    <div class="fanyi2-card">
                        <h2>汇率插件集成</h2>
                        <p>本插件已与 <strong>woo-huilv 汇率转换插件</strong> 集成，语言切换时会自动将当前语言传递给汇率插件。</p>
                        <?php if (class_exists('WOO_Huilv')): ?>
                            <p style="color:#155724;">&#10004; woo-huilv 插件已激活，货币联动已启用。</p>
                        <?php else: ?>
                            <p style="color:#856404;">&#9888; woo-huilv 插件未检测到，请安装并激活以启用货币联动功能。</p>
                        <?php endif; ?>
                    </div>

                    <div class="fanyi2-card">
                        <h2>URL模式</h2>
                        <div class="fanyi2-radio-group">
                            <label>
                                <input type="radio" name="fanyi2_url_mode" value="parameter" 
                                       <?php checked(get_option('fanyi2_url_mode', 'parameter'), 'parameter'); ?>>
                                <strong>参数模式</strong> - example.com?lang=en
                            </label>
                            <label>
                                <input type="radio" name="fanyi2_url_mode" value="subdirectory" 
                                       <?php checked(get_option('fanyi2_url_mode', 'parameter'), 'subdirectory'); ?>>
                                <strong>子目录模式</strong> - example.com/en/
                            </label>
                        </div>
                        <p class="description">子目录模式对SEO更友好，但需要确保固定链接设置正确。</p>
                    </div>

                    <div class="fanyi2-card">
                        <h2>批量翻译设置</h2>
                        <table class="form-table">
                            <tr>
                                <th>每批翻译数量</th>
                                <td>
                                    <input type="number" name="fanyi2_batch_size" 
                                           value="<?php echo esc_attr(get_option('fanyi2_batch_size', 10)); ?>"
                                           min="1" max="50">
                                    <p class="description">每次API请求翻译的字符串数量，建议10-20。</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-hero">💾 保存设置</button>
                </p>
            </form>
        </div>
        <?php
    }
}
