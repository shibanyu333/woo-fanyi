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
            $batch_size = intval(get_option('fanyi2_batch_size', 30));
        }
        if ($batch_size <= 0) {
            $batch_size = 30;
        }

        return max(1, min(500, $batch_size));
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
            return 45;
        }
        if ($batch_size >= 150) {
            return 60;
        }
        if ($batch_size >= 80) {
            return 75;
        }
        return max(20, min(80, $batch_size));
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

        if (empty($language)) {
            wp_send_json_error(array('message' => '请选择目标语言'));
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        $source_language = get_option('fanyi2_default_language', 'zh');
        $total_strings = Fanyi2_Database::count_active_strings($scope);

        // 大批量时自动降低单请求轮数，避免 500 卡在一个请求里长时间不返回
        $round_cap = ($batch_size >= 300) ? 1 : (($batch_size >= 120) ? 2 : 3);
        $rounds_per_request = min($rounds_per_request, $round_cap);

        $request_started_at = microtime(true);
        $max_request_seconds = 35;
        $max_items_per_request = max(45, min(140, $batch_size));
        $query_limit = max(20, min($batch_size, $max_items_per_request));
        $ai_chunk_size = self::get_ai_chunk_size($batch_size);
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
            $translated_in_round = 0;

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

                $texts_for_ai[$str->id] = $str->original_string;
            }

            if (!empty($texts_for_ai)) {
                $chunks = array_chunk($texts_for_ai, $ai_chunk_size, true);
                foreach ($chunks as $chunk) {
                    if ((microtime(true) - $request_started_at) >= $max_request_seconds) {
                        $warnings[] = '达到单次请求时间上限，剩余条目将在下一轮继续';
                        $stop_processing = true;
                        break;
                    }

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

                    $chunk_result = self::translate_batch_with_fallback($chunk, $language, $source_language);
                    if (!empty($chunk_result['warnings'])) {
                        $warnings = array_merge($warnings, $chunk_result['warnings']);
                    }

                    foreach ($chunk_result['translations'] as $string_id => $translated) {
                        $saved_id = Fanyi2_Database::save_translation_if_missing($string_id, $language, $translated, 'ai');
                        if ($saved_id) {
                            $saved_from_ai++;
                            $translated_in_round++;
                        }

                        if (isset($chunk[$string_id])) {
                            $normalized_original = Fanyi2_Database::normalize_string($chunk[$string_id]);
                            if ($normalized_original !== '') {
                                $memory_map[$normalized_original] = $translated;
                            }
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

        $total = Fanyi2_Batch::scan_site_pages();

        wp_send_json_success(array(
            'message'     => sprintf('扫描完成！共抓取 %d 条文本', $total),
            'total'       => $total,
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
        );

        $enabled_languages = array();
        if (!empty($settings['fanyi2_enabled_languages']) && is_array($settings['fanyi2_enabled_languages'])) {
            $enabled_languages = array_map('sanitize_text_field', $settings['fanyi2_enabled_languages']);
        } else {
            $enabled_languages = get_option('fanyi2_enabled_languages', array());
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
