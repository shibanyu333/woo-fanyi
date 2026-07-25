<?php
/**
 * 批量预翻译类
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_Batch {

    /**
     * 扫描站点抓取所有文本（直接查询数据库，不依赖 HTTP 请求）
     */
    public static function scan_site_pages($urls = array()) {
        $total_strings = 0;

        // 1. 直接从数据库扫描所有内容（可靠，不依赖 HTTP loopback）
        $total_strings += self::scan_database_content();

        // 2. 抓取真实前台渲染页面，覆盖主题模板、Theme Mod、短代码默认配置等运行期文案。
        $total_strings += self::scan_rendered_frontend_pages($urls);

        // 3. 注册 WooCommerce 通用界面字符串
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

        // ——— 扫描所有前台公开文章类型（不含草稿/回收站）———
        $post_types = self::get_scannable_post_types();
        $paged = 1;
        do {
            $query = new WP_Query(array(
                'post_type'              => $post_types,
                'post_status'            => 'publish',
                'posts_per_page'         => 200,
                'paged'                  => $paged,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ));

            if (empty($query->posts)) {
                break;
            }

            foreach ($query->posts as $post_id) {
                $post = get_post($post_id);
                if (!$post || empty($post->post_type)) {
                    continue;
                }

                $url = get_permalink($post_id);
                if (!is_string($url) || $url === '') {
                    $url = '';
                }

                $domain = self::resolve_post_domain($post, $front_page_id);

                $count += self::register_single_string($post->post_title, 'post_title', $url, $domain);
                $count += self::register_segments_from_string($post->post_excerpt, 'excerpt', $url, $domain);
                $count += self::register_post_content_strings($post->post_content, $url, $domain);
                $count += self::register_post_meta_strings($post_id, $url, $domain);
                $count += self::register_elementor_strings($post_id, $url, $domain);
            }

            wp_reset_postdata();
            $paged++;
        } while (true);

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

        // ——— 所有公开 taxonomy（博客分类、标签、商品分类等）———
        $count += self::register_public_taxonomy_strings();

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
     * 扫描真实渲染后的前台页面。
     *
     * 数据库扫描能覆盖文章/商品/菜单，但主题模板、Customizer 设置默认值、
     * 短代码默认文案等只有在页面渲染后才会出现。这里仅在管理员主动扫描时运行。
     */
    private static function scan_rendered_frontend_pages($urls = array()) {
        $queue = self::build_render_scan_urls($urls);
        if (empty($queue)) {
            return 0;
        }

        $count = 0;
        $scanned = array();
        $queued = array();
        foreach ($queue as $queued_url) {
            $normalized_url = self::normalize_render_scan_url($queued_url);
            if ($normalized_url !== '') {
                $queued[$normalized_url] = true;
            }
        }

        $max_urls = (int) apply_filters('fanyi2_render_scan_max_urls', 80);
        $max_urls = max(1, min(150, $max_urls));
        $default_language = sanitize_key((string) get_option('fanyi2_default_language', 'zh'));
        $language_cookie = sprintf(
            'fanyi2_user_selected=1; fanyi2_manual_lang=%1$s; fanyi2_language=%1$s',
            rawurlencode($default_language)
        );

        while (!empty($queue) && count($scanned) < $max_urls) {
            $url = array_shift($queue);
            $url = self::normalize_render_scan_url($url);
            if ($url === '' || isset($scanned[$url])) {
                continue;
            }
            $scanned[$url] = true;

            foreach (self::get_render_scan_url_variants($url) as $scan_url) {
                $response = null;
                foreach (self::get_render_request_candidates($scan_url) as $candidate) {
                    $args = array(
                        'timeout'     => 10,
                        'redirection' => 2,
                        'sslverify'   => false,
                        'headers'     => array(
                            'User-Agent'      => 'Fanyi2 rendered page scanner',
                            'Accept-Language' => $default_language . ',en;q=0.1',
                            'Cookie'          => $language_cookie,
                        ),
                    );
                    if (!empty($candidate['host_header'])) {
                        $args['headers']['Host'] = $candidate['host_header'];
                    }

                    $response = wp_remote_get($candidate['url'], $args);
                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                        break;
                    }
                }

                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                    continue;
                }

                $html = wp_remote_retrieve_body($response);
                if (!is_string($html) || trim($html) === '') {
                    continue;
                }

                $count += self::register_rendered_html_strings($html, $url, self::infer_rendered_page_domain($url));
                foreach (self::extract_render_scan_links($html, $url) as $linked_url) {
                    if (isset($queued[$linked_url]) || isset($scanned[$linked_url])) {
                        continue;
                    }
                    if ((count($queued) + count($scanned)) >= $max_urls) {
                        break;
                    }
                    $queued[$linked_url] = true;
                    $queue[] = $linked_url;
                }

                // A successful rendered variant is enough for this canonical URL.
                break;
            }
        }

        return $count;
    }

    /**
     * 构建单个页面的渲染扫描变体。
     *
     * 默认语言在子目录模式下通常就是原始 URL。强行追加 ?lang=zh 可能让部分站点
     * 命中不同的 rewrite/query 结果，导致真正首页没有被扫描。
     */
    private static function get_render_scan_url_variants($url) {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return array();
        }

        $variants = array($url => true);
        $url_mode = get_option('fanyi2_url_mode', 'parameter');
        $default_language = get_option('fanyi2_default_language', 'zh');

        if ($url_mode === 'parameter' && is_string($default_language) && $default_language !== '') {
            $variants[add_query_arg('lang', $default_language, $url)] = true;
        }

        return array_keys($variants);
    }

    /**
     * 构建渲染扫描 URL 列表，限制数量避免后台扫描过重。
     */
    private static function build_render_scan_urls($urls = array()) {
        $collected = array();
        $add_url = function($url) use (&$collected) {
            $url = esc_url_raw((string) $url);
            if ($url === '') {
                return;
            }
            $collected[$url] = true;
        };

        if (is_array($urls)) {
            foreach ($urls as $url) {
                $add_url($url);
            }
        }

        $add_url(home_url('/'));

        $menus = wp_get_nav_menus();
        if ($menus) {
            foreach ($menus as $menu) {
                $items = wp_get_nav_menu_items($menu->term_id);
                if (!$items) {
                    continue;
                }
                foreach ($items as $item) {
                    if (!empty($item->url)) {
                        $add_url($item->url);
                    }
                }
            }
        }

        if (function_exists('wc_get_page_permalink')) {
            foreach (array('shop', 'cart', 'checkout', 'myaccount') as $wc_page) {
                $add_url(wc_get_page_permalink($wc_page));
            }
        }

        $page_ids = get_posts(array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'numberposts'      => 20,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));
        foreach ((array) $page_ids as $page_id) {
            $add_url(get_permalink($page_id));
        }

        if (class_exists('WooCommerce')) {
            $product_ids = get_posts(array(
                'post_type'        => 'product',
                'post_status'      => 'publish',
                'numberposts'      => 12,
                'fields'           => 'ids',
                'suppress_filters' => true,
            ));
            foreach ((array) $product_ids as $product_id) {
                $add_url(get_permalink($product_id));
            }
        }

        $post_ids = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'numberposts'      => 12,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));
        foreach ((array) $post_ids as $post_id) {
            $add_url(get_permalink($post_id));
        }

        $max_urls = (int) apply_filters('fanyi2_render_scan_max_urls', 50);
        $max_urls = max(1, min(100, $max_urls));

        return array_slice(array_keys($collected), 0, $max_urls);
    }

    /**
     * 从已渲染页面继续发现前台链接，覆盖查询参数驱动的分类页/筛选页。
     */
    private static function extract_render_scan_links($html, $base_url) {
        if (!is_string($html) || $html === '') {
            return array();
        }

        $links = array();
        if (!preg_match_all('/<a\b[^>]*\shref\s*=\s*(["\'])(.*?)\1/is', $html, $matches)) {
            return array();
        }

        foreach ($matches[2] as $href) {
            $url = self::normalize_render_scan_url($href, $base_url);
            if ($url !== '') {
                $links[$url] = true;
            }
        }

        return array_keys($links);
    }

    /**
     * 规范化待扫描 URL，并过滤后台、资源、外链、语言切换链接等非内容页。
     */
    private static function normalize_render_scan_url($url, $base_url = '') {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === ''
            || $url[0] === '#'
            || strncasecmp($url, 'javascript:', 11) === 0
            || strncasecmp($url, 'mailto:', 7) === 0
            || strncasecmp($url, 'tel:', 4) === 0
            || strncasecmp($url, 'data:', 5) === 0) {
            return '';
        }

        $home_url = home_url('/');
        $home_parts = wp_parse_url($home_url);
        $home_scheme = isset($home_parts['scheme']) ? $home_parts['scheme'] : 'http';
        $home_host = isset($home_parts['host']) ? strtolower((string) $home_parts['host']) : '';
        $home_port = isset($home_parts['port']) ? intval($home_parts['port']) : 0;

        if (strpos($url, '//') === 0) {
            $url = $home_scheme . ':' . $url;
        } elseif (strpos($url, '/') === 0) {
            $url = rtrim($home_url, '/') . $url;
        } elseif (!preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $url)) {
            $base = $base_url !== '' ? $base_url : $home_url;
            $base_parts = wp_parse_url($base);
            $base_path = isset($base_parts['path']) ? (string) $base_parts['path'] : '/';
            $base_dir = preg_replace('#/[^/]*$#', '/', $base_path);
            $url = rtrim($home_url, '/') . '/' . ltrim($base_dir . $url, '/');
        }

        $url = esc_url_raw($url);
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? intval($parts['port']) : 0;
        if ($host !== $home_host || $port !== $home_port) {
            return '';
        }

        $path = isset($parts['path']) && $parts['path'] !== '' ? (string) $parts['path'] : '/';
        $path_lower = strtolower($path);
        if (preg_match('#/(wp-admin|wp-login\.php|wp-json|wp-content|wp-includes)(/|$)#i', $path)
            || preg_match('#/(?:comments/)?feed/?$#i', rtrim($path, '/') . '/')
            || preg_match('/\.(?:css|js|json|xml|txt|jpg|jpeg|png|gif|webp|svg|ico|pdf|zip|rar|mp4|webm|mp3|woff2?|ttf|eot)$/i', $path_lower)) {
            return '';
        }

        $enabled = get_option('fanyi2_enabled_languages', array());
        $default_lang = get_option('fanyi2_default_language', 'zh');
        $segments = explode('/', ltrim($path, '/'));
        $first_segment = isset($segments[0]) ? sanitize_key($segments[0]) : '';
        if ($first_segment !== '' && in_array($first_segment, $enabled, true) && $first_segment !== $default_lang) {
            return '';
        }

        $query = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (array_keys($query) as $key) {
                if (preg_match('/^(?:replytocom|add-to-cart|remove_item|wc-ajax|preview|customize_changeset_uuid|nonce|_wpnonce|fanyi2_editor)$/i', (string) $key)) {
                    unset($query[$key]);
                }
            }
            if (isset($query['feed'])) {
                return '';
            }
            if (isset($query['lang']) && $query['lang'] !== $default_lang) {
                return '';
            }
            unset($query['lang']);
            ksort($query);
        }

        $normalized = $home_scheme . '://' . $home_host;
        if ($home_port > 0) {
            $normalized .= ':' . $home_port;
        }
        $normalized .= $path;
        if (!empty($query)) {
            $normalized .= '?' . http_build_query($query);
        }

        return $normalized;
    }

    /**
     * Docker/本地端口映射下，容器内访问 localhost:外部端口通常不可达，优先改为 127.0.0.1:80。
     */
    private static function get_render_request_candidates($url) {
        $candidates = array();
        $parsed = wp_parse_url($url);
        $host = isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
        $port = isset($parsed['port']) ? intval($parsed['port']) : 0;
        $path = isset($parsed['path']) && $parsed['path'] !== '' ? (string) $parsed['path'] : '/';
        $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';

        if (in_array($host, array('localhost', '127.0.0.1', '::1'), true) && $port > 0 && !in_array($port, array(80, 443), true)) {
            $host_header = $host;
            if ($port > 0) {
                $host_header .= ':' . $port;
            }
            $candidates[] = array(
                'url'         => 'http://127.0.0.1' . $path . $query,
                'host_header' => $host_header,
            );
        }

        $candidates[] = array(
            'url'         => $url,
            'host_header' => '',
        );

        return $candidates;
    }

    /**
     * 从渲染 HTML 中注册可见文本和常见可见属性。
     */
    private static function register_rendered_html_strings($html, $page_url, $domain) {
        $html = preg_replace('/<\s*(script|style|noscript|template|svg)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', ' ', (string) $html);
        if (!is_string($html) || $html === '') {
            return 0;
        }

        $texts = array();
        preg_match_all('/>([^<]+)</u', $html, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $text) {
                $texts[] = $text;
            }
        }

        preg_match_all('/(?<=\s)(?:content|title|alt|placeholder|aria-label)=["\']([^"\']+)["\']/iu', $html, $attr_matches);
        if (!empty($attr_matches[1])) {
            foreach ($attr_matches[1] as $text) {
                $texts[] = $text;
            }
        }

        $count = 0;
        $seen = array();
        foreach ($texts as $text) {
            $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string) $text);
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
            if ($text === '' || mb_strlen($text) < 2 || mb_strlen($text) > 600) {
                continue;
            }
            if (!preg_match('/[\p{L}]/u', $text)) {
                continue;
            }
            if (filter_var($text, FILTER_VALIDATE_URL) || filter_var($text, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (self::should_skip_scan_text($text)) {
                continue;
            }

            $hash = md5($text);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;

            $count += self::register_single_string($text, 'rendered_html', $page_url, $domain);
        }

        return $count;
    }

    /**
     * 根据 URL 粗略归类渲染扫描文本，便于按范围翻译。
     */
    private static function infer_rendered_page_domain($url) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? strtolower($path) : '';

        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        $home_path = is_string($home_path) ? rtrim(strtolower($home_path), '/') : '';
        $clean_path = rtrim($path, '/');
        if ($clean_path === '' || $clean_path === $home_path) {
            return 'homepage';
        }

        if (strpos($path, '/product/') !== false || strpos($path, '/products') !== false || strpos($path, '/shop') !== false) {
            return 'products';
        }

        if (strpos($path, '/cart') !== false || strpos($path, '/checkout') !== false || strpos($path, '/my-account') !== false) {
            return 'woocommerce';
        }

        return 'general';
    }

    /**
     * 注册单个字符串到数据库
     * 直接调用 get_or_create_string 即可，由其内部决定新建或补全元数据
     */
    private static function register_single_string($text, $element_type = 'text', $page_url = '', $domain = 'general') {
        $text = trim($text);
        if (empty($text) || mb_strlen($text) < 2 || mb_strlen($text) > 1000 || !preg_match('/[\p{L}]/u', $text)) {
            return 0;
        }
        if (self::should_skip_scan_text($text)) {
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
     * 获取可扫描的公开文章类型（排除系统类型）
     */
    private static function get_scannable_post_types() {
        $post_types = get_post_types(array('public' => true), 'names');
        if (!is_array($post_types)) {
            return array('post', 'page');
        }

        $excluded = array(
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_global_styles',
            'wp_navigation',
            'wp_template',
            'wp_template_part',
        );

        $post_types = array_values(array_diff($post_types, $excluded));
        if (empty($post_types)) {
            return array('post', 'page');
        }

        return $post_types;
    }

    /**
     * 解析文章所属 domain（用于范围翻译）
     */
    private static function resolve_post_domain($post, $front_page_id = 0) {
        if (!is_object($post) || empty($post->post_type)) {
            return 'general';
        }

        if ($post->post_type === 'product') {
            return 'products';
        }

        if ((int) $front_page_id > 0 && (int) $post->ID === (int) $front_page_id) {
            return 'homepage';
        }

        return 'general';
    }

    /**
     * 从文本中拆分可翻译片段并入库
     */
    private static function register_segments_from_string($text, $element_type, $page_url, $domain) {
        if (!is_string($text) || trim($text) === '') {
            return 0;
        }

        $text = preg_replace('/<\s*(script|style|noscript|template)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', ' ', (string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<!--.*?-->/s', ' ', $text);
        $text = strip_shortcodes($text);
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string) $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        $text = trim((string) $text);

        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/[\r\n]+|(?<=[。！？!?；;])\s+/u', $text);
        if (!is_array($parts) || empty($parts)) {
            $parts = array($text);
        }

        $count = 0;
        $seen = array();
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || mb_strlen($part) < 2 || mb_strlen($part) > 1000) {
                continue;
            }
            if (!preg_match('/[\p{L}]/u', $part)) {
                continue;
            }
            if (self::should_skip_scan_text($part)) {
                continue;
            }
            if (filter_var($part, FILTER_VALIDATE_URL) || filter_var($part, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (preg_match('/^[\d\W_]+$/u', $part)) {
                continue;
            }

            $hash = md5($part);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $count += self::register_single_string($part, $element_type, $page_url, $domain);
        }

        return $count;
    }

    /**
     * 扫描阶段过滤：跳过 CSS/JS/admin-bar 噪声文本
     */
    private static function should_skip_scan_text($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return true;
        }

        if (preg_match('/(?:admin-bar-inline-css|wpadminbar|sourceURL=)/i', $text)) {
            return true;
        }

        if (preg_match('/(?:».*\bFeed|\bComments Feed)$/i', $text)) {
            return true;
        }

        if (preg_match('/^(?:width=device-width(?:,\s*)?)?initial-scale=|^width=device-width\b|^max-image-preview:|^(?:index|noindex|follow|nofollow)(?:\s*,\s*(?:index|noindex|follow|nofollow))*$/i', $text)) {
            return true;
        }

        if (preg_match('/@media\s+[^{]+\{|\{[^}]*margin-top:\s*32px\s*!important;?[^}]*\}/i', $text)) {
            return true;
        }

        if (preg_match('/\b(?:margin|padding|display|position|background|font-size|line-height|z-index|border)\s*:\s*[^;{}]+;/i', $text)
            && (strpos($text, '{') !== false || strpos($text, '}') !== false || substr_count($text, ';') >= 2)) {
            return true;
        }

        return false;
    }

    /**
     * 扫描 post_content（含块编辑器结构）
     */
    private static function register_post_content_strings($content, $page_url, $domain) {
        $count = 0;
        $count += self::register_segments_from_string((string) $content, 'content', $page_url, $domain);

        if (!function_exists('parse_blocks')) {
            return $count;
        }

        $blocks = parse_blocks((string) $content);
        if (!is_array($blocks) || empty($blocks)) {
            return $count;
        }

        foreach ($blocks as $block) {
            $count += self::register_structured_value($block, 'content_block', $page_url, $domain);
        }

        return $count;
    }

    /**
     * 扫描构建器（Elementor）JSON 文本
     */
    private static function register_elementor_strings($post_id, $page_url, $domain) {
        $count = 0;
        $elementor_keys = array('_elementor_data', '_elementor_page_settings');
        foreach ($elementor_keys as $meta_key) {
            $raw = get_post_meta($post_id, $meta_key, true);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $count += self::register_structured_value($decoded, 'elementor', $page_url, $domain);
            } else {
                $count += self::register_segments_from_string($raw, 'elementor', $page_url, $domain);
            }
        }
        return $count;
    }

    /**
     * 扫描文章 meta（覆盖常见前端可见自定义字段）
     */
    private static function register_post_meta_strings($post_id, $page_url, $domain) {
        $count = 0;
        $meta = get_post_meta($post_id);
        if (!is_array($meta) || empty($meta)) {
            return 0;
        }

        foreach ($meta as $meta_key => $values) {
            $meta_key = (string) $meta_key;
            // 跳过绝大多数内部字段，仅保留少量构建器字段
            if (strpos($meta_key, '_') === 0 && !in_array($meta_key, array('_elementor_data', '_elementor_page_settings'), true)) {
                continue;
            }

            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                $count += self::register_structured_value($value, 'post_meta', $page_url, $domain);
            }
        }

        return $count;
    }

    /**
     * 递归扫描数组/JSON/序列化中的字符串值
     */
    private static function register_structured_value($value, $element_type, $page_url, $domain, $depth = 0) {
        if ($depth > 8) {
            return 0;
        }

        $count = 0;

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return 0;
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return self::register_structured_value($decoded, $element_type, $page_url, $domain, $depth + 1);
            }

            $unserialized = maybe_unserialize($trimmed);
            if ($unserialized !== $trimmed) {
                return self::register_structured_value($unserialized, $element_type, $page_url, $domain, $depth + 1);
            }

            return self::register_segments_from_string($trimmed, $element_type, $page_url, $domain);
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k) && preg_match('/^(id|url|href|src|class|css|style|slug|guid|key|nonce)$/i', $k)) {
                    continue;
                }
                $count += self::register_structured_value($v, $element_type, $page_url, $domain, $depth + 1);
            }
            return $count;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $k => $v) {
                if (is_string($k) && preg_match('/^(id|url|href|src|class|css|style|slug|guid|key|nonce)$/i', $k)) {
                    continue;
                }
                $count += self::register_structured_value($v, $element_type, $page_url, $domain, $depth + 1);
            }
        }

        return $count;
    }

    /**
     * 扫描所有公开 taxonomy（分类、标签、商品分类、属性等）
     */
    private static function register_public_taxonomy_strings() {
        $count = 0;
        $taxonomies = get_taxonomies(array('public' => true), 'names');
        if (!is_array($taxonomies) || empty($taxonomies)) {
            return 0;
        }

        $excluded = array('nav_menu', 'link_category', 'post_format');
        $taxonomies = array_values(array_diff($taxonomies, $excluded));

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ));
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            $domain = (strpos($taxonomy, 'product_') === 0 || strpos($taxonomy, 'pa_') === 0) ? 'products' : 'general';
            foreach ($terms as $term) {
                if (!is_object($term)) {
                    continue;
                }
                $term_url = get_term_link($term);
                if (is_wp_error($term_url)) {
                    $term_url = '';
                }
                $count += self::register_single_string($term->name, $taxonomy, $term_url, $domain);
                if (!empty($term->description)) {
                    $count += self::register_segments_from_string($term->description, $taxonomy . '_desc', $term_url, $domain);
                }
            }
        }

        return $count;
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
