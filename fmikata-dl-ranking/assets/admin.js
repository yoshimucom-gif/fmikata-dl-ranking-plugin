/* global jQuery */
/**
 * 資料DLランキング — 管理画面。
 *
 * 集計はPHPで済んでいる。ここでやるのは「どれを載せるか」と「どの順で載せるか」、
 * そしてその場で本文を組み立てること。並べ替えるたびに番号を振り直すため、
 * 構造が変わったときだけ描き直し、文字入力のときはプレビューだけ更新している。
 */
(function ($) {
    'use strict';

    var NUMS = ['❶', '❷', '❸', '❹', '❺', '❻', '❼', '❽', '❾', '❿',
                '⓫', '⓬', '⓭', '⓮', '⓯', '⓰', '⓱', '⓲', '⓳', '⓴'];

    var DATA = null;

    function numGlyph(i) {
        return NUMS[i] || (i + 1) + '.';
    }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fill(tpl, vals) {
        return String(tpl).replace(/\{(no|title|comment|url|color)\}/g, function (m, k) {
            return vals[k] !== undefined ? vals[k] : m;
        });
    }

    /* ---------------------------------------------------------------- */

    function pickedOf(block) {
        return block.items.filter(function (it) { return it.picked; });
    }

    function poolOf(block) {
        return block.items.filter(function (it) { return !it.picked; });
    }

    function bodyHtml(block) {
        return pickedOf(block).map(function (it, i) {
            return fill(DATA.tplHtml, {
                no: esc(numGlyph(i)),
                title: esc(it.title),
                comment: esc(it.comment),
                url: esc(it.url),
                color: esc(DATA.color)
            });
        }).join('\n');
    }

    function bodyText(block) {
        return pickedOf(block).map(function (it, i) {
            return fill(DATA.tplText, {
                no: numGlyph(i),
                title: it.title,
                comment: it.comment,
                url: it.url,
                color: DATA.color
            });
        }).join('\n');
    }

    function trend(it) {
        var d = it.count - it.prev;
        if (it.prev === 0) return '前期間 0';
        if (d > 0) return '前期間 ' + it.prev + '（＋' + d + '）';
        if (d < 0) return '前期間 ' + it.prev + '（' + d + '）';
        return '前期間 ' + it.prev + '（増減なし）';
    }

    /* ---------------------------------------------------------------- */

    function itemMarkup(it, index, picked) {
        var no = picked ? '<span class="fdr-item__no">' + (index + 1) + '</span>' : '<span class="fdr-item__no fdr-item__no--pool">候補</span>';
        return '' +
            '<li class="fdr-item' + (picked ? '' : ' fdr-item--pool') + '" data-id="' + it.id + '">' +
                no +
                '<div class="fdr-item__body">' +
                    '<input type="text" class="fdr-item__title" value="' + esc(it.title) + '" aria-label="資料名">' +
                    '<div class="fdr-item__meta">' +
                        '<strong>' + it.count + '件</strong>' +
                        '<span>' + esc(trend(it)) + '</span>' +
                        (it.cats ? '<span>' + esc(it.cats) + '</span>' : '') +
                        '<a href="' + esc(it.url) + '" target="_blank" rel="noopener">ページを見る</a>' +
                    '</div>' +
                    '<input type="text" class="fdr-item__comment" value="' + esc(it.comment) + '" placeholder="≪ ここに一言 ≫" aria-label="一言コメント">' +
                '</div>' +
                '<div class="fdr-item__ops">' +
                    (picked
                        ? '<button type="button" class="button-link" data-act="up" title="上へ">↑</button>' +
                          '<button type="button" class="button-link" data-act="down" title="下へ">↓</button>' +
                          '<button type="button" class="button-link fdr-remove" data-act="toggle">外す</button>'
                        : '<button type="button" class="button button-small" data-act="toggle">載せる</button>') +
                '</div>' +
            '</li>';
    }

    function render($block, block) {
        var picked = pickedOf(block), pool = poolOf(block);

        $block.find('.fdr-list--picked').html(
            picked.length
                ? picked.map(function (it, i) { return itemMarkup(it, i, true); }).join('')
                : '<li class="fdr-empty">まだ1件も選ばれていません。右の候補から「載せる」を押してください。</li>'
        );
        $block.find('.fdr-list--pool').html(
            pool.length
                ? pool.map(function (it, i) { return itemMarkup(it, i, false); }).join('')
                : '<li class="fdr-empty">候補はすべて掲載中です。</li>'
        );

        $block.find('.fdr-count').text(picked.length + '件を掲載');
        initSortable($block, block);
        preview($block, block);
    }

    function initSortable($block, block) {
        var $lists = $block.find('.fdr-list');
        $lists.each(function () {
            var $l = $(this);
            if ($l.data('ui-sortable')) $l.sortable('destroy');
        });
        $lists.sortable({
            items: '> li.fdr-item',
            connectWith: '#' + $block.attr('id') + ' .fdr-list',
            placeholder: 'fdr-item fdr-placeholder',
            forcePlaceholderSize: true,
            cancel: 'input,textarea,button,a,select',
            stop: function () {
                syncFromDom($block, block);
                render($block, block);
            }
        });
    }

    /** DOM の並びを state に写す。ドラッグでリスト間を移動した分もここで拾う */
    function syncFromDom($block, block) {
        var byId = {};
        block.items.forEach(function (it) { byId[it.id] = it; });

        var next = [];
        $block.find('.fdr-list--picked > li.fdr-item').each(function () {
            var it = byId[$(this).data('id')];
            if (it) { it.picked = true; next.push(it); }
        });
        $block.find('.fdr-list--pool > li.fdr-item').each(function () {
            var it = byId[$(this).data('id')];
            if (it) { it.picked = false; next.push(it); }
        });
        // 念のため取りこぼしを拾う
        block.items.forEach(function (it) {
            if (next.indexOf(it) === -1) { it.picked = false; next.push(it); }
        });
        block.items = next;
    }

    function preview($block, block) {
        $block.find('.fdr-out__html').val(bodyHtml(block));
        $block.find('.fdr-out__text').val(bodyText(block));
        buildCombined();
    }

    /* ---------------------------------------------------------------- */

    function blockMarkup(block, idx) {
        return '' +
            '<section class="fdr-block" id="fdr-block-' + idx + '" data-index="' + idx + '">' +
                '<h2>' + esc(block.label) +
                    '<span class="fdr-period">' + esc(block.from) + ' 〜 ' + esc(block.to) + '</span>' +
                    '<span class="fdr-count"></span>' +
                '</h2>' +
                '<p class="fdr-summary">この期間の対象は <strong>' + block.matched + '件</strong>（' + block.kinds + '種）。' +
                    '前期間の比較は ' + esc(block.prevfrom) + ' 〜 ' + esc(block.prevto) + ' です。</p>' +
                '<div class="fdr-cols">' +
                    '<div class="fdr-col">' +
                        '<h3>掲載する順番<span class="description">ドラッグ、または ↑↓ で入れ替え</span></h3>' +
                        '<ol class="fdr-list fdr-list--picked"></ol>' +
                    '</div>' +
                    '<div class="fdr-col">' +
                        '<h3>候補<span class="description">ここから差し替え</span></h3>' +
                        '<ul class="fdr-list fdr-list--pool"></ul>' +
                    '</div>' +
                '</div>' +
                '<div class="fdr-out">' +
                    '<div class="fdr-out__row">' +
                        '<h3>この見出しの本文</h3>' +
                        '<button type="button" class="button fdr-save">選定内容を保存</button>' +
                        '<span class="fdr-saved" aria-live="polite"></span>' +
                    '</div>' +
                    '<div class="fdr-out__pair">' +
                        '<div><label>HTML</label><textarea class="fdr-out__html" rows="10" readonly></textarea>' +
                            '<button type="button" class="button fdr-copy" data-target="html">HTMLをコピー</button></div>' +
                        '<div><label>テキスト</label><textarea class="fdr-out__text" rows="10" readonly></textarea>' +
                            '<button type="button" class="button fdr-copy" data-target="text">テキストをコピー</button></div>' +
                    '</div>' +
                '</div>' +
            '</section>';
    }

    function combinedMarkup() {
        return '' +
            '<section class="fdr-block fdr-block--all">' +
                '<h2>1通にまとめた本文</h2>' +
                '<p class="fdr-summary">冒頭文・見出し・末尾文は「設定」画面で変えられます。</p>' +
                '<div class="fdr-out__pair">' +
                    '<div><label>HTML</label><textarea class="fdr-all__html" rows="14" readonly></textarea>' +
                        '<button type="button" class="button button-primary fdr-copy-all" data-target="html">HTMLをコピー</button></div>' +
                    '<div><label>テキスト</label><textarea class="fdr-all__text" rows="14" readonly></textarea>' +
                        '<button type="button" class="button fdr-copy-all" data-target="text">テキストをコピー</button></div>' +
                '</div>' +
            '</section>';
    }

    function nl2br(s) {
        return esc(s).replace(/\r\n|\r|\n/g, '<br />\n');
    }

    function buildCombined() {
        var html = [], text = [];
        if (DATA.intro) { html.push(nl2br(DATA.intro) + '<br />\n'); text.push(DATA.intro + '\n'); }

        DATA.blocks.forEach(function (block) {
            if (!pickedOf(block).length) return;
            html.push('<strong>' + esc(block.heading) + '</strong><br />\n<br />');
            text.push('■ ' + block.heading + '\n');
            html.push(bodyHtml(block));
            text.push(bodyText(block));
        });

        if (DATA.outro) { html.push('<br />\n' + nl2br(DATA.outro)); text.push('\n' + DATA.outro); }

        $('.fdr-all__html').val(html.join('\n'));
        $('.fdr-all__text').val(text.join('\n'));
    }

    /* ---------------------------------------------------------------- */

    function copyFrom($ta, $btn) {
        var text = $ta.val();
        var done = function () {
            var old = $btn.text();
            $btn.text('コピーしました').prop('disabled', true);
            setTimeout(function () { $btn.text(old).prop('disabled', false); }, 1500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { legacy(); });
        } else {
            legacy();
        }
        function legacy() {
            $ta.prop('readonly', false).trigger('select');
            try { document.execCommand('copy'); done(); } catch (e) { window.alert('コピーできませんでした。手動で選択してください。'); }
            $ta.prop('readonly', true);
        }
    }

    function save($block, block, $btn) {
        var payload = pickedOf(block).map(function (it) {
            return { id: it.id, title: it.title, comment: it.comment };
        });
        $btn.prop('disabled', true);
        $.post(DATA.ajaxurl, {
            action: 'fdr_save_draft',
            nonce: DATA.nonce,
            key: block.key,
            picked: JSON.stringify(payload)
        }).done(function (res) {
            $block.find('.fdr-saved').text(res && res.success ? '保存しました' : '保存に失敗しました');
        }).fail(function () {
            $block.find('.fdr-saved').text('保存に失敗しました');
        }).always(function () {
            $btn.prop('disabled', false);
            setTimeout(function () { $block.find('.fdr-saved').text(''); }, 2500);
        });
    }

    /* ---------------------------------------------------------------- */

    $(function () {
        // 期間の「任意」を選んだときだけ日付欄を出す
        $('.fdr-range').on('change', function () {
            var g = $(this).data('group');
            $('.fdr-custom[data-group="' + g + '"]').toggle($(this).val() === 'custom');
        });

        var raw = document.getElementById('fdr-data');
        if (!raw) return;
        try { DATA = JSON.parse(raw.textContent); } catch (e) { return; }
        if (!DATA || !DATA.blocks || !DATA.blocks.length) return;

        var $app = $('#fdr-app');
        $app.html(DATA.blocks.map(blockMarkup).join('') + combinedMarkup());

        DATA.blocks.forEach(function (block, i) {
            render($('#fdr-block-' + i), block);
        });

        $app.on('click', '[data-act]', function () {
            var $btn = $(this);
            var $block = $btn.closest('.fdr-block');
            var block = DATA.blocks[$block.data('index')];
            var id = $btn.closest('.fdr-item').data('id');
            var act = $btn.data('act');

            syncFromDom($block, block);
            var idx = block.items.findIndex(function (it) { return it.id === id; });
            if (idx < 0) return;

            if (act === 'toggle') {
                var it = block.items[idx];
                it.picked = !it.picked;
                // 選び直したものは、そのグループの末尾に置く
                block.items.splice(idx, 1);
                if (it.picked) {
                    var last = -1;
                    block.items.forEach(function (x, i2) { if (x.picked) last = i2; });
                    block.items.splice(last + 1, 0, it);
                } else {
                    block.items.push(it);
                }
            } else if (act === 'up' || act === 'down') {
                var picked = pickedOf(block);
                var pos = picked.indexOf(block.items[idx]);
                var swap = act === 'up' ? pos - 1 : pos + 1;
                if (swap < 0 || swap >= picked.length) return;
                var a = block.items.indexOf(picked[pos]);
                var b = block.items.indexOf(picked[swap]);
                var tmp = block.items[a];
                block.items[a] = block.items[b];
                block.items[b] = tmp;
            }
            render($block, block);
        });

        $app.on('input', '.fdr-item__title, .fdr-item__comment', function () {
            var $in = $(this);
            var $block = $in.closest('.fdr-block');
            var block = DATA.blocks[$block.data('index')];
            var id = $in.closest('.fdr-item').data('id');
            var it = block.items.find(function (x) { return x.id === id; });
            if (!it) return;
            if ($in.hasClass('fdr-item__title')) it.title = $in.val();
            else it.comment = $in.val();
            preview($block, block);
        });

        $app.on('click', '.fdr-copy', function () {
            var $btn = $(this);
            var $block = $btn.closest('.fdr-block');
            copyFrom($block.find($btn.data('target') === 'html' ? '.fdr-out__html' : '.fdr-out__text'), $btn);
        });

        $app.on('click', '.fdr-copy-all', function () {
            var $btn = $(this);
            copyFrom($($btn.data('target') === 'html' ? '.fdr-all__html' : '.fdr-all__text'), $btn);
        });

        $app.on('click', '.fdr-save', function () {
            var $btn = $(this);
            var $block = $btn.closest('.fdr-block');
            save($block, DATA.blocks[$block.data('index')], $btn);
        });

        buildCombined();
    });
})(jQuery);
