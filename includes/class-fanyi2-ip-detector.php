<?php
/**
 * 浏览器语言检测类 - 根据访问者浏览器语言确定网站语言
 * (替代原IP检测，改用 Accept-Language 头)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_IP_Detector {

    /**
     * 根据浏览器语言检测推荐语言
     */
    public static function detect_language() {
        if (get_option('fanyi2_auto_detect_browser', '1') !== '1') {
            return null;
        }

        $accept_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        if (empty($accept_language)) {
            return null;
        }

        $browser_langs = self::parse_accept_language($accept_language);
        $enabled = get_option('fanyi2_enabled_languages', array());

        foreach ($browser_langs as $lang_info) {
            $code = strtolower($lang_info['code']);

            // 精确匹配 (如 zh, en, ja)
            if (in_array($code, $enabled)) {
                return $code;
            }

            // 基础语言匹配 (如 en-US → en, zh-CN → zh, zh-TW → zh)
            $base = substr($code, 0, 2);
            if (in_array($base, $enabled)) {
                return $base;
            }
        }

        return null;
    }

    /**
     * 解析 Accept-Language 头
     *
     * @param string $header 如 "en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7"
     * @return array 按质量值降序排列的语言列表
     */
    private static function parse_accept_language($header) {
        $languages = array();
        $parts = explode(',', $header);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            $quality = 1.0;
            if (preg_match('/;q=([0-9.]+)/i', $part, $matches)) {
                $quality = (float) $matches[1];
                $part = preg_replace('/;q=[0-9.]+/i', '', $part);
            }

            $code = strtolower(trim($part));
            if (!empty($code)) {
                $languages[] = array('code' => $code, 'quality' => $quality);
            }
        }

        // 按质量值降序排列
        usort($languages, function($a, $b) {
            if ($a['quality'] == $b['quality']) return 0;
            return ($a['quality'] > $b['quality']) ? -1 : 1;
        });

        return $languages;
    }

    /**
     * 获取RTL语言列表
     */
    public static function get_rtl_languages() {
        return array('ar', 'he', 'fa', 'ur');
    }

    /**
     * 判断指定语言是否是RTL
     */
    public static function is_rtl($language = null) {
        if ($language === null) {
            $language = Fanyi2_Frontend::get_current_language();
        }
        return in_array($language, self::get_rtl_languages());
    }
}
