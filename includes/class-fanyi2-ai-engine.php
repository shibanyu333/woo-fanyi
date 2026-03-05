<?php
/**
 * AI翻译引擎类 - 支持DeepSeek和千问
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_AI_Engine {

    /**
     * 翻译文本
     */
    public static function translate($text, $target_language, $source_language = 'zh', $engine = null) {
        if (empty($text) || $target_language === $source_language) {
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

        if ($engine === null) {
            $engine = get_option('fanyi2_ai_engine', 'deepseek');
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
     * 构建翻译提示
     */
    private static function build_prompt($text, $target_language, $source_language) {
        $lang_names = self::get_language_full_names();
        $source_name = isset($lang_names[$source_language]) ? $lang_names[$source_language] : $source_language;
        $target_name = isset($lang_names[$target_language]) ? $lang_names[$target_language] : $target_language;

        return "You are a professional translator. Translate the following text from {$source_name} to {$target_name}. " .
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
        $source_name = isset($lang_names[$source_language]) ? $lang_names[$source_language] : $source_language;
        $target_name = isset($lang_names[$target_language]) ? $lang_names[$target_language] : $target_language;

        return "You are a professional translator. Translate the following {$count} numbered texts from {$source_name} to {$target_name}. " .
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
     * 调用OpenAI兼容API（DeepSeek和千问都兼容此格式）
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
        $lines = explode("\n", trim($result));
        $translations = array();
        $keys = array_keys($original_texts);
        $index = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // 去除编号前缀，如 "1. ", "2. " 等
            $line = preg_replace('/^\d+\.\s*/', '', $line);

            if ($index < count($keys)) {
                $translations[$keys[$index]] = $line;
                $index++;
            }
        }

        return $translations;
    }

    /**
     * 获取语言全名映射
     */
    private static function get_language_full_names() {
        return array(
            'zh' => 'Chinese (Simplified)',
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
