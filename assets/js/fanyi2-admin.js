/**
 * Fanyi2 后台管理脚本
 */
(function($) {
    'use strict';

    var Fanyi2Admin = {
        init: function() {
            this.bindEvents();
            this.initTabs();
        },

        bindEvents: function() {
            // 设置表单提交
            $('#fanyi2-settings-form').on('submit', function(e) {
                e.preventDefault();
                Fanyi2Admin.saveSettings();
            });

            // 测试API
            $('#fanyi2-test-deepseek').on('click', function() {
                Fanyi2Admin.testApi('deepseek', '#fanyi2-deepseek-test-result');
            });
            $('#fanyi2-test-qwen').on('click', function() {
                Fanyi2Admin.testApi('qwen', '#fanyi2-qwen-test-result');
            });

            // 扫描站点
            $('#fanyi2-scan-site').on('click', function() {
                Fanyi2Admin.scanSite();
            });

            // 预翻译
            $('#fanyi2-start-pretranslate').on('click', function() {
                Fanyi2Admin.startPretranslate();
            });

            // 翻译所有语言
            $('#fanyi2-translate-all-langs').on('click', function() {
                Fanyi2Admin.translateAllLanguages();
            });

            // 编辑翻译
            $(document).on('click', '.fanyi2-edit-string', function() {
                var stringId = $(this).data('id');
                Fanyi2Admin.editString(stringId);
            });

            // 删除字符串
            $(document).on('click', '.fanyi2-delete-string', function() {
                var stringId = $(this).data('id');
                if (confirm('确定要删除这条字符串及其所有翻译吗？')) {
                    Fanyi2Admin.deleteString(stringId);
                }
            });

            // 关闭弹窗
            $(document).on('click', '.fanyi2-modal-close', function() {
                $(this).closest('.fanyi2-modal').hide();
            });

            // 弹窗AI翻译所有
            $('#fanyi2-modal-ai-translate').on('click', function() {
                Fanyi2Admin.modalAiTranslate();
            });

            // 弹窗保存
            $('#fanyi2-modal-save').on('click', function() {
                Fanyi2Admin.modalSave();
            });
        },

        /**
         * 初始化设置标签页
         */
        initTabs: function() {
            $(document).on('click', '.fanyi2-tab', function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                
                $('.fanyi2-tab').removeClass('active');
                $(this).addClass('active');
                
                $('.fanyi2-tab-content').removeClass('active');
                $('#tab-' + tab).addClass('active');
            });
        },

        /**
         * 保存设置
         */
        saveSettings: function() {
            var $form = $('#fanyi2-settings-form');
            var settings = {};

            // 收集所有设置
            $form.find('input, select, textarea').each(function() {
                var $input = $(this);
                var name = $input.attr('name');
                if (!name) return;

                if ($input.is(':checkbox')) {
                    if (name.indexOf('[]') !== -1) {
                        // 多选
                        var baseName = name.replace('[]', '');
                        if (!settings[baseName]) settings[baseName] = [];
                        if ($input.is(':checked')) {
                            settings[baseName].push($input.val());
                        }
                    } else {
                        settings[name] = $input.is(':checked') ? $input.val() : '0';
                    }
                } else if ($input.is(':radio')) {
                    if ($input.is(':checked')) {
                        settings[name] = $input.val();
                    }
                } else if (name.indexOf('[') !== -1) {
                    // 嵌套对象
                    var match = name.match(/^([^\[]+)\[([^\]]+)\]$/);
                    if (match) {
                        if (!settings[match[1]]) settings[match[1]] = {};
                        settings[match[1]][match[2]] = $input.val();
                    }
                } else {
                    settings[name] = $input.val();
                }
            });

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_save_settings',
                    nonce: fanyi2_admin.nonce,
                    settings: settings
                },
                beforeSend: function() {
                    $form.find('.submit .button').prop('disabled', true).text('保存中...');
                },
                success: function(response) {
                    $form.find('.submit .button').prop('disabled', false).text('💾 保存设置');
                    if (response.success) {
                        Fanyi2Admin.showAdminNotice('success', response.data.message);
                    } else {
                        Fanyi2Admin.showAdminNotice('error', response.data.message);
                    }
                },
                error: function() {
                    $form.find('.submit .button').prop('disabled', false).text('💾 保存设置');
                    Fanyi2Admin.showAdminNotice('error', '保存失败，请重试');
                }
            });
        },

        /**
         * 测试API连接
         */
        testApi: function(engine, resultSelector) {
            var $result = $(resultSelector);
            $result.text('测试中...').css('color', '#666');

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_test_api',
                    nonce: fanyi2_admin.nonce,
                    engine: engine
                },
                success: function(response) {
                    if (response.success) {
                        $result.text('✅ 连接成功! 翻译结果: ' + response.data.result).css('color', '#155724');
                    } else {
                        $result.text('❌ ' + response.data.message).css('color', '#721c24');
                    }
                },
                error: function() {
                    $result.text('❌ 请求失败').css('color', '#721c24');
                }
            });
        },

        /**
         * 扫描站点
         */
        scanSite: function() {
            var $btn = $('#fanyi2-scan-site');
            $btn.prop('disabled', true).text('扫描中...');
            $('#fanyi2-scan-progress').show();

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_grab_page_strings',
                    nonce: fanyi2_admin.nonce,
                    strings: [],
                    page_url: '',
                    scan_site: true
                },
                timeout: 120000,
                success: function(response) {
                    $btn.prop('disabled', false).text('🔍 扫描整个站点');
                    if (response.success) {
                        $('.fanyi2-scan-status').text('✅ ' + response.data.message);
                        $('.fanyi2-progress-bar-fill').css('width', '100%');
                    } else {
                        $('.fanyi2-scan-status').text('❌ ' + response.data.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('🔍 扫描整个站点');
                    $('.fanyi2-scan-status').text('❌ 扫描超时，请稍后重试');
                }
            });
        },

        /**
         * 开始预翻译
         */
        startPretranslate: function() {
            var targetLang = $('#fanyi2-batch-target-lang').val();
            var batchSize = parseInt($('#fanyi2-batch-size').val()) || 10;
            var $btn = $('#fanyi2-start-pretranslate');

            $btn.prop('disabled', true).text('翻译中...');
            $('#fanyi2-pretranslate-progress').show();
            
            this.runPretranslateBatch(targetLang, batchSize, 0);
        },

        runPretranslateBatch: function(targetLang, batchSize, totalTranslated) {
            var self = this;

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_batch_pretranslate',
                    nonce: fanyi2_admin.nonce,
                    language: targetLang,
                    batch_size: batchSize
                },
                timeout: 120000,
                success: function(response) {
                    if (response.success) {
                        totalTranslated += response.data.translated;
                        var remaining = response.data.remaining;

                        if (remaining > 0 && response.data.translated > 0) {
                            var percent = Math.round((totalTranslated / (totalTranslated + remaining)) * 100);
                            $('.fanyi2-pretranslate-status').text('已翻译 ' + totalTranslated + ' 条，还剩 ' + remaining + ' 条...');
                            $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', percent + '%');
                            
                            // 继续下一批
                            setTimeout(function() {
                                self.runPretranslateBatch(targetLang, batchSize, totalTranslated);
                            }, 1000);
                        } else {
                            // 翻译完成
                            $('#fanyi2-start-pretranslate').prop('disabled', false).text('🚀 开始预翻译');
                            $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', '100%');
                            $('.fanyi2-pretranslate-status').text('✅ 翻译完成！共翻译 ' + totalTranslated + ' 条');
                            Fanyi2Admin.showAdminNotice('success', '预翻译完成！共翻译 ' + totalTranslated + ' 条');
                        }
                    } else {
                        $('#fanyi2-start-pretranslate').prop('disabled', false).text('🚀 开始预翻译');
                        $('.fanyi2-pretranslate-status').text('❌ ' + response.data.message);
                    }
                },
                error: function() {
                    $('#fanyi2-start-pretranslate').prop('disabled', false).text('🚀 开始预翻译');
                    $('.fanyi2-pretranslate-status').text('❌ 请求失败');
                }
            });
        },

        /**
         * 翻译所有语言
         */
        translateAllLanguages: function() {
            var enabledLangs = fanyi2_admin.settings.enabled_languages;
            var defaultLang = fanyi2_admin.settings.default_language;
            var batchSize = parseInt($('#fanyi2-batch-size').val()) || 10;

            var targetLangs = enabledLangs.filter(function(lang) {
                return lang !== defaultLang;
            });

            if (targetLangs.length === 0) {
                this.showAdminNotice('error', '没有其他语言需要翻译');
                return;
            }

            $('#fanyi2-pretranslate-progress').show();
            $('.fanyi2-pretranslate-status').text('开始翻译所有语言...');
            
            var self = this;
            var langIndex = 0;

            var processNextLang = function() {
                if (langIndex >= targetLangs.length) {
                    $('.fanyi2-pretranslate-status').text('✅ 所有语言翻译完成！');
                    return;
                }

                var lang = targetLangs[langIndex];
                var langName = fanyi2_admin.languages[lang] || lang;
                $('.fanyi2-pretranslate-status').text('正在翻译 ' + langName + ' (' + (langIndex + 1) + '/' + targetLangs.length + ')...');

                self.pretranslateLanguage(lang, batchSize, function() {
                    langIndex++;
                    var percent = Math.round((langIndex / targetLangs.length) * 100);
                    $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', percent + '%');
                    processNextLang();
                });
            };

            processNextLang();
        },

        pretranslateLanguage: function(lang, batchSize, callback) {
            var self = this;

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_batch_pretranslate',
                    nonce: fanyi2_admin.nonce,
                    language: lang,
                    batch_size: batchSize
                },
                timeout: 120000,
                success: function(response) {
                    if (response.success && response.data.remaining > 0 && response.data.translated > 0) {
                        setTimeout(function() {
                            self.pretranslateLanguage(lang, batchSize, callback);
                        }, 1000);
                    } else {
                        callback();
                    }
                },
                error: function() {
                    callback();
                }
            });
        },

        /**
         * 编辑字符串
         */
        editString: function(stringId) {
            // 通过AJAX获取字符串详情
            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_get_string_detail',
                    nonce: fanyi2_admin.nonce,
                    string_id: stringId
                },
                success: function(response) {
                    // 简化：直接在弹窗中显示编辑器
                    var $modal = $('#fanyi2-edit-modal');
                    $modal.data('string-id', stringId);
                    // TODO: 加载翻译到弹窗
                    $modal.show();
                }
            });
        },

        /**
         * 删除字符串
         */
        deleteString: function(stringId) {
            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_delete_string',
                    nonce: fanyi2_admin.nonce,
                    string_id: stringId
                },
                success: function(response) {
                    if (response.success) {
                        $('tr[data-string-id="' + stringId + '"]').fadeOut(300, function() {
                            $(this).remove();
                        });
                        Fanyi2Admin.showAdminNotice('success', '已删除');
                    } else {
                        Fanyi2Admin.showAdminNotice('error', response.data.message);
                    }
                }
            });
        },

        /**
         * 弹窗中AI翻译
         */
        modalAiTranslate: function() {
            var original = $('#fanyi2-modal-original').val();
            if (!original) return;

            var $btn = $('#fanyi2-modal-ai-translate');
            $btn.prop('disabled', true).text('翻译中...');

            // 获取所有目标语言输入框
            var $langInputs = $('#fanyi2-modal-translations .fanyi2-modal-lang-input');
            
            $langInputs.each(function() {
                var $input = $(this);
                var lang = $input.data('lang');
                
                $.ajax({
                    url: fanyi2_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fanyi2_translate_single',
                        nonce: fanyi2_admin.nonce,
                        text: original,
                        target_language: lang
                    },
                    success: function(response) {
                        if (response.success) {
                            $input.val(response.data.translated);
                        }
                        $btn.prop('disabled', false).text('🤖 AI翻译所有');
                    }
                });
            });
        },

        /**
         * 弹窗保存翻译
         */
        modalSave: function() {
            var original = $('#fanyi2-modal-original').val();
            var $langInputs = $('#fanyi2-modal-translations .fanyi2-modal-lang-input');
            var saved = 0;

            $langInputs.each(function() {
                var $input = $(this);
                var lang = $input.data('lang');
                var translated = $input.val();

                if (!translated) return;

                $.ajax({
                    url: fanyi2_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fanyi2_save_translation',
                        nonce: fanyi2_admin.nonce,
                        original_text: original,
                        translated_text: translated,
                        language: lang,
                        page_url: ''
                    },
                    success: function(response) {
                        saved++;
                        if (saved === $langInputs.length) {
                            Fanyi2Admin.showAdminNotice('success', '翻译已保存');
                            $('#fanyi2-edit-modal').hide();
                        }
                    }
                });
            });
        },

        /**
         * 显示管理后台通知
         */
        showAdminNotice: function(type, message) {
            var classMap = {
                'success': 'notice-success',
                'error': 'notice-error',
                'info': 'notice-info'
            };
            var $notice = $('<div class="notice ' + classMap[type] + ' is-dismissible"><p>' + message + '</p></div>');
            
            // 移除旧通知
            $('.fanyi2-admin-wrap .notice.is-dismissible').not('.below-h2').remove();
            
            $('.fanyi2-admin-wrap h1').after($notice);
            
            // 自动消失
            setTimeout(function() {
                $notice.fadeOut(300, function() { $(this).remove(); });
            }, 5000);
        }
    };

    $(document).ready(function() {
        Fanyi2Admin.init();
    });

})(jQuery);
