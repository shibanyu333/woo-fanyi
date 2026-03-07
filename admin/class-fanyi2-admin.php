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
            '整站翻译',
            '整站翻译',
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
                            🤖 整站翻译
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
                        <li><strong>整站翻译:</strong> 在整站翻译页面，扫描全站后一键翻译所有内容</li>
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
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
        $filter_lang = isset($_GET['filter_lang']) ? sanitize_text_field($_GET['filter_lang']) : '';
        $filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';
        $language_names = Fanyi2_Frontend::get_language_names();
        $enabled_languages = get_option('fanyi2_enabled_languages', array());
        $default_lang = get_option('fanyi2_default_language', 'zh');

        $result = Fanyi2_Database::get_all_strings(array(
            'page'     => $page,
            'per_page' => 20,
            'search'   => $search,
            'translation_status' => $filter_status,
            'filter_language'    => $filter_lang,
            'domain'             => $filter_type === 'woocommerce' ? 'woocommerce' : '',
        ));

        $target_languages = array_values(array_filter($enabled_languages, function($l) use ($default_lang) {
            return $l !== $default_lang;
        }));
        $target_lang_count = count($target_languages);
        ?>
        <div class="wrap fanyi2-admin-wrap">
            <h1>📝 翻译管理</h1>

            <!-- 筛选工具栏 -->
            <div class="fanyi2-toolbar">
                <form method="get" class="fanyi2-search-form">
                    <input type="hidden" name="page" value="fanyi2-translations">
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="搜索原文内容..." class="regular-text">
                    <select name="filter_status">
                        <option value="">全部状态</option>
                        <option value="translated" <?php selected($filter_status, 'translated'); ?>>✅ 已翻译</option>
                        <option value="untranslated" <?php selected($filter_status, 'untranslated'); ?>>❌ 未翻译</option>
                        <option value="partial" <?php selected($filter_status, 'partial'); ?>>⚠️ 部分翻译</option>
                    </select>
                    <select name="filter_lang">
                        <option value="">所有语言</option>
                        <?php foreach ($target_languages as $lang): ?>
                            <option value="<?php echo esc_attr($lang); ?>" <?php selected($filter_lang, $lang); ?>>
                                <?php echo esc_html(($language_names[$lang] ?? $lang) . ' (' . $lang . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="filter_type">
                        <option value="">全部来源</option>
                        <option value="woocommerce" <?php selected($filter_type, 'woocommerce'); ?>>WooCommerce</option>
                    </select>
                    <button type="submit" class="button">🔍 搜索</button>
                    <?php if (!empty($search) || !empty($filter_status) || !empty($filter_lang) || !empty($filter_type)): ?>
                        <a href="<?php echo admin_url('admin.php?page=fanyi2-translations'); ?>" class="button">✕ 清除筛选</a>
                    <?php endif; ?>
                </form>
                <div class="fanyi2-toolbar-right">
                    <span class="fanyi2-total-count">共 <strong><?php echo $result['total']; ?></strong> 条字符串</span>
                </div>
            </div>

            <!-- 翻译表格 -->
            <table class="wp-list-table widefat fixed striped fanyi2-table fanyi2-translations-table">
                <thead>
                    <tr>
                        <th class="column-id" style="width:4%">ID</th>
                        <th class="column-original" style="width:40%">原文 / 来源</th>
                        <th class="column-translations" style="width:36%">翻译状态</th>
                        <th class="column-actions" style="width:20%">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($result['items'])): ?>
                    <tr>
                        <td colspan="4" style="text-align:center;padding:40px 20px;">
                            <div style="font-size:16px;color:#666;margin-bottom:10px;">暂无翻译数据</div>
                            <p style="color:#999;">请先前往 <a href="<?php echo admin_url('admin.php?page=fanyi2-batch'); ?>">整站翻译</a> 页面扫描站点文本，或使用 <a href="<?php echo esc_url(home_url('?fanyi2_editor=1')); ?>" target="_blank">可视化编辑器</a> 抓取。</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($result['items'] as $item):
                        $translated_langs = $item->translated_languages ? explode(',', $item->translated_languages) : array();
                        $trans_count = count($translated_langs);
                        // 计算状态
                        if ($trans_count === 0) {
                            $status_class = 'status-none';
                            $status_label = '未翻译';
                        } elseif ($trans_count >= $target_lang_count) {
                            $status_class = 'status-complete';
                            $status_label = '已完成';
                        } else {
                            $status_class = 'status-partial';
                            $status_label = $trans_count . '/' . $target_lang_count;
                        }
                    ?>
                    <tr data-string-id="<?php echo $item->id; ?>">
                        <td class="column-id"><?php echo $item->id; ?></td>
                        <td class="column-original">
                            <div class="fanyi2-original-text" title="<?php echo esc_attr($item->original_string); ?>">
                                <?php echo esc_html(mb_strimwidth($item->original_string, 0, 120, '...')); ?>
                            </div>
                            <div class="fanyi2-string-meta">
                                <span class="fanyi2-type-badge"><?php echo esc_html($item->element_type); ?></span>
                                <?php if (!empty($item->domain) && $item->domain !== 'general'): ?>
                                    <span class="fanyi2-domain-badge"><?php echo esc_html($item->domain); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item->page_url)): ?>
                                    <a href="<?php echo esc_url($item->page_url); ?>" target="_blank" class="fanyi2-page-link" title="<?php echo esc_attr($item->page_url); ?>">
                                        📍 <?php echo esc_html(self::abbreviate_url($item->page_url)); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="column-translations">
                            <span class="fanyi2-status-indicator <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
                            <div class="fanyi2-lang-badges">
                                <?php foreach ($target_languages as $lang):
                                    $is_translated = in_array($lang, $translated_langs);
                                ?>
                                    <span class="fanyi2-lang-badge <?php echo $is_translated ? 'translated' : 'untranslated'; ?> fanyi2-badge-clickable"
                                          data-id="<?php echo $item->id; ?>"
                                          title="<?php echo esc_attr(($language_names[$lang] ?? $lang) . ': ' . ($is_translated ? '已翻译 - 点击编辑' : '未翻译 - 点击编辑')); ?>">
                                        <?php echo esc_html($lang); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="column-actions">
                            <button class="button button-small fanyi2-ai-single-string" data-id="<?php echo $item->id; ?>" title="AI翻译此条所有语言">🤖</button>
                            <button class="button button-small fanyi2-delete-string" data-id="<?php echo $item->id; ?>" title="清除翻译">🗑️</button>
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
                        <span id="fanyi2-modal-meta" style="font-size:12px;color:#999;"></span>
                        <button class="fanyi2-modal-close">&times;</button>
                    </div>
                    <div class="fanyi2-modal-body">
                        <div class="fanyi2-field">
                            <label>原文 (<?php echo esc_html($language_names[$default_lang] ?? $default_lang); ?>):</label>
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
     * 缩略显示URL（去掉域名保留路径）
     */
    private static function abbreviate_url($url) {
        $parsed = parse_url($url);
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        $path = rtrim($path, '/');
        if (empty($path)) {
            $path = '/';
        }
        if (mb_strlen($path) > 40) {
            $path = '...' . mb_substr($path, -37);
        }
        return $path;
    }

    /**
     * 渲染整站翻译页面
     */
    public static function render_batch_page() {
        $language_names = Fanyi2_Frontend::get_language_names();
        $enabled_languages = get_option('fanyi2_enabled_languages', array());
        $default_lang = get_option('fanyi2_default_language', 'zh');
        $batch_size = get_option('fanyi2_batch_size', 10);
        ?>
        <div class="wrap fanyi2-admin-wrap">
            <h1>🤖 整站翻译</h1>

            <div class="fanyi2-dashboard-grid">
                <!-- 扫描站点 -->
                <div class="fanyi2-card">
                    <h2>📡 扫描站点文本</h2>
                    <p>自动扫描数据库中所有页面、文章、产品、菜单、分类等内容，抓取需要翻译的文本。</p>
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
                    <h2>🌍 AI翻译</h2>
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
                        🚀 开始翻译
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
        $custom_language_names = get_option('fanyi2_language_custom_names', array());
        $custom_language_flags = get_option('fanyi2_language_custom_flags', array());
        $hidden_language_flags = get_option('fanyi2_hidden_language_flags', array());
        $default_language_flags = Fanyi2_Frontend::get_default_language_flags();
        $languages_for_display = !empty($enabled_languages) ? $enabled_languages : array_keys($all_languages);
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

                    <div class="fanyi2-card">
                        <h2>语言显示自定义</h2>
                        <p class="description">可为已启用语言自定义前台显示文字和国旗。留空则使用默认值；勾选“隐藏国旗”后会只显示文字。</p>
                        <table class="widefat striped fanyi2-language-display-table">
                            <thead>
                                <tr>
                                    <th>语言</th>
                                    <th>自定义显示文字</th>
                                    <th>自定义国旗/图标</th>
                                    <th>隐藏国旗</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($languages_for_display as $code):
                                    $name = $all_languages[$code] ?? $code;
                                    $default_flag = $default_language_flags[$code] ?? '';
                                    $custom_name = $custom_language_names[$code] ?? '';
                                    $custom_flag = $custom_language_flags[$code] ?? '';
                                    $hide_flag = in_array($code, $hidden_language_flags, true);
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($name); ?></strong><br>
                                            <span class="description"><?php echo esc_html($code); ?></span>
                                        </td>
                                        <td>
                                            <input type="text"
                                                   name="fanyi2_language_custom_names[<?php echo esc_attr($code); ?>]"
                                                   value="<?php echo esc_attr($custom_name); ?>"
                                                   class="regular-text"
                                                   placeholder="默认：<?php echo esc_attr($name); ?>">
                                        </td>
                                        <td>
                                            <input type="text"
                                                   name="fanyi2_language_custom_flags[<?php echo esc_attr($code); ?>]"
                                                   value="<?php echo esc_attr($custom_flag); ?>"
                                                   class="small-text"
                                                   placeholder="<?php echo esc_attr($default_flag); ?>">
                                            <p class="description">可填 emoji、简写，如 cn、zh。</p>
                                        </td>
                                        <td>
                                            <label>
                                                <input type="checkbox"
                                                       name="fanyi2_hidden_language_flags[]"
                                                       value="<?php echo esc_attr($code); ?>"
                                                       <?php checked($hide_flag); ?>>
                                                不显示
                                            </label>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="description">提示：勾选“隐藏国旗”后，只会移除对应的 emoji，显示文字本身不变。</p>
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
                            <label>
                                <input type="radio" name="fanyi2_ai_engine" value="openai" <?php checked($ai_engine, 'openai'); ?>>
                                <strong>OpenAI / GPT</strong> - GPT-4o/4o-mini，翻译质量高
                            </label>
                            <label>
                                <input type="radio" name="fanyi2_ai_engine" value="claude" <?php checked($ai_engine, 'claude'); ?>>
                                <strong>Claude (Anthropic)</strong> - Claude 大模型，理解力强
                            </label>
                            <label>
                                <input type="radio" name="fanyi2_ai_engine" value="google" <?php checked($ai_engine, 'google'); ?>>
                                <strong>Google Translate API</strong> - 谷歌翻译，速度快、语言覆盖广
                            </label>
                            <label>
                                <input type="radio" name="fanyi2_ai_engine" value="custom" <?php checked($ai_engine, 'custom'); ?>>
                                <strong>自定义 OpenAI 兼容接口</strong> - 支持中转站、本地部署等
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
                                    <button type="button" class="button fanyi2-test-api-btn" data-engine="deepseek">🔗 测试DeepSeek连接</button>
                                    <span class="fanyi2-test-result" data-engine="deepseek"></span>
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
                                    <button type="button" class="button fanyi2-test-api-btn" data-engine="qwen">🔗 测试千问连接</button>
                                    <span class="fanyi2-test-result" data-engine="qwen"></span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="fanyi2-card" id="openai-settings">
                        <h2>OpenAI / GPT 设置</h2>
                        <table class="form-table">
                            <tr>
                                <th>API Key</th>
                                <td>
                                    <input type="password" name="fanyi2_openai_api_key" 
                                           value="<?php echo esc_attr(get_option('fanyi2_openai_api_key', '')); ?>"
                                           class="regular-text" placeholder="sk-...">
                                    <p class="description">在 <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI 平台</a> 获取API Key</p>
                                </td>
                            </tr>
                            <tr>
                                <th>模型</th>
                                <td>
                                    <select name="fanyi2_openai_model">
                                        <option value="gpt-4o-mini" <?php selected(get_option('fanyi2_openai_model', 'gpt-4o-mini'), 'gpt-4o-mini'); ?>>gpt-4o-mini (快速、便宜)</option>
                                        <option value="gpt-4o" <?php selected(get_option('fanyi2_openai_model', 'gpt-4o-mini'), 'gpt-4o'); ?>>gpt-4o (高质量)</option>
                                        <option value="gpt-4-turbo" <?php selected(get_option('fanyi2_openai_model', 'gpt-4o-mini'), 'gpt-4-turbo'); ?>>gpt-4-turbo</option>
                                        <option value="gpt-3.5-turbo" <?php selected(get_option('fanyi2_openai_model', 'gpt-4o-mini'), 'gpt-3.5-turbo'); ?>>gpt-3.5-turbo (经济)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>API URL</th>
                                <td>
                                    <input type="url" name="fanyi2_openai_api_url" 
                                           value="<?php echo esc_attr(get_option('fanyi2_openai_api_url', 'https://api.openai.com/v1/chat/completions')); ?>"
                                           class="regular-text">
                                    <p class="description">如使用代理/中转站可修改此地址</p>
                                </td>
                            </tr>
                            <tr>
                                <th>测试连接</th>
                                <td>
                                    <button type="button" class="button fanyi2-test-api-btn" data-engine="openai">🔗 测试OpenAI连接</button>
                                    <span class="fanyi2-test-result" data-engine="openai"></span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="fanyi2-card" id="claude-settings">
                        <h2>Claude (Anthropic) 设置</h2>
                        <table class="form-table">
                            <tr>
                                <th>API Key</th>
                                <td>
                                    <input type="password" name="fanyi2_claude_api_key" 
                                           value="<?php echo esc_attr(get_option('fanyi2_claude_api_key', '')); ?>"
                                           class="regular-text" placeholder="sk-ant-...">
                                    <p class="description">在 <a href="https://console.anthropic.com/settings/keys" target="_blank">Anthropic 控制台</a> 获取API Key</p>
                                </td>
                            </tr>
                            <tr>
                                <th>模型</th>
                                <td>
                                    <select name="fanyi2_claude_model">
                                        <option value="claude-sonnet-4-20250514" <?php selected(get_option('fanyi2_claude_model', 'claude-sonnet-4-20250514'), 'claude-sonnet-4-20250514'); ?>>Claude Sonnet 4 (推荐)</option>
                                        <option value="claude-3-5-sonnet-20241022" <?php selected(get_option('fanyi2_claude_model', 'claude-sonnet-4-20250514'), 'claude-3-5-sonnet-20241022'); ?>>Claude 3.5 Sonnet</option>
                                        <option value="claude-3-haiku-20240307" <?php selected(get_option('fanyi2_claude_model', 'claude-sonnet-4-20250514'), 'claude-3-haiku-20240307'); ?>>Claude 3 Haiku (快速)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>API URL</th>
                                <td>
                                    <input type="url" name="fanyi2_claude_api_url" 
                                           value="<?php echo esc_attr(get_option('fanyi2_claude_api_url', 'https://api.anthropic.com/v1/messages')); ?>"
                                           class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>测试连接</th>
                                <td>
                                    <button type="button" class="button fanyi2-test-api-btn" data-engine="claude">🔗 测试Claude连接</button>
                                    <span class="fanyi2-test-result" data-engine="claude"></span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="fanyi2-card" id="google-settings">
                        <h2>Google Translate API 设置</h2>
                        <table class="form-table">
                            <tr>
                                <th>API Key</th>
                                <td>
                                    <input type="password" name="fanyi2_google_api_key" 
                                           value="<?php echo esc_attr(get_option('fanyi2_google_api_key', '')); ?>"
                                           class="regular-text">
                                    <p class="description">在 <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a> 获取API Key，需启用 Cloud Translation API</p>
                                </td>
                            </tr>
                            <tr>
                                <th>测试连接</th>
                                <td>
                                    <button type="button" class="button fanyi2-test-api-btn" data-engine="google">🔗 测试Google翻译连接</button>
                                    <span class="fanyi2-test-result" data-engine="google"></span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="fanyi2-card" id="custom-settings">
                        <h2>自定义 OpenAI 兼容接口设置</h2>
                        <p class="description">适用于任何兼容 OpenAI Chat Completions API 格式的接口，例如：本地部署的 LLM、中转API站点等。</p>
                        <table class="form-table">
                            <tr>
                                <th>API Key</th>
                                <td>
                                    <input type="password" name="fanyi2_custom_api_key" 
                                           value="<?php echo esc_attr(get_option('fanyi2_custom_api_key', '')); ?>"
                                           class="regular-text" placeholder="your-api-key">
                                </td>
                            </tr>
                            <tr>
                                <th>API URL</th>
                                <td>
                                    <input type="url" name="fanyi2_custom_api_url" 
                                           value="<?php echo esc_attr(get_option('fanyi2_custom_api_url', '')); ?>"
                                           class="regular-text" placeholder="https://your-api.com/v1/chat/completions">
                                    <p class="description">完整的 Chat Completions 端点URL</p>
                                </td>
                            </tr>
                            <tr>
                                <th>模型名称</th>
                                <td>
                                    <input type="text" name="fanyi2_custom_model" 
                                           value="<?php echo esc_attr(get_option('fanyi2_custom_model', '')); ?>"
                                           class="regular-text" placeholder="model-name">
                                    <p class="description">API对应的模型ID</p>
                                </td>
                            </tr>
                            <tr>
                                <th>测试连接</th>
                                <td>
                                    <button type="button" class="button fanyi2-test-api-btn" data-engine="custom">🔗 测试自定义接口连接</button>
                                    <span class="fanyi2-test-result" data-engine="custom"></span>
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
                        <h2>整站翻译设置</h2>
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
