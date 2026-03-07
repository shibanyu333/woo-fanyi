<?php
/**
 * Plugin Name: Fanyi2 - AI 智能翻译
 * Plugin URI: https://github.com/fanyi2
 * Description: 类似TranslatePress的WordPress多语言翻译插件，支持前端可视化翻译、DeepSeek/千问AI翻译、浏览器语言自动切换、兼容woo-huilv汇率插件
 * Version: 2.0.0
 * Author: Fanyi2
 * Author URI: https://github.com/fanyi2
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fanyi2
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.6
 */

if (!defined('ABSPATH')) {
    exit;
}

// 插件常量
define('FANYI2_VERSION', '2.0.0');
define('FANYI2_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FANYI2_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FANYI2_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('FANYI2_DB_VERSION', '1.0.0');

/**
 * 主插件类
 */
final class Fanyi2 {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
        $this->declare_wc_compatibility();
    }

    /**
     * 声明 WooCommerce 功能兼容性 (HPOS / Custom Order Tables 等)
     */
    private function declare_wc_compatibility() {
        add_action('before_woocommerce_init', function() {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                // 声明兼容 HPOS (High-Performance Order Storage)
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                    'custom_order_tables', __FILE__, true
                );
                // 声明兼容 Cart & Checkout Blocks
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                    'cart_checkout_blocks', __FILE__, true
                );
            }
        });
    }

    /**
     * 加载依赖文件
     */
    private function load_dependencies() {
        // 核心类
        require_once FANYI2_PLUGIN_DIR . 'includes/class-fanyi2-database.php';
        require_once FANYI2_PLUGIN_DIR . 'includes/class-fanyi2-translator.php';
        require_once FANYI2_PLUGIN_DIR . 'includes/class-fanyi2-ai-engine.php';
        require_once FANYI2_PLUGIN_DIR . 'includes/class-fanyi2-ip-detector.php';
        require_once FANYI2_PLUGIN_DIR . 'includes/class-fanyi2-currency.php';
        require_once FANYI2_PLUGIN_DIR . 'includes/class-fanyi2-frontend.php';
        require_once FANYI2_PLUGIN_DIR . 'includes/class-fanyi2-ajax.php';
        require_once FANYI2_PLUGIN_DIR . 'includes/class-fanyi2-batch.php';

        // 后台管理
        if (is_admin()) {
            require_once FANYI2_PLUGIN_DIR . 'admin/class-fanyi2-admin.php';
        }
    }

    /**
     * 初始化钩子
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'on_plugins_loaded'));
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }

    /**
     * 插件激活
     */
    public function activate() {
        Fanyi2_Database::create_tables();
        $this->set_default_options();
        flush_rewrite_rules();
    }

    /**
     * 插件停用
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * 设置默认选项
     */
    private function set_default_options() {
        $defaults = array(
            'fanyi2_default_language'  => 'zh',
            'fanyi2_enabled_languages' => array('zh', 'hk', 'tw', 'en', 'ja', 'ko', 'fr', 'de', 'es', 'ru', 'ar'),
            'fanyi2_ai_engine'         => 'deepseek',
            'fanyi2_deepseek_api_key'  => '',
            'fanyi2_deepseek_model'    => 'deepseek-chat',
            'fanyi2_deepseek_api_url'  => 'https://api.deepseek.com/v1/chat/completions',
            'fanyi2_qwen_api_key'      => '',
            'fanyi2_qwen_model'        => 'qwen-turbo',
            'fanyi2_qwen_api_url'      => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
            'fanyi2_openai_api_key'    => '',
            'fanyi2_openai_model'      => 'gpt-4o-mini',
            'fanyi2_openai_api_url'    => 'https://api.openai.com/v1/chat/completions',
            'fanyi2_claude_api_key'    => '',
            'fanyi2_claude_model'      => 'claude-sonnet-4-20250514',
            'fanyi2_claude_api_url'    => 'https://api.anthropic.com/v1/messages',
            'fanyi2_google_api_key'    => '',
            'fanyi2_custom_api_key'    => '',
            'fanyi2_custom_api_url'    => '',
            'fanyi2_custom_model'      => '',
            'fanyi2_auto_detect_browser' => '1',
            'fanyi2_url_mode' => 'parameter', // parameter or subdirectory
            'fanyi2_batch_size' => 10,
            'fanyi2_switcher_position' => 'bottom-right', // bottom-right, bottom-left, top-right, top-left
            'fanyi2_switcher_style' => 'dropdown', // dropdown, flags, minimal
            'fanyi2_switcher_visible' => '1', // 1=show, 0=hide
        );

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                update_option($key, $value);
            }
        }
    }

    /**
     * 插件加载后
     */
    public function on_plugins_loaded() {
        load_plugin_textdomain('fanyi2', false, dirname(FANYI2_PLUGIN_BASENAME) . '/languages');

        // 初始化各模块
        Fanyi2_Ajax::init();
        Fanyi2_Frontend::init();
        Fanyi2_Currency::init();

        if (is_admin()) {
            Fanyi2_Admin::init();
        }
    }

    /**
     * WordPress init 钩子
     */
    public function init() {
        // 注册自定义重写规则用于语言URL
        $this->register_language_routes();

        // 子目录模式下拦截请求并解析语言
        $this->handle_subdirectory_request();

        // 检查是否需要刷新重写规则（由设置保存触发的延迟刷新）
        if (get_transient('fanyi2_flush_rewrite')) {
            delete_transient('fanyi2_flush_rewrite');
            flush_rewrite_rules();
        }
    }

    /**
     * 处理子目录模式的请求
     * 核心思路：从 REQUEST_URI 中移除语言前缀，让 WordPress 按照原始路径路由，
     * 同时通过 cookie 传递检测到的语言。
     */
    private function handle_subdirectory_request() {
        $url_mode = get_option('fanyi2_url_mode', 'parameter');
        if ($url_mode !== 'subdirectory') {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = parse_url($request_uri, PHP_URL_PATH);
        $home_path = parse_url(home_url(), PHP_URL_PATH);
        $home_path_clean = $home_path ? rtrim($home_path, '/') : '';
        $relative = $path;
        if ($home_path_clean) {
            $relative = substr($path, strlen($home_path_clean));
        }
        $relative = ltrim($relative, '/');

        $languages = get_option('fanyi2_enabled_languages', array());
        $default_lang = get_option('fanyi2_default_language', 'zh');

        // 检查路径的第一段是否是语言代码
        $segments = explode('/', $relative);
        if (!empty($segments[0]) && in_array($segments[0], $languages) && $segments[0] !== $default_lang) {
            $detected_lang = $segments[0];

            // 设置 cookie 以便语言检测使用
            if (!isset($_COOKIE['fanyi2_language']) || $_COOKIE['fanyi2_language'] !== $detected_lang) {
                setcookie('fanyi2_language', $detected_lang, time() + (365 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
            }
            $_COOKIE['fanyi2_language'] = $detected_lang;

            // 从 REQUEST_URI 中移除语言前缀，让 WordPress 正常路由
            array_shift($segments);
            $clean = implode('/', $segments);
            $new_uri = $home_path_clean . '/' . $clean;
            // 确保首页 URL 至少是 /
            if (empty(trim($new_uri, '/'))) {
                $new_uri = $home_path_clean ? $home_path_clean . '/' : '/';
            }

            // 保留查询字符串
            $qs = parse_url($request_uri, PHP_URL_QUERY);
            if ($qs) {
                $new_uri .= '?' . $qs;
            }

            $_SERVER['REQUEST_URI'] = $new_uri;

            // 修正 canonical redirect：让 WordPress 重定向时保留语言前缀
            add_filter('redirect_canonical', function($redirect_url) use ($detected_lang, $default_lang) {
                if ($detected_lang === $default_lang) {
                    return $redirect_url;
                }
                return Fanyi2_Frontend::get_language_url($detected_lang, $redirect_url);
            }, 10, 1);
        } else {
            // 无语言前缀 = 默认语言，必须重置 cookie
            // 避免上一次非默认语言页面留下的 cookie 导致语言"粘滞"
            if (isset($_COOKIE['fanyi2_language']) && $_COOKIE['fanyi2_language'] !== $default_lang) {
                setcookie('fanyi2_language', $default_lang, time() + (365 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
            }
            $_COOKIE['fanyi2_language'] = $default_lang;
        }
    }

    /**
     * 注册语言路由
     */
    private function register_language_routes() {
        $url_mode = get_option('fanyi2_url_mode', 'parameter');

        // 参数模式也需要注册 query var
        add_filter('query_vars', function($vars) {
            $vars[] = 'lang';
            $vars[] = 'fanyi2_lang';
            $vars[] = 'fanyi2_path';
            return $vars;
        });

        if ($url_mode === 'subdirectory') {
            $languages = get_option('fanyi2_enabled_languages', array());
            $default_lang = get_option('fanyi2_default_language', 'zh');
            foreach ($languages as $lang) {
                if ($lang !== $default_lang) {
                    add_rewrite_rule(
                        '^' . $lang . '/?$',
                        'index.php?fanyi2_lang=' . $lang,
                        'top'
                    );
                    add_rewrite_rule(
                        '^' . $lang . '/(.+)$',
                        'index.php?fanyi2_lang=' . $lang . '&fanyi2_path=$matches[1]',
                        'top'
                    );
                }
            }
            add_rewrite_tag('%fanyi2_lang%', '([a-z]{2})');
            add_rewrite_tag('%fanyi2_path%', '(.*)');

            // 子目录模式下重写链接
            add_action('template_redirect', function() {
                $lang = get_query_var('fanyi2_lang');
                if (!empty($lang)) {
                    // 设置cookie
                    if (!isset($_COOKIE['fanyi2_language']) || $_COOKIE['fanyi2_language'] !== $lang) {
                        setcookie('fanyi2_language', $lang, time() + (365 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
                        $_COOKIE['fanyi2_language'] = $lang;
                    }
                }
            }, 0);
        }
    }

    /**
     * 加载前端资源
     */
    public function enqueue_frontend_assets() {
        // 语言切换器样式
        wp_enqueue_style(
            'fanyi2-frontend',
            FANYI2_PLUGIN_URL . 'assets/css/fanyi2-frontend.css',
            array(),
            FANYI2_VERSION
        );

        // 语言切换器脚本
        wp_enqueue_script(
            'fanyi2-frontend',
            FANYI2_PLUGIN_URL . 'assets/js/fanyi2-frontend.js',
            array('jquery'),
            FANYI2_VERSION,
            true
        );

        $current_lang = Fanyi2_Frontend::get_current_language();
        $is_rtl = Fanyi2_IP_Detector::is_rtl($current_lang);
        wp_localize_script('fanyi2-frontend', 'fanyi2_vars', array(
            'ajax_url'         => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('fanyi2_nonce'),
            'current_language' => $current_lang,
            'default_language' => get_option('fanyi2_default_language', 'zh'),
            'enabled_languages'=> get_option('fanyi2_enabled_languages', array()),
            'url_mode'         => get_option('fanyi2_url_mode', 'parameter'),
            'home_url'         => home_url('/'),
            'is_editor'        => current_user_can('manage_options') && isset($_GET['fanyi2_editor']) ? '1' : '0',
            'is_rtl'           => $is_rtl ? '1' : '0',
            'language_names'   => Fanyi2_Frontend::get_language_names(),
            'language_flags'   => Fanyi2_Frontend::get_language_flags(),
        ));

        // 可视化翻译编辑器（管理员且开启编辑模式时）
        if (current_user_can('manage_options') && isset($_GET['fanyi2_editor'])) {
            wp_enqueue_style(
                'fanyi2-editor',
                FANYI2_PLUGIN_URL . 'assets/css/fanyi2-editor.css',
                array(),
                FANYI2_VERSION
            );
            wp_enqueue_script(
                'fanyi2-editor',
                FANYI2_PLUGIN_URL . 'assets/js/fanyi2-editor.js',
                array('jquery', 'fanyi2-frontend'),
                FANYI2_VERSION,
                true
            );
        }
    }

    /**
     * 获取当前语言
     */
    public static function get_current_language() {
        return Fanyi2_Frontend::get_current_language();
    }

    /**
     * 获取支持的语言列表
     */
    public static function get_supported_languages() {
        return array(
            'zh' => '中文',
            'hk' => '繁體中文（香港）',
            'tw' => '繁體中文（台灣）',
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
}

/**
 * 获取插件实例
 */
function fanyi2() {
    return Fanyi2::instance();
}

// 启动插件
fanyi2();
