/**
 * Fanyi2 前端语言切换器脚本
 */
(function($) {
    'use strict';

    var Fanyi2Frontend = {
        init: function() {
            this.bindEvents();
            this.initLanguageSwitcher();
            this.applyRTL();
        },

        /**
         * 如果当前语言是RTL（如阿拉伯语、希伯来语等），设置页面方向
         */
        applyRTL: function() {
            if (typeof fanyi2_vars !== 'undefined' && fanyi2_vars.is_rtl === '1') {
                document.documentElement.setAttribute('dir', 'rtl');
                document.body.classList.add('fanyi2-rtl');
            }
        },

        bindEvents: function() {
            // 语言切换器开关 (dropdown样式)
            $(document).on('click', '.fanyi2-switcher-current', function(e) {
                e.stopPropagation();
                $(this).closest('.fanyi2-switcher').toggleClass('open');
            });

            // 语言选项链接已有真实 URL + data-fanyi2-lang，
            // 点击后直接由浏览器导航，无需 JS 拦截或 AJAX

            // 点击外部关闭
            $(document).on('click', function() {
                $('.fanyi2-switcher').removeClass('open');
            });
        },

        initLanguageSwitcher: function() {
            // 检查是否需要根据URL参数高亮当前语言
            var currentLang = fanyi2_vars.current_language;
            $('.fanyi2-lang-option').removeClass('active');
            $('.fanyi2-lang-option[data-lang="' + currentLang + '"]').addClass('active');
        },

        /**
         * 程序化切换语言（供外部调用）
         * 内置切换器已使用真实 URL，此方法仅作为公开 API 保留
         */
        switchLanguage: function(lang) {
            Fanyi2Frontend.redirectToLanguage(lang);
        },

        /**
         * 跳转到指定语言的URL
         */
        redirectToLanguage: function(lang) {
            var urlMode = fanyi2_vars.url_mode || 'parameter';
            var defaultLang = fanyi2_vars.default_language || 'zh';
            var url = new URL(window.location.href);

            if (urlMode === 'subdirectory') {
                // 子目录模式: /en/page -> /fr/page 或 /page
                var pathname = url.pathname;
                var homeUrl = fanyi2_vars.home_url || '';
                var homePath = '';
                try {
                    homePath = new URL(homeUrl).pathname.replace(/\/$/, '');
                } catch(e) {}

                var relativePath = pathname;
                if (homePath) {
                    relativePath = pathname.substring(homePath.length);
                }
                relativePath = relativePath.replace(/^\//, '');

                // 移除当前语言前缀
                var enabledLangs = fanyi2_vars.enabled_languages || [];
                var segments = relativePath.split('/');
                if (segments.length > 0 && enabledLangs.indexOf(segments[0]) !== -1) {
                    segments.shift();
                }
                var cleanPath = segments.join('/');

                // 添加新语言前缀
                if (lang === defaultLang) {
                    url.pathname = homePath + '/' + cleanPath;
                } else {
                    url.pathname = homePath + '/' + lang + '/' + cleanPath;
                }

                // 移除 ?lang 参数（如果存在）
                url.searchParams.delete('lang');
            } else {
                // 参数模式
                if (lang === defaultLang) {
                    url.searchParams.delete('lang');
                } else {
                    url.searchParams.set('lang', lang);
                }
            }

            window.location.href = url.toString();
        }
    };

    $(document).ready(function() {
        Fanyi2Frontend.init();
    });

})(jQuery);
