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
     * 自动检测推荐语言
     * 优先级：浏览器语言 > 国家映射 > 英文(en) > 默认语言
     */
    public static function detect_language() {
        if (get_option('fanyi2_auto_detect_browser', '1') !== '1') {
            return null;
        }

        $enabled = get_option('fanyi2_enabled_languages', array());
        $default_language = get_option('fanyi2_default_language', 'zh');

        $accept_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        $browser_langs = !empty($accept_language) ? self::parse_accept_language($accept_language) : array();

        // 1) 浏览器语言优先
        $browser_detected = self::detect_language_by_browser($browser_langs, $enabled);
        if (!empty($browser_detected)) {
            return $browser_detected;
        }

        // 2) 国家映射
        $mapped = self::detect_language_by_country_mapping($enabled, $browser_langs);
        if (!empty($mapped)) {
            return $mapped;
        }

        // 3) 英文兜底
        if (in_array('en', $enabled, true)) {
            return 'en';
        }

        // 4) 网站默认语言兜底
        if (in_array($default_language, $enabled, true)) {
            return $default_language;
        }

        // 最后兜底：返回第一个启用语言
        if (!empty($enabled) && is_array($enabled)) {
            return reset($enabled);
        }

        return null;
    }

    /**
     * 根据浏览器语言检测
     */
    private static function detect_language_by_browser($browser_langs, $enabled) {
        foreach ($browser_langs as $lang_info) {
            $code = strtolower($lang_info['code']);

            $regional_map = array(
                'zh-hk' => 'hk',
                'zh-mo' => 'hk',
                'zh-tw' => 'tw',
            );

            if (isset($regional_map[$code]) && in_array($regional_map[$code], $enabled, true)) {
                return $regional_map[$code];
            }

            if (in_array($code, $enabled, true)) {
                return $code;
            }

            $base = substr($code, 0, 2);
            if (in_array($base, $enabled, true)) {
                return $base;
            }
        }

        return null;
    }

    /**
     * 根据国家映射检测语言
     */
    private static function detect_language_by_country_mapping($enabled_languages, $browser_langs = array()) {
        $mapping = get_option('fanyi2_country_language_map', array());
        if (empty($mapping) || !is_array($mapping)) {
            return null;
        }

        $country_code = self::detect_country_code($browser_langs);
        if (empty($country_code)) {
            return null;
        }

        if (!empty($mapping[$country_code])) {
            $mapped_language = sanitize_text_field($mapping[$country_code]);
            if (in_array($mapped_language, $enabled_languages, true)) {
                return $mapped_language;
            }
        }

        return null;
    }

    /**
     * 检测访问者国家代码（ISO 3166-1 alpha-2）
     */
    private static function detect_country_code($browser_langs = array()) {
        // 1) 优先从浏览器语言区域推断（如 zh-MY -> MY）
        foreach ($browser_langs as $lang_info) {
            $code = strtolower($lang_info['code']);
            if (preg_match('/^[a-z]{2}-([a-z]{2})$/', $code, $matches)) {
                return strtoupper($matches[1]);
            }
        }

        // 2) Cloudflare
        if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $country = strtoupper(sanitize_text_field($_SERVER['HTTP_CF_IPCOUNTRY']));
            if (preg_match('/^[A-Z]{2}$/', $country)) {
                return $country;
            }
        }

        // 3) 常见反向代理/GEO 头
        $header_candidates = array(
            'HTTP_X_COUNTRY_CODE',
            'HTTP_X_GEOIP_COUNTRY',
            'HTTP_GEOIP_COUNTRY_CODE',
            'HTTP_X_COUNTRY',
        );
        foreach ($header_candidates as $header_key) {
            if (!empty($_SERVER[$header_key])) {
                $country = strtoupper(sanitize_text_field($_SERVER[$header_key]));
                if (preg_match('/^[A-Z]{2}$/', $country)) {
                    return $country;
                }
            }
        }

        // 4) WooCommerce 内置 IP 定位（可用时）
        if (class_exists('WC_Geolocation')) {
            $geo = WC_Geolocation::geolocate_ip();
            if (!empty($geo['country'])) {
                $country = strtoupper(sanitize_text_field($geo['country']));
                if (preg_match('/^[A-Z]{2}$/', $country)) {
                    return $country;
                }
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
     * 获取按洲分组的国家列表（用于后台映射设置）
     */
    public static function get_countries_by_continent() {
        return array(
            'asia' => array(
                'CN' => '中国', 'SG' => '新加坡', 'MY' => '马来西亚', 'HK' => '中国香港', 'MO' => '中国澳门', 'TW' => '中国台湾',
                'JP' => '日本', 'KR' => '韩国', 'TH' => '泰国', 'VN' => '越南', 'ID' => '印度尼西亚', 'PH' => '菲律宾',
                'IN' => '印度', 'PK' => '巴基斯坦', 'BD' => '孟加拉国', 'LK' => '斯里兰卡', 'NP' => '尼泊尔', 'MM' => '缅甸',
                'KH' => '柬埔寨', 'LA' => '老挝', 'AE' => '阿联酋', 'SA' => '沙特阿拉伯', 'QA' => '卡塔尔', 'KW' => '科威特',
                'OM' => '阿曼', 'BH' => '巴林', 'IL' => '以色列', 'TR' => '土耳其',
            ),
            'europe' => array(
                'GB' => '英国', 'IE' => '爱尔兰', 'FR' => '法国', 'DE' => '德国', 'ES' => '西班牙', 'IT' => '意大利',
                'NL' => '荷兰', 'BE' => '比利时', 'PT' => '葡萄牙', 'CH' => '瑞士', 'AT' => '奥地利', 'SE' => '瑞典',
                'NO' => '挪威', 'DK' => '丹麦', 'FI' => '芬兰', 'PL' => '波兰', 'CZ' => '捷克', 'HU' => '匈牙利',
                'RO' => '罗马尼亚', 'GR' => '希腊', 'UA' => '乌克兰', 'RU' => '俄罗斯',
            ),
            'north_america' => array(
                'US' => '美国', 'CA' => '加拿大', 'MX' => '墨西哥',
            ),
            'south_america' => array(
                'BR' => '巴西', 'AR' => '阿根廷', 'CL' => '智利', 'CO' => '哥伦比亚', 'PE' => '秘鲁', 'VE' => '委内瑞拉',
            ),
            'africa' => array(
                'ZA' => '南非', 'EG' => '埃及', 'NG' => '尼日利亚', 'KE' => '肯尼亚', 'MA' => '摩洛哥', 'DZ' => '阿尔及利亚',
                'ET' => '埃塞俄比亚', 'GH' => '加纳', 'TN' => '突尼斯',
            ),
            'oceania' => array(
                'AU' => '澳大利亚', 'NZ' => '新西兰', 'FJ' => '斐济', 'PG' => '巴布亚新几内亚',
            ),
        );
    }

    /**
     * 大洲名称
     */
    public static function get_continent_labels() {
        return array(
            'asia' => '亚洲',
            'europe' => '欧洲',
            'north_america' => '北美洲',
            'south_america' => '南美洲',
            'africa' => '非洲',
            'oceania' => '大洋洲',
        );
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
