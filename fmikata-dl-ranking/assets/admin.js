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
        return String(tpl).replace(/\{(no|n|title|comment|url)\}/g, function (m, k) {
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

    /** 名前とURLだけの一覧。そのままどこにでも貼れる形 */
    function bodyList(block) {
        return pickedOf(block).map(function (it, i) {
            return fill(DATA.tplList, {
                no: numGlyph(i),
                n: String(i + 1),
                title: it.title,
                comment: it.comment,
                url: it.url
            });
        }).join('\n');
    }

    function bodyText(block) {
        return pickedOf(block).map(function (it, i) {
            return fill(DATA.tplText, {
                no: numGlyph(i),
                n: String(i + 1),
                title: it.title,
                comment: it.comment,
                url: it.url
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
        $block.find('.fdr-out__list').val(bodyList(block));
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
                        '<h3>コピーする</h3>' +
                        '<button type="button" class="button fdr-save">選定内容を保存</button>' +
                        '<span class="fdr-saved" aria-live="polite"></span>' +
                    '</div>' +
                    '<div class="fdr-out__pair">' +
                        '<div><label>一覧（名前とURL）</label><textarea class="fdr-out__list" rows="10" readonly></textarea>' +
                            '<button type="button" class="button button-primary fdr-copy" data-target="list">一覧をコピー</button></div>' +
                        '<div><label>メール本文（一言つき）</label><textarea class="fdr-out__text" rows="10" readonly></textarea>' +
                            '<button type="button" class="button fdr-copy" data-target="text">メール本文をコピー</button></div>' +
                    '</div>' +
                '</div>' +
            '</section>';
    }

    function combinedMarkup() {
        return '' +
            '<section class="fdr-block fdr-block--all">' +
                '<h2>①②をまとめて</h2>' +
                '<p class="fdr-summary">見出し・冒頭文・末尾文は「設定」画面で変えられます。</p>' +
                '<div class="fdr-out__pair">' +
                    '<div><label>一覧（名前とURL）</label><textarea class="fdr-all__list" rows="14" readonly></textarea>' +
                        '<button type="button" class="button button-primary fdr-copy-all" data-target="list">一覧をコピー</button></div>' +
                    '<div><label>メール本文（一言つき）</label><textarea class="fdr-all__text" rows="14" readonly></textarea>' +
                        '<button type="button" class="button fdr-copy-all" data-target="text">メール本文をコピー</button></div>' +
                '</div>' +
            '</section>';
    }

    function buildCombined() {
        var list = [], text = [];
        if (DATA.intro) text.push(DATA.intro + '\n');

        DATA.blocks.forEach(function (block) {
            if (!pickedOf(block).length) return;
            list.push('■ ' + block.heading);
            list.push(bodyList(block) + '\n');
            text.push('■ ' + block.heading + '\n');
            text.push(bodyText(block));
        });

        if (DATA.outro) text.push('\n' + DATA.outro);

        $('.fdr-all__list').val(list.join('\n').replace(/\n+$/, ''));
        $('.fdr-all__text').val(text.join('\n'));
    }

    /* ----------------------------------------------------------------
     * TOPへの反映
     * ---------------------------------------------------------------- */

    function topMarkup() {
        var t = DATA.top || {};
        var head = '<section class="fdr-block fdr-block--top">' +
            '<h2>TOPに反映</h2>';

        if (!t.postId) {
            return head +
                '<p class="fdr-summary">反映先がまだ決まっていません。ランキング枠を置いているページを探します。</p>' +
                '<p><button type="button" class="button fdr-top-find">反映先を探す</button> ' +
                '<span class="fdr-top-msg" aria-live="polite"></span></p>' +
                '<div class="fdr-top-found"></div></section>';
        }

        var rows = (t.blocks || []).map(function (b, i) {
            return '' +
                '<div class="fdr-top-row" data-key="' + esc(b.key) + '" data-index="' + i + '">' +
                    '<div class="fdr-top-row__head">' +
                        '<strong>' + esc(b.label) + '</strong>' +
                        '<code>' + esc(b.name) + '</code>' +
                        (b.month ? '<span class="fdr-top-tag">見出しの月つき</span>' : '') +
                    '</div>' +
                    '<div class="fdr-top-row__now"><span>いま入っている ' + b.ids.length + ' 件：</span>' +
                        '<ol>' + b.titles.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ol></div>' +
                    '<div class="fdr-top-row__act">' +
                        DATA.blocks.map(function (blk, bi) {
                            return '<button type="button" class="button fdr-top-preview" data-block="' + bi + '">' +
                                esc(blk.label) + ' の上位' + b.number + '件を入れる</button>';
                        }).join(' ') +
                    '</div>' +
                    '<div class="fdr-top-row__diff"></div>' +
                '</div>';
        }).join('');

        return head +
            '<p class="fdr-summary">反映先: <strong>' + esc(t.postTitle) + '</strong>（ID ' + t.postId + '）' +
                (t.editLink ? ' <a href="' + esc(t.editLink) + '" target="_blank" rel="noopener">編集画面を開く</a>' : '') +
                '。書き換えるのは枠のIDだけで、デザインには触れません。前の状態はリビジョンに残ります。</p>' +
            (rows || '<p class="fdr-summary">この記事にランキング枠が見つかりませんでした。</p>') +
            '<p><button type="button" class="button fdr-top-find">別の反映先を探す</button> ' +
            '<span class="fdr-top-msg" aria-live="polite"></span></p>' +
            '<div class="fdr-top-found"></div></section>';
    }

    function idsFor(blockIndex, count) {
        return pickedOf(DATA.blocks[blockIndex]).slice(0, count).map(function (it) { return it.id; });
    }

    function topPost(params, done, fail) {
        $.post(DATA.ajaxurl, $.extend({ nonce: DATA.nonce }, params))
            .done(function (res) {
                if (res && res.success) done(res.data);
                else fail((res && res.data) || '失敗しました');
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.data) || '通信に失敗しました';
                fail(msg);
            });
    }

    function diffMarkup(d, blockIndex, count, month) {
        var before = d.before_titles.map(function (x, i) { return '<li>' + esc(x) + '</li>'; }).join('');
        var after  = d.after_titles.map(function (x, i) { return '<li>' + esc(x) + '</li>'; }).join('');
        return '' +
            '<div class="fdr-diff">' +
                '<div class="fdr-diff__cols">' +
                    '<div><h4>いま</h4><ol>' + before + '</ol></div>' +
                    '<div><h4>反映後</h4><ol class="is-after">' + after + '</ol></div>' +
                '</div>' +
                (month ? '<p class="fdr-diff__month">見出しの月を「' + esc(month) + '」に書き換えます（' + d.months + ' か所）。</p>' : '') +
                '<p><button type="button" class="button button-primary fdr-top-apply" ' +
                    'data-block="' + blockIndex + '" data-count="' + count + '" data-month="' + esc(month || '') + '">この内容でTOPに反映する</button> ' +
                '<button type="button" class="button fdr-top-cancel">やめる</button></p>' +
            '</div>';
    }

    function bindTop($app) {
        $app.on('click', '.fdr-top-find', function () {
            var $btn = $(this);
            var $msg = $btn.siblings('.fdr-top-msg');
            $btn.prop('disabled', true);
            $msg.text('探しています…');
            topPost({ action: 'fdr_top_find' }, function (list) {
                $msg.text('');
                $btn.prop('disabled', false);
                $btn.closest('section').find('.fdr-top-found').html(
                    '<table class="widefat striped"><thead><tr><th>ID</th><th>種別</th><th>タイトル</th><th>枠の数</th></tr></thead><tbody>' +
                    list.map(function (r) {
                        return '<tr><td>' + r.id + '</td><td>' + esc(r.type) + '</td><td>' + esc(r.title) + '</td><td>' + r.blocks + '</td></tr>';
                    }).join('') +
                    '</tbody></table><p class="description">使いたいIDを「設定」→「反映先の記事ID」に入れて保存してください。</p>'
                );
            }, function (msg) {
                $msg.text(msg);
                $btn.prop('disabled', false);
            });
        });

        $app.on('click', '.fdr-top-preview', function () {
            var $btn = $(this);
            var $row = $btn.closest('.fdr-top-row');
            var b = DATA.top.blocks[$row.data('index')];
            var bi = parseInt($btn.data('block'), 10);
            var ids = idsFor(bi, b.number);
            if (!ids.length) { window.alert('先に掲載する記事を選んでください。'); return; }

            var month = (b.month && DATA.top.monthLabel) ? DATA.blocks[bi].month : '';
            $btn.prop('disabled', true);
            topPost({
                action: 'fdr_top_apply', dry: 1, key: b.key,
                ids: JSON.stringify(ids), month: month
            }, function (d) {
                $btn.prop('disabled', false);
                $row.find('.fdr-top-row__diff').html(diffMarkup(d, bi, b.number, month));
            }, function (msg) {
                $btn.prop('disabled', false);
                $row.find('.fdr-top-row__diff').html('<div class="notice notice-error inline"><p>' + esc(msg) + '</p></div>');
            });
        });

        $app.on('click', '.fdr-top-cancel', function () {
            $(this).closest('.fdr-top-row__diff').empty();
        });

        $app.on('click', '.fdr-top-apply', function () {
            var $btn = $(this);
            var $row = $btn.closest('.fdr-top-row');
            var b = DATA.top.blocks[$row.data('index')];
            var bi = parseInt($btn.data('block'), 10);
            var count = parseInt($btn.data('count'), 10);
            var month = $btn.data('month') || '';

            $btn.prop('disabled', true).text('反映しています…');
            topPost({
                action: 'fdr_top_apply', dry: 0, key: b.key,
                ids: JSON.stringify(idsFor(bi, count)), month: month
            }, function (d) {
                b.ids = d.after;
                b.titles = d.after_titles;
                $row.find('.fdr-top-row__now ol').html(d.after_titles.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join(''));
                $row.find('.fdr-top-row__now span').text('いま入っている ' + d.after.length + ' 件：');
                $row.find('.fdr-top-row__diff').html('<div class="notice notice-success inline"><p>TOPに反映しました。</p></div>');
            }, function (msg) {
                $btn.prop('disabled', false).text('この内容でTOPに反映する');
                $row.find('.fdr-top-row__diff').append('<div class="notice notice-error inline"><p>' + esc(msg) + '</p></div>');
            });
        });
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
        $app.html(DATA.blocks.map(blockMarkup).join('') + combinedMarkup() + topMarkup());
        bindTop($app);

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
            copyFrom($block.find($btn.data('target') === 'list' ? '.fdr-out__list' : '.fdr-out__text'), $btn);
        });

        $app.on('click', '.fdr-copy-all', function () {
            var $btn = $(this);
            copyFrom($($btn.data('target') === 'list' ? '.fdr-all__list' : '.fdr-all__text'), $btn);
        });

        $app.on('click', '.fdr-save', function () {
            var $btn = $(this);
            var $block = $btn.closest('.fdr-block');
            save($block, DATA.blocks[$block.data('index')], $btn);
        });

        buildCombined();
    });
})(jQuery);
