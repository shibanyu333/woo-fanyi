<?php
/**
 * WooCommerce货币桥接类 - 与 woo-huilv 汇率转换插件集成
 * 
 * 本插件不再自行处理货币转换，而是通过 filter 将当前语言传递给
 * woo-huilv 插件，由其负责汇率换算和货币显示。
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_Currency {

    /**
     * 初始化 - 与 woo-huilv 插件建立桥接
     */
    public static function init() {
        // 通过 woo_huilv_current_language filter 将当前语言传递给汇率插件
        add_filter('woo_huilv_current_language', array(__CLASS__, 'provide_current_language'));
    }

    /**
     * 向 woo-huilv 插件提供当前语言
     *
     * @param string $lang 当前语言 (可能来自其他插件)
     * @return string 本插件检测到的当前语言
     */
    public static function provide_current_language($lang) {
        $current_lang = Fanyi2_Frontend::get_current_language();
        $browser_locale = self::get_browser_locale();

        if ($current_lang === 'en' && $browser_locale === 'en-gb') {
            return 'en-gb';
        }

        return $current_lang;
    }

    /**
     * 获取浏览器 locale（只保留语言-地区两段）
     *
     * @return string
     */
    private static function get_browser_locale() {
        $accept_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']) : '';
        if (empty($accept_language)) {
            return '';
        }

        $parts = explode(',', $accept_language);
        if (empty($parts[0])) {
            return '';
        }

        $locale = strtolower(trim($parts[0]));
        $locale = preg_replace('/;q=[0-9.]+/i', '', $locale);
        $locale = str_replace('_', '-', $locale);

        if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale)) {
            return '';
        }

        return $locale;
    }
}
