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
        add_action('wp_ajax_fanyi2_get_pretranslate_progress', array(__CLASS__, 'get_pretranslate_progress'));

        // 扫描站点
        add_action('wp_ajax_fanyi2_scan_site', array(__CLASS__, 'scan_site'));

        // 获取字符串详情
        add_action('wp_ajax_fanyi2_get_string_detail', array(__CLASS__, 'get_string_detail'));

        // 更新翻译（编辑弹窗保存）
        add_action('wp_ajax_fanyi2_update_translation', array(__CLASS__, 'update_translation'));

        // 清除翻译（仅删除翻译，保留字符串）
        add_action('wp_ajax_fanyi2_clear_translations', array(__CLASS__, 'clear_translations'));

        // 测试API连接
        add_action('wp_ajax_fanyi2_test_api', array(__CLASS__, 'test_api'));

        // 保存设置
        add_action('wp_ajax_fanyi2_save_settings', array(__CLASS__, 'save_settings'));
        add_action('wp_ajax_fanyi2_set_force_default_output', array(__CLASS__, 'set_force_default_output'));
        add_action('wp_ajax_fanyi2_apply_default_language_cleanup', array(__CLASS__, 'apply_default_language_cleanup'));
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
     * 规范化每批数量（提速模式支持更大批次）
     */
    private static function normalize_batch_size($batch_size) {
        $batch_size = intval($batch_size);
        if ($batch_size <= 0) {
            $batch_size = 500;
        }
        return max(50, min(500, $batch_size));
    }

    /**
     * 规范化单次请求轮数（一次 AJAX 内连续处理多轮，减少往返）
     */
    private static function normalize_rounds_per_request($rounds) {
        $rounds = intval($rounds);
        if ($rounds <= 0) {
            $rounds = 3;
        }
        return max(1, min(10, $rounds));
    }

    /**
     * 规范化整站翻译范围
     */
    private static function normalize_translation_scope($scope) {
        return Fanyi2_Database::normalize_translation_scope($scope);
    }

    /**
     * 构建整站翻译进度 key（语言 + 范围）
     */
    private static function build_pretranslate_progress_key($language, $scope = 'all') {
        $canonical_lang = Fanyi2_Database::resolve_translation_language($language);
        $scope = self::normalize_translation_scope($scope);
        return $canonical_lang . '::' . $scope;
    }

    /**
     * 规范化并截断警告列表，避免响应过大
     */
    private static function summarize_warnings($warnings, $max_items = 20) {
        if (!is_array($warnings) || empty($warnings)) {
            return array();
        }

        $max_items = max(1, intval($max_items));
        $seen = array();
        $clean = array();
        foreach ($warnings as $warning) {
            $warning = trim((string) $warning);
            if ($warning === '') {
                continue;
            }

            if (!isset($seen[$warning])) {
                $clean[] = $warning;
                $seen[$warning] = true;
            }
        }

        if (count($clean) > $max_items) {
            $clean = array_slice($clean, 0, $max_items);
            $clean[] = '更多警告已省略...';
        }

        return $clean;
    }

    /**
     * 根据每批数量动态决定 AI 子分块大小（防止大批量卡住）
     */
    private static function get_ai_chunk_size($batch_size) {
        $batch_size = intval($batch_size);
        if ($batch_size >= 300) {
            return 80;
        }
        if ($batch_size >= 150) {
            return 70;
        }
        if ($batch_size >= 80) {
            return 60;
        }
        return max(20, min(60, $batch_size));
    }

    /**
     * 并行请求数（按批量规模动态放大）
     */
    private static function get_parallel_concurrency($batch_size) {
        $batch_size = intval($batch_size);
        if ($batch_size >= 300) {
            return 16;
        }
        if ($batch_size >= 150) {
            return 12;
        }
        return 8;
    }

    /**
     * 默认语言统一模式下，判断文本是否可直接复用（避免无意义 AI 调用）
     */
    private static function can_reuse_original_for_default($text, $target_language) {
        $text = trim((string) $text);
        if ($text === '' || !preg_match('/[\p{L}]/u', $text)) {
            return false;
        }

        // 默认语言是英语时，纯拉丁文本直接复用可显著提速
        if ($target_language === 'en') {
            return preg_match('/^[\p{Latin}\p{N}\s\p{P}]+$/u', $text) === 1;
        }

        return false;
    }

    /**
     * 判断文本是否像 CSS/JS 噪声
     */
    private static function looks_like_code_noise($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        if (preg_match('/(?:admin-bar-inline-css|wpadminbar|sourceURL=)/i', $text)) {
            return true;
        }

        if (preg_match('/@media\\s+[^{]+\\{|\\{[^}]*margin-top:\\s*32px\\s*!important;?[^}]*\\}/i', $text)) {
            return true;
        }

        if (preg_match('/<\\/?\\s*(script|style|noscript|template)\\b/i', $text)) {
            return true;
        }

        if (preg_match('/\\b(?:margin|padding|display|position|background|font-size|line-height|z-index|border)\\s*:\\s*[^;{}]+;/i', $text)
            && (strpos($text, '{') !== false || strpos($text, '}') !== false || substr_count($text, ';') >= 2)) {
            return true;
        }

        return false;
    }

    /**
     * 是否应拒绝 AI 译文写库（译文像代码噪声而原文不是）
     */
    private static function should_reject_ai_translation($original, $translated) {
        $original = trim((string) $original);
        $translated = trim((string) $translated);
        if ($original === '' || $translated === '' || $original === $translated) {
            return false;
        }

        if (!self::looks_like_code_noise($translated)) {
            return false;
        }

        return !self::looks_like_code_noise($original);
    }

    /**
     * 批量翻译并兜底缺失项（批量失败/返回不完整时降级单条）
     */
    private static function translate_batch_with_fallback($texts_for_ai, $language, $source_language) {
        $translations = array();
        $warnings = array();

        if (empty($texts_for_ai) || !is_array($texts_for_ai)) {
            return array(
                'translations' => array(),
                'warnings'     => array(),
            );
        }

        $batch_results = Fanyi2_AI_Engine::translate_batch($texts_for_ai, $language, $source_language);

        if (is_wp_error($batch_results)) {
            $warnings[] = '批量翻译失败，已切换单条兜底：' . $batch_results->get_error_message();
            $batch_results = array();
        } elseif (!is_array($batch_results)) {
            $warnings[] = '批量翻译返回格式异常，已切换单条兜底';
            $batch_results = array();
        }

        foreach ($batch_results as $string_id => $translated) {
            if (!isset($texts_for_ai[$string_id])) {
                continue;
            }

            $translated = trim((string) $translated);
            if ($translated !== '') {
                $translations[$string_id] = $translated;
            }
        }

        $missing_ids = array_values(array_diff(array_keys($texts_for_ai), array_keys($translations)));
        if (!empty($missing_ids)) {
            $warnings[] = sprintf(
                '批量返回不完整（期望 %d 条，实际 %d 条），缺失项将单条补译',
                count($texts_for_ai),
                count($translations)
            );

            $max_single_retry = 3;
            if (count($missing_ids) > $max_single_retry) {
                $warnings[] = sprintf('缺失 %d 条，仅单条重试前 %d 条，其余留到下一轮', count($missing_ids), $max_single_retry);
                $missing_ids = array_slice($missing_ids, 0, $max_single_retry);
            }

            foreach ($missing_ids as $string_id) {
                if (!isset($texts_for_ai[$string_id])) {
                    continue;
                }

                $single = Fanyi2_AI_Engine::translate($texts_for_ai[$string_id], $language, $source_language);
                if (is_wp_error($single)) {
                    $warnings[] = sprintf('单条补译失败（ID %d）：%s', intval($string_id), $single->get_error_message());
                    continue;
                }

                $single = trim((string) $single);
                if ($single !== '') {
                    $translations[$string_id] = $single;
                }
            }
        }

        return array(
            'translations' => $translations,
            'warnings'     => $warnings,
        );
    }

    /**
     * 持久化整站翻译进度（中途退出可恢复查看）
     */
    private static function persist_pretranslate_progress($language, $payload, $scope = 'all') {
        $canonical_lang = Fanyi2_Database::resolve_translation_language($language);
        $scope = self::normalize_translation_scope($scope);
        $progress_key = self::build_pretranslate_progress_key($canonical_lang, $scope);
        $all_progress = get_option('fanyi2_pretranslate_progress', array());
        if (!is_array($all_progress)) {
            $all_progress = array();
        }

        $all_progress[$progress_key] = array_merge(array(
            'language'   => $canonical_lang,
            'scope'      => $scope,
            'updated_at' => current_time('mysql'),
        ), $payload);

        update_option('fanyi2_pretranslate_progress', $all_progress, false);
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
        setcookie('fanyi2_user_selected', '1', time() + (365 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
        setcookie('fanyi2_manual_lang', $language, time() + (365 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);

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
            $text = isset($item['text']) ? trim(wp_strip_all_tags(wp_unslash($item['text']))) : '';
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

        $original_text = isset($_POST['original_text']) ? trim(wp_strip_all_tags(wp_unslash($_POST['original_text']))) : '';
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

        // 清除翻译缓存
        Fanyi2_Translator::clear_translation_cache($language);

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
            $original = isset($item['original']) ? trim(wp_strip_all_tags(wp_unslash($item['original']))) : '';
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

        // 清除翻译缓存
        Fanyi2_Translator::clear_translation_cache($language);

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
        // 清除所有语言的缓存
        Fanyi2_Translator::clear_translation_cache();
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
        $scope = self::normalize_translation_scope(isset($_POST['scope']) ? sanitize_text_field($_POST['scope']) : 'all');
        $batch_size = self::normalize_batch_size(isset($_POST['batch_size']) ? $_POST['batch_size'] : 0);
        $rounds_per_request = self::normalize_rounds_per_request(isset($_POST['rounds_per_request']) ? $_POST['rounds_per_request'] : 3);
        $force_default_mode = !empty($_POST['force_default_mode']) && sanitize_text_field($_POST['force_default_mode']) === '1';
        $default_language = get_option('fanyi2_default_language', 'zh');

        if (empty($language)) {
            wp_send_json_error(array('message' => '请选择目标语言'));
        }

        if ($force_default_mode && $language !== $default_language) {
            wp_send_json_error(array('message' => '默认语言清洗模式仅允许翻译到默认语言'));
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        $source_language = $force_default_mode ? 'auto' : $default_language;
        $total_strings = Fanyi2_Database::count_active_strings($scope);

        // 大批量时自动降低单请求轮数（并行处理后每轮吞吐更高，但仍需控制总时长）
        $round_cap = 8;
        $rounds_per_request = min($rounds_per_request, $round_cap);

        $request_started_at = microtime(true);
        $max_request_seconds = 55;
        $max_items_per_request = min(1500, max(800, $batch_size * 4));
        $query_limit = $max_items_per_request;
        $ai_chunk_size = self::get_ai_chunk_size($batch_size);
        $parallel_concurrency = self::get_parallel_concurrency($batch_size);
        $attempted_ai_items = 0;
        $stop_processing = false;

        // 翻译记忆复用：规范化原文一致则直接沿用已有译文，减少AI调用
        $memory_map = Fanyi2_Database::get_translation_memory_map($language);
        $saved_from_memory = 0;
        $saved_from_ai = 0;
        $warnings = array();
        $round_count = 0;

        for ($round = 0; $round < $rounds_per_request; $round++) {
            if ((microtime(true) - $request_started_at) >= $max_request_seconds) {
                $warnings[] = '达到单次请求时间上限，自动进入下一轮';
                break;
            }

            $round_count++;
            $untranslated = Fanyi2_Database::get_untranslated_strings($language, $query_limit, $scope);

            if (empty($untranslated)) {
                break;
            }

            $texts_for_ai = array();
            $duplicate_ids = array();
            $normalized_seed_map = array();
            $translated_in_round = 0;
            $round_translated_texts = array();

            foreach ($untranslated as $str) {
                $normalized = Fanyi2_Database::normalize_string($str->original_string);
                if ($normalized !== '' && isset($memory_map[$normalized])) {
                    $saved_id = Fanyi2_Database::save_translation_if_missing($str->id, $language, $memory_map[$normalized], 'memory');
                    if ($saved_id) {
                        $saved_from_memory++;
                        $translated_in_round++;
                    }
                    continue;
                }

                if ($force_default_mode && self::can_reuse_original_for_default($str->original_string, $language)) {
                    $saved_id = Fanyi2_Database::save_translation_if_missing($str->id, $language, $str->original_string, 'memory');
                    if ($saved_id) {
                        $saved_from_memory++;
                        $translated_in_round++;
                        if ($normalized !== '') {
                            $memory_map[$normalized] = $str->original_string;
                        }
                    }
                    continue;
                }

                if ($normalized !== '' && isset($normalized_seed_map[$normalized])) {
                    $seed_id = $normalized_seed_map[$normalized];
                    if (!isset($duplicate_ids[$seed_id])) {
                        $duplicate_ids[$seed_id] = array();
                    }
                    $duplicate_ids[$seed_id][] = (int) $str->id;
                    continue;
                }

                $texts_for_ai[$str->id] = $str->original_string;
                if ($normalized !== '') {
                    $normalized_seed_map[$normalized] = (int) $str->id;
                }
            }

            if (!empty($texts_for_ai)) {
                $chunks = array_chunk($texts_for_ai, $ai_chunk_size, true);

                // 在预算范围内裁剪 chunks
                $budget_chunks = array();
                foreach ($chunks as $chunk) {
                    $remaining_budget = $max_items_per_request - $attempted_ai_items;
                    if ($remaining_budget <= 0) {
                        $warnings[] = sprintf('单次请求已处理 %d 条，剩余条目将在下一轮继续', $max_items_per_request);
                        $stop_processing = true;
                        break;
                    }
                    if (count($chunk) > $remaining_budget) {
                        $chunk = array_slice($chunk, 0, $remaining_budget, true);
                    }
                    $attempted_ai_items += count($chunk);
                    $budget_chunks[] = $chunk;
                }

                // 并行发送所有 chunks（curl_multi），而非逐个顺序等待
                if (!empty($budget_chunks)) {
                    $parallel_results = Fanyi2_AI_Engine::translate_batches_parallel(
                        $budget_chunks, $language, $source_language, null, $parallel_concurrency
                    );

                    $fallback_items = array(); // 收集需要单条补译的项
                    foreach ($parallel_results as $cidx => $presult) {
                        if (!empty($presult['error'])) {
                            $warnings[] = '并行批次失败：' . $presult['error']->get_error_message();
                            // 整个 chunk 失败 → 加入兜底队列
                            if (isset($budget_chunks[$cidx])) {
                                foreach ($budget_chunks[$cidx] as $sid => $txt) {
                                    $fallback_items[$sid] = $txt;
                                }
                            }
                            continue;
                        }

                        foreach ($presult['translations'] as $string_id => $translated) {
                            $translated = trim((string) $translated);
                            if ($translated === '') {
                                continue;
                            }
                            if (isset($texts_for_ai[$string_id]) && self::should_reject_ai_translation($texts_for_ai[$string_id], $translated)) {
                                $warnings[] = sprintf('已跳过疑似异常译文（ID %d）', intval($string_id));
                                continue;
                            }
                            $saved_id = Fanyi2_Database::save_translation_if_missing($string_id, $language, $translated, 'ai');
                            if ($saved_id) {
                                $saved_from_ai++;
                                $translated_in_round++;
                            }
                            $round_translated_texts[(int) $string_id] = $translated;
                            if (isset($texts_for_ai[$string_id])) {
                                $normalized_original = Fanyi2_Database::normalize_string($texts_for_ai[$string_id]);
                                if ($normalized_original !== '') {
                                    $memory_map[$normalized_original] = $translated;
                                }
                            }
                        }

                        // 批量结果不完整的项目加入兜底
                        if (isset($budget_chunks[$cidx])) {
                            $missing = array_diff(array_keys($budget_chunks[$cidx]), array_keys($presult['translations']));
                            foreach ($missing as $sid) {
                                $fallback_items[$sid] = $budget_chunks[$cidx][$sid];
                            }
                        }
                    }

                    // 单条兜底补译（最多 5 条，避免长时间卡住）
                    if (!empty($fallback_items)) {
                        $max_fallback = 5;
                        $fallback_count = 0;
                        $warnings[] = sprintf('有 %d 条批量未返回，单条补译前 %d 条', count($fallback_items), min(count($fallback_items), $max_fallback));
                        foreach ($fallback_items as $sid => $txt) {
                            if ($fallback_count >= $max_fallback) break;
                            if ((microtime(true) - $request_started_at) >= $max_request_seconds) break;
                            $fallback_count++;
                            $single = Fanyi2_AI_Engine::translate($txt, $language, $source_language);
                            if (is_wp_error($single)) continue;
                            $single = trim((string) $single);
                            if ($single === '') continue;
                            if (self::should_reject_ai_translation($txt, $single)) {
                                $warnings[] = sprintf('已跳过疑似异常单条译文（ID %d）', intval($sid));
                                continue;
                            }
                            $saved_id = Fanyi2_Database::save_translation_if_missing($sid, $language, $single, 'ai');
                            if ($saved_id) {
                                $saved_from_ai++;
                                $translated_in_round++;
                            }
                            $round_translated_texts[(int) $sid] = $single;
                        }
                    }
                }
            }

            // 将同轮重复文本复用到其它 string_id，减少下一轮 AI 调用
            if (!empty($duplicate_ids) && !empty($round_translated_texts)) {
                foreach ($duplicate_ids as $seed_id => $dup_list) {
                    $seed_id = (int) $seed_id;
                    if (!isset($round_translated_texts[$seed_id])) {
                        continue;
                    }
                    $seed_translated = $round_translated_texts[$seed_id];
                    foreach ($dup_list as $dup_id) {
                        $saved_id = Fanyi2_Database::save_translation_if_missing((int) $dup_id, $language, $seed_translated, 'memory');
                        if ($saved_id) {
                            $saved_from_memory++;
                            $translated_in_round++;
                        }
                    }
                }
            }

            // 没有新增时终止，避免空转
            if ($translated_in_round <= 0) {
                break;
            }

            if ($stop_processing) {
                break;
            }
        }

        $saved = $saved_from_memory + $saved_from_ai;

        // 清除该语言的翻译缓存
        Fanyi2_Translator::clear_translation_cache($language);

        // 获取剩余未翻译数量（使用 COUNT 查询，而非 LIMIT 1 后 count 数组）
        $remaining = Fanyi2_Database::count_untranslated_strings($language, $scope);
        $translated_total = Fanyi2_Database::count_translated_strings($language, $scope);
        if ($remaining <= 0) {
            $status = 'completed';
        } elseif ($saved > 0) {
            $status = 'running';
        } else {
            $status = !empty($warnings) ? 'error' : 'stalled';
            if (empty($warnings)) {
                $warnings[] = '本轮未产生新译文，可能是模型返回格式异常或该批次文本需重试';
            }
        }

        // 当出现 stalled 时，尝试自动降级为多条单译探测，避免被个别异常文本卡住
        if ($status === 'stalled' && $remaining > 0) {
            $probe_rows = Fanyi2_Database::get_untranslated_strings($language, 8, $scope);
            $probe_saved_any = false;
            $probe_attempted = 0;
            $probe_errors = 0;

            if (!empty($probe_rows)) {
                foreach ($probe_rows as $probe) {
                    if (!isset($probe->id) || !isset($probe->original_string)) {
                        continue;
                    }

                    $probe_attempted++;
                    $probe_result = Fanyi2_AI_Engine::translate($probe->original_string, $language, $source_language);
                    if (is_wp_error($probe_result)) {
                        $probe_errors++;
                        $warnings[] = '自动单条补译失败：' . $probe_result->get_error_message();
                        continue;
                    }

                    $probe_result = trim((string) $probe_result);
                    if ($probe_result === '') {
                        $probe_errors++;
                        $warnings[] = '自动单条补译返回空内容';
                        continue;
                    }

                    $probe_saved = Fanyi2_Database::save_translation_if_missing($probe->id, $language, $probe_result, 'ai');
                    if ($probe_saved) {
                        $saved_from_ai++;
                        $saved++;
                        $probe_saved_any = true;
                        break;
                    }
                }
            }

            if ($probe_saved_any) {
                $warnings[] = '检测到批量未推进，已自动切换单条补译并恢复进度';
                $remaining = Fanyi2_Database::count_untranslated_strings($language, $scope);
                $translated_total = Fanyi2_Database::count_translated_strings($language, $scope);
                $status = ($remaining <= 0) ? 'completed' : 'running';
            } else {
                if ($probe_attempted <= 0) {
                    $warnings[] = '未找到可用于自动补译的待翻译条目';
                    $status = 'error';
                } elseif ($probe_errors > 0) {
                    $warnings[] = '自动补译未恢复，请重试或调小每批数量';
                    $status = 'error';
                } else {
                    $warnings[] = '自动补译未写入，下一轮将继续尝试';
                    $status = 'running';
                }
            }
        }

        $warnings = self::summarize_warnings($warnings, 20);
        $response_data = array(
            'status'                           => $status,
            'scope'                            => $scope,
            'force_default_mode'               => $force_default_mode ? 1 : 0,
            'batch_size'                       => $batch_size,
            'rounds_per_request'               => $rounds_per_request,
            'rounds_executed'                  => $round_count,
            'translated'                       => $saved,
            'translated_by_memory'             => $saved_from_memory,
            'translated_by_ai'                 => $saved_from_ai,
            'translated_total'                 => $translated_total,
            'total_strings'                    => $total_strings,
            'remaining'                        => $remaining,
            'last_batch_translated'            => $saved,
            'last_batch_translated_by_memory'  => $saved_from_memory,
            'last_batch_translated_by_ai'      => $saved_from_ai,
            'warnings'                         => $warnings,
        );

        self::persist_pretranslate_progress($language, $response_data, $scope);

        $message = sprintf('本次处理 %d 条（复用 %d 条，AI翻译 %d 条）', $saved, $saved_from_memory, $saved_from_ai);
        if ($force_default_mode) {
            $message = '默认语言清洗模式：' . $message;
        }
        if (!empty($warnings)) {
            $message .= '；警告：' . implode(' | ', $warnings);
        }
        $response_data['message'] = $message;

        if ($status === 'error') {
            wp_send_json_error($response_data);
        }

        wp_send_json_success($response_data);
    }

    /**
     * 获取整站翻译进度（用于中断后恢复显示）
     */
    public static function get_pretranslate_progress() {
        self::verify_admin();

        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
        $scope = self::normalize_translation_scope(isset($_POST['scope']) ? sanitize_text_field($_POST['scope']) : 'all');
        $all_progress = get_option('fanyi2_pretranslate_progress', array());
        if (!is_array($all_progress)) {
            $all_progress = array();
        }

        if (!empty($language)) {
            $canonical_lang = Fanyi2_Database::resolve_translation_language($language);
            $progress_key = self::build_pretranslate_progress_key($canonical_lang, $scope);
            $progress = isset($all_progress[$progress_key]) ? $all_progress[$progress_key] : null;

            // 兼容历史版本：旧 key 仅按语言存储
            if (!$progress && $scope === 'all' && isset($all_progress[$canonical_lang])) {
                $progress = $all_progress[$canonical_lang];
            }

            wp_send_json_success(array(
                'progress' => $progress,
            ));
        }

        wp_send_json_success(array(
            'progress' => $all_progress,
        ));
    }

    /**
     * 扫描站点（从批量翻译页面调用）
     */
    public static function scan_site() {
        self::verify_admin();

        // 增加执行时间
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        Fanyi2_Batch::scan_site_pages();

        // 返回数据库中实际的活跃字符串总数，而非本次尝试注册的条数
        $actual_total = Fanyi2_Database::count_active_strings('all');

        wp_send_json_success(array(
            'message'     => sprintf('扫描完成！数据库中共有 %d 条待翻译文本', $actual_total),
            'total'       => $actual_total,
        ));
    }

    /**
     * 获取字符串详情及所有翻译
     */
    public static function get_string_detail() {
        self::verify_admin();

        $string_id = isset($_POST['string_id']) ? intval($_POST['string_id']) : 0;
        if ($string_id <= 0) {
            wp_send_json_error(array('message' => '无效的字符串ID'));
        }

        $string = Fanyi2_Database::get_string_with_translations($string_id);

        if (!$string) {
            wp_send_json_error(array('message' => '字符串不存在'));
        }

        $enabled_languages = get_option('fanyi2_enabled_languages', array());
        $default_lang = get_option('fanyi2_default_language', 'zh');
        $language_names = Fanyi2_Frontend::get_language_names();

        $translations = array();
        foreach ($enabled_languages as $lang) {
            if ($lang === $default_lang) continue;
            $translations[$lang] = array(
                'language'    => $lang,
                'lang_name'   => isset($language_names[$lang]) ? $language_names[$lang] : $lang,
                'translated'  => isset($string->translations[$lang]) ? $string->translations[$lang]->translated_string : '',
                'source'      => isset($string->translations[$lang]) ? $string->translations[$lang]->translation_source : '',
            );
        }

        wp_send_json_success(array(
            'id'            => $string->id,
            'original'      => $string->original_string,
            'element_type'  => $string->element_type,
            'page_url'      => $string->page_url,
            'translations'  => $translations,
        ));
    }

    /**
     * 更新翻译（编辑弹窗保存）
     */
    public static function update_translation() {
        self::verify_admin();

        $string_id = isset($_POST['string_id']) ? intval($_POST['string_id']) : 0;
        $translations = isset($_POST['translations']) ? $_POST['translations'] : array();

        if ($string_id <= 0) {
            wp_send_json_error(array('message' => '无效的字符串ID'));
        }

        if (empty($translations) || !is_array($translations)) {
            wp_send_json_error(array('message' => '没有翻译数据'));
        }

        $saved = 0;
        foreach ($translations as $lang => $text) {
            $text = wp_unslash($text);
            if (!empty($text)) {
                Fanyi2_Database::save_translation($string_id, sanitize_text_field($lang), $text, 'manual');
                // 清除对应语言的翻译缓存
                Fanyi2_Translator::clear_translation_cache(sanitize_text_field($lang));
                $saved++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf('已保存 %d 条翻译', $saved),
            'saved'   => $saved,
        ));
    }

    /**
     * 清除字符串的所有翻译（保留字符串本身）
     */
    public static function clear_translations() {
        self::verify_admin();

        $string_id = isset($_POST['string_id']) ? intval($_POST['string_id']) : 0;
        if ($string_id <= 0) {
            wp_send_json_error(array('message' => '无效的字符串ID'));
        }

        Fanyi2_Database::delete_translations_for_string($string_id);
        // 清除所有语言的缓存
        Fanyi2_Translator::clear_translation_cache();
        wp_send_json_success(array('message' => '已清除所有翻译'));
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
     * 将默认语言清洗结果写入数据库（一次性操作）
     */
    public static function apply_default_language_cleanup() {
        self::verify_admin();

        if (function_exists('set_time_limit')) {
            set_time_limit(600);
        }

        $default_language = get_option('fanyi2_default_language', 'zh');
        $replacements = self::get_default_language_cleanup_replacements($default_language);
        if (empty($replacements)) {
            wp_send_json_error(array('message' => '未找到可写入数据库的默认语言清洗结果，请先执行“清洗默认语言”翻译阶段'));
        }

        $search = array_keys($replacements);
        $replace = array_values($replacements);

        $stats = array(
            'candidate_pairs' => count($replacements),
        );
        $stats = array_merge($stats, self::apply_replacements_to_posts($search, $replace));
        $stats = array_merge($stats, self::apply_replacements_to_postmeta($search, $replace));
        $stats = array_merge($stats, self::apply_replacements_to_menu_items($search, $replace));
        $stats = array_merge($stats, self::apply_replacements_to_terms($search, $replace));
        $stats = array_merge($stats, self::apply_replacements_to_termmeta($search, $replace));
        $stats = array_merge($stats, self::apply_replacements_to_options($search, $replace));

        // 清洗为一次性动作，不保留常驻开关状态
        update_option('fanyi2_force_default_output', '0');
        Fanyi2_Translator::clear_translation_cache();

        $message = sprintf(
            '默认语言清洗已写入数据库：内容 %d 条、菜单 %d 条、分类 %d 条、站点设置 %d 项',
            intval($stats['posts_updated']),
            intval($stats['menu_items_updated']),
            intval($stats['terms_updated']),
            intval($stats['options_updated'])
        );

        wp_send_json_success(array(
            'message' => $message,
            'stats'   => $stats,
        ));
    }

    /**
     * 获取默认语言清洗的替换映射（original => translated）
     */
    private static function get_default_language_cleanup_replacements($default_language) {
        global $wpdb;

        $canonical_lang = Fanyi2_Database::resolve_translation_language($default_language);
        $equivalent_languages = Fanyi2_Database::get_equivalent_translation_languages($canonical_lang);
        $table_strings = $wpdb->prefix . Fanyi2_Database::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . Fanyi2_Database::TABLE_TRANSLATIONS;
        $lang_placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));
        $params = array_merge($equivalent_languages, array($canonical_lang));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.original_string, t.translated_string, t.translation_source
             FROM {$table_trans} t
             INNER JOIN {$table_strings} s ON t.string_id = s.id
             WHERE t.language IN ($lang_placeholders)
               AND t.status = 'published'
               AND s.status = 'active'
             ORDER BY CHAR_LENGTH(s.original_string) DESC,
                      CASE WHEN t.language = %s THEN 0 ELSE 1 END,
                      t.updated_at DESC",
            $params
        ));

        if (empty($rows)) {
            return array();
        }

        $map = array();
        foreach ($rows as $row) {
            $original = trim((string) $row->original_string);
            $translated = trim((string) $row->translated_string);
            if (!self::should_include_default_cleanup_pair($original, $translated, $canonical_lang)) {
                continue;
            }
            if (!isset($map[$original])) {
                $map[$original] = $translated;
            }
        }

        if (empty($map)) {
            return array();
        }

        uksort($map, function($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        return $map;
    }

    /**
     * 默认语言清洗映射过滤
     */
    private static function should_include_default_cleanup_pair($original, $translated, $default_language) {
        if ($original === '' || $translated === '') {
            return false;
        }
        if ($original === $translated) {
            return false;
        }
        if (mb_strlen($original) < 2) {
            return false;
        }
        if (!preg_match('/[\p{L}\p{N}]/u', $original)) {
            return false;
        }

        // 默认语言为英语时，仅清洗非拉丁文本，避免误改已有英文文案
        if ($default_language === 'en') {
            return preg_match('/^[\p{Latin}\p{N}\s\p{P}]+$/u', $original) !== 1;
        }

        return true;
    }

    /**
     * 写回公开内容（文章/页面/商品等）
     */
    private static function apply_replacements_to_posts($search, $replace) {
        $public_post_types = get_post_types(array('public' => true), 'names');
        $post_types = array_values(array_diff((array) $public_post_types, array('attachment')));
        if (empty($post_types)) {
            return array(
                'posts_updated' => 0,
                'post_fields_replaced' => 0,
                'post_errors' => 0,
            );
        }

        $post_ids = get_posts(array(
            'post_type'        => $post_types,
            'post_status'      => 'publish',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));

        $updated = 0;
        $replaced_fields = 0;
        $errors = 0;

        foreach ((array) $post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            $title_count = 0;
            $excerpt_count = 0;
            $content_count = 0;

            $new_title = str_replace($search, $replace, (string) $post->post_title, $title_count);
            $new_excerpt = str_replace($search, $replace, (string) $post->post_excerpt, $excerpt_count);
            $new_content = str_replace($search, $replace, (string) $post->post_content, $content_count);

            $total_count = $title_count + $excerpt_count + $content_count;
            if ($total_count <= 0) {
                continue;
            }

            $result = wp_update_post(array(
                'ID'           => $post_id,
                'post_title'   => $new_title,
                'post_excerpt' => $new_excerpt,
                'post_content' => $new_content,
            ), true);

            if (is_wp_error($result)) {
                $errors++;
                continue;
            }

            $updated++;
            $replaced_fields += $total_count;
        }

        return array(
            'posts_updated' => $updated,
            'post_fields_replaced' => $replaced_fields,
            'post_errors' => $errors,
        );
    }

    /**
     * 写回已发布内容的元数据（覆盖 Elementor / 自定义按钮文案等）
     */
    private static function apply_replacements_to_postmeta($search, $replace) {
        global $wpdb;

        $public_post_types = get_post_types(array('public' => true), 'names');
        $post_types = array_values(array_diff((array) $public_post_types, array('attachment')));
        if (empty($post_types)) {
            return array(
                'postmeta_updated' => 0,
                'postmeta_replacements' => 0,
                'postmeta_errors' => 0,
            );
        }

        $post_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $table_posts = $wpdb->posts;
        $table_meta = $wpdb->postmeta;

        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$table_posts}
             WHERE post_status = 'publish'
               AND post_type IN ($post_placeholders)",
            $post_types
        ));

        if (empty($post_ids)) {
            return array(
                'postmeta_updated' => 0,
                'postmeta_replacements' => 0,
                'postmeta_errors' => 0,
            );
        }

        $updated = 0;
        $replaced = 0;
        $errors = 0;

        foreach ($post_ids as $post_id) {
            $meta = get_post_meta($post_id);
            if (empty($meta) || !is_array($meta)) {
                continue;
            }

            foreach ($meta as $meta_key => $values) {
                if (!is_array($values)) {
                    continue;
                }

                foreach ($values as $old_value) {
                    $value = maybe_unserialize($old_value);
                    $replace_count = 0;
                    $new_value = self::recursive_replace_strings($value, $search, $replace, $replace_count);
                    if ($replace_count <= 0) {
                        continue;
                    }

                    $result = update_metadata('post', $post_id, $meta_key, $new_value, $value);
                    if ($result === false) {
                        $errors++;
                        continue;
                    }

                    $updated++;
                    $replaced += $replace_count;
                }
            }
        }

        return array(
            'postmeta_updated' => $updated,
            'postmeta_replacements' => $replaced,
            'postmeta_errors' => $errors,
        );
    }

    /**
     * 写回菜单项标题
     */
    private static function apply_replacements_to_menu_items($search, $replace) {
        $menu_item_ids = get_posts(array(
            'post_type'        => 'nav_menu_item',
            'post_status'      => 'publish',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));

        $updated = 0;
        $replaced = 0;
        $errors = 0;

        foreach ((array) $menu_item_ids as $item_id) {
            $item = get_post($item_id);
            if (!$item) {
                continue;
            }

            $title_count = 0;
            $new_title = str_replace($search, $replace, (string) $item->post_title, $title_count);
            if ($title_count <= 0 || $new_title === $item->post_title) {
                continue;
            }

            $result = wp_update_post(array(
                'ID'         => $item_id,
                'post_title' => $new_title,
            ), true);
            if (is_wp_error($result)) {
                $errors++;
                continue;
            }

            $updated++;
            $replaced += $title_count;
        }

        return array(
            'menu_items_updated' => $updated,
            'menu_item_replacements' => $replaced,
            'menu_item_errors' => $errors,
        );
    }

    /**
     * 写回公开分类法（分类名/描述）
     */
    private static function apply_replacements_to_terms($search, $replace) {
        $taxonomies = get_taxonomies(array('public' => true), 'names');
        if (empty($taxonomies)) {
            return array(
                'terms_updated' => 0,
                'term_replacements' => 0,
                'term_errors' => 0,
            );
        }

        $updated = 0;
        $replaced = 0;
        $errors = 0;

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ));
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $name_count = 0;
                $desc_count = 0;
                $new_name = str_replace($search, $replace, (string) $term->name, $name_count);
                $new_desc = str_replace($search, $replace, (string) $term->description, $desc_count);
                $total_count = $name_count + $desc_count;
                if ($total_count <= 0) {
                    continue;
                }

                $result = wp_update_term($term->term_id, $taxonomy, array(
                    'name'        => $new_name,
                    'description' => $new_desc,
                ));
                if (is_wp_error($result)) {
                    $errors++;
                    continue;
                }

                $updated++;
                $replaced += $total_count;
            }
        }

        return array(
            'terms_updated' => $updated,
            'term_replacements' => $replaced,
            'term_errors' => $errors,
        );
    }

    /**
     * 写回术语元数据
     */
    private static function apply_replacements_to_termmeta($search, $replace) {
        global $wpdb;

        $taxonomies = get_taxonomies(array('public' => true), 'names');
        if (empty($taxonomies)) {
            return array(
                'termmeta_updated' => 0,
                'termmeta_replacements' => 0,
                'termmeta_errors' => 0,
            );
        }

        $terms = get_terms(array(
            'taxonomy'   => $taxonomies,
            'hide_empty' => false,
        ));
        if (is_wp_error($terms) || empty($terms)) {
            return array(
                'termmeta_updated' => 0,
                'termmeta_replacements' => 0,
                'termmeta_errors' => 0,
            );
        }

        $updated = 0;
        $replaced = 0;
        $errors = 0;

        foreach ($terms as $term) {
            $meta = get_term_meta($term->term_id);
            if (empty($meta) || !is_array($meta)) {
                continue;
            }

            foreach ($meta as $meta_key => $values) {
                if (!is_array($values)) {
                    continue;
                }

                foreach ($values as $old_value) {
                    $value = maybe_unserialize($old_value);
                    $replace_count = 0;
                    $new_value = self::recursive_replace_strings($value, $search, $replace, $replace_count);
                    if ($replace_count <= 0) {
                        continue;
                    }

                    $result = update_metadata('term', $term->term_id, $meta_key, $new_value, $value);
                    if ($result === false) {
                        $errors++;
                        continue;
                    }

                    $updated++;
                    $replaced += $replace_count;
                }
            }
        }

        return array(
            'termmeta_updated' => $updated,
            'termmeta_replacements' => $replaced,
            'termmeta_errors' => $errors,
        );
    }

    /**
     * 递归替换字符串（支持数组/对象）
     */
    private static function recursive_replace_strings($value, $search, $replace, &$replace_count) {
        if (is_string($value)) {
            $local_count = 0;
            $new_value = str_replace($search, $replace, $value, $local_count);
            $replace_count += $local_count;
            return $new_value;
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::recursive_replace_strings($v, $search, $replace, $replace_count);
            }
            return $value;
        }

        if (is_object($value)) {
            foreach ($value as $k => $v) {
                $value->$k = self::recursive_replace_strings($v, $search, $replace, $replace_count);
            }
            return $value;
        }

        return $value;
    }

    /**
     * 判断是否跳过某些系统选项
     */
    private static function should_skip_option_for_cleanup($option_name) {
        $name = (string) $option_name;

        if ($name === '' || $name === 'cron' || $name === 'rewrite_rules') {
            return true;
        }

        if (strpos($name, '_transient_') === 0 || strpos($name, '_site_transient_') === 0) {
            return true;
        }

        if (strpos($name, 'fanyi2_') === 0) {
            return true;
        }

        if (strpos($name, 'session_') === 0 || strpos($name, 'wc_session_') === 0) {
            return true;
        }

        return false;
    }

    /**
     * 写回站点设置（深度遍历 options，覆盖页脚/按钮等主题配置）
     */
    private static function apply_replacements_to_options($search, $replace) {
        global $wpdb;

        $table_options = $wpdb->options;
        $rows = $wpdb->get_results("SELECT option_name, option_value FROM {$table_options}");
        if (empty($rows)) {
            return array(
                'options_updated' => 0,
                'option_replacements' => 0,
                'option_errors' => 0,
            );
        }

        $updated = 0;
        $replaced = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $option_name = (string) $row->option_name;
            if (self::should_skip_option_for_cleanup($option_name)) {
                continue;
            }

            $value = maybe_unserialize($row->option_value);
            $replace_count = 0;
            $new_value = self::recursive_replace_strings($value, $search, $replace, $replace_count);
            if ($replace_count <= 0) {
                continue;
            }

            $result = update_option($option_name, $new_value);
            if ($result === false && get_option($option_name) !== $new_value) {
                $errors++;
                continue;
            }

            $updated++;
            $replaced += $replace_count;
        }

        return array(
            'options_updated' => $updated,
            'option_replacements' => $replaced,
            'option_errors' => $errors,
        );
    }

    /**
     * 开启/关闭默认语言清洗模式
     */
    public static function set_force_default_output() {
        self::verify_admin();

        $enabled = !empty($_POST['enabled']) && sanitize_text_field($_POST['enabled']) === '1' ? '1' : '0';
        update_option('fanyi2_force_default_output', $enabled);
        Fanyi2_Translator::clear_translation_cache();

        wp_send_json_success(array(
            'enabled' => $enabled,
            'message' => $enabled === '1' ? '已开启默认语言清洗（仅默认语言页面）' : '已关闭默认语言清洗',
        ));
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
            'fanyi2_language_custom_names',
            'fanyi2_language_custom_flags',
            'fanyi2_hidden_language_flags',
            'fanyi2_country_language_map',
            'fanyi2_ai_engine',
            'fanyi2_deepseek_api_key',
            'fanyi2_deepseek_model',
            'fanyi2_deepseek_api_url',
            'fanyi2_qwen_api_key',
            'fanyi2_qwen_model',
            'fanyi2_qwen_api_url',
            'fanyi2_openai_api_key',
            'fanyi2_openai_model',
            'fanyi2_openai_api_url',
            'fanyi2_claude_api_key',
            'fanyi2_claude_model',
            'fanyi2_claude_api_url',
            'fanyi2_google_api_key',
            'fanyi2_custom_api_key',
            'fanyi2_custom_api_url',
            'fanyi2_custom_model',
            'fanyi2_auto_detect_browser',
            'fanyi2_url_mode',
            'fanyi2_batch_size',
            'fanyi2_switcher_position',
            'fanyi2_switcher_style',
            'fanyi2_switcher_visible',
            'fanyi2_force_default_output',
        );

        $enabled_languages = array();
        if (!empty($settings['fanyi2_enabled_languages']) && is_array($settings['fanyi2_enabled_languages'])) {
            $enabled_languages = array_map('sanitize_text_field', $settings['fanyi2_enabled_languages']);
        } else {
            $enabled_languages = get_option('fanyi2_enabled_languages', array());
        }

        // 复选框组未勾选时不会发送到服务器，需要手动设置默认空数组
        $checkbox_array_options = array('fanyi2_hidden_language_flags');
        foreach ($checkbox_array_options as $opt) {
            if (!isset($settings[$opt])) {
                $settings[$opt] = array();
            }
        }

        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowed_options)) {
                continue;
            }
            $clean_key = sanitize_text_field($key);
            if (is_array($value)) {
                // 数组值（如 enabled_languages）逐项清理
                $value = array_map('sanitize_text_field', $value);

                if ($clean_key === 'fanyi2_country_language_map') {
                    $filtered = array();
                    foreach ($value as $country => $lang) {
                        $country_code = strtoupper(sanitize_text_field($country));
                        $lang_code = sanitize_text_field($lang);
                        if (preg_match('/^[A-Z]{2}$/', $country_code) && !empty($lang_code) && in_array($lang_code, $enabled_languages, true)) {
                            $filtered[$country_code] = $lang_code;
                        }
                    }
                    $value = $filtered;
                }
            } else {
                $value = sanitize_text_field($value);
                if ($clean_key === 'fanyi2_batch_size') {
                    $value = self::normalize_batch_size($value);
                }
            }
            update_option($clean_key, $value);
        }

        // URL模式变更时标记需要刷新重写规则（延迟到下一次 init，让新规则先注册）
        if (isset($settings['fanyi2_url_mode'])) {
            set_transient('fanyi2_flush_rewrite', 1, 60);
        }

        wp_send_json_success(array('message' => '设置已保存'));
    }

}
