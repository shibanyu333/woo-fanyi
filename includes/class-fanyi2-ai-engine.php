<?php
/**
 * AI翻译引擎类 - 支持 DeepSeek、千问、OpenAI/GPT、Claude、Google Translate、自定义OpenAI兼容接口
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_AI_Engine {

    /**
     * 是否自动识别源语言
     */
    private static function is_auto_source_language($source_language) {
        $source_language = strtolower(trim((string) $source_language));
        return in_array($source_language, array('auto', 'detect', 'auto-detect', '*'), true);
    }

    /**
     * 翻译文本
     */
    public static function translate($text, $target_language, $source_language = 'zh', $engine = null) {
        if (empty($text)) {
            return $text;
        }

        if (!self::is_auto_source_language($source_language) && $target_language === $source_language) {
            return $text;
        }

        if ($engine === null) {
            $engine = get_option('fanyi2_ai_engine', 'deepseek');
        }

        switch ($engine) {
            case 'deepseek':
                return self::translate_with_deepseek($text, $target_language, $source_language);
            case 'qwen':
                return self::translate_with_qwen($text, $target_language, $source_language);
            case 'openai':
                return self::translate_with_openai($text, $target_language, $source_language);
            case 'claude':
                return self::translate_with_claude($text, $target_language, $source_language);
            case 'google':
                return self::translate_with_google($text, $target_language, $source_language);
            case 'custom':
                return self::translate_with_custom($text, $target_language, $source_language);
            default:
                return new WP_Error('invalid_engine', __('无效的翻译引擎', 'fanyi2'));
        }
    }

    /**
     * 批量翻译
     */
    public static function translate_batch($texts, $target_language, $source_language = 'zh', $engine = null) {
        if (empty($texts)) {
            return array();
        }

        if (!self::is_auto_source_language($source_language) && $target_language === $source_language) {
            return $texts;
        }

        if ($engine === null) {
            $engine = get_option('fanyi2_ai_engine', 'deepseek');
        }

        // Google Translate 使用自己的批量方式
        if ($engine === 'google') {
            return self::translate_batch_google($texts, $target_language, $source_language);
        }

        // 将文本数组合并为一个请求以减少API调用
        $numbered_texts = array();
        foreach (array_values($texts) as $index => $text) {
            $numbered_texts[] = ($index + 1) . ". " . $text;
        }
        $combined_text = implode("\n", $numbered_texts);

        $prompt = self::build_batch_prompt($combined_text, count($texts), $target_language, $source_language);

        switch ($engine) {
            case 'deepseek':
                $result = self::call_deepseek_api($prompt);
                break;
            case 'qwen':
                $result = self::call_qwen_api($prompt);
                break;
            case 'openai':
                $result = self::call_openai_api($prompt);
                break;
            case 'claude':
                $result = self::call_claude_api($prompt);
                break;
            case 'custom':
                $result = self::call_custom_api($prompt);
                break;
            default:
                return new WP_Error('invalid_engine', __('无效的翻译引擎', 'fanyi2'));
        }

        if (is_wp_error($result)) {
            return $result;
        }

        // 解析批量翻译结果
        return self::parse_batch_result($result, $texts);
    }

    /**
     * 使用DeepSeek翻译
     */
    private static function translate_with_deepseek($text, $target_language, $source_language) {
        $prompt = self::build_prompt($text, $target_language, $source_language);
        $result = self::call_deepseek_api($prompt);

        if (is_wp_error($result)) {
            return $result;
        }

        return trim($result);
    }

    /**
     * 使用千问翻译
     */
    private static function translate_with_qwen($text, $target_language, $source_language) {
        $prompt = self::build_prompt($text, $target_language, $source_language);
        $result = self::call_qwen_api($prompt);

        if (is_wp_error($result)) {
            return $result;
        }

        return trim($result);
    }

    /**
     * 使用 OpenAI/GPT 翻译
     */
    private static function translate_with_openai($text, $target_language, $source_language) {
        $prompt = self::build_prompt($text, $target_language, $source_language);
        $result = self::call_openai_api($prompt);

        if (is_wp_error($result)) {
            return $result;
        }

        return trim($result);
    }

    /**
     * 使用 Claude 翻译
     */
    private static function translate_with_claude($text, $target_language, $source_language) {
        $prompt = self::build_prompt($text, $target_language, $source_language);
        $result = self::call_claude_api($prompt);

        if (is_wp_error($result)) {
            return $result;
        }

        return trim($result);
    }

    /**
     * 使用 Google Translate API 翻译
     */
    private static function translate_with_google($text, $target_language, $source_language) {
        $api_key = get_option('fanyi2_google_api_key', '');
        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('Google Translate API Key 未配置', 'fanyi2'));
        }

        $url = 'https://translation.googleapis.com/language/translate/v2';
        $body = array(
            'key'    => $api_key,
            'q'      => $text,
            'target' => self::get_google_lang_code($target_language),
            'format' => 'html',
        );
        if (!self::is_auto_source_language($source_language)) {
            $body['source'] = self::get_google_lang_code($source_language);
        }

        $response = wp_remote_post($url, array(
            'body' => $body,
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['data']['translations'][0]['translatedText'])) {
            return html_entity_decode($body['data']['translations'][0]['translatedText'], ENT_QUOTES, 'UTF-8');
        }

        $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Google Translate API 返回错误';
        return new WP_Error('google_error', $error_msg);
    }

    /**
     * 使用自定义 OpenAI 兼容接口翻译
     */
    private static function translate_with_custom($text, $target_language, $source_language) {
        $prompt = self::build_prompt($text, $target_language, $source_language);
        $result = self::call_custom_api($prompt);

        if (is_wp_error($result)) {
            return $result;
        }

        return trim($result);
    }

    /**
     * Google Translate 批量翻译
     */
    private static function translate_batch_google($texts, $target_language, $source_language) {
        $api_key = get_option('fanyi2_google_api_key', '');
        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('Google Translate API Key 未配置', 'fanyi2'));
        }

        $url = 'https://translation.googleapis.com/language/translate/v2';
        $q_array = array_values($texts);

        $body = array(
            'key'    => $api_key,
            'target' => self::get_google_lang_code($target_language),
            'format' => 'html',
        );
        if (!self::is_auto_source_language($source_language)) {
            $body['source'] = self::get_google_lang_code($source_language);
        }

        // Google API 允许多个 q 参数
        $post_fields = http_build_query($body);
        foreach ($q_array as $q) {
            $post_fields .= '&q=' . urlencode($q);
        }

        $response = wp_remote_post($url, array(
            'body'    => $post_fields,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $resp_body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($resp_body['data']['translations'])) {
            $error_msg = isset($resp_body['error']['message']) ? $resp_body['error']['message'] : 'Google API 返回错误';
            return new WP_Error('google_error', $error_msg);
        }

        $translations = array();
        $keys = array_keys($texts);
        foreach ($resp_body['data']['translations'] as $index => $t) {
            if (isset($keys[$index])) {
                $translations[$keys[$index]] = html_entity_decode($t['translatedText'], ENT_QUOTES, 'UTF-8');
            }
        }

        return $translations;
    }

    /**
     * 获取 Google Translate 语言代码映射
     */
    private static function get_google_lang_code($code) {
        $map = array(
            'zh' => 'zh-CN',
            'hk' => 'zh-TW',
            'tw' => 'zh-TW',
        );
        return isset($map[$code]) ? $map[$code] : $code;
    }

    /**
     * 构建翻译提示
     */
    private static function build_prompt($text, $target_language, $source_language) {
        $lang_names = self::get_language_full_names();
        $target_name = isset($lang_names[$target_language]) ? $lang_names[$target_language] : $target_language;
        $auto_source = self::is_auto_source_language($source_language);
        $source_name = isset($lang_names[$source_language]) ? $lang_names[$source_language] : $source_language;
        $source_clause = $auto_source
            ? "by automatically detecting the source language into {$target_name}"
            : "from {$source_name} to {$target_name}";

        return "You are a professional translator. Translate the following text {$source_clause}. " .
               "Rules:\n" .
               "1. Only return the translated text, nothing else.\n" .
               "2. Preserve HTML tags, placeholders, and special characters.\n" .
               "3. Keep the same tone and style.\n" .
               "4. Do not add any explanation or notes.\n" .
               "5. If the text contains technical terms or brand names, keep them as-is.\n\n" .
               "Text to translate:\n{$text}";
    }

    /**
     * 构建批量翻译提示
     */
    private static function build_batch_prompt($combined_text, $count, $target_language, $source_language) {
        $lang_names = self::get_language_full_names();
        $target_name = isset($lang_names[$target_language]) ? $lang_names[$target_language] : $target_language;
        $auto_source = self::is_auto_source_language($source_language);
        $source_name = isset($lang_names[$source_language]) ? $lang_names[$source_language] : $source_language;
        $source_clause = $auto_source
            ? "by automatically detecting each source language into {$target_name}"
            : "from {$source_name} to {$target_name}";

        return "You are a professional translator. Translate the following {$count} numbered texts {$source_clause}. " .
               "Rules:\n" .
               "1. Return ONLY the translated texts, each on a new line, keeping the same numbering format (1. xxx\\n2. xxx).\n" .
               "2. Preserve HTML tags, placeholders, and special characters.\n" .
               "3. Keep the same tone and style.\n" .
               "4. Do not add any explanation or notes.\n" .
               "5. If the text contains technical terms or brand names, keep them as-is.\n\n" .
               "Texts to translate:\n{$combined_text}";
    }

    /**
     * 调用DeepSeek API
     */
    private static function call_deepseek_api($prompt) {
        $api_key = get_option('fanyi2_deepseek_api_key', '');
        $api_url = get_option('fanyi2_deepseek_api_url', 'https://api.deepseek.com/v1/chat/completions');
        $model = get_option('fanyi2_deepseek_model', 'deepseek-chat');

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('DeepSeek API Key 未配置', 'fanyi2'));
        }

        return self::call_openai_compatible_api($api_url, $api_key, $model, $prompt);
    }

    /**
     * 调用千问 API
     */
    private static function call_qwen_api($prompt) {
        $api_key = get_option('fanyi2_qwen_api_key', '');
        $api_url = get_option('fanyi2_qwen_api_url', 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions');
        $model = get_option('fanyi2_qwen_model', 'qwen-turbo');

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('千问 API Key 未配置', 'fanyi2'));
        }

        return self::call_openai_compatible_api($api_url, $api_key, $model, $prompt);
    }

    /**
     * 调用 OpenAI API
     */
    private static function call_openai_api($prompt) {
        $api_key = get_option('fanyi2_openai_api_key', '');
        $api_url = get_option('fanyi2_openai_api_url', 'https://api.openai.com/v1/chat/completions');
        $model = get_option('fanyi2_openai_model', 'gpt-4o-mini');

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('OpenAI API Key 未配置', 'fanyi2'));
        }

        return self::call_openai_compatible_api($api_url, $api_key, $model, $prompt);
    }

    /**
     * 调用 Claude API (Anthropic Messages API)
     */
    private static function call_claude_api($prompt) {
        $api_key = get_option('fanyi2_claude_api_key', '');
        $api_url = get_option('fanyi2_claude_api_url', 'https://api.anthropic.com/v1/messages');
        $model = get_option('fanyi2_claude_model', 'claude-sonnet-4-20250514');

        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('Claude API Key 未配置', 'fanyi2'));
        }

        $body = array(
            'model'      => $model,
            'max_tokens' => 4096,
            'messages'   => array(
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
        );

        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body'    => json_encode($body),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_msg = isset($response_body['error']['message'])
                ? $response_body['error']['message']
                : __('Claude API 请求失败', 'fanyi2');
            return new WP_Error('api_error', $error_msg . ' (HTTP ' . $status_code . ')');
        }

        if (isset($response_body['content'][0]['text'])) {
            return $response_body['content'][0]['text'];
        }

        return new WP_Error('invalid_response', __('Claude API 返回格式无效', 'fanyi2'));
    }

    /**
     * 调用自定义 OpenAI 兼容 API
     */
    private static function call_custom_api($prompt) {
        $api_key = get_option('fanyi2_custom_api_key', '');
        $api_url = get_option('fanyi2_custom_api_url', '');
        $model = get_option('fanyi2_custom_model', '');

        if (empty($api_key) || empty($api_url)) {
            return new WP_Error('no_api_key', __('自定义 API 未配置', 'fanyi2'));
        }

        if (empty($model)) {
            $model = 'default';
        }

        return self::call_openai_compatible_api($api_url, $api_key, $model, $prompt);
    }

    /**
     * 调用OpenAI兼容API（DeepSeek、千问、OpenAI、自定义都兼容此格式）
     */
    private static function call_openai_compatible_api($api_url, $api_key, $model, $prompt) {
        $body = array(
            'model'    => $model,
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => 'You are a professional translator. You translate text accurately and naturally while preserving formatting.',
                ),
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
            'temperature' => 0.3,
            'max_tokens'  => 4096,
        );

        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => json_encode($body),
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_msg = isset($response_body['error']['message']) 
                ? $response_body['error']['message'] 
                : __('API 请求失败', 'fanyi2');
            return new WP_Error('api_error', $error_msg . ' (HTTP ' . $status_code . ')');
        }

        if (isset($response_body['choices'][0]['message']['content'])) {
            return $response_body['choices'][0]['message']['content'];
        }

        return new WP_Error('invalid_response', __('API 返回格式无效', 'fanyi2'));
    }

    /**
     * 解析批量翻译结果
     */
    private static function parse_batch_result($result, $original_texts) {
        $translations = array();
        $keys = array_keys($original_texts);
        if (empty($keys)) {
            return $translations;
        }

        $result = preg_replace("/\r\n?/", "\n", (string) $result);
        $result = trim((string) $result);
        if ($result === '') {
            return $translations;
        }

        // 优先按编号块解析，支持多行译文，避免行错位导致串翻
        $matches = array();
        preg_match_all('/^\s*(\d+)[\.\)]\s*(.*?)\s*(?=^\s*\d+[\.\)]\s*|\z)/msu', $result, $matches, PREG_SET_ORDER);
        if (!empty($matches)) {
            foreach ($matches as $match) {
                $number = isset($match[1]) ? intval($match[1]) : 0;
                if ($number < 1 || $number > count($keys)) {
                    continue;
                }

                $text = self::cleanup_batch_line(isset($match[2]) ? $match[2] : '');
                if ($text === '') {
                    continue;
                }

                $key = $keys[$number - 1];
                $original = isset($original_texts[$key]) ? (string) $original_texts[$key] : '';
                if (self::is_suspect_batch_translation($original, $text)) {
                    continue;
                }

                $translations[$key] = $text;
            }

            if (!empty($translations)) {
                return $translations;
            }
        }

        // 兼容兜底：逐行按顺序解析
        $lines = explode("\n", $result);
        $index = 0;
        foreach ($lines as $line) {
            if ($index >= count($keys)) {
                break;
            }

            $line = self::cleanup_batch_line($line);
            if ($line === '') {
                continue;
            }

            $key = $keys[$index];
            $original = isset($original_texts[$key]) ? (string) $original_texts[$key] : '';
            if (self::is_suspect_batch_translation($original, $line)) {
                $index++;
                continue;
            }

            $translations[$key] = $line;
            $index++;
        }

        return $translations;
    }

    /**
     * 清理批量翻译行文本
     */
    private static function cleanup_batch_line($line) {
        $line = trim((string) $line);
        if ($line === '') {
            return '';
        }

        $line = preg_replace('/^\s*\d+[\.|\)]\s*/u', '', $line);
        $line = trim((string) $line);
        if ($line === '') {
            return '';
        }

        // 去除常见代码块包裹
        $line = preg_replace('/^`{1,3}(.*?)`{1,3}$/su', '$1', $line);

        return trim((string) $line);
    }

    /**
     * 判断批量译文是否明显异常（避免 CSS/JS 污染串到普通文本）
     */
    private static function is_suspect_batch_translation($original, $translated) {
        $original = trim((string) $original);
        $translated = trim((string) $translated);
        if ($original === '' || $translated === '' || $original === $translated) {
            return false;
        }

        if (!self::looks_like_css_or_js($translated)) {
            return false;
        }

        return !self::looks_like_css_or_js($original);
    }

    /**
     * 是否看起来像 CSS/JS 代码片段
     */
    private static function looks_like_css_or_js($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        if (preg_match('/<\/?\s*(script|style|noscript)\b/i', $text)) {
            return true;
        }

        if (preg_match('/@media\s+[^{]+\{/i', $text)) {
            return true;
        }

        if (preg_match('/(?:admin-bar-inline-css|wpadminbar|sourceURL=)/i', $text)) {
            return true;
        }

        if (preg_match('/\b(?:margin|padding|display|position|background|font-size|line-height|z-index)\s*:\s*[^;{}]+;/i', $text)
            && (strpos($text, '{') !== false || strpos($text, '}') !== false)) {
            return true;
        }

        $length = mb_strlen($text);
        if ($length > 0) {
            $code_chars = preg_match_all('/[{};<>=$#]/u', $text, $tmp);
            if ($code_chars > 0 && ($code_chars / $length) > 0.08 && preg_match('/[:;{}]/', $text)) {
                return true;
            }
        }

        if (preg_match('/\b(?:function\s*\(|const\s+|let\s+|var\s+|document\.|window\.)/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * 获取语言全名映射
     */
    private static function get_language_full_names() {
        return array(
            'zh' => 'Chinese (Simplified)',
            'hk' => 'Chinese (Traditional, Hong Kong)',
            'tw' => 'Chinese (Traditional, Taiwan)',
            'en' => 'English',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'fr' => 'French',
            'de' => 'German',
            'es' => 'Spanish',
            'ru' => 'Russian',
            'ar' => 'Arabic',
            'pt' => 'Portuguese',
            'it' => 'Italian',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'tr' => 'Turkish',
            'pl' => 'Polish',
            'nl' => 'Dutch',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'fi' => 'Finnish',
            'no' => 'Norwegian',
            'uk' => 'Ukrainian',
            'cs' => 'Czech',
            'el' => 'Greek',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
            'bn' => 'Bengali',
        );
    }

    /**
     * 获取所有可用引擎列表
     */
    public static function get_available_engines() {
        return array(
            'deepseek' => array(
                'name'        => 'DeepSeek',
                'description' => '性价比高，中文翻译效果好',
                'type'        => 'ai',
            ),
            'qwen' => array(
                'name'        => '通义千问 (Qwen)',
                'description' => '阿里云大模型，多语言支持',
                'type'        => 'ai',
            ),
            'openai' => array(
                'name'        => 'OpenAI / GPT',
                'description' => 'GPT-4o/4o-mini，翻译质量高',
                'type'        => 'ai',
            ),
            'claude' => array(
                'name'        => 'Claude (Anthropic)',
                'description' => 'Claude 大模型，理解力强',
                'type'        => 'ai',
            ),
            'google' => array(
                'name'        => 'Google Translate API',
                'description' => '谷歌翻译，速度快、语言覆盖广',
                'type'        => 'mt',
            ),
            'custom' => array(
                'name'        => '自定义 OpenAI 兼容接口',
                'description' => '支持任何 OpenAI 格式兼容的 API（如中转站、本地部署等）',
                'type'        => 'ai',
            ),
        );
    }

    // ====== 并行翻译支持（curl_multi）======

    /**
     * 并行翻译多个文本批次（使用 curl_multi 同时发送多个 AI 请求）
     *
     * @param array  $chunks          数组，每个元素是 [string_id => text, ...] 的批次
     * @param string $target_language
     * @param string $source_language
     * @param string|null $engine
     * @param int    $max_concurrency 最大并发数（避免触发 API 限频）
     * @return array 同键名数组，每个元素 ['translations' => [...], 'error' => WP_Error|null]
     */
    public static function translate_batches_parallel($chunks, $target_language, $source_language = 'zh', $engine = null, $max_concurrency = 5) {
        if (empty($chunks)) {
            return array();
        }

        if ($engine === null) {
            $engine = get_option('fanyi2_ai_engine', 'deepseek');
        }

        // Google Translate 已有自身批量接口，顺序调用即可
        if ($engine === 'google') {
            $results = array();
            foreach ($chunks as $idx => $texts) {
                $batch_result = self::translate_batch_google($texts, $target_language, $source_language);
                $results[$idx] = array(
                    'translations' => is_wp_error($batch_result) ? array() : $batch_result,
                    'error'        => is_wp_error($batch_result) ? $batch_result : null,
                );
            }
            return $results;
        }

        // 为每个 chunk 构建 prompt
        $prompts     = array();
        $chunk_texts = array();
        foreach ($chunks as $idx => $texts) {
            $numbered = array();
            foreach (array_values($texts) as $i => $text) {
                $numbered[] = ($i + 1) . '. ' . $text;
            }
            $combined = implode("\n", $numbered);
            $prompts[$idx]     = self::build_batch_prompt($combined, count($texts), $target_language, $source_language);
            $chunk_texts[$idx] = $texts;
        }

        // 单 chunk 或 curl_multi 不可用时退回顺序执行
        if (!function_exists('curl_multi_init') || count($prompts) <= 1) {
            $results = array();
            foreach ($prompts as $idx => $prompt) {
                $api_result = self::call_single_engine($prompt, $engine);
                if (is_wp_error($api_result)) {
                    $results[$idx] = array('translations' => array(), 'error' => $api_result);
                } else {
                    $parsed = self::parse_batch_result($api_result, $chunk_texts[$idx]);
                    $results[$idx] = array('translations' => $parsed, 'error' => null);
                }
            }
            return $results;
        }

        // 使用 curl_multi 并发执行
        return self::execute_parallel_requests($prompts, $chunk_texts, $engine, $max_concurrency);
    }

    /**
     * 按引擎调用对应 API（内部路由）
     */
    private static function call_single_engine($prompt, $engine) {
        switch ($engine) {
            case 'deepseek': return self::call_deepseek_api($prompt);
            case 'qwen':     return self::call_qwen_api($prompt);
            case 'openai':   return self::call_openai_api($prompt);
            case 'claude':   return self::call_claude_api($prompt);
            case 'custom':   return self::call_custom_api($prompt);
            default:         return new WP_Error('invalid_engine', 'Invalid engine');
        }
    }

    /**
     * 构建 curl 请求参数（不发送）
     */
    private static function build_api_request_params($prompt, $engine) {
        switch ($engine) {
            case 'deepseek':
                $api_key = get_option('fanyi2_deepseek_api_key', '');
                $api_url = get_option('fanyi2_deepseek_api_url', 'https://api.deepseek.com/v1/chat/completions');
                $model   = get_option('fanyi2_deepseek_model', 'deepseek-chat');
                break;
            case 'qwen':
                $api_key = get_option('fanyi2_qwen_api_key', '');
                $api_url = get_option('fanyi2_qwen_api_url', 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions');
                $model   = get_option('fanyi2_qwen_model', 'qwen-turbo');
                break;
            case 'openai':
                $api_key = get_option('fanyi2_openai_api_key', '');
                $api_url = get_option('fanyi2_openai_api_url', 'https://api.openai.com/v1/chat/completions');
                $model   = get_option('fanyi2_openai_model', 'gpt-4o-mini');
                break;
            case 'custom':
                $api_key = get_option('fanyi2_custom_api_key', '');
                $api_url = get_option('fanyi2_custom_api_url', '');
                $model   = get_option('fanyi2_custom_model', 'default');
                break;
            case 'claude':
                $api_key = get_option('fanyi2_claude_api_key', '');
                $api_url = get_option('fanyi2_claude_api_url', 'https://api.anthropic.com/v1/messages');
                $model   = get_option('fanyi2_claude_model', 'claude-sonnet-4-20250514');
                break;
            default:
                return new WP_Error('invalid_engine', 'Invalid engine');
        }

        if (empty($api_key) || ($engine === 'custom' && empty($api_url))) {
            return new WP_Error('no_api_key', 'API Key 未配置');
        }

        if ($engine === 'claude') {
            $body = json_encode(array(
                'model'      => $model,
                'max_tokens' => 4096,
                'messages'   => array(array('role' => 'user', 'content' => $prompt)),
            ));
            $headers = array(
                'Content-Type: application/json',
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01',
            );
        } else {
            $body = json_encode(array(
                'model'       => $model,
                'messages'    => array(
                    array('role' => 'system', 'content' => 'You are a professional translator. You translate text accurately and naturally while preserving formatting.'),
                    array('role' => 'user', 'content' => $prompt),
                ),
                'temperature' => 0.3,
                'max_tokens'  => 4096,
            ));
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            );
        }

        return array(
            'url'     => $api_url,
            'headers' => $headers,
            'body'    => $body,
            'engine'  => $engine,
        );
    }

    /**
     * 解析 API 原始响应（curl_multi 场景使用）
     */
    private static function parse_api_raw_response($response_body, $http_code, $engine) {
        $data = json_decode($response_body, true);

        if ($http_code !== 200) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'API error';
            return new WP_Error('api_error', $error_msg . ' (HTTP ' . $http_code . ')');
        }

        if ($engine === 'claude') {
            if (isset($data['content'][0]['text'])) {
                return $data['content'][0]['text'];
            }
            return new WP_Error('invalid_response', 'Claude API 返回格式无效');
        }

        if (isset($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }
        return new WP_Error('invalid_response', 'API 返回格式无效');
    }

    /**
     * curl_multi 并发执行（内部）
     */
    private static function execute_parallel_requests($prompts, $chunk_texts, $engine, $max_concurrency = 5) {
        $results = array();
        $prompt_keys = array_keys($prompts);

        // 分批并发（尊重 max_concurrency）
        $batches = array_chunk($prompt_keys, max(1, $max_concurrency));

        foreach ($batches as $batch_keys) {
            $mh = curl_multi_init();
            $handles = array();
            $errors  = array();

            foreach ($batch_keys as $idx) {
                $req = self::build_api_request_params($prompts[$idx], $engine);
                if (is_wp_error($req)) {
                    $errors[$idx] = $req;
                    continue;
                }

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $req['url']);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body']);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $req['headers']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 90);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_multi_add_handle($mh, $ch);
                $handles[$idx] = $ch;
            }

            // 执行所有并发请求
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 1);
                }
            } while ($running > 0 && $status === CURLM_OK);

            // 收集结果
            foreach ($batch_keys as $idx) {
                if (isset($errors[$idx])) {
                    $results[$idx] = array('translations' => array(), 'error' => $errors[$idx]);
                    continue;
                }
                if (!isset($handles[$idx])) {
                    $results[$idx] = array('translations' => array(), 'error' => new WP_Error('no_handle', '请求未建立'));
                    continue;
                }

                $ch = $handles[$idx];
                $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $response_body = curl_multi_getcontent($ch);
                $curl_error    = curl_error($ch);

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if (!empty($curl_error)) {
                    $results[$idx] = array('translations' => array(), 'error' => new WP_Error('curl_error', $curl_error));
                    continue;
                }

                $text_result = self::parse_api_raw_response($response_body, $http_code, $engine);
                if (is_wp_error($text_result)) {
                    $results[$idx] = array('translations' => array(), 'error' => $text_result);
                    continue;
                }

                $parsed = self::parse_batch_result($text_result, $chunk_texts[$idx]);
                $results[$idx] = array('translations' => $parsed, 'error' => null);
            }

            curl_multi_close($mh);
        }

        return $results;
    }

    /**
     * 测试API连接
     */
    public static function test_connection($engine = null) {
        if ($engine === null) {
            $engine = get_option('fanyi2_ai_engine', 'deepseek');
        }

        $result = self::translate('Hello, world!', 'zh', 'en', $engine);

        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'success' => true,
            'result'  => $result,
            'engine'  => $engine,
        );
    }
}
