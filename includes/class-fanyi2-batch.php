<?php
/**
 * 批量预翻译类
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_Batch {

    private static $syncing_option_cleanup = false;

    /**
     * 注册默认语言数据库同步钩子
     */
    public static function register_cleanup_hooks() {
        add_action('customize_save_after', array(__CLASS__, 'sync_after_customizer_save'), 20);
        add_action('updated_option', array(__CLASS__, 'maybe_sync_updated_option'), 20, 3);
    }

    /**
     * 扫描站点抓取所有文本（直接查询数据库，不依赖 HTTP 请求）
     */
    public static function scan_site_pages($urls = array()) {
        $total_strings = 0;

        // 1. 直接从数据库扫描所有内容（可靠，不依赖 HTTP loopback）
        $total_strings += self::scan_database_content();
        $total_strings += self::scan_database_options();

        // 2. 注册 WooCommerce 通用界面字符串
        if (class_exists('WooCommerce')) {
            $total_strings += self::register_woocommerce_strings();
            $total_strings += self::register_woocommerce_dynamic_settings_strings();
        }

        return $total_strings;
    }

    /**
     * 从数据库直接扫描站点内容
     */
    public static function scan_database_content() {
        $count = 0;

        // ——— 站点标题和描述 ———
        $count += self::register_single_string(get_bloginfo('name'), 'site_title', home_url('/'), 'homepage');
        $count += self::register_single_string(get_bloginfo('description'), 'site_description', home_url('/'), 'homepage');

        // 检测首页静态页面 ID（用于标记首页内容 domain）
        $front_page_id = 0;
        if (get_option('show_on_front') === 'page') {
            $front_page_id = (int) get_option('page_on_front');
        }

        // ——— 所有公开的 post / page / product ———
        $post_types = array('post', 'page');
        if (post_type_exists('product')) {
            $post_types[] = 'product';
        }

        $all_posts = get_posts(array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ));

        foreach ($all_posts as $post) {
            $url = get_permalink($post->ID);

            // 根据 post_type 确定 domain，便于分范围翻译
            if ($post->post_type === 'product') {
                $domain = 'products';
            } elseif ($front_page_id > 0 && $post->ID === $front_page_id) {
                $domain = 'homepage';
            } else {
                $domain = 'general';
            }

            // 标题
            $count += self::register_single_string($post->post_title, 'post_title', $url, $domain);

            // 摘要
            if (!empty($post->post_excerpt)) {
                $count += self::register_single_string($post->post_excerpt, 'excerpt', $url, $domain);
            }

            // 内容文本（按段拆分）
            $content = $post->post_content;
            $content = strip_shortcodes($content);
            $content = preg_replace('/<!--.*?-->/s', '', $content);  // 移除块编辑器注释
            $content = wp_strip_all_tags($content);

            $lines = preg_split('/[\r\n]+/', $content);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && mb_strlen($line) >= 2 && mb_strlen($line) <= 1000 && preg_match('/[\p{L}]/u', $line)) {
                    $count += self::register_single_string($line, 'content', $url, $domain);
                }
            }
        }

        // ——— 导航菜单项 ———
        $menus = wp_get_nav_menus();
        if ($menus) {
            foreach ($menus as $menu) {
                $items = wp_get_nav_menu_items($menu->term_id);
                if ($items) {
                    foreach ($items as $item) {
                        if (!empty($item->title)) {
                            $count += self::register_single_string($item->title, 'menu_item', '', 'general');
                        }
                    }
                }
            }
        }

        // ——— 文章分类 ———
        $categories = get_categories(array('hide_empty' => false));
        if ($categories && !is_wp_error($categories)) {
            foreach ($categories as $cat) {
                $count += self::register_single_string($cat->name, 'category', get_category_link($cat->term_id));
                if (!empty($cat->description)) {
                    $count += self::register_single_string($cat->description, 'category_desc', get_category_link($cat->term_id));
                }
            }
        }

        // ——— 文章标签 ———
        $tags = get_tags(array('hide_empty' => false));
        if ($tags && !is_wp_error($tags)) {
            foreach ($tags as $tag) {
                $count += self::register_single_string($tag->name, 'tag', get_tag_link($tag->term_id));
            }
        }

        // ——— WooCommerce 分类 / 标签 / 属性 ———
        if (class_exists('WooCommerce')) {
            $product_cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            if ($product_cats && !is_wp_error($product_cats)) {
                foreach ($product_cats as $cat) {
                    $count += self::register_single_string($cat->name, 'product_cat', '', 'woocommerce');
                    if (!empty($cat->description)) {
                        $count += self::register_single_string($cat->description, 'product_cat_desc', '', 'woocommerce');
                    }
                }
            }

            $product_tags = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
            if ($product_tags && !is_wp_error($product_tags)) {
                foreach ($product_tags as $tag) {
                    $count += self::register_single_string($tag->name, 'product_tag', '', 'woocommerce');
                }
            }

            if (function_exists('wc_get_attribute_taxonomies')) {
                $attributes = wc_get_attribute_taxonomies();
                if ($attributes) {
                    foreach ($attributes as $attr) {
                        $count += self::register_single_string($attr->attribute_label, 'product_attr', '', 'woocommerce');
                        $terms = get_terms(array('taxonomy' => 'pa_' . $attr->attribute_name, 'hide_empty' => false));
                        if ($terms && !is_wp_error($terms)) {
                            foreach ($terms as $term) {
                                $count += self::register_single_string($term->name, 'attr_value', '', 'woocommerce');
                            }
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * 深度扫描 wp_options / theme mods / widgets 等数据库文案
     */
    public static function scan_database_options() {
        global $wpdb;

        $table_options = $wpdb->options;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value, autoload
             FROM $table_options
             WHERE autoload = 'yes'
                OR option_name LIKE 'theme_mods_%'
                OR option_name LIKE 'widget_%'
                OR option_name LIKE 'woocommerce_%'
                OR option_name LIKE 'elementor_%'"
        );

        if (empty($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $option_name = (string) $row->option_name;
            $autoload = isset($row->autoload) ? (string) $row->autoload : 'no';

            if (!self::should_scan_option_name($option_name, $autoload)) {
                continue;
            }

            $value = maybe_unserialize($row->option_value);
            $domain = self::resolve_option_domain($option_name);
            $count += self::register_strings_from_option_value($value, $option_name, $domain);
        }

        return $count;
    }

    /**
     * 注册单个字符串到数据库
     * 直接调用 get_or_create_string 即可，由其内部决定新建或补全元数据
     */
    private static function register_single_string($text, $element_type = 'text', $page_url = '', $domain = 'general') {
        $text = trim($text);
        if (empty($text) || mb_strlen($text) < 2 || !preg_match('/[\p{L}]/u', $text) || self::is_probably_code_like($text)) {
            return 0;
        }
        $obj = Fanyi2_Database::get_or_create_string($text, array(
            'domain'       => $domain,
            'element_type' => $element_type,
            'page_url'     => $page_url,
        ));
        return $obj ? 1 : 0;
    }

    /**
     * 递归注册 option/theme_mod/widget 中的字符串
     */
    private static function register_strings_from_option_value($value, $option_name, $domain = 'general', $path = array(), $depth = 0) {
        if ($depth > 6) {
            return 0;
        }

        $count = 0;

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $child_path = $path;
                $child_path[] = is_scalar($key) ? sanitize_key((string) $key) : 'item';
                $count += self::register_strings_from_option_value($child, $option_name, $domain, $child_path, $depth + 1);
            }
            return $count;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $child) {
                $child_path = $path;
                $child_path[] = sanitize_key((string) $key);
                $count += self::register_strings_from_option_value($child, $option_name, $domain, $child_path, $depth + 1);
            }
            return $count;
        }

        if (!is_string($value)) {
            return 0;
        }

        if (!self::is_probably_display_text($value, $option_name, $path)) {
            return 0;
        }

        $selector = $option_name;
        if (!empty($path)) {
            $selector .= ':' . implode('.', array_filter($path));
        }

        $plain_text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain_text = preg_replace('/<!--.*?-->/s', '', $plain_text);
        $plain_text = strip_shortcodes($plain_text);
        $plain_text = wp_strip_all_tags($plain_text);
        $segments = preg_split('/[\r\n]+/', $plain_text);

        foreach ((array) $segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '' || mb_strlen($segment) < 2 || mb_strlen($segment) > 800 || !preg_match('/[\p{L}]/u', $segment) || self::is_probably_code_like($segment)) {
                continue;
            }

            $obj = Fanyi2_Database::get_or_create_string($segment, array(
                'domain'       => $domain,
                'element_type' => 'option_text',
                'page_url'     => '',
                'selector'     => $selector,
            ));

            if ($obj) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * option 是否值得扫描
     */
    private static function should_scan_option_name($option_name, $autoload = 'no') {
        $option_name_lc = strtolower((string) $option_name);

        if ($option_name_lc === '') {
            return false;
        }

        $skip_prefixes = array(
            '_transient_',
            '_site_transient_',
            'fanyi2_',
            'rewrite_rules',
            'cron',
            'can_compress_scripts',
            'dismissed_wp_pointers',
        );
        foreach ($skip_prefixes as $prefix) {
            if (strpos($option_name_lc, $prefix) === 0) {
                return false;
            }
        }

        $skip_contains = array(
            'api_key', 'apikey', 'secret', 'token', 'license', 'nonce', 'hash',
            'smtp', 'mailgun', 'recaptcha', 'access_key', 'private_key',
        );
        foreach ($skip_contains as $needle) {
            if (strpos($option_name_lc, $needle) !== false) {
                return false;
            }
        }

        if ($autoload === 'yes') {
            return true;
        }

        return (
            strpos($option_name_lc, 'theme_mods_') === 0
            || strpos($option_name_lc, 'widget_') === 0
            || strpos($option_name_lc, 'woocommerce_') === 0
            || strpos($option_name_lc, 'elementor_') === 0
        );
    }

    /**
     * 粗略判断字符串是否像前端展示文案
     */
    private static function is_probably_display_text($text, $option_name, $path = array()) {
        $raw = trim((string) $text);
        if ($raw === '') {
            return false;
        }

        $normalized = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = trim((string) wp_strip_all_tags($normalized));

        if ($normalized === '' || mb_strlen($normalized) < 2 || mb_strlen($normalized) > 800) {
            return false;
        }

        if (!preg_match('/[\p{L}]/u', $normalized)) {
            return false;
        }

        if (self::is_probably_code_like($normalized)) {
            return false;
        }

        if (filter_var($normalized, FILTER_VALIDATE_URL) || filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (preg_match('/^\[[^\]]+\]$/', $normalized)) {
            return false;
        }

        if (preg_match('/^(#[0-9a-f]{3,8}|[a-z0-9_\-\/\.]+\.(?:png|jpe?g|gif|svg|webp|css|js|woff2?|ttf))$/i', $normalized)) {
            return false;
        }

        if (preg_match('/^[a-f0-9]{24,}$/i', $normalized)) {
            return false;
        }

        $hint = strtolower($option_name . ' ' . implode(' ', array_map('strval', (array) $path)));
        $deny_keywords = array(
            'color', 'font', 'size', 'width', 'height', 'padding', 'margin', 'radius',
            'border', 'background', 'image', 'icon', 'logo', 'attachment', 'media',
            'slug', 'path', 'file', 'css', 'scss', 'script', 'javascript', 'endpoint',
            'rest', 'html', 'json', 'schema', 'lat', 'lng', 'phone', 'email'
        );
        $allow_keywords = array(
            'title', 'label', 'text', 'content', 'description', 'subtitle', 'heading',
            'button', 'cta', 'message', 'notice', 'placeholder', 'caption',
            'announcement', 'tagline', 'footer', 'header'
        );

        $has_allow = false;
        foreach ($allow_keywords as $keyword) {
            if (strpos($hint, $keyword) !== false) {
                $has_allow = true;
                break;
            }
        }

        if (!$has_allow) {
            foreach ($deny_keywords as $keyword) {
                if (strpos($hint, $keyword) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 粗略识别代码 / 配置片段，避免污染翻译库
     */
    private static function is_probably_code_like($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        $patterns = array(
            '/^\s*(var|let|const)\s+[A-Za-z_$][\w$]*\s*=/u',
            '/^\s*(?:\(?function\b|\(\s*function\b)/u',
            '/^\s*:root\s*\{/u',
            '/^\s*[.#][A-Za-z0-9_\-:>\s,\[\]="\'\(\)]+\{[^}]+\}\s*$/u',
            '/--[a-z0-9\-]+\s*:/iu',
            '/(?:wp-admin\/admin-ajax\.php|wc-ajax=|cart_hash_key|fragment_name|request_timeout)/iu',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        if (strpos($text, '{') !== false && strpos($text, '}') !== false) {
            if (preg_match('/[;=]/', $text) || preg_match('/"[A-Za-z0-9_\-]+"\s*:/u', $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 解析 option 所属 domain
     */
    private static function resolve_option_domain($option_name) {
        $option_name_lc = strtolower((string) $option_name);

        if (strpos($option_name_lc, 'woocommerce_') === 0) {
            return 'woocommerce';
        }

        if (strpos($option_name_lc, 'theme_mods_') === 0 || strpos($option_name_lc, 'widget_') === 0) {
            return 'general';
        }

        return 'general';
    }

    /**
     * 自定义器发布后，把已存在的默认语言清洗结果回写到底层 option
     */
    public static function sync_after_customizer_save() {
        self::sync_default_language_option_texts();
    }

    /**
     * 指定 option 更新后同步默认语言底层值
     */
    public static function maybe_sync_updated_option($option_name, $old_value, $value) {
        if (self::$syncing_option_cleanup) {
            return;
        }

        if (get_option('fanyi2_force_default_language_cleanup', '0') !== '1') {
            return;
        }

        if (!self::should_scan_option_name($option_name, 'no')) {
            return;
        }

        self::sync_default_language_option_texts(array((string) $option_name));
    }

    /**
     * 将默认语言的已保存译文回写到 theme_mods / widgets / options
     */
    public static function sync_default_language_option_texts($option_names = array()) {
        if (get_option('fanyi2_force_default_language_cleanup', '0') !== '1') {
            return array(
                'options_updated'     => 0,
                'option_values_fixed' => 0,
            );
        }

        $default_language = get_option('fanyi2_default_language', 'zh');
        $rows = Fanyi2_Database::get_option_cleanup_translations($default_language, (array) $option_names);
        if (empty($rows)) {
            return array(
                'options_updated'     => 0,
                'option_values_fixed' => 0,
            );
        }

        $grouped = array();
        foreach ($rows as $row) {
            if (empty($row->selector) || empty($row->translated_string)) {
                continue;
            }

            $parts = explode(':', (string) $row->selector, 2);
            $option_name = isset($parts[0]) ? (string) $parts[0] : '';
            if ($option_name === '') {
                continue;
            }

            $path = array();
            if (!empty($parts[1])) {
                $path = array_values(array_filter(explode('.', $parts[1]), 'strlen'));
            }

            $grouped[$option_name][] = array(
                'path'       => $path,
                'original'   => (string) $row->original_string,
                'translated' => (string) $row->translated_string,
            );
        }

        $options_updated = 0;
        $option_values_fixed = 0;

        foreach ($grouped as $option_name => $translations) {
            $option_value = get_option($option_name, null);
            if ($option_value === null) {
                continue;
            }

            $original_snapshot = maybe_serialize($option_value);
            foreach ($translations as $translation) {
                $option_values_fixed += self::replace_option_value_by_selector(
                    $option_value,
                    $translation['path'],
                    $translation['original'],
                    $translation['translated']
                );
            }

            if (maybe_serialize($option_value) !== $original_snapshot) {
                self::$syncing_option_cleanup = true;
                update_option($option_name, $option_value, false);
                self::$syncing_option_cleanup = false;
                $options_updated++;
            }
        }

        return array(
            'options_updated'     => $options_updated,
            'option_values_fixed' => $option_values_fixed,
        );
    }

    /**
     * 按 selector path 回写 option 值
     */
    private static function replace_option_value_by_selector(&$value, $path, $original, $translated) {
        $original_normalized = Fanyi2_Database::normalize_string($original);
        if ($original_normalized === '' || $translated === '') {
            return 0;
        }

        $replaced = 0;
        if (!empty($path)) {
            $replaced = self::replace_option_value_at_path($value, $path, $original_normalized, $translated);
        }

        if ($replaced > 0) {
            return $replaced;
        }

        return self::replace_option_value_recursively($value, $original_normalized, $translated);
    }

    /**
     * 按精确路径尝试替换 option 值
     */
    private static function replace_option_value_at_path(&$value, $path, $original_normalized, $translated) {
        if (empty($path)) {
            if (is_string($value) && Fanyi2_Database::normalize_string($value) === $original_normalized && $value !== $translated) {
                $value = $translated;
                return 1;
            }
            return 0;
        }

        $segment = array_shift($path);

        if (is_array($value)) {
            foreach ($value as $key => &$child) {
                if ((string) $key === $segment || sanitize_key((string) $key) === $segment) {
                    return self::replace_option_value_at_path($child, $path, $original_normalized, $translated);
                }
            }
            return 0;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $child) {
                if ((string) $key === $segment || sanitize_key((string) $key) === $segment) {
                    return self::replace_option_value_at_path($value->$key, $path, $original_normalized, $translated);
                }
            }
        }

        return 0;
    }

    /**
     * 递归兜底替换 option 中完全匹配的字符串
     */
    private static function replace_option_value_recursively(&$value, $original_normalized, $translated) {
        if (is_string($value)) {
            if (Fanyi2_Database::normalize_string($value) === $original_normalized && $value !== $translated) {
                $value = $translated;
                return 1;
            }
            return 0;
        }

        $replaced = 0;
        if (is_array($value)) {
            foreach ($value as &$child) {
                $replaced += self::replace_option_value_recursively($child, $original_normalized, $translated);
            }
            return $replaced;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $child) {
                $replaced += self::replace_option_value_recursively($value->$key, $original_normalized, $translated);
            }
        }

        return $replaced;
    }

    /**
     * 预翻译所有未翻译的字符串
     */
    public static function pretranslate_all($target_language, $batch_size = 10) {
        $source_language = get_option('fanyi2_default_language', 'zh');
        $total_translated = 0;
        $errors = array();

        while (true) {
            $untranslated = Fanyi2_Database::get_untranslated_strings($target_language, $batch_size);

            if (empty($untranslated)) {
                break;
            }

            $texts = array();
            foreach ($untranslated as $str) {
                $texts[$str->id] = $str->original_string;
            }

            $results = Fanyi2_AI_Engine::translate_batch($texts, $target_language, $source_language);

            if (is_wp_error($results)) {
                $errors[] = $results->get_error_message();
                break;
            }

            foreach ($results as $string_id => $translated) {
                if (!empty($translated)) {
                    $saved_id = Fanyi2_Database::save_translation_if_missing($string_id, $target_language, $translated, 'ai');
                    if ($saved_id) {
                        $total_translated++;
                    }
                }
            }

            // 防止超时
            if ($total_translated >= 500) {
                break;
            }
        }

        return array(
            'translated' => $total_translated,
            'errors'     => $errors,
        );
    }

    /**
     * 注册 WooCommerce 通用界面字符串
     * 这些是购物车、结账、账户页面等常见文本
     */
    public static function register_woocommerce_strings() {
        $wc_strings = array(
            // 购物车
            'Add to cart', 'View cart', 'Cart', 'Cart totals',
            'Update cart', 'Subtotal', 'Total', 'Coupon code',
            'Apply coupon', 'Remove this item', 'Cart updated.',
            'Proceed to checkout', 'Your cart is currently empty.',
            'Return to shop', 'Coupon', 'Remove', 'Quantity',
            'Price', 'Product', 'Shipping', 'Free shipping',
            'Flat rate', 'Calculate shipping', 'Update totals',
            'Cart is empty', 'Shipping options', 'Estimated total',
            'Continue to shipping', 'Continue to payment',

            // 结账
            'Checkout', 'Billing details', 'Shipping details',
            'Your order', 'Place order', 'Order notes',
            'First name', 'Last name', 'Company name',
            'Country / Region', 'Street address', 'Apartment, suite, unit, etc.',
            'Town / City', 'State / County', 'Postcode / ZIP',
            'Phone', 'Email address', 'Create an account?',
            'Notes about your order', 'Ship to a different address?',
            'Payment method', 'Order summary',
            'Contact information', 'Shipping address', 'Billing address',
            'Use same address for billing', 'Payment', 'Review order',
            'Express checkout', 'Have a coupon?', 'Apply',

            // 账户
            'My account', 'Dashboard', 'Orders', 'Downloads',
            'Addresses', 'Account details', 'Logout', 'Log out',
            'Edit your password and account details.',
            'Billing address', 'Shipping address', 'Edit',
            'No order has been made yet.', 'No downloads available yet.',
            'The following addresses will be used on the checkout page by default.',

            // 产品
            'Description', 'Additional information', 'Reviews',
            'Related products', 'Sale!', 'Out of stock', 'In stock',
            'SKU', 'Category', 'Categories', 'Tag', 'Tags',
            'Add to wishlist', 'Compare', 'Quick view',
            'Read more', 'Select options',

            // 搜索和筛选
            'Search', 'Search products…', 'Search results for',
            'No products were found matching your selection.',
            'Sort by', 'Default sorting', 'Sort by popularity',
            'Sort by average rating', 'Sort by latest',
            'Sort by price: low to high', 'Sort by price: high to low',
            'Show', 'Filter', 'Price',

            // 登录注册
            'Login', 'Register', 'Username or email address',
            'Password', 'Remember me', 'Log in', 'Lost your password?',
            'Email', 'Username',

            // 订单
            'Order', 'Date', 'Status', 'Actions', 'View',
            'Thank you. Your order has been received.',
            'Order number', 'Order details', 'Customer details',
            'Processing', 'Completed', 'On hold', 'Cancelled', 'Refunded', 'Failed', 'Pending payment',

            // 商店
            'Shop', 'Home', 'Products',
            'Showing all results', 'Showing the single result',

            // 通用
            'Save changes', 'Close', 'Continue shopping',
            'Apply', 'Cancel', 'Submit', 'Update', 'Delete',
            'Yes', 'No', 'OK', 'Error', 'Success',
            'Loading...', 'Please wait...', 'Required field',

            // 常见中文（语言包/区块场景）
            '购物车', '产品', '合计', '小计', '添加优惠券',
            '购物车总计', '预估总额', '继续结账', '删除项目', '节省',
            '配送', '订单总计', '结账', '账单详情', '收货地址',
            '联系信息', '配送地址', '配送选项', '付款选项', '订单摘要',
            '使用相同的地址付款', '为订单添加备注', '继续购买即表示，您同意我们的 条款和条件 和 隐私政策',
            '编辑', '银行汇款', '货到付款',
        );

        $count = 0;
        foreach ($wc_strings as $str) {
            // 1) 收录原始字符串
            $obj = Fanyi2_Database::get_or_create_string($str, array(
                'domain'       => 'woocommerce',
                'element_type' => 'gettext',
                'page_url'     => '',
            ));
            if ($obj) {
                $count++;
            }

            // 2) 收录 WooCommerce 语言包当前翻译（例如中文站点里的“购物车总计”）
            $localized_wc = __($str, 'woocommerce');
            if (is_string($localized_wc) && $localized_wc !== $str && !empty(trim($localized_wc))) {
                $obj = Fanyi2_Database::get_or_create_string($localized_wc, array(
                    'domain'       => 'woocommerce',
                    'element_type' => 'gettext',
                    'page_url'     => '',
                ));
                if ($obj) {
                    $count++;
                }
            }

            // 3) 收录 Woo Blocks 语言包当前翻译
            $localized_blocks = __($str, 'woo-gutenberg-products-block');
            if (is_string($localized_blocks) && $localized_blocks !== $str && !empty(trim($localized_blocks))) {
                $obj = Fanyi2_Database::get_or_create_string($localized_blocks, array(
                    'domain'       => 'woocommerce',
                    'element_type' => 'gettext',
                    'page_url'     => '',
                ));
                if ($obj) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * 注册 WooCommerce 中存于数据库的动态文案
     * 如：配送方式标题、支付方式标题/说明、隐私政策文案等
     */
    public static function register_woocommerce_dynamic_settings_strings() {
        $count = 0;

        // 1) 隐私政策相关文案（结账/注册）
        $privacy_options = array(
            'woocommerce_checkout_privacy_policy_text',
            'woocommerce_registration_privacy_policy_text',
            'woocommerce_checkout_terms_and_conditions_text',
        );
        foreach ($privacy_options as $opt_key) {
            $value = get_option($opt_key, '');
            if (!empty($value)) {
                $count += self::register_single_string($value, 'woocommerce_setting', '', 'woocommerce');
            }
        }

        // 2) 支付网关标题/描述/指引
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (!empty($gateways) && is_array($gateways)) {
                foreach ($gateways as $gateway) {
                    if (!empty($gateway->title)) {
                        $count += self::register_single_string($gateway->title, 'payment_gateway_title', '', 'woocommerce');
                    }
                    if (!empty($gateway->description)) {
                        $count += self::register_single_string($gateway->description, 'payment_gateway_desc', '', 'woocommerce');
                    }
                    if (!empty($gateway->instructions)) {
                        $count += self::register_single_string($gateway->instructions, 'payment_gateway_instructions', '', 'woocommerce');
                    }
                }
            }
        }

        // 3) 运送方式标题（各配送区域实例）
        if (class_exists('WC_Shipping_Zones')) {
            $zones = WC_Shipping_Zones::get_zones();
            if (!empty($zones) && is_array($zones)) {
                foreach ($zones as $zone_data) {
                    if (empty($zone_data['shipping_methods']) || !is_array($zone_data['shipping_methods'])) {
                        continue;
                    }
                    foreach ($zone_data['shipping_methods'] as $method) {
                        if (is_object($method)) {
                            if (method_exists($method, 'get_title')) {
                                $title = $method->get_title();
                                if (!empty($title)) {
                                    $count += self::register_single_string($title, 'shipping_method_title', '', 'woocommerce');
                                }
                            }
                            if (method_exists($method, 'get_method_title')) {
                                $method_title = $method->get_method_title();
                                if (!empty($method_title)) {
                                    $count += self::register_single_string($method_title, 'shipping_method_label', '', 'woocommerce');
                                }
                            }
                        }
                    }
                }
            }

            // 兜底：默认区域
            $default_zone = new WC_Shipping_Zone(0);
            $default_methods = $default_zone->get_shipping_methods();
            if (!empty($default_methods) && is_array($default_methods)) {
                foreach ($default_methods as $method) {
                    if (is_object($method) && method_exists($method, 'get_title')) {
                        $title = $method->get_title();
                        if (!empty($title)) {
                            $count += self::register_single_string($title, 'shipping_method_title', '', 'woocommerce');
                        }
                    }
                }
            }
        }

        return $count;
    }
}
