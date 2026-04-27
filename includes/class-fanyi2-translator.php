<?php
/**
 * 翻译器类 - 负责内容输出时替换翻译
 * 
 * 覆盖范围：
 * 1. 页面整体 HTML 输出缓冲替换
 * 2. WooCommerce gettext 翻译（购物车、结账、账户等硬编码文本）
 * 3. WooCommerce 产品名称、属性、端点等
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_Translator {

    private static $current_language = null;
    private static $translations_cache = array();
    private static $gettext_cache = array();
    private static $persistent_cache_loaded = false;
    private static $persistent_cache_group = 'fanyi2_trans';
    private static $runtime_registered_hashes = array();
    private static $wc_hooks_registered = false;

    /**
     * 初始化（保留供外部调用；输出缓冲已由 Fanyi2_Frontend::start_translation_buffer 启动）
     */
    public static function init() {
        // 注意：当前版本中输出缓冲由 Fanyi2_Frontend::start_translation_buffer() 注册，
        // 此方法不再需要额外注册。保留以兼容可能的外部代码调用。
    }

    /**
     * 注册 WooCommerce 专用翻译钩子（在 Fanyi2_Frontend::init 中调用）
     */
    public static function register_wc_hooks() {
        if (self::$wc_hooks_registered) {
            return;
        }
        self::$wc_hooks_registered = true;

        $should_translate = self::should_translate_current_request();

        // --- gettext 级别拦截：覆盖 WooCommerce 核心翻译 ---
        add_filter('gettext', array(__CLASS__, 'filter_gettext'), 10, 3);
        add_filter('gettext_with_context', array(__CLASS__, 'filter_gettext_with_context'), 10, 4);
        add_filter('ngettext', array(__CLASS__, 'filter_ngettext'), 10, 5);
        add_filter('ngettext_with_context', array(__CLASS__, 'filter_ngettext_with_context'), 10, 6);

        // --- Woo Blocks 前端 JS 文案翻译（购物车/结账区块） ---
        add_filter('load_script_translations', array(__CLASS__, 'filter_script_translations'), 10, 4);

        // --- Woo Blocks / Store API 响应兜底翻译（购物车/结账区块） ---
        add_filter('rest_pre_echo_response', array(__CLASS__, 'filter_store_api_response'), 10, 3);

        if (!$should_translate) {
            return;
        }

        if (!class_exists('WooCommerce')) {
            return;
        }

        // --- WooCommerce 产品文本（the_title 仅前端，避免后台列表也被翻译） ---
        if (!is_admin()) {
            add_filter('the_title', array(__CLASS__, 'translate_text_filter'), 20, 1);
        }
        add_filter('woocommerce_product_get_name', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_product_get_short_description', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_product_get_description', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_product_get_purchase_note', array(__CLASS__, 'translate_text_filter'), 20, 1);

        // --- WooCommerce 属性和分类法 ---
        add_filter('woocommerce_attribute_label', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_variation_option_name', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('get_term', array(__CLASS__, 'translate_term'), 20, 2);

        // --- 购物车 ---
        add_filter('woocommerce_cart_item_name', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_cart_item_quantity', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_cart_shipping_method_full_label', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_cart_totals_coupon_label', array(__CLASS__, 'translate_text_filter'), 20, 1);

        // --- 结账 ---
        add_filter('woocommerce_gateway_title', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_gateway_description', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_order_button_text', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_checkout_fields', array(__CLASS__, 'translate_checkout_fields'), 20, 1);

        // --- 账户页面 ---
        add_filter('woocommerce_account_menu_items', array(__CLASS__, 'translate_account_menu_items'), 20, 1);
        add_filter('woocommerce_endpoint_order-pay_title', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_endpoint_order-received_title', array(__CLASS__, 'translate_text_filter'), 20, 1);

        // --- 小部件和面包屑 ---
        add_filter('woocommerce_product_categories_widget_args', array(__CLASS__, 'translate_widget_args'), 20, 1);
        add_filter('woocommerce_get_breadcrumb', array(__CLASS__, 'translate_breadcrumb'), 20, 1);

        // --- 邮件 ---
        add_filter('woocommerce_email_subject_new_order', array(__CLASS__, 'translate_text_filter'), 20, 1);
        add_filter('woocommerce_email_heading_new_order', array(__CLASS__, 'translate_text_filter'), 20, 1);

        // --- Tab 标签 ---
        add_filter('woocommerce_product_tabs', array(__CLASS__, 'translate_product_tabs'), 20, 1);
    }

    /**
     * 开始输出缓冲
     */
    public static function start_output_buffer() {
        ob_start(array(__CLASS__, 'process_output'));
    }

    /**
     * 处理输出内容，替换翻译
     */
    public static function process_output($html) {
        if (empty($html)) {
            return $html;
        }

        $current_lang = Fanyi2_Frontend::get_current_language();
        $default_lang = get_option('fanyi2_default_language', 'zh');
        $target_lang = $current_lang;

        // 不处理AJAX请求和JSON响应
        if (wp_doing_ajax() || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)) {
            return $html;
        }

        // 运行期抓取默认关闭，避免匿名前台访问造成写库放大和性能抖动。
        if (self::should_capture_runtime_strings()) {
            self::capture_visible_strings_from_html($html);
        }

        if ($current_lang === $default_lang) {
            return $html;
        }

        if (empty($target_lang)) {
            return $html;
        }

        // 1. 翻译文本内容
        $html = self::translate_html($html, $target_lang);

        // 2. 重写内部链接（加上语言标识）
        $html = self::rewrite_internal_urls($html, $target_lang);

        return $html;
    }

    /**
     * 是否在当前请求自动抓取前台可见文本。
     */
    private static function should_capture_runtime_strings() {
        $enabled = get_option('fanyi2_runtime_capture_enabled', '0') === '1';
        $enabled = apply_filters('fanyi2_capture_runtime_strings', $enabled);
        if (!$enabled) {
            return false;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        return true;
    }

    // ====== gettext 拦截 (覆盖 WooCommerce / WordPress 核心文本) ======

    /**
     * 拦截 gettext（__() / _e() 等）
     */
    public static function filter_gettext($translated, $text, $domain) {
        if (!self::is_target_domain($domain)) {
            return $translated;
        }

        self::capture_runtime_string($translated, $domain);
        return self::maybe_translate($translated);
    }

    /**
     * 拦截带上下文的 gettext（_x()）
     */
    public static function filter_gettext_with_context($translated, $text, $context, $domain) {
        if (!self::is_target_domain($domain)) {
            return $translated;
        }

        self::capture_runtime_string($translated, $domain, $context);
        return self::maybe_translate($translated);
    }

    /**
     * 拦截复数形式 gettext（_n()）
     */
    public static function filter_ngettext($translated, $single, $plural, $number, $domain) {
        if (!self::is_target_domain($domain)) {
            return $translated;
        }

        self::capture_runtime_string($translated, $domain);
        return self::maybe_translate($translated);
    }

    /**
     * 拦截带上下文复数形式 gettext（_nx()）
     */
    public static function filter_ngettext_with_context($translated, $single, $plural, $number, $context, $domain) {
        if (!self::is_target_domain($domain)) {
            return $translated;
        }

        self::capture_runtime_string($translated, $domain, $context);
        return self::maybe_translate($translated);
    }

    /**
     * 目标翻译域
     */
    private static function is_target_domain($domain) {
        $target_domains = array('woocommerce', 'woo-gutenberg-products-block', 'default', '');

        if (in_array($domain, $target_domains, true)) {
            return true;
        }

        return (strpos((string) $domain, 'woocommerce') !== false);
    }

    /**
     * 是否需要在当前请求执行翻译（非默认语言）
     */
    private static function should_translate_current_request() {
        $current_lang = Fanyi2_Frontend::get_current_language();
        $default_lang = get_option('fanyi2_default_language', 'zh');
        if (empty($current_lang)) {
            return false;
        }

        return ($current_lang !== $default_lang);
    }

    /**
     * 是否为 WooCommerce 相关脚本句柄
     */
    private static function is_woocommerce_script_handle($handle) {
        $handle = (string) $handle;
        if ($handle === '') {
            return false;
        }

        return (strpos($handle, 'wc-') === 0
            || strpos($handle, 'woocommerce') !== false
            || strpos($handle, 'woo-') === 0);
    }

    /**
     * 是否处于 WooCommerce 场景（用于 default domain 的抓取限流）
     */
    private static function is_woocommerce_context() {
        if (is_admin()) {
            return false;
        }

        if (!class_exists('WooCommerce')) {
            return false;
        }

        if (function_exists('is_woocommerce') && is_woocommerce()) {
            return true;
        }

        if (function_exists('is_cart') && is_cart()) {
            return true;
        }

        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }

        if (function_exists('is_account_page') && is_account_page()) {
            return true;
        }

        return false;
    }

    /**
     * 过滤 Woo Store API 响应，翻译其中文本字段
     */
    public static function filter_store_api_response($result, $server, $request) {
        if (!is_array($result) && !is_object($result)) {
            return $result;
        }

        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return $result;
        }

        $route = (string) $request->get_route();
        if (strpos($route, '/wc/store/') !== 0) {
            return $result;
        }

        return self::translate_store_api_data($result);
    }

    /**
     * 过滤 JS i18n 翻译 JSON，覆盖 Woo Blocks（Cart / Checkout）前端文案
     */
    public static function filter_script_translations($translations, $file, $handle, $domain) {
        if (empty($translations) || !is_string($translations)) {
            return $translations;
        }

        $is_woo_domain = self::is_target_domain($domain);
        $is_woo_handle = self::is_woocommerce_script_handle($handle);

        if (!$is_woo_domain && !$is_woo_handle) {
            return $translations;
        }

        $decoded = json_decode($translations, true);
        if (!is_array($decoded) || empty($decoded['locale_data']) || !is_array($decoded['locale_data'])) {
            return $translations;
        }

        $should_translate = self::should_translate_current_request();
        $changed = false;
        $capture_domain = $is_woo_domain ? (string) $domain : 'woocommerce';

        foreach ($decoded['locale_data'] as $locale_domain => $messages) {
            if (!is_array($messages)) {
                continue;
            }

            foreach ($messages as $source => $forms) {
                if ($source === '' || !is_array($forms)) {
                    continue;
                }

                self::capture_runtime_string($source, $capture_domain, 'js_i18n');

                foreach ($forms as $index => $translated_text) {
                    if (!is_string($translated_text) || $translated_text === '') {
                        continue;
                    }

                    self::capture_runtime_string($translated_text, $capture_domain, 'js_i18n');

                    if (!$should_translate) {
                        continue;
                    }

                    $new_text = self::maybe_translate($translated_text);
                    if ($new_text === $translated_text) {
                        // fallback：部分语言包会给出英语文案，尝试用原 msgid 查库
                        $source_text = self::maybe_translate($source);
                        if ($source_text !== $source) {
                            $new_text = $source_text;
                        }
                    }

                    if ($new_text !== $translated_text) {
                        $decoded['locale_data'][$locale_domain][$source][$index] = $new_text;
                        $changed = true;
                    }
                }
            }
        }

        if (!$changed) {
            return $translations;
        }

        $encoded = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : $translations;
    }

    /**
     * 递归翻译 Store API 数据
     */
    private static function translate_store_api_data($data, $key_name = '', $parent_key = '') {
        if (is_string($data)) {
            if (!self::is_store_api_translatable_key($key_name, $parent_key)) {
                return $data;
            }

            $text = trim($data);

            if ($text === '' || mb_strlen($text) < 2 || mb_strlen($text) > 200) {
                return $data;
            }

            // 跳过URL/邮箱/纯数字金额编码
            if (filter_var($text, FILTER_VALIDATE_URL)
                || filter_var($text, FILTER_VALIDATE_EMAIL)
                || preg_match('/^[\d\s\.,:%#\-+\/]+$/u', $text)) {
                return $data;
            }

            if (!preg_match('/[\p{L}]/u', $text)) {
                return $data;
            }

            return self::maybe_translate($text);
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::translate_store_api_data($value, (string) $key, (string) $key_name);
            }
            return $data;
        }

        if (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->{$key} = self::translate_store_api_data($value, (string) $key, (string) $key_name);
            }
            return $data;
        }

        return $data;
    }

    /**
     * Store API 中只翻译明确展示给用户的字段，避免破坏协议值/枚举/货币代码。
     */
    private static function is_store_api_translatable_key($key_name, $parent_key = '') {
        $key = strtolower((string) $key_name);
        if ($key !== '' && ctype_digit($key)) {
            $key = strtolower((string) $parent_key);
        }

        if ($key === '') {
            return false;
        }

        $allowed = array(
            'name',
            'title',
            'label',
            'description',
            'short_description',
            'summary',
            'text',
            'message',
            'messages',
            'notice',
            'notices',
            'content',
            'heading',
            'placeholder',
            'button_text',
        );

        return in_array($key, $allowed, true);
    }

    /**
     * 运行期自动抓取 gettext 字符串，便于后台补翻译
     */
    private static function capture_runtime_string($text, $domain = 'woocommerce', $context = '') {
        if (empty($text) || !is_string($text)) {
            return;
        }

        $text = trim($text);
        if (mb_strlen($text) < 2 || mb_strlen($text) > 300) {
            return;
        }

        if (!preg_match('/[\p{L}]/u', $text)) {
            return;
        }

        if (self::should_skip_text_candidate($text)) {
            return;
        }

        $domain = (string) $domain;
        if ($domain === 'default' || $domain === '') {
            if (!self::is_woocommerce_context()) {
                return;
            }
            $domain = 'woocommerce';
        }

        if ($domain === 'woo-gutenberg-products-block') {
            $domain = 'woocommerce';
        }

        $hash = md5($domain . '|' . $context . '|' . $text);
        if (isset(self::$runtime_registered_hashes[$hash])) {
            return;
        }
        self::$runtime_registered_hashes[$hash] = 1;

        Fanyi2_Database::get_or_create_string($text, array(
            'domain'       => $domain,
            'context'      => (string) $context,
            'element_type' => 'gettext',
            'page_url'     => '',
        ));
    }

    /**
     * 预加载当前语言所有翻译到内存 + 持久缓存
     * 采用整体预加载策略：一次查询取回所有翻译，避免 N+1 查询
     */
    private static function preload_translations() {
        if (self::$persistent_cache_loaded) {
            return;
        }
        self::$persistent_cache_loaded = true;

        $current_lang = Fanyi2_Frontend::get_current_language();
        $cache_lang = Fanyi2_Database::resolve_translation_language($current_lang);
        $equivalent_languages = Fanyi2_Database::get_equivalent_translation_languages($cache_lang);
        $cache_key = 'fanyi2_all_' . $cache_lang;

        // 尝试从 wp_cache（object cache）读取
        $cached = wp_cache_get($cache_key, self::$persistent_cache_group);
        if (is_array($cached)) {
            self::$gettext_cache = $cached;
            return;
        }

        // 尝试从 transient 读取（无 object cache 时的后备）
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            self::$gettext_cache = $cached;
            wp_cache_set($cache_key, $cached, self::$persistent_cache_group, 3600);
            return;
        }

        // 从数据库全量加载当前语言的翻译
        global $wpdb;
        $table_strings = $wpdb->prefix . Fanyi2_Database::TABLE_STRINGS;
        $table_trans   = $wpdb->prefix . Fanyi2_Database::TABLE_TRANSLATIONS;
        $lang_placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));
        $params = array_merge($equivalent_languages, array($cache_lang));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.string_hash, t.translated_string
             FROM {$table_trans} t
             INNER JOIN {$table_strings} s ON t.string_id = s.id
             WHERE t.language IN ($lang_placeholders) AND t.status = 'published' AND s.status = 'active'
             ORDER BY CASE WHEN t.language = %s THEN 0 ELSE 1 END, t.updated_at DESC",
            $params
        ));

        $map = array();
        foreach ($results as $row) {
            if (!isset($map[$row->string_hash])) {
                $map[$row->string_hash] = $row->translated_string;
            }
        }

        self::$gettext_cache = $map;

        // 写入持久缓存（5分钟）
        wp_cache_set($cache_key, $map, self::$persistent_cache_group, 300);
        set_transient($cache_key, $map, 300);
    }

    /**
     * 自动抓取可见文本并入库，覆盖主题/模板硬编码文案
     */
    private static function capture_visible_strings_from_html($html) {
        if (is_admin()) {
            return;
        }

        $texts = self::extract_text_from_html($html);
        if (empty($texts)) {
            return;
        }

        $page_url = home_url(add_query_arg(array()));
        $domain = 'general';
        if (function_exists('is_front_page') && (is_front_page() || is_home())) {
            $domain = 'homepage';
        } elseif ((function_exists('is_singular') && is_singular('product')) || strpos((string) $page_url, '/product/') !== false) {
            $domain = 'products';
        } elseif (class_exists('WooCommerce') && (
            (function_exists('is_woocommerce') && is_woocommerce())
            || (function_exists('is_cart') && is_cart())
            || (function_exists('is_checkout') && is_checkout())
            || (function_exists('is_account_page') && is_account_page())
        )) {
            $domain = 'woocommerce';
        }

        $captured = 0;
        foreach ($texts as $text) {
            $text = trim((string) $text);
            if ($text === '' || mb_strlen($text) < 2 || mb_strlen($text) > 600) {
                continue;
            }
            if (!preg_match('/[\p{L}]/u', $text)) {
                continue;
            }
            if (filter_var($text, FILTER_VALIDATE_URL) || filter_var($text, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (self::should_skip_text_candidate($text)) {
                continue;
            }

            Fanyi2_Database::get_or_create_string($text, array(
                'domain'       => $domain,
                'element_type' => 'runtime_html',
                'page_url'     => $page_url,
            ));

            $captured++;
            if ($captured >= 800) {
                break;
            }
        }
    }

    /**
     * 清除翻译缓存（翻译保存/删除后调用）
     */
    public static function clear_translation_cache($lang = '') {
        if ($lang) {
            $canonical_lang = Fanyi2_Database::resolve_translation_language($lang);
            $cache_keys = array('fanyi2_all_' . $canonical_lang);
            // 兼容历史缓存键
            if ($canonical_lang === 'tw') {
                $cache_keys[] = 'fanyi2_all_hk';
            }
            foreach (array_unique($cache_keys) as $cache_key) {
                wp_cache_delete($cache_key, self::$persistent_cache_group);
                delete_transient($cache_key);
            }
        } else {
            // 清除所有语言
            $languages = get_option('fanyi2_enabled_languages', array());
            $cache_keys = array();
            foreach ($languages as $l) {
                $canonical_lang = Fanyi2_Database::resolve_translation_language($l);
                $cache_keys[] = 'fanyi2_all_' . $canonical_lang;
                if ($canonical_lang === 'tw') {
                    $cache_keys[] = 'fanyi2_all_hk';
                }
            }
            foreach (array_unique($cache_keys) as $cache_key) {
                wp_cache_delete($cache_key, self::$persistent_cache_group);
                delete_transient($cache_key);
            }
        }
        self::$gettext_cache = array();
        self::$persistent_cache_loaded = false;
    }

    /**
     * 查询数据库翻译，命中则返回翻译文本
     * 优化：先预加载所有翻译到内存，然后通过哈希查找，O(1) 复杂度
     */
    private static function maybe_translate($text) {
        if (!is_string($text) || empty($text) || mb_strlen($text) < 2) {
            return $text;
        }

        if (!self::should_translate_current_request()) {
            return $text;
        }

        // 确保翻译已预加载
        self::preload_translations();

        // 通过哈希快速查找
        $hash = md5($text);
        if (isset(self::$gettext_cache[$hash])) {
            $candidate = self::$gettext_cache[$hash];
            if (!self::should_reject_translation($text, $candidate)) {
                return $candidate;
            }
        }

        // 兼容 HTML 实体/空白差异（常见于块 JSON 文案）
        $normalized = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($normalized !== '' && $normalized !== $text) {
            $hash = md5($normalized);
            if (isset(self::$gettext_cache[$hash])) {
                $candidate = self::$gettext_cache[$hash];
                if (!self::should_reject_translation($text, $candidate)) {
                    return $candidate;
                }
            }
        }

        // 未命中：没有这条翻译
        return $text;
    }

    // ====== WooCommerce 属性级翻译 ======

    /**
     * 通用文本 filter
     */
    public static function translate_text_filter($text) {
        return self::maybe_translate($text);
    }

    /**
     * 翻译分类法术语
     */
    public static function translate_term($term, $taxonomy) {
        if (is_object($term) && isset($term->name)) {
            $term->name = self::maybe_translate($term->name);
        }
        return $term;
    }

    /**
     * 翻译结账字段标签和占位符
     */
    public static function translate_checkout_fields($fields) {
        foreach ($fields as $group_key => $group) {
            foreach ($group as $field_key => $field) {
                if (!empty($field['label'])) {
                    $fields[$group_key][$field_key]['label'] = self::maybe_translate($field['label']);
                }
                if (!empty($field['placeholder'])) {
                    $fields[$group_key][$field_key]['placeholder'] = self::maybe_translate($field['placeholder']);
                }
            }
        }
        return $fields;
    }

    /**
     * 翻译账户菜单项
     */
    public static function translate_account_menu_items($items) {
        foreach ($items as $key => $label) {
            $items[$key] = self::maybe_translate($label);
        }
        return $items;
    }

    /**
     * 翻译面包屑
     */
    public static function translate_breadcrumb($crumbs) {
        foreach ($crumbs as &$crumb) {
            if (!empty($crumb[0])) {
                $crumb[0] = self::maybe_translate($crumb[0]);
            }
        }
        return $crumbs;
    }

    /**
     * 翻译产品标签页
     */
    public static function translate_product_tabs($tabs) {
        foreach ($tabs as $key => &$tab) {
            if (!empty($tab['title'])) {
                $tab['title'] = self::maybe_translate($tab['title']);
            }
        }
        return $tabs;
    }

    /**
     * 翻译小部件参数中的标题
     */
    public static function translate_widget_args($args) {
        if (!empty($args['title'])) {
            $args['title'] = self::maybe_translate($args['title']);
        }
        return $args;
    }

    // ====== URL 重写（输出缓冲阶段，将内部链接加上语言标识）======

    /**
     * 重写 HTML 中的内部链接
     */
    private static function rewrite_internal_urls($html, $target_lang) {
        $url_mode    = get_option('fanyi2_url_mode', 'parameter');
        $default_lang = get_option('fanyi2_default_language', 'zh');
        $enabled     = get_option('fanyi2_enabled_languages', array());

        $parsed_home = parse_url(home_url());
        $home_host   = isset($parsed_home['host'])   ? $parsed_home['host']   : '';
        $home_path   = isset($parsed_home['path'])    ? rtrim($parsed_home['path'], '/') : '';
        $home_scheme = isset($parsed_home['scheme'])  ? $parsed_home['scheme'] : 'https';

        // 保护语言切换器链接：含有 data-fanyi2-lang 属性的 <a> 标签不应被重写
        $protected_links = array();
        $html = preg_replace_callback('/<a\b[^>]*data-fanyi2-lang[^>]*>.*?<\/a>/is', function($m) use (&$protected_links) {
            $key = '<!--FANYI2_PROT_' . count($protected_links) . '-->';
            $protected_links[$key] = $m[0];
            return $key;
        }, $html);

        // 同样保护 minimal 样式切换器的 <select>
        $html = preg_replace_callback('/<select\b[^>]*fanyi2-switcher-select[^>]*>.*?<\/select>/is', function($m) use (&$protected_links) {
            $key = '<!--FANYI2_PROT_' . count($protected_links) . '-->';
            $protected_links[$key] = $m[0];
            return $key;
        }, $html);

        // 保护 hreflang 标签：各语言的 alternate 链接不应被重写为当前语言
        $html = preg_replace_callback('/<link\b[^>]*hreflang=["\'][^"\']*["\'][^>]*\/?>/is', function($m) use (&$protected_links) {
            $key = '<!--FANYI2_PROT_' . count($protected_links) . '-->';
            $protected_links[$key] = $m[0];
            return $key;
        }, $html);

        // 匹配 href="..." 和 action="..."（不匹配 src / srcset 等资源属性）
        $html = preg_replace_callback(
            '/(href|action)\s*=\s*(["\'])([^"\']*)\2/i',
            function ($m) use ($url_mode, $target_lang, $default_lang, $enabled, $home_host, $home_path) {
                $attr = $m[1];
                $quote = $m[2];
                $url   = $m[3];

                // 跳过空值、锚点、javascript:、mailto:、tel:、data:
                if (empty($url) || $url[0] === '#'
                    || strncasecmp($url, 'javascript:', 11) === 0
                    || strncasecmp($url, 'mailto:', 7) === 0
                    || strncasecmp($url, 'tel:', 4) === 0
                    || strncasecmp($url, 'data:', 5) === 0) {
                    return $m[0];
                }

                // 跳过 wp-admin、wp-login、admin-ajax、wp-json、wp-content (资源文件)
                if (strpos($url, '/wp-admin') !== false
                    || strpos($url, '/wp-login') !== false
                    || strpos($url, 'admin-ajax.php') !== false
                    || strpos($url, '/wp-json/') !== false
                    || strpos($url, '/wp-content/') !== false
                    || strpos($url, '/wp-includes/') !== false) {
                    return $m[0];
                }

                // 判断是否为内部URL
                $parsed = parse_url($url);
                $url_host = isset($parsed['host']) ? $parsed['host'] : '';
                if (!empty($url_host) && $url_host !== $home_host) {
                    return $m[0]; // 外部链接
                }

                // 已经包含 fanyi2 语言切换占位符的跳过
                if (strpos($url, '#fanyi2-') !== false) {
                    return $m[0];
                }

                $new_url = self::add_language_to_url($url, $target_lang, $url_mode, $default_lang, $enabled, $home_path);
                return $attr . '=' . $quote . $new_url . $quote;
            },
            $html
        );

        // 恢复被保护的语言切换器链接
        if (!empty($protected_links)) {
            $html = str_replace(array_keys($protected_links), array_values($protected_links), $html);
        }

        return $html;
    }

    /**
     * 给单个 URL 加上语言标识
     */
    private static function add_language_to_url($url, $lang, $url_mode, $default_lang, $enabled, $home_path) {
        $parsed = parse_url($url);

        if ($url_mode === 'subdirectory') {
            $path = isset($parsed['path']) ? $parsed['path'] : '/';

            // 获取相对于 home_path 的路径
            $relative = $path;
            if ($home_path && strpos($path, $home_path) === 0) {
                $relative = substr($path, strlen($home_path));
            }
            $relative = ltrim($relative, '/');

            // 移除已有的语言前缀
            $segments = explode('/', $relative);
            if (!empty($segments[0]) && in_array($segments[0], $enabled)) {
                array_shift($segments);
            }
            $clean_path = implode('/', $segments);

            // 构建新路径
            if ($lang === $default_lang) {
                $new_path = $home_path . '/' . $clean_path;
            } else {
                $new_path = $home_path . '/' . $lang . ($clean_path ? '/' . $clean_path : '/');
            }
            $new_path = rtrim($new_path, '/');
            if (empty($new_path)) {
                $new_path = '/';
            } else {
                $new_path .= '/';
            }

            // 重建 URL
            $new_url = '';
            if (isset($parsed['scheme']) && isset($parsed['host'])) {
                $new_url = $parsed['scheme'] . '://' . $parsed['host'];
                if (isset($parsed['port'])) {
                    $new_url .= ':' . $parsed['port'];
                }
            } elseif (isset($parsed['host'])) {
                $new_url = '//' . $parsed['host'];
            }
            $new_url .= $new_path;

            // 查询参数（去掉 lang）
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $params);
                unset($params['lang']);
                if (!empty($params)) {
                    $new_url .= '?' . http_build_query($params);
                }
            }
            if (isset($parsed['fragment'])) {
                $new_url .= '#' . $parsed['fragment'];
            }

            return $new_url;

        } else {
            // 参数模式
            if ($lang === $default_lang) {
                // 移除 lang 参数
                if (isset($parsed['query'])) {
                    parse_str($parsed['query'], $params);
                    unset($params['lang']);
                    $base = strtok($url, '?');
                    $base = strtok($base, '#');
                    $new_url = $base;
                    if (!empty($params)) {
                        $new_url .= '?' . http_build_query($params);
                    }
                    if (isset($parsed['fragment'])) {
                        $new_url .= '#' . $parsed['fragment'];
                    }
                    return $new_url;
                }
                return $url;
            }

            // 添加/替换 lang 参数
            $params = array();
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $params);
            }
            $params['lang'] = $lang;

            $base = strtok($url, '?');
            $base = strtok($base, '#');

            $new_url = $base . '?' . http_build_query($params);
            if (isset($parsed['fragment'])) {
                $new_url .= '#' . $parsed['fragment'];
            }

            return $new_url;
        }
    }

    // ====== HTML 输出缓冲翻译 ======

    /**
     * 翻译HTML内容
     * 优化：使用预加载缓存 + 单次扫描替换，防止链式替换
     * 增强：空白归一化回退 + 块级元素内联标签处理
     */
    public static function translate_html($html, $target_language) {
        // 确保翻译已预加载
        self::preload_translations();

        // 先保护 script/style 等非可翻译代码块，防止污染匹配与替换
        $protected_blocks = array();
        $working_html = self::mask_non_translatable_blocks($html, $protected_blocks);

        // 阶段1：翻译含内联标签的块级元素（如 <p>这是<strong>重要</strong>文字</p>）
        $working_html = self::translate_block_elements($working_html);

        // 提取所有文本内容
        $text_nodes = self::extract_text_from_html($working_html);

        if (empty($text_nodes)) {
            return self::restore_masked_blocks($working_html, $protected_blocks);
        }

        // 从预加载缓存中收集需要替换的映射
        $replacements = array();
        $missed_texts = array();
        foreach ($text_nodes as $text) {
            $hash = md5($text);
            if (isset(self::$gettext_cache[$hash])) {
                $translated = self::$gettext_cache[$hash];
                if ($translated !== $text && !self::should_reject_translation($text, $translated)) {
                    $replacements[$text] = $translated;
                }
                continue;
            }

            // 空白归一化 + HTML实体解码 回退查找
            $normalized = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($normalized !== '' && $normalized !== $text) {
                $norm_hash = md5($normalized);
                if (isset(self::$gettext_cache[$norm_hash])) {
                    $translated = self::$gettext_cache[$norm_hash];
                    if ($translated !== $text && !self::should_reject_translation($text, $translated)) {
                        $replacements[$text] = $translated;
                    }
                    continue;
                }
            }

            $missed_texts[] = $text;
        }

        // 缓存没命中的部分，补一次批量数据库查询
        if (!empty($missed_texts)) {
            $db_trans = Fanyi2_Database::get_translations_batch($missed_texts, $target_language);
            foreach ($db_trans as $original => $translated) {
                if (!empty($translated) && $original !== $translated && !self::should_reject_translation($original, $translated)) {
                    $replacements[$original] = $translated;
                }
            }

            // 对仍未命中的文本尝试归一化后再查数据库
            $still_missed = array_diff($missed_texts, array_keys($replacements));
            if (!empty($still_missed)) {
                $normalized_missed = array();
                $norm_to_original = array();
                foreach ($still_missed as $text) {
                    $normalized = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    if ($normalized !== '' && $normalized !== $text && !isset($replacements[$text])) {
                        $normalized_missed[] = $normalized;
                        $norm_to_original[$normalized] = $text;
                    }
                }
                if (!empty($normalized_missed)) {
                    $norm_trans = Fanyi2_Database::get_translations_batch($normalized_missed, $target_language);
                    foreach ($norm_trans as $norm_original => $translated) {
                        if (!empty($translated) && isset($norm_to_original[$norm_original])) {
                            $original_text = $norm_to_original[$norm_original];
                            if ($translated !== $original_text && !self::should_reject_translation($original_text, $translated)) {
                                $replacements[$original_text] = $translated;
                            }
                        }
                    }
                }
            }
        }

        if (empty($replacements)) {
            return self::restore_masked_blocks($working_html, $protected_blocks);
        }

        // 按原文长度降序排列，防止短文本先匹配导致长文本断裂
        uksort($replacements, function($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        $working_html = self::safe_replace_batch($working_html, $replacements);

        return self::restore_masked_blocks($working_html, $protected_blocks);
    }

    /**
     * 翻译含内联标签的块级元素
     * 处理如 <p>这是<strong>重要</strong>文字</p> 的场景：
     * 数据库扫描时存储的是 strip_tags 后的 "这是重要文字"，
     * 但 extract_text_from_html 只能提取 "这是"、"重要"、"文字" 片段无法匹配。
     * 此方法提取块元素的完整去标签文本进行匹配。
     */
    private static function translate_block_elements($html) {
        if (empty(self::$gettext_cache)) {
            return $html;
        }

        $new_html = preg_replace_callback(
            '/<(p|h[1-6]|li|td|th|option|button|label|figcaption|dt|dd|caption|summary)(\b[^>]*)>(.*?)<\/\1>/si',
            function ($m) {
                $tag   = $m[1];
                $attrs = $m[2];
                $inner = $m[3];

                // 导航/列表中常见 <li><a>...</a></li>，若直接替换会丢失链接结构
                if (preg_match('/<\s*a\b/i', $inner)) {
                    return $m[0];
                }

                // 非可翻译代码块
                if (preg_match('/<\s*(script|style|noscript|template)\b/i', $inner)) {
                    return $m[0];
                }

                // 内部没有子标签时跳过（第一遍逐文本替换已可处理）
                if (strip_tags($inner) === $inner) {
                    return $m[0];
                }

                // 去标签后的纯文本内容
                $stripped = trim(wp_strip_all_tags(html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if (empty($stripped) || mb_strlen($stripped) < 2) {
                    return $m[0];
                }
                if (self::should_skip_text_candidate($stripped)) {
                    return $m[0];
                }

                // 查找翻译
                $hash = md5($stripped);
                $translated = null;
                if (isset(self::$gettext_cache[$hash])) {
                    $translated = self::$gettext_cache[$hash];
                }

                // 归一化重试
                if (!$translated) {
                    $normalized = trim((string) preg_replace('/\s+/u', ' ', $stripped));
                    if ($normalized !== $stripped) {
                        $norm_hash = md5($normalized);
                        if (isset(self::$gettext_cache[$norm_hash])) {
                            $translated = self::$gettext_cache[$norm_hash];
                        }
                    }
                }

                if ($translated && $translated !== $stripped && !self::should_reject_translation($stripped, $translated)) {
                    return '<' . $tag . $attrs . '>' . esc_html($translated) . '</' . $tag . '>';
                }

                return $m[0];
            },
            $html
        );

        return ($new_html !== null) ? $new_html : $html;
    }

    /**
     * 从HTML中提取文本内容
     */
    private static function extract_text_from_html($html) {
        $texts = array();
        $html = self::strip_non_translatable_blocks($html);

        // 使用正则提取标签间的文本
        // 匹配标签之间的文本内容
        preg_match_all('/>([^<]+)</', $html, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $text) {
                $text = trim($text);
                // 过滤空白和纯数字/符号
                if (!empty($text) && mb_strlen($text) >= 2 && preg_match('/[\p{L}]/u', $text) && !self::should_skip_text_candidate($text)) {
                    $texts[] = $text;
                }
            }
        }

        // 提取meta描述和title等属性中的文本（不含 value，避免翻译表单值）
        // 使用 (?<=\s) 确保属性名前必须是空白，防止误匹配 data-title, data-thumb-alt 等
        preg_match_all('/(?<=\s)(?:content|title|alt|placeholder)=["\']([^"\']+)["\']/i', $html, $attr_matches);
        if (!empty($attr_matches[1])) {
            foreach ($attr_matches[1] as $text) {
                $text = trim($text);
                if (!empty($text) && mb_strlen($text) >= 2 && preg_match('/[\p{L}]/u', $text) && !self::should_skip_text_candidate($text)) {
                    $texts[] = $text;
                }
            }
        }

        return array_unique($texts);
    }

    /**
     * 移除 script/style/noscript/template 块，避免采集或替换其中内容
     */
    private static function strip_non_translatable_blocks($html) {
        $pattern = '/<\s*(script|style|noscript|template)\b[^>]*>.*?<\s*\/\s*\1\s*>/is';
        $new_html = preg_replace($pattern, '', (string) $html);
        return ($new_html !== null) ? $new_html : (string) $html;
    }

    /**
     * 保护非可翻译代码块（用于替换前后无损恢复）
     */
    private static function mask_non_translatable_blocks($html, &$protected_blocks) {
        $protected_blocks = array();
        $index = 0;
        $pattern = '/<\s*(script|style|noscript|template)\b[^>]*>.*?<\s*\/\s*\1\s*>/is';

        $new_html = preg_replace_callback($pattern, function($m) use (&$protected_blocks, &$index) {
            $key = "\x00FANYI2_BLOCK_{$index}\x00";
            $protected_blocks[$key] = $m[0];
            $index++;
            return $key;
        }, (string) $html);

        return ($new_html !== null) ? $new_html : (string) $html;
    }

    /**
     * 恢复被保护的代码块
     */
    private static function restore_masked_blocks($html, $protected_blocks) {
        if (empty($protected_blocks) || !is_array($protected_blocks)) {
            return $html;
        }

        return str_replace(array_keys($protected_blocks), array_values($protected_blocks), (string) $html);
    }

    /**
     * 判断是否应跳过该文本（CSS/JS/JSON 噪声）
     */
    private static function should_skip_text_candidate($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return true;
        }

        if (preg_match('/(?:admin-bar-inline-css|wpadminbar|sourceURL=)/i', $text)) {
            return true;
        }

        if (preg_match('/@media\s+[^{]+\{|\{[^}]*margin-top:\s*32px\s*!important;?[^}]*\}/i', $text)) {
            return true;
        }

        if (preg_match('/<\s*\/?\s*(script|style|noscript|template)\b/i', $text)) {
            return true;
        }

        // 典型 CSS 声明/块
        if (preg_match('/\b(?:margin|padding|display|position|background|font-size|line-height|z-index|border)\s*:\s*[^;{}]+;/i', $text)
            && (strpos($text, '{') !== false || strpos($text, '}') !== false || substr_count($text, ';') >= 2)) {
            return true;
        }

        $length = mb_strlen($text);
        if ($length > 0) {
            $code_chars = preg_match_all('/[{};<>=$#]/u', $text, $tmp);
            if ($code_chars > 0 && ($code_chars / $length) > 0.08 && preg_match('/[:;{}]/', $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断是否应拒绝该译文（译文是噪声代码，原文不是）
     */
    private static function should_reject_translation($original, $translated) {
        $original = trim((string) $original);
        $translated = trim((string) $translated);
        if ($original === '' || $translated === '' || $original === $translated) {
            return false;
        }

        if (!self::should_skip_text_candidate($translated)) {
            return false;
        }

        return !self::should_skip_text_candidate($original);
    }

    /**
     * 安全替换文本，避免破坏HTML标签（单条兼容接口）
     */
    private static function safe_replace($html, $original, $translated) {
        return self::safe_replace_batch($html, array($original => $translated));
    }

    /**
     * 批量安全替换：使用占位符机制防止链式替换
     *
     * 问题场景：翻译A的结果恰好包含原文B，逐条替换时B的规则会破坏A的译文。
     * 解决：阶段1 将所有原文替换为唯一占位符（\x00 打头，HTML 中不会出现），
     *       阶段2 一次性将占位符替换为最终译文。
     *
     * @param string $html          HTML 内容
     * @param array  $replacements  原文 => 译文 映射（应已按长度降序排序）
     * @return string
     */
    private static function safe_replace_batch($html, array $replacements) {
        if (empty($replacements)) {
            return $html;
        }

        // 阶段1：将所有匹配的原文替换为唯一占位符
        $placeholders = array(); // placeholder => translated
        $index = 0;

        foreach ($replacements as $original => $translated) {
            $escaped = preg_quote($original, '/');
            $ph_tag  = "\x00FANYI2_PH_{$index}_TAG\x00";
            $ph_attr = "\x00FANYI2_PH_{$index}_ATTR\x00";

            // 替换标签间文本 >原文<（允许两侧有空白）
            $new_html = preg_replace(
                '/(>\s*)(' . $escaped . ')(\s*<)/',
                '${1}' . $ph_tag . '${3}',
                $html
            );
            if ($new_html !== null) {
                $html = $new_html;
            }

            // 替换属性中的文本（(?<=\s) 防止误匹配 data-title, data-thumb-alt 等）
            $new_html = preg_replace(
                '/((?<=\s)(?:content|title|alt|placeholder)=["\'])(' . $escaped . ')(["\'])/',
                '${1}' . $ph_attr . '${3}',
                $html
            );
            if ($new_html !== null) {
                $html = $new_html;
            }

            $placeholders[$ph_tag]  = esc_html((string) $translated);
            $placeholders[$ph_attr] = esc_attr((string) $translated);
            $index++;
        }

        // 阶段2：将所有占位符一次性替换为最终译文
        if (!empty($placeholders)) {
            $html = str_replace(
                array_keys($placeholders),
                array_values($placeholders),
                $html
            );
        }

        return $html;
    }
}
