<?php
/**
 * 前端功能类 - 语言切换、输出翻译
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_Frontend {

    private static $current_language = null;

    /**
     * 初始化
     */
    public static function init() {
        add_action('wp_footer', array(__CLASS__, 'render_language_switcher'));
        add_action('wp_footer', array(__CLASS__, 'render_editor_toolbar'));
        add_action('template_redirect', array(__CLASS__, 'detect_and_set_language'), 1);

        // 输出缓冲替换翻译
        add_action('template_redirect', array(__CLASS__, 'start_translation_buffer'), 2);

        // 将语言信息添加到html标签
        add_filter('language_attributes', array(__CLASS__, 'modify_language_attributes'));

        // 添加hreflang标签用于SEO
        add_action('wp_head', array(__CLASS__, 'add_hreflang_tags'));

        // 注册 WooCommerce 专用翻译钩子
        add_action('wp', array('Fanyi2_Translator', 'register_wc_hooks'));

        // RTL 语言修正：让 WooCommerce Flexslider 及画廊在 RTL 模式下正常工作
        add_filter('woocommerce_single_product_carousel_options', array(__CLASS__, 'fix_gallery_rtl'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_enqueue_rtl_fix'), 99);
    }

    /**
     * 检测并设置当前语言
     *
     * 优先级（修正版）：
     *   URL路径/参数 > Cookie（仅参数模式）> 浏览器检测 > 默认语言
     *
     * 关键修正：子目录模式下，URL 没有语言前缀 = 默认语言，
     * 不再回退到 cookie，避免 cookie 残留导致语言"粘滞"。
     */
    public static function detect_and_set_language() {
        $language = null;
        $url_mode = get_option('fanyi2_url_mode', 'parameter');
        $default_lang = get_option('fanyi2_default_language', 'zh');
        $enabled = get_option('fanyi2_enabled_languages', array('zh', 'en'));
        $url_determined = false; // 标记 URL 是否明确指定了语言

        // 1. URL参数 (?lang=xx)
        if (isset($_GET['lang'])) {
            $language = sanitize_text_field($_GET['lang']);
            $url_determined = true;
        }

        // 2. 子目录模式
        if (empty($language) && $url_mode === 'subdirectory') {
            // handle_subdirectory_request() 在 init 阶段已将检测到的语言写入 cookie
            if (isset($_COOKIE['fanyi2_language']) && in_array($_COOKIE['fanyi2_language'], $enabled)) {
                $cookie_lang = sanitize_text_field($_COOKIE['fanyi2_language']);
            } else {
                $cookie_lang = $default_lang;
            }

            // 检查原始 REQUEST_URI 是否包含语言前缀
            // 注意：handle_subdirectory_request 已经修改了 $_SERVER['REQUEST_URI']，
            // 所以这里通过 cookie 是否在本次请求中被设为非默认来判断
            $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            $req_path = parse_url($request_uri, PHP_URL_PATH);
            $home_path = parse_url(home_url(), PHP_URL_PATH);
            $home_path_clean = $home_path ? rtrim($home_path, '/') : '';
            $relative = $req_path;
            if ($home_path_clean) {
                $relative = substr($req_path, strlen($home_path_clean));
            }
            $relative = ltrim($relative ?: '', '/');
            $segments = explode('/', $relative);
            $first_seg = !empty($segments[0]) ? $segments[0] : '';

            // 如果 URL 中仍有语言前缀（未被 handle_subdirectory_request 剥离，
            // 比如 rewrite 阶段晚于 init），使用它
            if (in_array($first_seg, $enabled) && $first_seg !== $default_lang) {
                $language = $first_seg;
            } elseif ($cookie_lang !== $default_lang) {
                // cookie 从 handle_subdirectory_request 传来
                $language = $cookie_lang;
            } else {
                // 无语言前缀 => 默认语言
                $language = $default_lang;
            }

            // 子目录模式下 URL 始终是确定性的
            $url_determined = true;
        }

        // 3. Cookie（仅参数模式可回退）
        if (empty($language) && !$url_determined && isset($_COOKIE['fanyi2_language'])) {
            $language = sanitize_text_field($_COOKIE['fanyi2_language']);
        }

        // 4. 浏览器语言检测
        if (empty($language)) {
            $language = Fanyi2_IP_Detector::detect_language();
        }

        // 5. 默认语言
        if (empty($language)) {
            $language = $default_lang;
        }

        // 验证语言是否启用
        if (!in_array($language, $enabled)) {
            $language = $default_lang;
        }

        self::$current_language = $language;

        // 设置 Cookie
        if (!isset($_COOKIE['fanyi2_language']) || $_COOKIE['fanyi2_language'] !== $language) {
            setcookie('fanyi2_language', $language, time() + (365 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
        }
    }

    /**
     * 开始翻译输出缓冲
     */
    public static function start_translation_buffer() {
        $current_lang = self::get_current_language();
        $default_lang = get_option('fanyi2_default_language', 'zh');

        // 编辑器模式不翻译
        if (isset($_GET['fanyi2_editor'])) {
            return;
        }

        if ($current_lang !== $default_lang) {
            ob_start(array('Fanyi2_Translator', 'process_output'));
        }
    }

    /**
     * 获取当前语言
     */
    public static function get_current_language() {
        if (self::$current_language === null) {
            // 在init之前调用时的处理
            if (isset($_GET['lang'])) {
                return sanitize_text_field($_GET['lang']);
            }
            if (isset($_COOKIE['fanyi2_language'])) {
                return sanitize_text_field($_COOKIE['fanyi2_language']);
            }
            return get_option('fanyi2_default_language', 'zh');
        }
        return self::$current_language;
    }

    /**
     * 渲染语言切换器（内置浮动按钮，受后台设置控制）
     */
    public static function render_language_switcher() {
        // 后台设置隐藏则不渲染内置切换器
        if (get_option('fanyi2_switcher_visible', '1') !== '1') {
            // 仍然触发 hook，方便自定义位置
            do_action('fanyi2_language_switcher');
            return;
        }

        $position = get_option('fanyi2_switcher_position', 'bottom-right');
        $style = get_option('fanyi2_switcher_style', 'dropdown');

        self::output_language_switcher($style, $position);

        // 触发 hook，方便主题/插件在其他位置渲染切换器
        do_action('fanyi2_language_switcher');
    }

    /**
     * 输出语言切换器HTML
     * 可被 shortcode、hook、模板函数调用
     *
     * @param string $style    dropdown|flags|minimal
     * @param string $position bottom-right|bottom-left|top-right|top-left|inline
     */
    public static function output_language_switcher($style = 'dropdown', $position = 'bottom-right') {
        $enabled_languages = get_option('fanyi2_enabled_languages', array('zh', 'en'));
        $current_language = self::get_current_language();
        $language_names = self::get_language_names();
        $language_flags = self::get_language_flags();

        $position_class = 'fanyi2-pos-' . esc_attr($position);
        $style_class = 'fanyi2-style-' . esc_attr($style);

        ?>
        <div id="fanyi2-language-switcher" class="fanyi2-language-switcher fanyi2-switcher <?php echo esc_attr($position_class . ' ' . $style_class); ?>">
            <?php if ($style === 'flags'): ?>
                <div class="fanyi2-switcher-flags">
                    <?php foreach ($enabled_languages as $lang):
                        $url = self::get_language_url($lang);
                    ?>
                        <a href="<?php echo esc_url($url); ?>" class="fanyi2-flag-option <?php echo $lang === $current_language ? 'active' : ''; ?>"
                           data-lang="<?php echo esc_attr($lang); ?>"
                           data-fanyi2-lang="<?php echo esc_attr($lang); ?>"
                           title="<?php echo esc_html($language_names[$lang] ?? $lang); ?>">
                            <span class="fanyi2-flag"><?php echo esc_html($language_flags[$lang] ?? '🌐'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($style === 'minimal'): ?>
                <select class="fanyi2-switcher-select" onchange="if(this.value)location.href=this.value">
                    <?php foreach ($enabled_languages as $lang):
                        $url = self::get_language_url($lang);
                    ?>
                        <option value="<?php echo esc_url($url); ?>" <?php selected($lang, $current_language); ?>>
                            <?php echo esc_html(($language_flags[$lang] ?? '') . ' ' . ($language_names[$lang] ?? $lang)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: /* dropdown */ ?>
                <div class="fanyi2-switcher-current">
                    <span class="fanyi2-flag"><?php echo esc_html($language_flags[$current_language] ?? '🌐'); ?></span>
                    <span class="fanyi2-lang-name"><?php echo esc_html($language_names[$current_language] ?? $current_language); ?></span>
                    <span class="fanyi2-arrow">▼</span>
                </div>
                <div class="fanyi2-switcher-dropdown">
                    <?php foreach ($enabled_languages as $lang):
                        $url = self::get_language_url($lang);
                    ?>
                        <a href="<?php echo esc_url($url); ?>" class="fanyi2-lang-option <?php echo $lang === $current_language ? 'active' : ''; ?>"
                           data-lang="<?php echo esc_attr($lang); ?>"
                           data-fanyi2-lang="<?php echo esc_attr($lang); ?>">
                            <span class="fanyi2-flag"><?php echo esc_html($language_flags[$lang] ?? '🌐'); ?></span>
                            <span><?php echo esc_html($language_names[$lang] ?? $lang); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 渲染编辑器工具栏 (仅管理员)
     */
    public static function render_editor_toolbar() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $is_editor = isset($_GET['fanyi2_editor']);
        $current_url = esc_url(remove_query_arg('fanyi2_editor'));
        $editor_url = esc_url(add_query_arg('fanyi2_editor', '1'));
        $enabled_languages = get_option('fanyi2_enabled_languages', array('zh', 'en'));
        $language_names = self::get_language_names();
        $default_lang = get_option('fanyi2_default_language', 'zh');
        $default_lang_name = isset($language_names[$default_lang]) ? $language_names[$default_lang] : $default_lang;

        if ($is_editor): ?>
        <div id="fanyi2-editor-toolbar">
            <div class="fanyi2-toolbar-inner">
                <div class="fanyi2-toolbar-logo">
                    <strong>Fanyi2</strong> 翻译编辑器
                </div>
                <div class="fanyi2-toolbar-controls">
                    <span class="fanyi2-toolbar-source-lang">
                        源语言: <strong><?php echo esc_html($default_lang_name); ?></strong>
                    </span>
                    <span class="fanyi2-toolbar-separator">→</span>
                    <label class="fanyi2-toolbar-label" for="fanyi2-editor-target-lang">翻译为:</label>
                    <select id="fanyi2-editor-target-lang" class="fanyi2-toolbar-select">
                        <?php foreach ($enabled_languages as $lang): 
                            if ($lang === $default_lang) continue;
                        ?>
                            <option value="<?php echo esc_attr($lang); ?>">
                                <?php echo esc_html($language_names[$lang] ?? $lang); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button id="fanyi2-grab-all" class="fanyi2-toolbar-btn fanyi2-btn-primary">
                        📥 抓取所有文本
                    </button>
                    <button id="fanyi2-grab-and-translate" class="fanyi2-toolbar-btn fanyi2-btn-success">
                        🤖 一键抓取并翻译
                    </button>
                    <a href="<?php echo $current_url; ?>" class="fanyi2-toolbar-btn fanyi2-btn-secondary">
                        ✕ 退出编辑器
                    </a>
                </div>
            </div>
            <div id="fanyi2-editor-panel" style="display:none;">
                <div class="fanyi2-panel-header">
                    <h3>翻译面板</h3>
                    <button id="fanyi2-panel-close" class="fanyi2-panel-close-btn">&times;</button>
                </div>
                <div class="fanyi2-panel-body">
                    <div class="fanyi2-panel-field">
                        <label>原文:</label>
                        <textarea id="fanyi2-original-text" readonly rows="3"></textarea>
                    </div>
                    <div class="fanyi2-panel-field">
                        <label>翻译:</label>
                        <textarea id="fanyi2-translated-text" rows="3" placeholder="输入翻译或使用AI翻译..."></textarea>
                    </div>
                    <div class="fanyi2-panel-actions">
                        <button id="fanyi2-ai-translate-single" class="fanyi2-toolbar-btn fanyi2-btn-primary">
                            🤖 AI翻译
                        </button>
                        <button id="fanyi2-save-single" class="fanyi2-toolbar-btn fanyi2-btn-success">
                            💾 保存
                        </button>
                    </div>
                </div>
            </div>
            <!-- 批量翻译进度 -->
            <div id="fanyi2-progress-overlay" style="display:none;">
                <div class="fanyi2-progress-box">
                    <h3>正在翻译...</h3>
                    <div class="fanyi2-progress-bar">
                        <div class="fanyi2-progress-fill" style="width: 0%"></div>
                    </div>
                    <p class="fanyi2-progress-text">准备中...</p>
                    <button id="fanyi2-cancel-translate" class="fanyi2-toolbar-btn fanyi2-btn-secondary">取消</button>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div id="fanyi2-edit-trigger">
            <a href="<?php echo $editor_url; ?>" class="fanyi2-edit-btn" title="翻译此页面">
                🌐 翻译
            </a>
        </div>
        <?php endif;
    }

    /**
     * 修改html lang属性
     */
    public static function modify_language_attributes($output) {
        $current_lang = self::get_current_language();
        $locale_map = array(
            'zh' => 'zh-CN', 'en' => 'en-US', 'ja' => 'ja',
            'ko' => 'ko', 'fr' => 'fr-FR', 'de' => 'de-DE',
            'es' => 'es-ES', 'ru' => 'ru-RU', 'ar' => 'ar',
            'pt' => 'pt-BR', 'it' => 'it-IT', 'th' => 'th',
            'vi' => 'vi', 'id' => 'id', 'ms' => 'ms',
            'tr' => 'tr', 'pl' => 'pl', 'nl' => 'nl',
        );

        $locale = isset($locale_map[$current_lang]) ? $locale_map[$current_lang] : $current_lang;
        $output = preg_replace('/lang="[^"]*"/', 'lang="' . esc_attr($locale) . '"', $output);

        // RTL语言支持
        $rtl_languages = Fanyi2_IP_Detector::get_rtl_languages();
        if (in_array($current_lang, $rtl_languages)) {
            $output .= ' dir="rtl"';
        }

        return $output;
    }

    /**
     * 添加hreflang标签
     */
    public static function add_hreflang_tags() {
        $enabled_languages = get_option('fanyi2_enabled_languages', array());
        $url_mode = get_option('fanyi2_url_mode', 'parameter');
        $default_lang = get_option('fanyi2_default_language', 'zh');
        $current_url = home_url(add_query_arg(array()));

        foreach ($enabled_languages as $lang) {
            $lang_url = self::get_language_url($lang, $current_url);
            $hreflang = ($lang === 'zh') ? 'zh-Hans' : $lang;
            echo '<link rel="alternate" hreflang="' . esc_attr($hreflang) . '" href="' . esc_url($lang_url) . '" />' . "\n";
        }
        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url(self::get_language_url($default_lang, $current_url)) . '" />' . "\n";
    }

    /**
     * 获取指定语言的URL（支持参数模式和子目录模式）
     */
    public static function get_language_url($lang, $base_url = '') {
        if (empty($base_url)) {
            $base_url = home_url(add_query_arg(array()));
        }

        $url_mode = get_option('fanyi2_url_mode', 'parameter');
        $default_lang = get_option('fanyi2_default_language', 'zh');

        if ($url_mode === 'subdirectory') {
            // 子目录模式
            $parsed = parse_url($base_url);
            $home_parsed = parse_url(home_url());
            $home_path = isset($home_parsed['path']) ? rtrim($home_parsed['path'], '/') : '';
            $path = isset($parsed['path']) ? $parsed['path'] : '/';

            // 移除当前语言前缀
            $relative_path = $path;
            if ($home_path) {
                $relative_path = substr($path, strlen($home_path));
            }
            $relative_path = ltrim($relative_path, '/');

            $enabled_languages = get_option('fanyi2_enabled_languages', array());
            $segments = explode('/', $relative_path);
            if (!empty($segments[0]) && in_array($segments[0], $enabled_languages)) {
                array_shift($segments);
            }
            $clean_path = implode('/', $segments);

            // 构建新URL
            if ($lang === $default_lang) {
                $new_path = $home_path . '/' . $clean_path;
            } else {
                $new_path = $home_path . '/' . $lang . '/' . $clean_path;
            }

            $new_url = (isset($parsed['scheme']) ? $parsed['scheme'] : 'https') . '://' . $parsed['host'];
            if (isset($parsed['port'])) {
                $new_url .= ':' . $parsed['port'];
            }
            $new_url .= rtrim($new_path, '/') . '/';
            if (isset($parsed['query'])) {
                // 移除 lang 参数
                parse_str($parsed['query'], $query_params);
                unset($query_params['lang']);
                if (!empty($query_params)) {
                    $new_url .= '?' . http_build_query($query_params);
                }
            }
            return $new_url;
        } else {
            // 参数模式
            if ($lang === $default_lang) {
                return remove_query_arg('lang', $base_url);
            }
            return add_query_arg('lang', $lang, remove_query_arg('lang', $base_url));
        }
    }

    /**
     * 获取语言名称
     */
    public static function get_language_names() {
        return array(
            'zh' => '中文',
            'en' => 'English',
            'ja' => '日本語',
            'ko' => '한국어',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'es' => 'Español',
            'ru' => 'Русский',
            'ar' => 'العربية',
            'pt' => 'Português',
            'it' => 'Italiano',
            'th' => 'ไทย',
            'vi' => 'Tiếng Việt',
            'id' => 'Bahasa Indonesia',
            'ms' => 'Bahasa Melayu',
            'tr' => 'Türkçe',
            'pl' => 'Polski',
            'nl' => 'Nederlands',
            'sv' => 'Svenska',
            'da' => 'Dansk',
            'fi' => 'Suomi',
            'no' => 'Norsk',
            'uk' => 'Українська',
            'cs' => 'Čeština',
            'el' => 'Ελληνικά',
            'he' => 'עברית',
            'hi' => 'हिन्दी',
            'bn' => 'বাংলা',
        );
    }

    /**
     * 获取语言旗帜emoji
     */
    public static function get_language_flags() {
        return array(
            'zh' => '🇨🇳',
            'en' => '🇺🇸',
            'ja' => '🇯🇵',
            'ko' => '🇰🇷',
            'fr' => '🇫🇷',
            'de' => '🇩🇪',
            'es' => '🇪🇸',
            'ru' => '🇷🇺',
            'ar' => '🇸🇦',
            'pt' => '🇧🇷',
            'it' => '🇮🇹',
            'th' => '🇹🇭',
            'vi' => '🇻🇳',
            'id' => '🇮🇩',
            'ms' => '🇲🇾',
            'tr' => '🇹🇷',
            'pl' => '🇵🇱',
            'nl' => '🇳🇱',
            'sv' => '🇸🇪',
            'da' => '🇩🇰',
            'fi' => '🇫🇮',
            'no' => '🇳🇴',
            'uk' => '🇺🇦',
            'cs' => '🇨🇿',
            'el' => '🇬🇷',
            'he' => '🇮🇱',
            'hi' => '🇮🇳',
            'bn' => '🇧🇩',
        );
    }

    /**
     * 修正 WooCommerce 产品画廊 Flexslider 在 RTL 语言下的方向
     */
    public static function fix_gallery_rtl($options) {
        $current_lang = self::get_current_language();
        $rtl_languages = Fanyi2_IP_Detector::get_rtl_languages();
        if (in_array($current_lang, $rtl_languages)) {
            $options['rtl'] = true;
        }
        return $options;
    }

    /**
     * RTL 语言下注入画廊保护 CSS，防止图片被 direction:rtl 破坏
     */
    public static function maybe_enqueue_rtl_fix() {
        $current_lang = self::get_current_language();
        $rtl_languages = Fanyi2_IP_Detector::get_rtl_languages();
        if (!in_array($current_lang, $rtl_languages)) {
            return;
        }
        $css = '
            /* Fanyi2 RTL 画廊修复 */
            [dir="rtl"] .woocommerce-product-gallery,
            [dir="rtl"] .woocommerce-product-gallery__wrapper,
            [dir="rtl"] .woocommerce-product-gallery__image,
            [dir="rtl"] .flex-viewport,
            [dir="rtl"] .flex-control-thumbs,
            [dir="rtl"] .flex-direction-nav,
            [dir="rtl"] .woocommerce-product-gallery .flex-control-thumbs li {
                direction: ltr !important;
            }
            /* 确保画廊滑块不溢出隐藏 */
            [dir="rtl"] .flex-viewport { overflow: hidden; }
            [dir="rtl"] .woocommerce-product-gallery__wrapper {
                display: flex;
                flex-direction: row;
            }
            /* PhotoSwipe 灯箱修正 */
            [dir="rtl"] .pswp,
            [dir="rtl"] .pswp__container,
            [dir="rtl"] .pswp__item {
                direction: ltr !important;
            }
            /* 通用图片容器保护 */
            [dir="rtl"] .gallery,
            [dir="rtl"] .wp-block-gallery,
            [dir="rtl"] .wp-block-image,
            [dir="rtl"] .tiled-gallery,
            [dir="rtl"] .swiper,
            [dir="rtl"] .swiper-wrapper,
            [dir="rtl"] .slick-slider,
            [dir="rtl"] .slick-list {
                direction: ltr !important;
            }
        ';
        wp_add_inline_style('fanyi2-frontend', $css);
    }

    /**
     * 语言切换器短代码
     * 用法: [fanyi2_switcher] 或 [fanyi2_switcher style="flags"]
     */
    public static function shortcode_language_switcher($atts) {
        $atts = shortcode_atts(array(
            'style' => 'dropdown', // dropdown, flags, minimal
        ), $atts, 'fanyi2_switcher');

        ob_start();
        self::output_language_switcher($atts['style'], 'inline');
        return ob_get_clean();
    }

    // ====== 导航菜单集成 ======

    /**
     * 在 外观>菜单 编辑器中注册 "Fanyi2 语言切换器" 面板
     */
    public static function register_nav_menu_metabox() {
        add_meta_box(
            'fanyi2-language-switcher-nav',
            'Fanyi2 语言切换器',
            array(__CLASS__, 'render_nav_menu_metabox'),
            'nav-menus',
            'side',
            'low'
        );
    }

    /**
     * 渲染菜单编辑器中的语言切换器面板
     */
    public static function render_nav_menu_metabox() {
        ?>
        <div id="fanyi2-nav-menu-metabox" class="posttypediv">
            <p class="description" style="margin-bottom:10px;">
                添加语言切换菜单项。无自带样式，完全继承主题菜单风格。
            </p>
            <div id="tabs-panel-fanyi2" class="tabs-panel tabs-panel-active">
                <ul class="categorychecklist form-no-clear">
                    <li>
                        <label>
                            <input type="checkbox" class="menu-item-checkbox"
                                   name="menu-item[-1][menu-item-object-id]" value="-1">
                            语言切换器（下拉子菜单）
                        </label>
                    </li>
                </ul>
            </div>
            <p class="button-controls wp-clearfix">
                <span class="add-to-menu">
                    <input type="submit" class="button submit-add-to-menu right"
                           value="添加到菜单"
                           name="add-fanyi2-menu-item"
                           id="fanyi2-add-to-menu"
                           onclick="fanyi2AddToMenu(); return false;">
                </span>
            </p>
            <script>
            function fanyi2AddToMenu() {
                // 使用 WordPress 菜单 API 添加自定义链接
                wpNavMenu.addItemToMenu({
                    '-1': {
                        'menu-item-type': 'custom',
                        'menu-item-url': '#fanyi2-language-switcher',
                        'menu-item-title': '🌐 语言'
                    }
                }, wpNavMenu.addMenuItemToBottom, function() {});
            }
            </script>
            <p class="description" style="margin-top:10px; font-size:11px;">
                <strong>用法说明：</strong><br>
                1. 添加后，菜单中会出现"🌐 语言"项<br>
                2. 各语言选项会自动作为子菜单显示<br>
                3. 不附带额外样式，完全使用主题样式<br>
                4. 也可手动添加自定义链接，URL 填 <code>#fanyi2-language-switcher</code>
            </p>
        </div>
        <?php
    }

    /**
     * 在前台渲染菜单时，将 #fanyi2-language-switcher 占位链接
     * 替换为实际的语言切换子菜单项（纯 HTML <li>，无额外样式）
     */
    public static function filter_nav_menu_items($items, $args) {
        // 检查菜单中是否包含我们的占位链接
        if (strpos($items, '#fanyi2-language-switcher') === false) {
            return $items;
        }

        $enabled_languages = get_option('fanyi2_enabled_languages', array('zh', 'en'));
        $current_language = self::get_current_language();
        $language_names = self::get_language_names();
        $language_flags = self::get_language_flags();
        $url_mode = get_option('fanyi2_url_mode', 'parameter');
        $default_lang = get_option('fanyi2_default_language', 'zh');

        // 构建子菜单项（使用当前页面 URL 作为基准，而非首页）
        $current_page_url = home_url(add_query_arg(array()));
        $submenu_items = '';
        foreach ($enabled_languages as $lang) {
            $lang_name = isset($language_names[$lang]) ? $language_names[$lang] : $lang;
            $flag = isset($language_flags[$lang]) ? $language_flags[$lang] : '';

            // 使用 get_language_url 生成当前页面在各语言下的 URL
            $url = self::get_language_url($lang, $current_page_url);

            $active_class = ($lang === $current_language) ? ' current-menu-item current-lang' : '';
            $submenu_items .= '<li class="menu-item fanyi2-menu-lang-item' . $active_class . '">';
            $submenu_items .= '<a href="' . esc_url($url) . '" data-fanyi2-lang="' . esc_attr($lang) . '">';
            $submenu_items .= $flag . ' ' . esc_html($lang_name);
            $submenu_items .= '</a></li>';
        }

        // 替换占位链接为带子菜单的菜单项
        // 找到包含 #fanyi2-language-switcher 的 <li> 并在其中注入 <ul class="sub-menu">
        $pattern = '/(<li[^>]*class="[^"]*menu-item[^"]*"[^>]*>)\s*<a[^>]*href="[^"]*#fanyi2-language-switcher[^"]*"[^>]*>([^<]*)<\/a>\s*(<\/li>)/s';
        $replacement = '${1}<a href="#">${2}</a><ul class="sub-menu">' . $submenu_items . '</ul></li>';
        $items = preg_replace($pattern, $replacement, $items);

        return $items;
    }
}

// 注册短代码
add_shortcode('fanyi2_switcher', array('Fanyi2_Frontend', 'shortcode_language_switcher'));

/**
 * 模板函数：在主题模板中渲染语言切换器
 * 用法: <?php fanyi2_language_switcher('dropdown'); ?>
 *
 * @param string $style dropdown|flags|minimal
 */
function fanyi2_language_switcher($style = 'dropdown') {
    Fanyi2_Frontend::output_language_switcher($style, 'inline');
}
