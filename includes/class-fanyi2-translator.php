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
        $current_lang = Fanyi2_Frontend::get_current_language();
        $default_lang = get_option('fanyi2_default_language', 'zh');

        if ($current_lang === $default_lang) {
            return;
        }

        // --- gettext 级别拦截：覆盖 WooCommerce 核心翻译 ---
        add_filter('gettext', array(__CLASS__, 'filter_gettext'), 10, 3);
        add_filter('gettext_with_context', array(__CLASS__, 'filter_gettext_with_context'), 10, 4);
        add_filter('ngettext', array(__CLASS__, 'filter_ngettext'), 10, 5);

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

        if ($current_lang === $default_lang) {
            return $html;
        }

        // 不处理AJAX请求和JSON响应
        if (wp_doing_ajax() || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)) {
            return $html;
        }

        // 1. 翻译文本内容
        $html = self::translate_html($html, $current_lang);

        // 2. 重写内部链接（加上语言标识）
        $html = self::rewrite_internal_urls($html, $current_lang);

        return $html;
    }

    // ====== gettext 拦截 (覆盖 WooCommerce / WordPress 核心文本) ======

    /**
     * 拦截 gettext（__() / _e() 等）
     */
    public static function filter_gettext($translated, $text, $domain) {
        // 只处理 woocommerce 和 wordpress 核心 domain
        $target_domains = array('woocommerce', 'default', '');
        if (!in_array($domain, $target_domains, true)) {
            return $translated;
        }

        return self::maybe_translate($translated);
    }

    /**
     * 拦截带上下文的 gettext（_x()）
     */
    public static function filter_gettext_with_context($translated, $text, $context, $domain) {
        $target_domains = array('woocommerce', 'default', '');
        if (!in_array($domain, $target_domains, true)) {
            return $translated;
        }

        return self::maybe_translate($translated);
    }

    /**
     * 拦截复数形式 gettext（_n()）
     */
    public static function filter_ngettext($translated, $single, $plural, $number, $domain) {
        $target_domains = array('woocommerce', 'default', '');
        if (!in_array($domain, $target_domains, true)) {
            return $translated;
        }

        return self::maybe_translate($translated);
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
        $cache_key = 'fanyi2_all_' . $current_lang;

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

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.string_hash, t.translated_string
             FROM {$table_trans} t
             INNER JOIN {$table_strings} s ON t.string_id = s.id
             WHERE t.language = %s AND t.status = 'published' AND s.status = 'active'",
            $current_lang
        ));

        $map = array();
        foreach ($results as $row) {
            $map[$row->string_hash] = $row->translated_string;
        }

        self::$gettext_cache = $map;

        // 写入持久缓存（5分钟）
        wp_cache_set($cache_key, $map, self::$persistent_cache_group, 300);
        set_transient($cache_key, $map, 300);
    }

    /**
     * 清除翻译缓存（翻译保存/删除后调用）
     */
    public static function clear_translation_cache($lang = '') {
        if ($lang) {
            $cache_key = 'fanyi2_all_' . $lang;
            wp_cache_delete($cache_key, self::$persistent_cache_group);
            delete_transient($cache_key);
        } else {
            // 清除所有语言
            $languages = get_option('fanyi2_enabled_languages', array());
            foreach ($languages as $l) {
                $cache_key = 'fanyi2_all_' . $l;
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
        if (empty($text) || mb_strlen($text) < 2) {
            return $text;
        }

        // 确保翻译已预加载
        self::preload_translations();

        // 通过哈希快速查找
        $hash = md5($text);
        if (isset(self::$gettext_cache[$hash])) {
            return self::$gettext_cache[$hash];
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
     */
    public static function translate_html($html, $target_language) {
        // 确保翻译已预加载
        self::preload_translations();

        // 提取所有文本内容
        $text_nodes = self::extract_text_from_html($html);

        if (empty($text_nodes)) {
            return $html;
        }

        // 从预加载缓存中收集需要替换的映射
        $replacements = array();
        $missed_texts = array();
        foreach ($text_nodes as $text) {
            $hash = md5($text);
            if (isset(self::$gettext_cache[$hash])) {
                $translated = self::$gettext_cache[$hash];
                if ($translated !== $text) {
                    $replacements[$text] = $translated;
                }
            } else {
                $missed_texts[] = $text;
            }
        }

        // 缓存没命中的部分，补一次批量数据库查询
        if (!empty($missed_texts)) {
            $db_trans = Fanyi2_Database::get_translations_batch($missed_texts, $target_language);
            foreach ($db_trans as $original => $translated) {
                if (!empty($translated) && $original !== $translated) {
                    $replacements[$original] = $translated;
                }
            }
        }

        if (empty($replacements)) {
            return $html;
        }

        // 按原文长度降序排列，防止短文本先匹配导致长文本断裂
        uksort($replacements, function($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        $html = self::safe_replace_batch($html, $replacements);

        return $html;
    }

    /**
     * 从HTML中提取文本内容
     */
    private static function extract_text_from_html($html) {
        $texts = array();

        // 使用正则提取标签间的文本
        // 匹配标签之间的文本内容
        preg_match_all('/>([^<]+)</', $html, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $text) {
                $text = trim($text);
                // 过滤空白和纯数字/符号
                if (!empty($text) && mb_strlen($text) >= 2 && preg_match('/[\p{L}]/u', $text)) {
                    $texts[] = $text;
                }
            }
        }

        // 提取meta描述和title等属性中的文本（不含 value，避免翻译表单值）
        preg_match_all('/(?:content|title|alt|placeholder)=["\']([^"\']+)["\']/', $html, $attr_matches);
        if (!empty($attr_matches[1])) {
            foreach ($attr_matches[1] as $text) {
                $text = trim($text);
                if (!empty($text) && mb_strlen($text) >= 2 && preg_match('/[\p{L}]/u', $text)) {
                    $texts[] = $text;
                }
            }
        }

        return array_unique($texts);
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

            // 替换属性中的文本
            $new_html = preg_replace(
                '/((?:content|title|alt|placeholder)=["\'])(' . $escaped . ')(["\'])/',
                '${1}' . $ph_attr . '${3}',
                $html
            );
            if ($new_html !== null) {
                $html = $new_html;
            }

            $placeholders[$ph_tag]  = $translated;
            $placeholders[$ph_attr] = $translated;
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
