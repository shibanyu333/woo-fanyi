/**
 * Fanyi2 可视化翻译编辑器脚本
 */
(function($) {
    'use strict';

    var Fanyi2Editor = {
        // 存储抓取的字符串
        strings: [],
        // 当前翻译
        translations: {},
        // 当前选中元素
        selectedElement: null,
        // 是否正在翻译
        isTranslating: false,
        // 取消标记
        cancelTranslation: false,

        init: function() {
            if (fanyi2_vars.is_editor !== '1') return;

            $('body').addClass('fanyi2-editor-mode');
            this.createTableModal();
            this.markEditableElements();
            this.bindEvents();
            this.loadExistingTranslations();
        },

        /**
         * 标记所有可编辑的文本元素
         */
        markEditableElements: function() {
            var skipTags = ['SCRIPT', 'STYLE', 'LINK', 'META', 'BR', 'HR', 'IMG', 'INPUT', 'SELECT', 'TEXTAREA', 'IFRAME', 'SVG', 'PATH', 'NOSCRIPT'];
            var skipClasses = ['fanyi2-', 'wp-admin'];
            var count = 0;

            // 遍历所有文本节点的父元素
            $('body *').each(function() {
                var $el = $(this);
                var el = this;

                // 跳过编辑器自身元素
                if ($el.closest('#fanyi2-editor-toolbar, #fanyi2-language-switcher, #fanyi2-edit-trigger, #wpadminbar, #fanyi2-table-modal').length) {
                    return;
                }

                // 跳过特定标签
                if (skipTags.indexOf(el.tagName) !== -1) return;

                // 跳过fanyi2相关class
                var className = $el.attr('class') || '';
                for (var i = 0; i < skipClasses.length; i++) {
                    if (className.indexOf(skipClasses[i]) !== -1) return;
                }

                // 检查是否有直接文本内容
                var directText = Fanyi2Editor.getDirectText(el);
                if (directText && directText.trim().length >= 2) {
                    $el.attr('data-fanyi2-editable', 'true');
                    $el.attr('data-fanyi2-text', directText.trim());
                    count++;
                }

                // 检查属性文本
                var attrs = ['alt', 'title', 'placeholder'];
                attrs.forEach(function(attr) {
                    var val = $el.attr(attr);
                    if (val && val.trim().length >= 2) {
                        $el.attr('data-fanyi2-attr-' + attr, val.trim());
                    }
                });
            });

            console.log('Fanyi2: 标记了 ' + count + ' 个可编辑元素');
        },

        /**
         * 获取元素的直接文本内容（不含子元素）
         */
        getDirectText: function(element) {
            var text = '';
            for (var i = 0; i < element.childNodes.length; i++) {
                if (element.childNodes[i].nodeType === 3) { // 文本节点
                    text += element.childNodes[i].textContent;
                }
            }
            return text.trim();
        },

        /**
         * 获取元素的CSS选择器路径
         */
        getSelector: function(element) {
            if (element.id) return '#' + element.id;

            var path = [];
            while (element && element.nodeType === 1) {
                var selector = element.tagName.toLowerCase();
                if (element.id) {
                    selector = '#' + element.id;
                    path.unshift(selector);
                    break;
                }
                if (element.className) {
                    var classes = element.className.toString().trim().split(/\s+/)
                        .filter(function(c) { return c && c.indexOf('fanyi2') === -1; })
                        .slice(0, 2);
                    if (classes.length > 0) {
                        selector += '.' + classes.join('.');
                    }
                }

                var sibling = element;
                var nth = 1;
                while (sibling = sibling.previousElementSibling) {
                    if (sibling.tagName === element.tagName) nth++;
                }
                if (nth > 1) selector += ':nth-of-type(' + nth + ')';

                path.unshift(selector);
                element = element.parentNode;
                if (element === document.body) {
                    path.unshift('body');
                    break;
                }
            }
            return path.join(' > ');
        },

        /**
         * 绑定事件
         */
        bindEvents: function() {
            var self = this;

            // 点选文本元素
            $(document).on('click', '[data-fanyi2-editable]', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.selectElement(this);
            });

            // 抓取所有文本
            $('#fanyi2-grab-all').on('click', function() {
                self.grabAllStrings();
            });

            // 一键抓取并AI翻译
            $('#fanyi2-grab-and-translate').on('click', function() {
                self.grabAndTranslateAll();
            });

            // 关闭翻译面板
            $('#fanyi2-panel-close').on('click', function() {
                $('#fanyi2-editor-panel').hide();
                self.deselectAll();
            });

            // 单条AI翻译
            $('#fanyi2-ai-translate-single').on('click', function() {
                self.translateSingle();
            });

            // 保存单条
            $('#fanyi2-save-single').on('click', function() {
                self.saveSingle();
            });

            // 取消翻译
            $('#fanyi2-cancel-translate').on('click', function() {
                self.cancelTranslation = true;
            });

            // ===== 表格弹窗事件 =====
            $('#fanyi2-table-close').on('click', function() {
                $('#fanyi2-table-modal').hide();
            });

            $('#fanyi2-table-ai-all').on('click', function() {
                self.tableTranslateAll();
            });

            $('#fanyi2-table-save-all').on('click', function() {
                self.tableSaveAll();
            });

            $(document).on('click', '.fanyi2-row-ai-btn', function() {
                var idx = $(this).data('index');
                self.tableTranslateRow(idx);
            });

            $(document).on('click', '.fanyi2-row-save-btn', function() {
                var idx = $(this).data('index');
                self.tableSaveRow(idx);
            });

            // 复制全部原文（批量区按钮）
            $('#fanyi2-copy-originals').on('click', function() {
                var $ta = $('#fanyi2-batch-originals');
                $ta[0].select();
                document.execCommand('copy');
                self.showNotice('success', '已复制 ' + self.strings.length + ' 条原文到剪贴板');
            });

            // 一键复制原文（表格顶部醒目按钮）
            $('#fanyi2-table-copy-all').on('click', function() {
                var lines = [];
                $('#fanyi2-edit-table tbody tr').each(function() {
                    var text = $(this).find('.fanyi2-cell-original').text().trim();
                    if (text) lines.push(text);
                });
                if (!lines.length) {
                    self.showNotice('warning', '表格中没有原文可复制');
                    return;
                }
                var textToCopy = lines.join('\n');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(textToCopy).then(function() {
                        self.showNotice('success', '已复制 ' + lines.length + ' 条原文到剪贴板');
                    });
                } else {
                    // fallback
                    var $ta = $('#fanyi2-batch-originals');
                    $ta[0].select();
                    document.execCommand('copy');
                    self.showNotice('success', '已复制 ' + lines.length + ' 条原文到剪贴板');
                }
            });

            // 粘贴翻译后应用到表格
            $('#fanyi2-apply-paste').on('click', function() {
                self.applyPastedTranslations();
            });

            // 目标语言切换时刷新表格翻译
            $('#fanyi2-editor-target-lang').on('change', function() {
                self.loadExistingTranslations();
                if ($('#fanyi2-table-modal').is(':visible')) {
                    self.refreshTableTranslations();
                }
            });
        },

        /**
         * 选中一个文本元素（点选翻译）
         */
        selectElement: function(element) {
            var $el = $(element);
            var text = $el.attr('data-fanyi2-text') || $el.text().trim();

            // 清除之前选中
            this.deselectAll();

            // 高亮选中
            $el.addClass('fanyi2-selected');
            this.selectedElement = element;

            // 显示翻译面板
            $('#fanyi2-original-text').val(text);
            $('#fanyi2-translated-text').val('');

            // 查看是否有已保存的翻译
            var targetLang = $('#fanyi2-editor-target-lang').val();
            var key = this.hashString(text);
            if (this.translations[key] && this.translations[key][targetLang]) {
                $('#fanyi2-translated-text').val(this.translations[key][targetLang]);
            }

            $('#fanyi2-editor-panel').show();
        },

        deselectAll: function() {
            $('[data-fanyi2-editable]').removeClass('fanyi2-selected');
            this.selectedElement = null;
        },

        /**
         * 创建表格弹窗 DOM
         */
        createTableModal: function() {
            var modal = '<div id="fanyi2-table-modal" class="fanyi2-table-modal" style="display:none;">' +
                '<div class="fanyi2-table-modal-content">' +
                    '<div class="fanyi2-table-modal-header">' +
                        '<h2>📝 翻译编辑表格</h2>' +
                        '<div class="fanyi2-table-modal-actions">' +
                            '<button id="fanyi2-table-copy-all" class="fanyi2-toolbar-btn fanyi2-btn-warning">📋 一键复制原文</button>' +
                            '<button id="fanyi2-table-ai-all" class="fanyi2-toolbar-btn fanyi2-btn-success">🤖 AI翻译全部</button>' +
                            '<button id="fanyi2-table-save-all" class="fanyi2-toolbar-btn fanyi2-btn-primary">💾 保存全部</button>' +
                            '<button id="fanyi2-table-close" class="fanyi2-toolbar-btn fanyi2-btn-secondary">✕ 关闭</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="fanyi2-table-modal-info">' +
                        '<span id="fanyi2-table-stats"></span>' +
                    '</div>' +
                    /* ===== 批量复制粘贴区 ===== */
                    '<div class="fanyi2-batch-paste-area">' +
                        '<div class="fanyi2-batch-col">' +
                            '<div class="fanyi2-batch-col-header">' +
                                '<strong>📋 原文（一行一条，可全选复制）</strong>' +
                                '<button id="fanyi2-copy-originals" class="fanyi2-mini-btn" title="复制全部原文">复制全部</button>' +
                            '</div>' +
                            '<textarea id="fanyi2-batch-originals" readonly class="fanyi2-batch-textarea"></textarea>' +
                        '</div>' +
                        '<div class="fanyi2-batch-col">' +
                            '<div class="fanyi2-batch-col-header">' +
                                '<strong>📝 翻译（粘贴翻译结果，一行对应一条原文）</strong>' +
                                '<button id="fanyi2-apply-paste" class="fanyi2-mini-btn fanyi2-mini-btn-primary" title="应用到下方表格">应用到表格</button>' +
                            '</div>' +
                            '<textarea id="fanyi2-batch-translations" class="fanyi2-batch-textarea" placeholder="将翻译器的结果粘贴到此处，每行对应一条原文..."></textarea>' +
                        '</div>' +
                    '</div>' +
                    '<div class="fanyi2-batch-tip">💡 工作流：复制左侧原文 → 粘贴到翻译器(如Google/DeepL) → 复制翻译结果 → 粘贴到右侧 → 点击"应用到表格" → 保存全部</div>' +
                    /* ===== 逐行编辑表格 ===== */
                    '<div class="fanyi2-table-modal-body">' +
                        '<table class="fanyi2-edit-table" id="fanyi2-edit-table">' +
                            '<thead><tr>' +
                                '<th class="fanyi2-col-num">#</th>' +
                                '<th class="fanyi2-col-original">原文 (源语言)</th>' +
                                '<th class="fanyi2-col-translated">翻译 (目标语言)</th>' +
                                '<th class="fanyi2-col-actions">操作</th>' +
                            '</tr></thead>' +
                            '<tbody></tbody>' +
                        '</table>' +
                    '</div>' +
                '</div>' +
            '</div>';
            $('body').append(modal);
        },

        /**
         * 抓取页面所有文本字符串，完成后弹出表格
         */
        grabAllStrings: function() {
            var self = this;
            var strings = [];

            $('[data-fanyi2-editable]').each(function() {
                var $el = $(this);
                var text = $el.attr('data-fanyi2-text') || $el.text().trim();
                if (text && text.length >= 2) {
                    strings.push({
                        text: text,
                        selector: self.getSelector(this),
                        type: this.tagName.toLowerCase()
                    });
                }
            });

            // 去重
            var unique = {};
            strings = strings.filter(function(s) {
                if (unique[s.text]) return false;
                unique[s.text] = true;
                return true;
            });

            self.strings = strings;

            var $btn = $('#fanyi2-grab-all');
            $btn.prop('disabled', true).text('抓取中...');

            // 发送到服务器保存
            $.ajax({
                url: fanyi2_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_grab_page_strings',
                    nonce: fanyi2_vars.nonce,
                    strings: strings,
                    page_url: window.location.pathname
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('📥 抓取所有文本');
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        self.strings = response.data.strings;
                        self.showTableModal();
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('📥 抓取所有文本');
                    self.showNotice('error', '抓取失败，请重试');
                }
            });
        },

        /**
         * 一键抓取并AI翻译全部
         */
        grabAndTranslateAll: function() {
            var self = this;
            var strings = [];

            $('[data-fanyi2-editable]').each(function() {
                var $el = $(this);
                var text = $el.attr('data-fanyi2-text') || $el.text().trim();
                if (text && text.length >= 2) {
                    strings.push({
                        text: text,
                        selector: self.getSelector(this),
                        type: this.tagName.toLowerCase()
                    });
                }
            });

            var unique = {};
            strings = strings.filter(function(s) {
                if (unique[s.text]) return false;
                unique[s.text] = true;
                return true;
            });

            self.strings = strings;

            var $btn = $('#fanyi2-grab-and-translate');
            $btn.prop('disabled', true).text('抓取中...');

            $.ajax({
                url: fanyi2_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_grab_page_strings',
                    nonce: fanyi2_vars.nonce,
                    strings: strings,
                    page_url: window.location.pathname
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('🤖 一键抓取并翻译');
                    if (response.success) {
                        self.strings = response.data.strings;
                        self.showTableModal();
                        // 抓取完成后立即开始AI翻译全部
                        setTimeout(function() {
                            self.tableTranslateAll();
                        }, 300);
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('🤖 一键抓取并翻译');
                    self.showNotice('error', '抓取失败，请重试');
                }
            });
        },

        /**
         * 显示表格弹窗
         */
        showTableModal: function() {
            var self = this;
            var targetLang = $('#fanyi2-editor-target-lang').val();
            var langNames = fanyi2_vars.language_names || {};
            var targetName = langNames[targetLang] || targetLang;
            var defaultLang = fanyi2_vars.default_language || 'zh';
            var sourceName = langNames[defaultLang] || defaultLang;

            // 更新表头
            $('#fanyi2-edit-table thead .fanyi2-col-original').text('原文 (' + sourceName + ')');
            $('#fanyi2-edit-table thead .fanyi2-col-translated').text('翻译 (' + targetName + ')');

            // 构建表格行
            var $tbody = $('#fanyi2-edit-table tbody');
            $tbody.empty();

            self.strings.forEach(function(str, index) {
                var key = self.hashString(str.text);
                var existingTrans = '';
                if (self.translations[key] && self.translations[key][targetLang]) {
                    existingTrans = self.translations[key][targetLang];
                }

                var statusClass = existingTrans ? 'fanyi2-row-translated' : 'fanyi2-row-untranslated';

                var row = '<tr class="' + statusClass + '" data-index="' + index + '">' +
                    '<td class="fanyi2-col-num">' + (index + 1) + '</td>' +
                    '<td class="fanyi2-col-original">' +
                        '<div class="fanyi2-cell-text" title="' + self.escapeAttr(str.text) + '">' + self.escapeHtml(str.text) + '</div>' +
                    '</td>' +
                    '<td class="fanyi2-col-translated">' +
                        '<textarea class="fanyi2-table-input" data-index="' + index + '" rows="1" placeholder="输入翻译...">' + self.escapeHtml(existingTrans) + '</textarea>' +
                    '</td>' +
                    '<td class="fanyi2-col-actions">' +
                        '<button class="fanyi2-row-ai-btn" data-index="' + index + '" title="AI翻译此条">🤖</button>' +
                        '<button class="fanyi2-row-save-btn" data-index="' + index + '" title="保存此条">💾</button>' +
                    '</td>' +
                '</tr>';

                $tbody.append(row);
            });

            self.updateTableStats();
            $('#fanyi2-table-modal').show();

            // 填充批量复制粘贴区的原文
            var originalsText = self.strings.map(function(s) { return s.text; }).join('\n');
            $('#fanyi2-batch-originals').val(originalsText);

            // 如果已有翻译，填充右侧
            var transText = self.strings.map(function(s) {
                var key = self.hashString(s.text);
                if (self.translations[key] && self.translations[key][targetLang]) {
                    return self.translations[key][targetLang];
                }
                return '';
            }).join('\n');
            $('#fanyi2-batch-translations').val(transText);

            // 自动调整 textarea 高度
            $('.fanyi2-table-input').each(function() {
                self.autoResizeTextarea(this);
            }).on('input', function() {
                self.autoResizeTextarea(this);
            });
        },

        /**
         * 自动调整textarea高度
         */
        autoResizeTextarea: function(el) {
            el.style.height = 'auto';
            el.style.height = Math.max(32, el.scrollHeight) + 'px';
        },

        /**
         * 将粘贴的翻译按行应用到表格
         */
        applyPastedTranslations: function() {
            var self = this;
            var pastedText = $('#fanyi2-batch-translations').val();
            if (!pastedText.trim()) {
                self.showNotice('error', '右侧翻译区为空，请先粘贴翻译内容');
                return;
            }

            var lines = pastedText.split('\n');
            var applied = 0;
            var targetLang = $('#fanyi2-editor-target-lang').val();

            self.strings.forEach(function(str, index) {
                if (index < lines.length && lines[index].trim()) {
                    var translated = lines[index].trim();
                    var $input = $('.fanyi2-table-input[data-index="' + index + '"]');
                    $input.val(translated);
                    self.autoResizeTextarea($input[0]);
                    $input.closest('tr').removeClass('fanyi2-row-untranslated fanyi2-row-saved').addClass('fanyi2-row-translated');

                    var key = self.hashString(str.text);
                    if (!self.translations[key]) self.translations[key] = {};
                    self.translations[key][targetLang] = translated;
                    applied++;
                }
            });

            self.updateTableStats();

            if (lines.length !== self.strings.length) {
                self.showNotice('info', '已应用 ' + applied + ' 条翻译（粘贴 ' + lines.length + ' 行，原文共 ' + self.strings.length + ' 条）');
            } else {
                self.showNotice('success', '已成功应用 ' + applied + ' 条翻译到表格');
            }
        },

        /**
         * 刷新表格中已有翻译(切换语言后)
         */
        refreshTableTranslations: function() {
            var self = this;
            var targetLang = $('#fanyi2-editor-target-lang').val();
            var langNames = fanyi2_vars.language_names || {};
            var targetName = langNames[targetLang] || targetLang;

            $('#fanyi2-edit-table thead .fanyi2-col-translated').text('翻译 (' + targetName + ')');

            self.strings.forEach(function(str, index) {
                var key = self.hashString(str.text);
                var existingTrans = '';
                if (self.translations[key] && self.translations[key][targetLang]) {
                    existingTrans = self.translations[key][targetLang];
                }
                var $input = $('.fanyi2-table-input[data-index="' + index + '"]');
                $input.val(existingTrans);
                var $row = $input.closest('tr');
                $row.removeClass('fanyi2-row-translated fanyi2-row-untranslated fanyi2-row-saved');
                $row.addClass(existingTrans ? 'fanyi2-row-translated' : 'fanyi2-row-untranslated');
            });

            self.updateTableStats();

            // 同步更新批量粘贴区
            var transText = self.strings.map(function(s) {
                var k = self.hashString(s.text);
                if (self.translations[k] && self.translations[k][targetLang]) {
                    return self.translations[k][targetLang];
                }
                return '';
            }).join('\n');
            $('#fanyi2-batch-translations').val(transText);
        },

        /**
         * 更新表格统计信息
         */
        updateTableStats: function() {
            var total = this.strings.length;
            var translated = $('#fanyi2-edit-table .fanyi2-row-translated, #fanyi2-edit-table .fanyi2-row-saved').length;
            var untranslated = total - translated;
            $('#fanyi2-table-stats').html(
                '<strong>共 ' + total + ' 条</strong> | ' +
                '<span style="color:#10b981">已翻译 ' + translated + '</span> | ' +
                '<span style="color:#ef4444">未翻译 ' + untranslated + '</span>'
            );
        },

        /**
         * 表格 - AI翻译全部未翻译的条目
         */
        tableTranslateAll: function() {
            var self = this;
            if (self.isTranslating) return;

            var targetLang = $('#fanyi2-editor-target-lang').val();

            // 收集所有空翻译的索引
            var toTranslate = [];
            self.strings.forEach(function(str, index) {
                var $input = $('.fanyi2-table-input[data-index="' + index + '"]');
                if (!$input.val().trim()) {
                    toTranslate.push(index);
                }
            });

            if (toTranslate.length === 0) {
                self.showNotice('info', '所有条目都已有翻译');
                return;
            }

            self.isTranslating = true;
            self.cancelTranslation = false;

            $('#fanyi2-progress-overlay').show();
            var total = toTranslate.length;
            var processed = 0;
            var batchSize = 10;

            var batches = [];
            for (var i = 0; i < toTranslate.length; i += batchSize) {
                batches.push(toTranslate.slice(i, i + batchSize));
            }

            var processBatch = function(batchIndex) {
                if (batchIndex >= batches.length || self.cancelTranslation) {
                    self.isTranslating = false;
                    $('#fanyi2-progress-overlay').hide();
                    if (!self.cancelTranslation) {
                        self.showNotice('success', '翻译完成！共翻译 ' + processed + ' 条');
                        self.updateTableStats();
                    }
                    return;
                }

                var batch = batches[batchIndex];
                var texts = {};
                batch.forEach(function(idx) {
                    texts[self.strings[idx].text] = self.strings[idx].text;
                });

                var percent = Math.round((processed / total) * 100);
                self.updateProgress(percent,
                    '正在翻译第 ' + (processed + 1) + '-' + Math.min(processed + batch.length, total) + ' 条 / 共 ' + total + ' 条');

                $.ajax({
                    url: fanyi2_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fanyi2_translate_batch',
                        nonce: fanyi2_vars.nonce,
                        texts: texts,
                        target_language: targetLang
                    },
                    success: function(response) {
                        if (response.success) {
                            var results = response.data.translations;
                            batch.forEach(function(idx) {
                                var originalText = self.strings[idx].text;
                                if (results[originalText]) {
                                    var translated = results[originalText];
                                    var $input = $('.fanyi2-table-input[data-index="' + idx + '"]');
                                    $input.val(translated);
                                    self.autoResizeTextarea($input[0]);
                                    $input.closest('tr').removeClass('fanyi2-row-untranslated').addClass('fanyi2-row-translated');

                                    var key = self.hashString(originalText);
                                    if (!self.translations[key]) self.translations[key] = {};
                                    self.translations[key][targetLang] = translated;
                                    processed++;
                                }
                            });
                        }
                        setTimeout(function() { processBatch(batchIndex + 1); }, 500);
                    },
                    error: function() {
                        processed += batch.length;
                        setTimeout(function() { processBatch(batchIndex + 1); }, 1000);
                    }
                });
            };

            processBatch(0);
        },

        /**
         * 表格 - 单行AI翻译
         */
        tableTranslateRow: function(index) {
            var self = this;
            var str = self.strings[index];
            if (!str) return;

            var targetLang = $('#fanyi2-editor-target-lang').val();
            var $btn = $('.fanyi2-row-ai-btn[data-index="' + index + '"]');
            $btn.prop('disabled', true).text('⏳');

            $.ajax({
                url: fanyi2_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_translate_single',
                    nonce: fanyi2_vars.nonce,
                    text: str.text,
                    target_language: targetLang
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('🤖');
                    if (response.success) {
                        var $input = $('.fanyi2-table-input[data-index="' + index + '"]');
                        $input.val(response.data.translated);
                        self.autoResizeTextarea($input[0]);
                        $input.closest('tr').removeClass('fanyi2-row-untranslated').addClass('fanyi2-row-translated');

                        var key = self.hashString(str.text);
                        if (!self.translations[key]) self.translations[key] = {};
                        self.translations[key][targetLang] = response.data.translated;
                        self.updateTableStats();
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('🤖');
                    self.showNotice('error', '翻译请求失败');
                }
            });
        },

        /**
         * 表格 - 保存全部翻译
         */
        tableSaveAll: function() {
            var self = this;
            var targetLang = $('#fanyi2-editor-target-lang').val();
            var translations = [];

            self.strings.forEach(function(str, index) {
                var $input = $('.fanyi2-table-input[data-index="' + index + '"]');
                var translated = $input.val().trim();
                if (translated) {
                    translations.push({
                        original: str.text,
                        translated: translated,
                        selector: str.selector || ''
                    });
                }
            });

            if (translations.length === 0) {
                self.showNotice('error', '没有翻译可保存，请先翻译或输入翻译内容');
                return;
            }

            var $btn = $('#fanyi2-table-save-all');
            $btn.prop('disabled', true).text('保存中...');

            $.ajax({
                url: fanyi2_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_save_translations_batch',
                    nonce: fanyi2_vars.nonce,
                    translations: translations,
                    language: targetLang,
                    page_url: window.location.pathname
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('💾 保存全部');
                    if (response.success) {
                        self.showNotice('success', response.data.message);
                        translations.forEach(function(t) {
                            var key = self.hashString(t.original);
                            if (!self.translations[key]) self.translations[key] = {};
                            self.translations[key][targetLang] = t.translated;
                        });
                        // 标记已保存的行
                        $('#fanyi2-edit-table tbody tr').each(function() {
                            var $input = $(this).find('.fanyi2-table-input');
                            if ($input.val().trim()) {
                                $(this).removeClass('fanyi2-row-untranslated fanyi2-row-translated').addClass('fanyi2-row-saved');
                            }
                        });
                        self.updateTableStats();
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('💾 保存全部');
                    self.showNotice('error', '保存失败');
                }
            });
        },

        /**
         * 表格 - 保存单行翻译
         */
        tableSaveRow: function(index) {
            var self = this;
            var str = self.strings[index];
            if (!str) return;

            var targetLang = $('#fanyi2-editor-target-lang').val();
            var $input = $('.fanyi2-table-input[data-index="' + index + '"]');
            var translated = $input.val().trim();

            if (!translated) {
                self.showNotice('error', '请先输入翻译内容');
                return;
            }

            var $btn = $('.fanyi2-row-save-btn[data-index="' + index + '"]');
            $btn.prop('disabled', true).text('⏳');

            $.ajax({
                url: fanyi2_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_save_translation',
                    nonce: fanyi2_vars.nonce,
                    original_text: str.text,
                    translated_text: translated,
                    language: targetLang,
                    page_url: window.location.pathname,
                    selector: str.selector || ''
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('💾');
                    if (response.success) {
                        $input.closest('tr').removeClass('fanyi2-row-untranslated fanyi2-row-translated').addClass('fanyi2-row-saved');
                        var key = self.hashString(str.text);
                        if (!self.translations[key]) self.translations[key] = {};
                        self.translations[key][targetLang] = translated;
                        self.updateTableStats();
                        self.showNotice('success', '已保存');
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('💾');
                    self.showNotice('error', '保存失败');
                }
            });
        },

        /**
         * 单条AI翻译
         */
        translateSingle: function() {
            var self = this;
            var text = $('#fanyi2-original-text').val();
            var targetLang = $('#fanyi2-editor-target-lang').val();

            if (!text) return;

            var $btn = $('#fanyi2-ai-translate-single');
            $btn.prop('disabled', true).text('翻译中...');

            $.ajax({
                url: fanyi2_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_translate_single',
                    nonce: fanyi2_vars.nonce,
                    text: text,
                    target_language: targetLang
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('🤖 AI翻译');
                    if (response.success) {
                        $('#fanyi2-translated-text').val(response.data.translated);
                        // 保存到本地缓存
                        var key = self.hashString(text);
                        if (!self.translations[key]) {
                            self.translations[key] = {};
                        }
                        self.translations[key][targetLang] = response.data.translated;
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('🤖 AI翻译');
                    self.showNotice('error', '翻译请求失败');
                }
            });
        },

        /**
         * 保存单条翻译
         */
        saveSingle: function() {
            var self = this;
            var original = $('#fanyi2-original-text').val();
            var translated = $('#fanyi2-translated-text').val();
            var targetLang = $('#fanyi2-editor-target-lang').val();
            var selector = self.selectedElement ? self.getSelector(self.selectedElement) : '';

            if (!original || !translated) {
                self.showNotice('error', '请填写翻译内容');
                return;
            }

            $.ajax({
                url: fanyi2_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_save_translation',
                    nonce: fanyi2_vars.nonce,
                    original_text: original,
                    translated_text: translated,
                    language: targetLang,
                    page_url: window.location.pathname,
                    selector: selector
                },
                success: function(response) {
                    if (response.success) {
                        // 标记为已翻译
                        if (self.selectedElement) {
                            $(self.selectedElement).addClass('fanyi2-translated');
                        }
                        // 更新缓存
                        var key = self.hashString(original);
                        if (!self.translations[key]) {
                            self.translations[key] = {};
                        }
                        self.translations[key][targetLang] = translated;

                        self.showNotice('success', '翻译已保存');
                        $('#fanyi2-editor-panel').hide();
                        self.deselectAll();
                    } else {
                        self.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    self.showNotice('error', '保存失败');
                }
            });
        },

        /**
         * 加载已有翻译
         */
        loadExistingTranslations: function() {
            var self = this;
            var targetLang = $('#fanyi2-editor-target-lang').val();

            $.ajax({
                url: fanyi2_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'fanyi2_get_page_translations',
                    nonce: fanyi2_vars.nonce,
                    page_url: window.location.pathname,
                    language: targetLang
                },
                success: function(response) {
                    if (response.success && response.data.translations) {
                        response.data.translations.forEach(function(t) {
                            var key = self.hashString(t.original_string);
                            if (!self.translations[key]) {
                                self.translations[key] = {};
                            }
                            if (t.translated_string) {
                                self.translations[key][targetLang] = t.translated_string;
                                // 标记页面中已翻译的元素
                                $('[data-fanyi2-editable]').each(function() {
                                    var text = $(this).attr('data-fanyi2-text') || $(this).text().trim();
                                    if (text === t.original_string && t.translated_string) {
                                        $(this).addClass('fanyi2-translated');
                                    }
                                });
                            }
                        });
                    }
                }
            });
        },

        /**
         * 更新进度
         */
        updateProgress: function(percent, text) {
            $('.fanyi2-progress-fill').css('width', percent + '%');
            $('.fanyi2-progress-text').text(text);
        },

        /**
         * 显示通知
         */
        showNotice: function(type, message) {
            var $notice = $('<div class="fanyi2-notice-float fanyi2-notice-' + type + '">' + this.escapeHtml(message) + '</div>');
            $notice.css({
                position: 'fixed',
                top: '60px',
                left: '50%',
                transform: 'translateX(-50%)',
                zIndex: 99999999,
                padding: '12px 24px',
                borderRadius: '8px',
                fontSize: '14px',
                fontWeight: '500',
                boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                background: type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6',
                color: '#fff',
                opacity: 0,
                transition: 'opacity 0.3s'
            });
            $('body').append($notice);
            setTimeout(function() { $notice.css('opacity', 1); }, 10);
            setTimeout(function() {
                $notice.css('opacity', 0);
                setTimeout(function() { $notice.remove(); }, 300);
            }, 3000);
        },

        /**
         * 简单字符串哈希
         */
        hashString: function(str) {
            var hash = 0;
            for (var i = 0; i < str.length; i++) {
                var char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash |= 0;
            }
            return 'h' + Math.abs(hash).toString(36);
        },

        /**
         * HTML转义
         */
        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        },

        /**
         * 属性转义
         */
        escapeAttr: function(text) {
            return text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
    };

    $(document).ready(function() {
        Fanyi2Editor.init();
    });

})(jQuery);
