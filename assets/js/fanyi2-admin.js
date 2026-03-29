/**
 * Fanyi2 后台管理脚本
 */
(function($) {
    'use strict';

    var Fanyi2Admin = {
        backgroundStatusTimer: null,

        init: function() {
            this.bindEvents();
            this.initTabs();
            this.loadPretranslateProgress();
            this.loadBackgroundTranslationStatus();
        },

        bindEvents: function() {
            // 设置表单提交
            $('#fanyi2-settings-form').on('submit', function(e) {
                e.preventDefault();
                Fanyi2Admin.saveSettings();
            });

            // 测试API - 通用处理
            $(document).on('click', '.fanyi2-test-api-btn', function() {
                var engine = $(this).data('engine');
                Fanyi2Admin.testApi(engine, '.fanyi2-test-result[data-engine="' + engine + '"]');
            });

            // 引擎选择切换 - 显示/隐藏对应设置面板
            $('input[name="fanyi2_ai_engine"]').on('change', function() {
                Fanyi2Admin.toggleEngineSettings();
            });
            // 初始化显示状态
            this.toggleEngineSettings();

            // 扫描站点
            $('#fanyi2-scan-site').on('click', function() {
                Fanyi2Admin.scanSite();
            });

            // 预翻译
            $('#fanyi2-start-pretranslate').on('click', function() {
                Fanyi2Admin.startPretranslate();
            });
            $('#fanyi2-stop-background-translation').on('click', function() {
                Fanyi2Admin.stopBackgroundTranslation();
            });
            $('#fanyi2-clear-target-lang-translations').on('click', function() {
                Fanyi2Admin.clearTargetLanguageTranslations();
            });

            // 翻译所有语言
            $('#fanyi2-translate-all-langs').on('click', function() {
                Fanyi2Admin.translateAllLanguages();
            });

            // 清洗默认语言
            $('#fanyi2-unify-default-language').on('click', function() {
                Fanyi2Admin.unifyToDefaultLanguage();
            });

            // 切换目标语言/翻译范围时加载最近进度
            $('#fanyi2-batch-target-lang, #fanyi2-batch-scope').on('change', function() {
                Fanyi2Admin.loadPretranslateProgress();
                Fanyi2Admin.loadBackgroundTranslationStatus();
            });

            // 编辑翻译（保留旧选择器作为兼容）
            $(document).on('click', '.fanyi2-edit-string', function() {
                var stringId = $(this).data('id');
                Fanyi2Admin.editString(stringId);
            });

            // 点击语言徽章打开编辑弹窗
            $(document).on('click', '.fanyi2-badge-clickable', function() {
                var stringId = $(this).data('id');
                Fanyi2Admin.editString(stringId);
            });

            // 删除翻译（保留字符串）
            $(document).on('click', '.fanyi2-delete-string', function() {
                var stringId = $(this).data('id');
                if (confirm('确定要清除该字符串的所有翻译吗？（字符串本身会保留）')) {
                    Fanyi2Admin.clearTranslations(stringId);
                }
            });

            // 单条字符串 AI 翻译（翻译列表页的 🤖 按钮）
            $(document).on('click', '.fanyi2-ai-single-string', function() {
                var stringId = $(this).data('id');
                Fanyi2Admin.aiTranslateSingle(stringId, $(this));
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
         * 切换引擎设置面板显示
         */
        toggleEngineSettings: function() {
            var engines = ['deepseek', 'qwen', 'openai', 'claude', 'google', 'custom'];
            var selected = $('input[name="fanyi2_ai_engine"]:checked').val() || 'deepseek';
            engines.forEach(function(eng) {
                if (eng === selected) {
                    $('#' + eng + '-settings').show();
                } else {
                    $('#' + eng + '-settings').hide();
                }
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
            $('.fanyi2-scan-status').text('正在扫描站点页面，请耐心等待...');
            $('.fanyi2-progress-bar-fill').css('width', '30%');

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_scan_site',
                    nonce: fanyi2_admin.nonce
                },
                timeout: 300000,
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
         * 开始翻译
         */
        startPretranslate: function() {
            this.startBackgroundTranslation();
        },

        unifyToDefaultLanguage: function() {
            var defaultLang = fanyi2_admin.settings.default_language;
            var $btn = $('#fanyi2-unify-default-language');
            var batchSize = this.getPreferredBatchSize();

            if (!defaultLang) {
                this.showAdminNotice('error', '未检测到默认语言');
                return;
            }

            $btn.prop('disabled', true).text('清洗中...');
            $('#fanyi2-pretranslate-progress').show();
            $('.fanyi2-pretranslate-status').text('正在扫描站点可见文本...');

            this.scanSiteForCleanup(function(scanOk) {
                if (!scanOk) {
                    $btn.prop('disabled', false).text('🧹 清洗默认语言（' + (fanyi2_admin.languages[defaultLang] || defaultLang) + '，写入数据库）');
                    return;
                }

                $('.fanyi2-pretranslate-status').text('扫描完成，正在补齐默认语言翻译...');
                Fanyi2Admin.runPretranslateBatch(defaultLang, 'all', batchSize, 0, 0, {
                    force_default_mode: true,
                    onDone: function(status, responseData) {
                        $btn.prop('disabled', false).text('🧹 清洗默认语言（' + (fanyi2_admin.languages[defaultLang] || defaultLang) + '，写入数据库）');
                        if (status === 'completed' || (responseData && parseInt(responseData.remaining || 0, 10) <= 0)) {
                            Fanyi2Admin.applyDefaultLanguageCleanup();
                            return;
                        }
                        Fanyi2Admin.showAdminNotice('error', '默认语言翻译阶段未完成，请重试');
                    }
                });
            });
        },

        scanSiteForCleanup: function(callback) {
            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_scan_site',
                    nonce: fanyi2_admin.nonce
                },
                timeout: 300000,
                success: function(response) {
                    if (response && response.success) {
                        if (typeof callback === 'function') {
                            callback(true);
                        }
                        return;
                    }
                    Fanyi2Admin.showAdminNotice('error', (response && response.data && response.data.message) || '扫描站点失败');
                    if (typeof callback === 'function') {
                        callback(false);
                    }
                },
                error: function() {
                    Fanyi2Admin.showAdminNotice('error', '扫描站点请求失败');
                    if (typeof callback === 'function') {
                        callback(false);
                    }
                }
            });
        },

        normalizeBatchSize: function(value) {
            var size = parseInt(value, 10);
            if (isNaN(size) || size <= 0) {
                size = 500;
            }
            return Math.max(50, Math.min(500, size));
        },

        getPreferredBatchSize: function() {
            var configured = (fanyi2_admin.settings && fanyi2_admin.settings.batch_size) ? fanyi2_admin.settings.batch_size : 120;
            // 稳定优先：避免默认过大批次导致超时卡住
            return Math.max(50, Math.min(180, this.normalizeBatchSize(configured)));
        },

        getPretranslateScope: function() {
            var scope = $('#fanyi2-batch-scope').val() || 'all';
            var allowed = ['all', 'homepage', 'products', 'other_pages', 'woocommerce'];
            if (allowed.indexOf(scope) === -1) {
                scope = 'all';
            }
            return scope;
        },

        loadPretranslateProgress: function() {
            if ($('#fanyi2-start-pretranslate').length === 0) {
                return;
            }

            var targetLang = $('#fanyi2-batch-target-lang').val();
            var scope = this.getPretranslateScope();
            if (!targetLang) {
                return;
            }

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_get_pretranslate_progress',
                    nonce: fanyi2_admin.nonce,
                    language: targetLang,
                    scope: scope
                },
                timeout: 60000,
                success: function(response) {
                    if (!response.success || !response.data || !response.data.progress) {
                        return;
                    }

                    var p = response.data.progress;
                    var translatedTotal = parseInt(p.translated_total || 0, 10);
                    var totalStrings = parseInt(p.total_strings || 0, 10);
                    var remaining = parseInt(p.remaining || 0, 10);
                    var statusText = '进行中';
                    if (p.status === 'completed') {
                        statusText = '已完成';
                    } else if (p.status === 'stalled') {
                        statusText = '已暂停';
                    } else if (p.status === 'error') {
                        statusText = '异常';
                    }
                    var updatedAt = p.updated_at || '';
                    var base = '上次进度（' + statusText + '）：已翻译 ' + translatedTotal + ' / ' + totalStrings + '，剩余 ' + remaining;
                    if (updatedAt) {
                        base += '（更新于 ' + updatedAt + '）';
                    }
                    $('.fanyi2-pretranslate-status').text(base);

                    if (totalStrings > 0) {
                        var percent = Math.round((translatedTotal / totalStrings) * 100);
                        $('#fanyi2-pretranslate-progress').show();
                        $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', percent + '%');
                    }
                }
            });
        },

        runPretranslateBatch: function(targetLang, scope, batchSize, totalTranslated, stalledRetries, opts) {
            var self = this;
            stalledRetries = parseInt(stalledRetries || 0, 10);
            opts = opts || {};

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_batch_pretranslate',
                    nonce: fanyi2_admin.nonce,
                    language: targetLang,
                    scope: scope,
                    batch_size: batchSize,
                    rounds_per_request: 5,
                    force_default_mode: opts.force_default_mode ? 1 : 0
                },
                timeout: 300000,
                success: function(response) {
                    if (response.success) {
                        totalTranslated += response.data.translated;
                        var remaining = response.data.remaining;
                        var translatedTotal = parseInt(response.data.translated_total || totalTranslated, 10);
                        var totalStrings = parseInt(response.data.total_strings || (translatedTotal + remaining), 10);
                        var status = response.data.status || (remaining > 0 ? 'running' : 'completed');
                        var warnings = (response.data.warnings && response.data.warnings.length) ? response.data.warnings : [];
                        var hasTimeoutWarning = warnings.some(function(w) {
                            var s = String(w || '').toLowerCase();
                            return s.indexOf('timed out') !== -1 || s.indexOf('curl error 28') !== -1 || s.indexOf('网络超时') !== -1;
                        });
                        var nextBatchSize = hasTimeoutWarning ? Math.max(50, Math.floor(batchSize * 0.7)) : batchSize;

                        if (status === 'running' && remaining > 0) {
                            var translatedThisRound = parseInt(response.data.translated || 0, 10);
                            if (translatedThisRound <= 0) {
                                var nextBatchOnRunning = Math.max(50, Math.floor(batchSize / 2));
                                if (stalledRetries < 3 && nextBatchOnRunning < batchSize) {
                                    var percentRunningRetry = totalStrings > 0 ? Math.round((translatedTotal / totalStrings) * 100) : 0;
                                    $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', percentRunningRetry + '%');
                                    $('.fanyi2-pretranslate-status').text('⚠ 本轮推进较慢，自动降批到 ' + nextBatchOnRunning + ' 继续...');
                                    setTimeout(function() {
                                        self.runPretranslateBatch(targetLang, scope, nextBatchOnRunning, totalTranslated, stalledRetries + 1, opts);
                                    }, 250);
                                    return;
                                }
                            }

                            var percent = totalStrings > 0 ? Math.round((translatedTotal / totalStrings) * 100) : Math.round((totalTranslated / (totalTranslated + remaining)) * 100);
                            $('.fanyi2-pretranslate-status').text('已翻译 ' + translatedTotal + ' / ' + totalStrings + '，剩余 ' + remaining + '...');
                            $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', percent + '%');
                            
                            // 继续下一批（无需等待，立即发起下一轮）
                            setTimeout(function() {
                                self.runPretranslateBatch(targetLang, scope, nextBatchSize, totalTranslated, 0, opts);
                            }, 10);
                        } else if (status === 'completed' || remaining <= 0) {
                            // 翻译完成
                            $('#fanyi2-start-pretranslate').prop('disabled', false).text('🚀 开始翻译（后台持续）');
                            $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', '100%');
                            $('.fanyi2-pretranslate-status').text('✅ 翻译完成！共翻译 ' + translatedTotal + ' / ' + totalStrings + ' 条');
                            Fanyi2Admin.showAdminNotice('success', '翻译完成！共翻译 ' + translatedTotal + ' 条');
                            if (typeof opts.onDone === 'function') {
                                opts.onDone('completed', response.data);
                            }
                        } else if (status === 'error') {
                            $('#fanyi2-start-pretranslate').prop('disabled', false).text('🚀 开始翻译（后台持续）');
                            var percentError = totalStrings > 0 ? Math.round((translatedTotal / totalStrings) * 100) : 0;
                            $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', percentError + '%');
                            $('.fanyi2-pretranslate-status').text('❌ 处理异常。已翻译 ' + translatedTotal + ' / ' + totalStrings + '，剩余 ' + remaining + '。');
                            Fanyi2Admin.showAdminNotice('error', response.data.message || '本轮处理异常，请稍后重试。');
                            if (typeof opts.onDone === 'function') {
                                opts.onDone('error', response.data);
                            }
                        } else {
                            // 有剩余但本轮无进展，避免误报“完成”
                            var nextBatchSize = Math.max(50, Math.floor(batchSize / 2));
                            if (stalledRetries < 3 && nextBatchSize < batchSize) {
                                var percentRetry = totalStrings > 0 ? Math.round((translatedTotal / totalStrings) * 100) : 0;
                                $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', percentRetry + '%');
                                $('.fanyi2-pretranslate-status').text('⚠ 本轮未推进，自动降批到 ' + nextBatchSize + ' 重试中...（' + (stalledRetries + 1) + '/3）');
                                setTimeout(function() {
                                    self.runPretranslateBatch(targetLang, scope, nextBatchSize, totalTranslated, stalledRetries + 1, opts);
                                }, 250);
                                return;
                            }

                            $('#fanyi2-start-pretranslate').prop('disabled', false).text('🚀 开始翻译（后台持续）');
                            var percentStalled = totalStrings > 0 ? Math.round((translatedTotal / totalStrings) * 100) : 0;
                            $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', percentStalled + '%');
                            $('.fanyi2-pretranslate-status').text('⚠ 本轮未推进。已翻译 ' + translatedTotal + ' / ' + totalStrings + '，剩余 ' + remaining + '。可重试或调小批次。');
                            Fanyi2Admin.showAdminNotice('error', '本轮未推进，且自动重试未恢复。请重试或调小每批数量。');
                            if (typeof opts.onDone === 'function') {
                                opts.onDone('stalled', response.data);
                            }
                        }

                        if (warnings.length && status !== 'running') {
                            Fanyi2Admin.showAdminNotice('error', '部分批次出现警告：' + warnings.join(' | '));
                        }
                    } else {
                        $('#fanyi2-start-pretranslate').prop('disabled', false).text('🚀 开始翻译（后台持续）');
                        var errMsg = response.data && response.data.message ? response.data.message : '处理失败';
                        $('.fanyi2-pretranslate-status').text('❌ ' + errMsg);
                        Fanyi2Admin.showAdminNotice('error', errMsg);
                        if (typeof opts.onDone === 'function') {
                            opts.onDone('error', response.data || {});
                        }
                        if (response.data && response.data.total_strings) {
                            var translatedErr = parseInt(response.data.translated_total || 0, 10);
                            var totalErr = parseInt(response.data.total_strings || 0, 10);
                            var errPercent = totalErr > 0 ? Math.round((translatedErr / totalErr) * 100) : 0;
                            $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', errPercent + '%');
                        }
                    }
                },
                error: function(xhr, textStatus) {
                    if (textStatus === 'timeout') {
                        var retryBatch = Math.max(50, Math.floor(batchSize / 2));
                        if (stalledRetries < 4 && retryBatch < batchSize) {
                            $('.fanyi2-pretranslate-status').text('⚠ 请求超时，自动降批到 ' + retryBatch + ' 重试中...（' + (stalledRetries + 1) + '/4）');
                            setTimeout(function() {
                                self.runPretranslateBatch(targetLang, scope, retryBatch, totalTranslated, stalledRetries + 1, opts);
                            }, 300);
                            return;
                        }
                    }
                    $('#fanyi2-start-pretranslate').prop('disabled', false).text('🚀 开始翻译（后台持续）');
                    $('.fanyi2-pretranslate-status').text('❌ 请求失败');
                    Fanyi2Admin.showAdminNotice('error', '请求失败，请稍后重试');
                    if (typeof opts.onDone === 'function') {
                        opts.onDone('error', {});
                    }
                }
            });
        },

        startBackgroundTranslation: function() {
            var self = this;
            var targetLang = $('#fanyi2-batch-target-lang').val();
            var scope = this.getPretranslateScope();
            var batchSize = this.getPreferredBatchSize();
            var $startBtn = $('#fanyi2-start-pretranslate');

            if (!targetLang) {
                this.showAdminNotice('error', '请先选择目标语言');
                return;
            }

            $startBtn.prop('disabled', true).text('启动中...');
            $('#fanyi2-background-status').text('正在启动后台翻译任务...');
            $('#fanyi2-pretranslate-progress').show();

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_start_background_translation',
                    nonce: fanyi2_admin.nonce,
                    language: targetLang,
                    scope: scope,
                    batch_size: batchSize
                },
                timeout: 60000,
                success: function(response) {
                    if (response && response.success) {
                        self.showAdminNotice('success', response.data.message || '后台持续翻译已启动');
                        self.renderBackgroundStatus(response.data.job || {});
                        self.startBackgroundStatusPolling();
                    } else {
                        $startBtn.prop('disabled', false).text('🚀 开始翻译（后台持续）');
                        self.showAdminNotice('error', (response && response.data && response.data.message) || '启动后台翻译失败');
                        self.loadBackgroundTranslationStatus();
                    }
                },
                error: function() {
                    $startBtn.prop('disabled', false).text('🚀 开始翻译（后台持续）');
                    self.showAdminNotice('error', '启动后台翻译请求失败');
                    self.loadBackgroundTranslationStatus();
                }
            });
        },

        stopBackgroundTranslation: function() {
            var self = this;
            $('#fanyi2-stop-background-translation').prop('disabled', true).text('停止中...');

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_stop_background_translation',
                    nonce: fanyi2_admin.nonce
                },
                timeout: 60000,
                success: function(response) {
                    $('#fanyi2-stop-background-translation').prop('disabled', false).text('⏹ 停止后台翻译');
                    if (response && response.success) {
                        self.showAdminNotice('success', response.data.message || '后台翻译已停止');
                        self.stopBackgroundStatusPolling();
                        self.renderBackgroundStatus(response.data.job || {});
                    } else {
                        self.showAdminNotice('error', (response && response.data && response.data.message) || '停止后台翻译失败');
                        self.loadBackgroundTranslationStatus();
                    }
                },
                error: function() {
                    $('#fanyi2-stop-background-translation').prop('disabled', false).text('⏹ 停止后台翻译');
                    self.showAdminNotice('error', '停止后台翻译请求失败');
                }
            });
        },

        loadBackgroundTranslationStatus: function() {
            var self = this;
            if ($('#fanyi2-background-status').length === 0) {
                return;
            }

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_get_background_translation_status',
                    nonce: fanyi2_admin.nonce
                },
                timeout: 60000,
                success: function(response) {
                    if (!response || !response.success) {
                        return;
                    }
                    var job = response.data && response.data.job ? response.data.job : {};
                    var progress = response.data && response.data.progress ? response.data.progress : null;
                    self.renderBackgroundStatus(job, progress);
                    if (job && parseInt(job.running || 0, 10) === 1) {
                        self.startBackgroundStatusPolling();
                    } else {
                        self.stopBackgroundStatusPolling();
                    }
                }
            });
        },

        renderBackgroundStatus: function(job, progress) {
            job = job || {};
            progress = progress || null;

            var running = parseInt(job.running || 0, 10) === 1;
            var status = job.status || 'idle';
            var lang = job.language || '-';
            var scope = job.scope || 'all';
            var translatedTotal = parseInt((progress && progress.translated_total) || job.translated_total || 0, 10);
            var totalStrings = parseInt((progress && progress.total_strings) || job.total_strings || 0, 10);
            var remaining = parseInt((progress && progress.remaining) || job.remaining || 0, 10);
            var updatedAt = job.updated_at || (progress && progress.updated_at) || '';
            var message = job.last_message || '';
            if (message.indexOf('；警告：') !== -1) {
                message = message.split('；警告：')[0];
            }
            if (message.indexOf('警告：') !== -1) {
                message = message.split('警告：')[0];
            }

            var statusText = '空闲';
            if (running || status === 'running') {
                statusText = '运行中';
            } else if (status === 'completed') {
                statusText = '已完成';
            } else if (status === 'stopped') {
                statusText = '已停止';
            } else if (status === 'error') {
                statusText = '异常';
            }

            var text = '后台翻译状态：' + statusText + ' | 语言：' + lang + ' | 范围：' + scope;
            if (totalStrings > 0) {
                text += ' | 进度：' + translatedTotal + ' / ' + totalStrings + '（剩余 ' + remaining + '）';
            }
            if (updatedAt) {
                text += ' | 更新：' + updatedAt;
            }
            if (message && status !== 'running') {
                text += ' | ' + message;
            }

            $('#fanyi2-background-status').text(text);
            $('#fanyi2-start-pretranslate')
                .prop('disabled', running)
                .text(running ? '⏳ 后台翻译运行中' : '🚀 开始翻译（后台持续）');
            $('#fanyi2-stop-background-translation').prop('disabled', !running);
        },

        startBackgroundStatusPolling: function() {
            var self = this;
            self.stopBackgroundStatusPolling();
            self.backgroundStatusTimer = setInterval(function() {
                self.loadBackgroundTranslationStatus();
            }, 5000);
        },

        stopBackgroundStatusPolling: function() {
            if (this.backgroundStatusTimer) {
                clearInterval(this.backgroundStatusTimer);
                this.backgroundStatusTimer = null;
            }
        },

        clearTargetLanguageTranslations: function() {
            var self = this;
            var targetLang = $('#fanyi2-batch-target-lang').val();
            var langName = (fanyi2_admin.languages && fanyi2_admin.languages[targetLang]) ? fanyi2_admin.languages[targetLang] : targetLang;

            if (!targetLang) {
                this.showAdminNotice('error', '请先选择目标语言');
                return;
            }

            if (!window.confirm('确认清空语言 [' + langName + '] 的全部翻译吗？此操作不可撤销。')) {
                return;
            }

            $('#fanyi2-clear-target-lang-translations').prop('disabled', true).text('清空中...');

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_clear_language_translations',
                    nonce: fanyi2_admin.nonce,
                    language: targetLang
                },
                timeout: 60000,
                success: function(response) {
                    $('#fanyi2-clear-target-lang-translations').prop('disabled', false).text('🗑 清空目标语言翻译');
                    if (response && response.success) {
                        self.showAdminNotice('success', response.data.message || '已清空目标语言翻译');
                        self.loadPretranslateProgress();
                        self.loadBackgroundTranslationStatus();
                    } else {
                        self.showAdminNotice('error', (response && response.data && response.data.message) || '清空失败');
                    }
                },
                error: function() {
                    $('#fanyi2-clear-target-lang-translations').prop('disabled', false).text('🗑 清空目标语言翻译');
                    self.showAdminNotice('error', '清空目标语言翻译请求失败');
                }
            });
        },

        applyDefaultLanguageCleanup: function() {
            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_apply_default_language_cleanup',
                    nonce: fanyi2_admin.nonce,
                },
                timeout: 600000,
                success: function(response) {
                    if (response && response.success) {
                        var stats = (response.data && response.data.stats) || {};
                        var summary = '默认语言清洗完成：内容 ' + (stats.posts_updated || 0) + '、内容元数据 ' + (stats.postmeta_updated || 0) +
                            '、菜单 ' + (stats.menu_items_updated || 0) + '、分类 ' + (stats.terms_updated || 0) +
                            '、分类元数据 ' + (stats.termmeta_updated || 0) + '、站点设置 ' + (stats.options_updated || 0) + '。';
                        $('.fanyi2-pretranslate-status').text('✅ ' + summary);
                        Fanyi2Admin.showAdminNotice('success', summary);
                    } else {
                        Fanyi2Admin.showAdminNotice('error', (response && response.data && response.data.message) || '默认语言清洗写库失败');
                    }
                },
                error: function() {
                    Fanyi2Admin.showAdminNotice('error', '默认语言清洗写库请求失败');
                }
            });
        },

        /**
         * 翻译所有语言
         */
        translateAllLanguages: function() {
            var enabledLangs = fanyi2_admin.settings.enabled_languages;
            var defaultLang = fanyi2_admin.settings.default_language;
            var scope = this.getPretranslateScope();
            var batchSize = this.getPreferredBatchSize();

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

                self.pretranslateLanguage(lang, scope, batchSize, function() {
                    langIndex++;
                    var percent = Math.round((langIndex / targetLangs.length) * 100);
                    $('#fanyi2-pretranslate-progress .fanyi2-progress-bar-fill').css('width', percent + '%');
                    processNextLang();
                });
            };

            processNextLang();
        },

        pretranslateLanguage: function(lang, scope, batchSize, callback) {
            var self = this;

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_batch_pretranslate',
                    nonce: fanyi2_admin.nonce,
                    language: lang,
                    scope: scope,
                    batch_size: batchSize,
                    rounds_per_request: 5
                },
                timeout: 300000,
                success: function(response) {
                    if (response.success && response.data.status === 'running' && response.data.remaining > 0) {
                        var warnings = (response.data.warnings && response.data.warnings.length) ? response.data.warnings : [];
                        var hasTimeoutWarning = warnings.some(function(w) {
                            var s = String(w || '').toLowerCase();
                            return s.indexOf('timed out') !== -1 || s.indexOf('curl error 28') !== -1 || s.indexOf('网络超时') !== -1;
                        });
                        var nextBatchSize = hasTimeoutWarning ? Math.max(50, Math.floor(batchSize * 0.7)) : batchSize;
                        setTimeout(function() {
                            self.pretranslateLanguage(lang, scope, nextBatchSize, callback);
                        }, 50);
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
                    if (!response.success) {
                        Fanyi2Admin.showAdminNotice('error', response.data.message || '加载失败');
                        return;
                    }

                    var data = response.data;
                    var $modal = $('#fanyi2-edit-modal');
                    $modal.data('string-id', stringId);

                    // 填充原文
                    $('#fanyi2-modal-original').val(data.original);

                    // 显示元信息（类型和页面位置）
                    var metaText = '类型: ' + (data.element_type || '-');
                    if (data.page_url) {
                        metaText += ' | 来源: ' + data.page_url;
                    }
                    $('#fanyi2-modal-meta').text(metaText);

                    // 构建各语言翻译输入框
                    var $transContainer = $('#fanyi2-modal-translations');
                    $transContainer.empty();

                    var translations = data.translations || {};
                    $.each(translations, function(lang, info) {
                        var fieldHtml = '<div class="fanyi2-field" style="margin-bottom:10px;">' +
                            '<label><strong>' + Fanyi2Admin.escapeHtml(info.lang_name) + ' (' + Fanyi2Admin.escapeHtml(lang) + ')</strong>' +
                            (info.source ? ' <span style="color:#999;font-size:12px;">[' + Fanyi2Admin.escapeHtml(info.source) + ']</span>' : '') +
                            '</label>' +
                            '<textarea class="fanyi2-modal-lang-input" data-lang="' + Fanyi2Admin.escapeHtml(lang) + '" rows="2" style="width:100%;">' +
                            Fanyi2Admin.escapeHtml(info.translated) + '</textarea>' +
                            '</div>';
                        $transContainer.append(fieldHtml);
                    });

                    $modal.show();
                },
                error: function() {
                    Fanyi2Admin.showAdminNotice('error', '加载字符串详情失败');
                }
            });
        },

        /**
         * 清除翻译（保留字符串本身）
         */
        clearTranslations: function(stringId) {
            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_clear_translations',
                    nonce: fanyi2_admin.nonce,
                    string_id: stringId
                },
                success: function(response) {
                    if (response.success) {
                        // 更新行内状态为未翻译
                        var $row = $('tr[data-string-id="' + stringId + '"]');
                        $row.find('.fanyi2-lang-badge').removeClass('translated').addClass('untranslated');
                        $row.find('.fanyi2-status-indicator')
                            .removeClass('status-complete status-partial')
                            .addClass('status-none')
                            .text('未翻译');
                        Fanyi2Admin.showAdminNotice('success', '已清除所有翻译');
                    } else {
                        Fanyi2Admin.showAdminNotice('error', response.data.message);
                    }
                }
            });
        },

        /**
         * 单条字符串 AI 翻译所有语言（逐语言顺序执行）
         */
        aiTranslateSingle: function(stringId, $btn) {
            var originalText = $btn.text();
            $btn.prop('disabled', true).text('⏳');

            // 先获取字符串详情
            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_get_string_detail',
                    nonce: fanyi2_admin.nonce,
                    string_id: stringId
                },
                success: function(response) {
                    if (!response.success) {
                        $btn.prop('disabled', false).text(originalText);
                        Fanyi2Admin.showAdminNotice('error', response.data.message || '获取失败');
                        return;
                    }

                    var data = response.data;
                    var translations = data.translations || {};
                    var langs = Object.keys(translations);
                    var total = langs.length;

                    if (total === 0) {
                        $btn.prop('disabled', false).text(originalText);
                        return;
                    }

                    var langIndex = 0;
                    var successCount = 0;
                    var failCount = 0;

                    // 逐语言顺序翻译，避免并发问题
                    function translateNext() {
                        if (langIndex >= total) {
                            // 全部完成
                            if (failCount === 0) {
                                $btn.prop('disabled', false).text('✅');
                                var $row = $('tr[data-string-id="' + stringId + '"]');
                                $row.find('.fanyi2-lang-badge').removeClass('untranslated').addClass('translated');
                                $row.find('.fanyi2-status-indicator')
                                    .removeClass('status-none status-partial')
                                    .addClass('status-complete')
                                    .text('已完成');
                            } else {
                                $btn.prop('disabled', false).text('⚠️');
                                Fanyi2Admin.showAdminNotice('error', '翻译完成，但有 ' + failCount + ' 个语言失败');
                            }
                            setTimeout(function() { $btn.text(originalText); }, 3000);
                            return;
                        }

                        var lang = langs[langIndex];
                        var langName = translations[lang].lang_name || lang;
                        $btn.text('⏳ ' + lang);

                        $.ajax({
                            url: fanyi2_admin.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'fanyi2_translate_single',
                                nonce: fanyi2_admin.nonce,
                                text: data.original,
                                target_language: lang
                            },
                            timeout: 30000,
                            success: function(tr) {
                                if (tr.success && tr.data.translated) {
                                    // 保存翻译
                                    var saveTrans = {};
                                    saveTrans[lang] = tr.data.translated;
                                    $.ajax({
                                        url: fanyi2_admin.ajax_url,
                                        type: 'POST',
                                        data: {
                                            action: 'fanyi2_update_translation',
                                            nonce: fanyi2_admin.nonce,
                                            string_id: stringId,
                                            translations: saveTrans
                                        },
                                        success: function() {
                                            successCount++;
                                            // 更新该语言徽章
                                            var $row = $('tr[data-string-id="' + stringId + '"]');
                                            $row.find('.fanyi2-lang-badge').each(function() {
                                                if ($(this).text().trim() === lang) {
                                                    $(this).removeClass('untranslated').addClass('translated');
                                                }
                                            });
                                            langIndex++;
                                            translateNext();
                                        },
                                        error: function() {
                                            failCount++;
                                            langIndex++;
                                            translateNext();
                                        }
                                    });
                                } else {
                                    failCount++;
                                    if (tr.data && tr.data.message) {
                                        console.warn('Fanyi2 AI translate failed for ' + lang + ': ' + tr.data.message);
                                    }
                                    langIndex++;
                                    translateNext();
                                }
                            },
                            error: function(xhr, status) {
                                failCount++;
                                console.warn('Fanyi2 AI translate error for ' + lang + ': ' + status);
                                langIndex++;
                                translateNext();
                            }
                        });
                    }

                    translateNext();
                },
                error: function() {
                    $btn.prop('disabled', false).text(originalText);
                    Fanyi2Admin.showAdminNotice('error', '请求失败');
                }
            });
        },

        /**
         * 弹窗中AI翻译（逐语言顺序执行，避免并发竞态）
         */
        modalAiTranslate: function() {
            var original = $('#fanyi2-modal-original').val();
            if (!original) return;

            var $btn = $('#fanyi2-modal-ai-translate');
            $btn.prop('disabled', true).text('翻译中...');

            // 获取所有目标语言输入框
            var $langInputs = $('#fanyi2-modal-translations .fanyi2-modal-lang-input');
            var inputs = $langInputs.toArray();
            var idx = 0;

            function translateNext() {
                if (idx >= inputs.length) {
                    $btn.prop('disabled', false).text('🤖 AI翻译所有');
                    return;
                }
                var $input = $(inputs[idx]);
                var lang = $input.data('lang');
                idx++;
                $btn.text('⏳ ' + lang + ' (' + idx + '/' + inputs.length + ')');

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
                        translateNext();
                    },
                    error: function() {
                        translateNext();
                    }
                });
            }
            translateNext();
        },

        /**
         * 弹窗保存翻译
         */
        modalSave: function() {
            var stringId = $('#fanyi2-edit-modal').data('string-id');
            var $langInputs = $('#fanyi2-modal-translations .fanyi2-modal-lang-input');
            var translations = {};

            $langInputs.each(function() {
                var lang = $(this).data('lang');
                var translated = $(this).val().trim();
                if (translated) {
                    translations[lang] = translated;
                }
            });

            if (Object.keys(translations).length === 0) {
                Fanyi2Admin.showAdminNotice('error', '没有翻译内容可保存');
                return;
            }

            var $btn = $('#fanyi2-modal-save');
            $btn.prop('disabled', true).text('保存中...');

            $.ajax({
                url: fanyi2_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_update_translation',
                    nonce: fanyi2_admin.nonce,
                    string_id: stringId,
                    translations: translations
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('💾 保存');
                    if (response.success) {
                        Fanyi2Admin.showAdminNotice('success', response.data.message);
                        $('#fanyi2-edit-modal').hide();
                        // 刷新当前行的翻译状态
                        location.reload();
                    } else {
                        Fanyi2Admin.showAdminNotice('error', response.data.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('💾 保存');
                    Fanyi2Admin.showAdminNotice('error', '保存失败，请重试');
                }
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
            var $notice = $('<div class="notice ' + classMap[type] + ' is-dismissible"><p></p></div>');
            $notice.find('p').text(message);
            
            // 移除旧通知
            $('.fanyi2-admin-wrap .notice.is-dismissible').not('.below-h2').remove();
            
            $('.fanyi2-admin-wrap h1').after($notice);
            
            // 自动消失
            setTimeout(function() {
                $notice.fadeOut(300, function() { $(this).remove(); });
            }, 5000);
        },

        /**
         * HTML转义
         */
        escapeHtml: function(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }
    };

    $(document).ready(function() {
        Fanyi2Admin.init();
    });

})(jQuery);
