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

            // 语言选择 (dropdown样式)
            $(document).on('click', '.fanyi2-lang-option', function(e) {
                e.preventDefault();
                var lang = $(this).data('lang');
                Fanyi2Frontend.switchLanguage(lang);
            });

            // 语言选择 (flags样式)
            $(document).on('click', '.fanyi2-flag-option', function(e) {
                e.preventDefault();
                var lang = $(this).data('lang');
                Fanyi2Frontend.switchLanguage(lang);
            });

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

        switchLanguage: function(lang) {
            // AJAX切换语言（设置cookie）
            $.ajax({
                url: fanyi2_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_switch_language',
                    nonce: fanyi2_vars.nonce,
                    language: lang
                },
                success: function(response) {
                    if (response.success) {
                        Fanyi2Frontend.redirectToLanguage(lang);
                    }
                },
                error: function() {
                    // 降级：直接跳转
                    Fanyi2Frontend.redirectToLanguage(lang);
                }
            });
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
