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
    }

    /**
     * 检测并设置当前语言
     */
    public static function detect_and_set_language() {
        // 优先级: URL参数 > Cookie > IP检测 > 默认语言
        $language = null;

        // 1. URL参数/路径
        if (isset($_GET['lang'])) {
            $language = sanitize_text_field($_GET['lang']);
        } elseif (get_query_var('fanyi2_lang')) {
            $language = get_query_var('fanyi2_lang');
        }

        // 2. Cookie
        if (empty($language) && isset($_COOKIE['fanyi2_language'])) {
            $language = sanitize_text_field($_COOKIE['fanyi2_language']);
        }

        // 3. 浏览器语言检测
        if (empty($language)) {
            $language = Fanyi2_IP_Detector::detect_language();
        }

        // 4. 默认语言
        if (empty($language)) {
            $language = get_option('fanyi2_default_language', 'zh');
        }

        // 验证语言是否启用
        $enabled = get_option('fanyi2_enabled_languages', array('zh', 'en'));
        if (!in_array($language, $enabled)) {
            $language = get_option('fanyi2_default_language', 'zh');
        }

        self::$current_language = $language;

        // 设置Cookie
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
                    <?php foreach ($enabled_languages as $lang): ?>
                        <a href="#" class="fanyi2-flag-option <?php echo $lang === $current_language ? 'active' : ''; ?>"
                           data-lang="<?php echo esc_attr($lang); ?>"
                           title="<?php echo esc_html($language_names[$lang] ?? $lang); ?>">
                            <span class="fanyi2-flag"><?php echo esc_html($language_flags[$lang] ?? '🌐'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($style === 'minimal'): ?>
                <select class="fanyi2-switcher-select" onchange="if(this.value)location.href=this.value">
                    <?php foreach ($enabled_languages as $lang):
                        $url = add_query_arg('lang', $lang, remove_query_arg('lang'));
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
                    <?php foreach ($enabled_languages as $lang): ?>
                        <a href="#" class="fanyi2-lang-option <?php echo $lang === $current_language ? 'active' : ''; ?>"
                           data-lang="<?php echo esc_attr($lang); ?>">
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
        $current_url = home_url(add_query_arg(array()));

        foreach ($enabled_languages as $lang) {
            $lang_url = add_query_arg('lang', $lang, remove_query_arg('lang', $current_url));
            $hreflang = ($lang === 'zh') ? 'zh-Hans' : $lang;
            echo '<link rel="alternate" hreflang="' . esc_attr($hreflang) . '" href="' . esc_url($lang_url) . '" />' . "\n";
        }
        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url(remove_query_arg('lang', $current_url)) . '" />' . "\n";
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
