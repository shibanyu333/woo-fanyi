<?php
/**
 * 批量预翻译类
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_Batch {

    /**
     * 扫描站点页面抓取所有文本
     */
    public static function scan_site_pages($urls = array()) {
        if (empty($urls)) {
            $urls = self::get_site_urls();
        }

        $total_strings = 0;

        foreach ($urls as $url) {
            $strings = self::scan_page($url);
            $total_strings += count($strings);
        }

        return $total_strings;
    }

    /**
     * 获取站点所有公开URL
     */
    public static function get_site_urls() {
        $urls = array(home_url('/'));

        // 获取所有公开页面
        $pages = get_pages(array('post_status' => 'publish'));
        foreach ($pages as $page) {
            $urls[] = get_permalink($page->ID);
        }

        // 获取所有公开文章（最近50篇）
        $posts = get_posts(array(
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));
        foreach ($posts as $post) {
            $urls[] = get_permalink($post->ID);
        }

        // WooCommerce产品
        if (class_exists('WooCommerce')) {
            $products = get_posts(array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 100,
            ));
            foreach ($products as $product) {
                $urls[] = get_permalink($product->ID);
            }

            // WooCommerce特殊页面
            $wc_pages = array('shop', 'cart', 'checkout', 'myaccount');
            foreach ($wc_pages as $wc_page) {
                $page_id = wc_get_page_id($wc_page);
                if ($page_id > 0) {
                    $urls[] = get_permalink($page_id);
                }
            }
        }

        // 分类和标签页面
        $categories = get_categories(array('hide_empty' => true));
        foreach ($categories as $cat) {
            $urls[] = get_category_link($cat->term_id);
        }

        return array_unique($urls);
    }

    /**
     * 扫描单个页面
     */
    public static function scan_page($url) {
        $response = wp_remote_get($url, array(
            'timeout'   => 30,
            'cookies'   => array(), // 使用默认语言
            'sslverify' => false,
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $html = wp_remote_retrieve_body($response);
        return self::extract_strings_from_html($html, $url);
    }

    /**
     * 从HTML中提取字符串
     */
    public static function extract_strings_from_html($html, $page_url = '') {
        $strings = array();

        // 提取标签间文本
        preg_match_all('/>([^<]+)</', $html, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $text) {
                $text = trim($text);
                if (!empty($text) && mb_strlen($text) >= 2 && preg_match('/[\p{L}]/u', $text)) {
                    // 排除脚本和样式中的内容
                    $string_obj = Fanyi2_Database::get_or_create_string($text, array(
                        'page_url'     => $page_url,
                        'element_type' => 'text',
                    ));
                    if ($string_obj) {
                        $strings[] = $string_obj;
                    }
                }
            }
        }

        // 提取属性文本
        preg_match_all('/(alt|title|placeholder)=["\']([^"\']+)["\']/', $html, $attr_matches);
        if (!empty($attr_matches[2])) {
            foreach ($attr_matches[2] as $i => $text) {
                $text = trim($text);
                if (!empty($text) && mb_strlen($text) >= 2 && preg_match('/[\p{L}]/u', $text)) {
                    $string_obj = Fanyi2_Database::get_or_create_string($text, array(
                        'page_url'     => $page_url,
                        'element_type' => $attr_matches[1][$i],
                    ));
                    if ($string_obj) {
                        $strings[] = $string_obj;
                    }
                }
            }
        }

        // 提取meta内容
        preg_match_all('/<meta[^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html, $meta_matches);
        if (!empty($meta_matches[1])) {
            foreach ($meta_matches[1] as $text) {
                $text = trim($text);
                if (!empty($text) && mb_strlen($text) >= 5 && preg_match('/[\p{L}]/u', $text)) {
                    $string_obj = Fanyi2_Database::get_or_create_string($text, array(
                        'page_url'     => $page_url,
                        'element_type' => 'meta',
                    ));
                    if ($string_obj) {
                        $strings[] = $string_obj;
                    }
                }
            }
        }

        return $strings;
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
                    Fanyi2_Database::save_translation($string_id, $target_language, $translated, 'ai');
                    $total_translated++;
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
}
