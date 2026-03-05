<?php
/**
 * 翻译器类 - 负责内容输出时替换翻译
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_Translator {

    private static $current_language = null;
    private static $translations_cache = array();

    /**
     * 初始化
     */
    public static function init() {
        $current_lang = Fanyi2_Frontend::get_current_language();
        $default_lang = get_option('fanyi2_default_language', 'zh');

        if ($current_lang !== $default_lang) {
            // 使用输出缓冲来替换翻译
            add_action('template_redirect', array(__CLASS__, 'start_output_buffer'), 0);
        }
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

        // 使用DOMDocument解析HTML并替换文本节点
        return self::translate_html($html, $current_lang);
    }

    /**
     * 翻译HTML内容
     */
    public static function translate_html($html, $target_language) {
        // 提取所有文本内容
        $text_nodes = self::extract_text_from_html($html);
        
        if (empty($text_nodes)) {
            return $html;
        }

        // 批量获取翻译
        $translations = Fanyi2_Database::get_translations_batch($text_nodes, $target_language);

        // 替换翻译
        foreach ($translations as $original => $translated) {
            if (!empty($translated) && $original !== $translated) {
                // 使用精确匹配替换，避免破坏HTML标签
                $html = self::safe_replace($html, $original, $translated);
            }
        }

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

        // 提取meta描述和title等属性中的文本
        preg_match_all('/(?:content|title|alt|placeholder|value)=["\']([^"\']+)["\']/', $html, $attr_matches);
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
     * 安全替换文本，避免破坏HTML标签
     */
    private static function safe_replace($html, $original, $translated) {
        // 只替换标签之间的文本内容
        $escaped_original = preg_quote($original, '/');

        // 替换标签内文本
        $html = preg_replace(
            '/(>)(' . $escaped_original . ')(<)/',
            '${1}' . str_replace('$', '\\$', $translated) . '${3}',
            $html
        );

        // 替换属性中的文本
        $html = preg_replace(
            '/((?:content|title|alt|placeholder)=["\'])(' . $escaped_original . ')(["\'])/',
            '${1}' . str_replace('$', '\\$', $translated) . '${3}',
            $html
        );

        return $html;
    }
}
