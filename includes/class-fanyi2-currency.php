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
        return Fanyi2_Frontend::get_current_language();
    }
}
