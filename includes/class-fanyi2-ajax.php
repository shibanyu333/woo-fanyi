<?php
/**
 * AJAX处理类
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_Ajax {

    /**
     * 初始化
     */
    public static function init() {
        // 前端语言切换
        add_action('wp_ajax_fanyi2_switch_language', array(__CLASS__, 'switch_language'));
        add_action('wp_ajax_nopriv_fanyi2_switch_language', array(__CLASS__, 'switch_language'));

        // 翻译操作（仅管理员）
        add_action('wp_ajax_fanyi2_grab_page_strings', array(__CLASS__, 'grab_page_strings'));
        add_action('wp_ajax_fanyi2_translate_single', array(__CLASS__, 'translate_single'));
        add_action('wp_ajax_fanyi2_translate_batch', array(__CLASS__, 'translate_batch'));
        add_action('wp_ajax_fanyi2_save_translation', array(__CLASS__, 'save_translation'));
        add_action('wp_ajax_fanyi2_save_translations_batch', array(__CLASS__, 'save_translations_batch'));
        add_action('wp_ajax_fanyi2_get_page_translations', array(__CLASS__, 'get_page_translations'));
        add_action('wp_ajax_fanyi2_delete_string', array(__CLASS__, 'delete_string'));
        add_action('wp_ajax_fanyi2_get_stats', array(__CLASS__, 'get_stats'));

        // 批量预翻译
        add_action('wp_ajax_fanyi2_batch_pretranslate', array(__CLASS__, 'batch_pretranslate'));

        // 测试API连接
        add_action('wp_ajax_fanyi2_test_api', array(__CLASS__, 'test_api'));

        // 保存设置
        add_action('wp_ajax_fanyi2_save_settings', array(__CLASS__, 'save_settings'));
    }

    /**
     * 验证管理员权限
     */
    private static function verify_admin() {
        if (!check_ajax_referer('fanyi2_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => '安全验证失败'));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
    }

    /**
     * 切换语言
     */
    public static function switch_language() {
        check_ajax_referer('fanyi2_nonce', 'nonce');

        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
        $enabled = get_option('fanyi2_enabled_languages', array());

        if (!in_array($language, $enabled)) {
            wp_send_json_error(array('message' => '不支持的语言'));
        }

        setcookie('fanyi2_language', $language, time() + (365 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);

        wp_send_json_success(array(
            'language' => $language,
            'message'  => '语言已切换',
        ));
    }

    /**
     * 抓取页面字符串
     */
    public static function grab_page_strings() {
        self::verify_admin();

        $strings = isset($_POST['strings']) ? $_POST['strings'] : array();
        $page_url = isset($_POST['page_url']) ? sanitize_url($_POST['page_url']) : '';

        if (empty($strings) || !is_array($strings)) {
            wp_send_json_error(array('message' => '没有找到文本'));
        }

        $saved = array();
        foreach ($strings as $item) {
            $text = isset($item['text']) ? sanitize_text_field(wp_unslash($item['text'])) : '';
            $selector = isset($item['selector']) ? sanitize_text_field($item['selector']) : '';
            $element_type = isset($item['type']) ? sanitize_text_field($item['type']) : 'text';

            if (empty($text) || mb_strlen($text) < 2) {
                continue;
            }

            $string_obj = Fanyi2_Database::get_or_create_string($text, array(
                'page_url'     => $page_url,
                'selector'     => $selector,
                'element_type' => $element_type,
            ));

            if ($string_obj) {
                $saved[] = array(
                    'id'       => $string_obj->id,
                    'text'     => $string_obj->original_string,
                    'selector' => $selector,
                );
            }
        }

        wp_send_json_success(array(
            'saved_count' => count($saved),
            'strings'     => $saved,
            'message'     => sprintf('成功抓取 %d 条文本', count($saved)),
        ));
    }

    /**
     * 单条AI翻译
     */
    public static function translate_single() {
        self::verify_admin();

        $text = isset($_POST['text']) ? wp_unslash($_POST['text']) : '';
        $target_language = isset($_POST['target_language']) ? sanitize_text_field($_POST['target_language']) : 'en';
        $source_language = get_option('fanyi2_default_language', 'zh');

        if (empty($text)) {
            wp_send_json_error(array('message' => '文本为空'));
        }

        $result = Fanyi2_AI_Engine::translate($text, $target_language, $source_language);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'translated' => $result,
            'source'     => $text,
            'language'   => $target_language,
        ));
    }

    /**
     * 批量AI翻译
     */
    public static function translate_batch() {
        self::verify_admin();

        $texts = isset($_POST['texts']) ? wp_unslash($_POST['texts']) : array();
        $target_language = isset($_POST['target_language']) ? sanitize_text_field($_POST['target_language']) : 'en';
        $source_language = get_option('fanyi2_default_language', 'zh');

        if (empty($texts) || !is_array($texts)) {
            wp_send_json_error(array('message' => '没有要翻译的文本'));
        }

        $result = Fanyi2_AI_Engine::translate_batch($texts, $target_language, $source_language);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'translations' => $result,
            'count'        => count($result),
        ));
    }

    /**
     * 保存单条翻译
     */
    public static function save_translation() {
        self::verify_admin();

        $original_text = isset($_POST['original_text']) ? wp_unslash($_POST['original_text']) : '';
        $translated_text = isset($_POST['translated_text']) ? wp_unslash($_POST['translated_text']) : '';
        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
        $page_url = isset($_POST['page_url']) ? sanitize_url($_POST['page_url']) : '';
        $selector = isset($_POST['selector']) ? sanitize_text_field($_POST['selector']) : '';

        if (empty($original_text) || empty($translated_text) || empty($language)) {
            wp_send_json_error(array('message' => '参数不完整'));
        }

        $string_obj = Fanyi2_Database::get_or_create_string($original_text, array(
            'page_url' => $page_url,
            'selector' => $selector,
        ));

        if (!$string_obj) {
            wp_send_json_error(array('message' => '字符串保存失败'));
        }

        $trans_id = Fanyi2_Database::save_translation($string_obj->id, $language, $translated_text, 'manual');

        wp_send_json_success(array(
            'translation_id' => $trans_id,
            'message'        => '翻译已保存',
        ));
    }

    /**
     * 批量保存翻译
     */
    public static function save_translations_batch() {
        self::verify_admin();

        $translations = isset($_POST['translations']) ? $_POST['translations'] : array();
        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
        $page_url = isset($_POST['page_url']) ? sanitize_url($_POST['page_url']) : '';

        if (empty($translations) || !is_array($translations) || empty($language)) {
            wp_send_json_error(array('message' => '参数不完整'));
        }

        $saved = 0;
        foreach ($translations as $item) {
            $original = isset($item['original']) ? wp_unslash($item['original']) : '';
            $translated = isset($item['translated']) ? wp_unslash($item['translated']) : '';
            $selector = isset($item['selector']) ? sanitize_text_field($item['selector']) : '';

            if (empty($original) || empty($translated)) {
                continue;
            }

            $string_obj = Fanyi2_Database::get_or_create_string($original, array(
                'page_url' => $page_url,
                'selector' => $selector,
            ));

            if ($string_obj) {
                Fanyi2_Database::save_translation($string_obj->id, $language, $translated, 'ai');
                $saved++;
            }
        }

        wp_send_json_success(array(
            'saved_count' => $saved,
            'message'     => sprintf('成功保存 %d 条翻译', $saved),
        ));
    }

    /**
     * 获取页面翻译
     */
    public static function get_page_translations() {
        check_ajax_referer('fanyi2_nonce', 'nonce');

        $page_url = isset($_POST['page_url']) ? sanitize_url($_POST['page_url']) : '';
        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';

        if (empty($page_url) || empty($language)) {
            wp_send_json_error(array('message' => '参数不完整'));
        }

        $translations = Fanyi2_Database::get_page_translations($page_url, $language);

        wp_send_json_success(array(
            'translations' => $translations,
            'count'        => count($translations),
        ));
    }

    /**
     * 删除字符串
     */
    public static function delete_string() {
        self::verify_admin();

        $string_id = isset($_POST['string_id']) ? intval($_POST['string_id']) : 0;
        if ($string_id <= 0) {
            wp_send_json_error(array('message' => '无效的字符串ID'));
        }

        Fanyi2_Database::delete_string($string_id);
        wp_send_json_success(array('message' => '已删除'));
    }

    /**
     * 获取统计
     */
    public static function get_stats() {
        self::verify_admin();
        $stats = Fanyi2_Database::get_stats();
        wp_send_json_success($stats);
    }

    /**
     * 批量预翻译
     */
    public static function batch_pretranslate() {
        self::verify_admin();

        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;

        if (empty($language)) {
            wp_send_json_error(array('message' => '请选择目标语言'));
        }

        $untranslated = Fanyi2_Database::get_untranslated_strings($language, $batch_size);

        if (empty($untranslated)) {
            wp_send_json_success(array(
                'message'    => '没有需要翻译的字符串',
                'translated' => 0,
                'remaining'  => 0,
            ));
        }

        $texts = array();
        foreach ($untranslated as $str) {
            $texts[$str->id] = $str->original_string;
        }

        $source_language = get_option('fanyi2_default_language', 'zh');
        $results = Fanyi2_AI_Engine::translate_batch($texts, $language, $source_language);

        if (is_wp_error($results)) {
            wp_send_json_error(array('message' => $results->get_error_message()));
        }

        $saved = 0;
        foreach ($results as $string_id => $translated) {
            if (!empty($translated)) {
                Fanyi2_Database::save_translation($string_id, $language, $translated, 'ai');
                $saved++;
            }
        }

        // 获取剩余未翻译数量
        $remaining = count(Fanyi2_Database::get_untranslated_strings($language, 1));

        wp_send_json_success(array(
            'translated' => $saved,
            'remaining'  => $remaining,
            'message'    => sprintf('本批次翻译了 %d 条', $saved),
        ));
    }

    /**
     * 测试API连接
     */
    public static function test_api() {
        self::verify_admin();

        $engine = isset($_POST['engine']) ? sanitize_text_field($_POST['engine']) : '';
        $result = Fanyi2_AI_Engine::test_connection($engine);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    /**
     * 保存设置
     */
    public static function save_settings() {
        self::verify_admin();

        $settings = isset($_POST['settings']) ? $_POST['settings'] : array();

        if (empty($settings) || !is_array($settings)) {
            wp_send_json_error(array('message' => '无效的设置数据'));
        }

        $allowed_options = array(
            'fanyi2_default_language',
            'fanyi2_enabled_languages',
            'fanyi2_ai_engine',
            'fanyi2_deepseek_api_key',
            'fanyi2_deepseek_model',
            'fanyi2_deepseek_api_url',
            'fanyi2_qwen_api_key',
            'fanyi2_qwen_model',
            'fanyi2_qwen_api_url',
            'fanyi2_auto_detect_browser',
            'fanyi2_url_mode',
            'fanyi2_batch_size',
            'fanyi2_switcher_position',
            'fanyi2_switcher_style',
            'fanyi2_switcher_visible',
        );

        foreach ($settings as $key => $value) {
            if (in_array($key, $allowed_options)) {
                update_option(sanitize_text_field($key), $value);
            }
        }

        wp_send_json_success(array('message' => '设置已保存'));
    }

}
