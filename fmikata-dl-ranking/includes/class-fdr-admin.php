<?php
/**
 * 管理画面。
 *
 * 画面の作りは「候補を出す → 掲載分を選ぶ → 並べ替える → 本文を作る」の一本道。
 * 並べ替えと本文づくりはブラウザ側で完結させ、サーバーには集計と保存だけを任せている。
 */

if (!defined('ABSPATH')) exit;

class FDR_Admin {

    const CAP  = 'manage_options';
    const SLUG = 'fmikata-dl-ranking';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_fdr_save_draft', array(__CLASS__, 'ajax_save_draft'));
    }

    public static function menu() {
        add_menu_page(
            '資料DLランキング', '資料DLランキング', self::CAP, self::SLUG,
            array(__CLASS__, 'render_main'), 'dashicons-chart-bar', 58
        );
        add_submenu_page(self::SLUG, 'ランキング作成', 'ランキング作成', self::CAP, self::SLUG, array(__CLASS__, 'render_main'));
        add_submenu_page(self::SLUG, '設定', '設定', self::CAP, self::SLUG . '-settings', array(__CLASS__, 'render_settings'));
    }

    public static function assets($hook) {
        if (strpos($hook, self::SLUG) === false) return;
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('fdr-admin', FDR_URL . 'assets/admin.css', array(), FDR_VER);
        wp_enqueue_script('fdr-admin', FDR_URL . 'assets/admin.js', array('jquery', 'jquery-ui-sortable'), FDR_VER, true);
    }

    /* ===================================================================
     * ランキング作成
     * =================================================================== */

    public static function render_main() {
        if (!current_user_can(self::CAP)) wp_die('権限がありません。');

        $opt = FDR_Settings::get();

        if (isset($_POST['fdr_refresh_map'])) {
            check_admin_referer('fdr_main');
            FDR_Aggregator::url_map(true);
            echo '<div class="notice notice-success is-dismissible"><p>記事とカテゴリの対応表を取り直しました。</p></div>';
        }

        $submitted = isset($_POST['fdr_run']) && check_admin_referer('fdr_main');

        // 画面の入力値（未送信なら設定の既定値）
        $conf = array(
            'service' => array(
                'range' => $submitted ? sanitize_text_field(wp_unslash($_POST['service_range'] ?? '')) : $opt['service_range'],
                'from'  => $submitted ? sanitize_text_field(wp_unslash($_POST['service_from'] ?? ''))  : '',
                'to'    => $submitted ? sanitize_text_field(wp_unslash($_POST['service_to'] ?? ''))    : '',
            ),
            'knowhow' => array(
                'range' => $submitted ? sanitize_text_field(wp_unslash($_POST['knowhow_range'] ?? '')) : $opt['knowhow_range'],
                'from'  => $submitted ? sanitize_text_field(wp_unslash($_POST['knowhow_from'] ?? ''))  : '',
                'to'    => $submitted ? sanitize_text_field(wp_unslash($_POST['knowhow_to'] ?? ''))    : '',
            ),
        );
        $candidates = $submitted ? max(1, min(30, (int)($_POST['candidates'] ?? $opt['candidates']))) : (int)$opt['candidates'];
        $picks      = $submitted ? max(1, min(30, (int)($_POST['picks'] ?? $opt['picks'])))           : (int)$opt['picks'];
        $unique     = $submitted ? !empty($_POST['unique_email'])                                     : (bool)$opt['unique_email'];
        if ($picks > $candidates) $picks = $candidates;

        $ranges = FDR_Aggregator::ranges();
        foreach ($conf as $g => $c) {
            if (!isset($ranges[$c['range']])) $conf[$g]['range'] = 'prev1m';
        }

        $ready = FDR_Aggregator::flamingo_active();
        $blocks = array();
        $diag = array('unmatched' => array(), 'total' => 0, 'excluded' => 0, 'map' => 0);

        if ($ready) {
            $map = FDR_Aggregator::url_map();
            $diag['map'] = count($map);

            foreach (array('service', 'knowhow') as $group) {
                list($from, $to) = FDR_Aggregator::period($conf[$group]['range'], $conf[$group]['from'], $conf[$group]['to']);
                list($pf, $pt)   = FDR_Aggregator::previous_period($from, $to);

                $tally      = FDR_Aggregator::tally($from, $to, $unique);
                $prev_tally = FDR_Aggregator::tally($pf, $pt, $unique);
                $items      = FDR_Aggregator::candidates($group, $from, $to, $candidates, $unique, $tally, $prev_tally);

                $key = self::draft_key($group, $from, $to, $unique);
                $items = self::apply_draft($items, $key, $picks);

                $blocks[$group] = array(
                    'group'    => $group,
                    'key'      => $key,
                    'label'    => $group === 'service' ? '① サービスランキング' : '② ノウハウ本ランキング',
                    'heading'  => $group === 'service' ? $opt['heading_service'] : $opt['heading_knowhow'],
                    'from'     => $from->format('Y-m-d'),
                    'to'       => $to->modify('-1 day')->format('Y-m-d'),
                    'prevfrom' => $pf->format('Y-m-d'),
                    'prevto'   => $pt->modify('-1 day')->format('Y-m-d'),
                    'items'    => $items,
                    'picks'    => $picks,
                    'matched'  => array_sum(array_map(function ($n) use ($tally, $group) {
                        return ($tally['groups'][$n] ?? '') === $group ? $tally['counts'][$n] : 0;
                    }, array_keys($tally['counts']))),
                    'kinds'    => count(array_filter($tally['groups'], function ($v) use ($group) { return $v === $group; })),
                );

                // 診断は期間の広いほうを採用しておけば足りる
                if ($tally['total'] > $diag['total']) {
                    $diag['total']     = $tally['total'];
                    $diag['excluded']  = $tally['excluded'];
                    $diag['unmatched'] = $tally['unmatched'];
                }
            }
            arsort($diag['unmatched']);
        }

        $payload = array(
            'nonce'   => wp_create_nonce('fdr_ajax'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'color'   => $opt['accent_color'],
            'tplHtml' => $opt['item_tpl_html'],
            'tplText' => $opt['item_tpl_text'],
            'intro'   => $opt['mail_intro'],
            'outro'   => $opt['mail_outro'],
            'picks'   => $picks,
            'blocks'  => array_values($blocks),
        );
        ?>
        <div class="wrap fdr-wrap">
            <h1>資料DLランキング</h1>

            <?php if (!$ready) : ?>
                <div class="notice notice-error"><p>
                    Flamingo（投稿タイプ <code>flamingo_inbound</code>）が見つかりません。
                    Flamingo を有効化してから、もう一度この画面を開いてください。
                </p></div>
            <?php endif; ?>

            <form method="post" class="fdr-controls">
                <?php wp_nonce_field('fdr_main'); ?>
                <div class="fdr-controls__grid">
                    <?php foreach (array('service' => '① サービス', 'knowhow' => '② ノウハウ本') as $g => $lbl) : ?>
                        <fieldset class="fdr-fieldset">
                            <legend><?php echo esc_html($lbl); ?> の集計期間</legend>
                            <select name="<?php echo esc_attr($g); ?>_range" class="fdr-range" data-group="<?php echo esc_attr($g); ?>">
                                <?php foreach ($ranges as $val => $label) : ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($conf[$g]['range'], $val); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="fdr-custom" data-group="<?php echo esc_attr($g); ?>" <?php echo $conf[$g]['range'] === 'custom' ? '' : 'style="display:none"'; ?>>
                                <input type="date" name="<?php echo esc_attr($g); ?>_from" value="<?php echo esc_attr($conf[$g]['from']); ?>"> 〜
                                <input type="date" name="<?php echo esc_attr($g); ?>_to" value="<?php echo esc_attr($conf[$g]['to']); ?>">
                            </span>
                        </fieldset>
                    <?php endforeach; ?>

                    <fieldset class="fdr-fieldset">
                        <legend>出しかた</legend>
                        <label>候補 <input type="number" name="candidates" min="1" max="30" value="<?php echo esc_attr($candidates); ?>" class="small-text"> 件</label>
                        <label>掲載 <input type="number" name="picks" min="1" max="30" value="<?php echo esc_attr($picks); ?>" class="small-text"> 件</label>
                        <label><input type="checkbox" name="unique_email" value="1" <?php checked($unique); ?>> 同じメールアドレスの重複を除く</label>
                    </fieldset>
                </div>
                <p class="fdr-controls__actions">
                    <button type="submit" name="fdr_run" value="1" class="button button-primary">この条件で集計する</button>
                    <button type="submit" name="fdr_refresh_map" value="1" class="button">記事カテゴリを取り直す</button>
                    <span class="description">記事のカテゴリを変えた直後は「取り直す」を1回押してください（対応表は1時間キャッシュしています）。</span>
                </p>
            </form>

            <div id="fdr-app"></div>

            <details class="fdr-diag">
                <summary>集計の内訳とカテゴリ未設定の申込</summary>
                <p>
                    期間内の全申込 <strong><?php echo (int)$diag['total']; ?></strong> 件／
                    除外設定で落とした分 <strong><?php echo (int)$diag['excluded']; ?></strong> 件／
                    対応表に載っている記事 <strong><?php echo (int)$diag['map']; ?></strong> 本
                </p>
                <?php if ($diag['unmatched']) : ?>
                    <p>下は「サービス一覧」にも「ノウハウ資料」にも属していないため集計から外れた申込です。テンプレート類はここに出るのが正常です。ランキングに載せたいものが混ざっていたら、記事側のカテゴリを直してください。</p>
                    <table class="widefat striped fdr-diag__table">
                        <thead><tr><th style="width:80px">件数</th><th>URL</th></tr></thead>
                        <tbody>
                        <?php $i = 0; foreach ($diag['unmatched'] as $u => $c) { if ($i++ >= 30) break; ?>
                            <tr><td><?php echo (int)$c; ?></td><td><code><?php echo esc_html($u); ?></code></td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>未設定の申込はありません。</p>
                <?php endif; ?>
            </details>
        </div>
        <script type="application/json" id="fdr-data"><?php
            echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        ?></script>
        <?php
    }

    /* ===================================================================
     * 選定内容の保存
     * =================================================================== */

    private static function draft_key($group, $from, $to, $unique) {
        return $group . '|' . $from->format('Y-m-d') . '|' . $to->format('Y-m-d') . '|' . ($unique ? 'u' : 'a');
    }

    /**
     * 保存済みの選定内容を、今回の集計結果に重ねる。
     * 保存に無い記事は候補（未選定）に回す。保存にあって今回の候補に無い記事は捨てる
     * （期間が変わって圏外になったものを黙って載せ続けないため）。
     */
    private static function apply_draft($items, $key, $picks) {
        $drafts = get_option(FDR_DRAFT, array());
        $draft  = is_array($drafts) && isset($drafts[$key]) ? $drafts[$key] : null;

        $by_id = array();
        foreach ($items as $i => $it) $by_id[$it['id']] = $i;

        if (!$draft || empty($draft['picked'])) {
            // 初回は上から picks 件を選定済みにする
            foreach ($items as $i => $it) $items[$i]['picked'] = ($i < $picks);
            return $items;
        }

        $order = array();
        foreach ($draft['picked'] as $p) {
            $pid = (int)($p['id'] ?? 0);
            if (!isset($by_id[$pid])) continue;
            $idx = $by_id[$pid];
            if (isset($p['comment'])) $items[$idx]['comment'] = (string)$p['comment'];
            if (!empty($p['title']))  $items[$idx]['title']   = (string)$p['title'];
            $items[$idx]['picked'] = true;
            $order[] = $idx;
        }

        $picked = array();
        foreach ($order as $idx) $picked[] = $items[$idx];
        $rest = array();
        foreach ($items as $i => $it) {
            if (in_array($i, $order, true)) continue;
            $it['picked'] = false;
            $rest[] = $it;
        }
        return array_merge($picked, $rest);
    }

    public static function ajax_save_draft() {
        check_ajax_referer('fdr_ajax', 'nonce');
        if (!current_user_can(self::CAP)) wp_send_json_error('権限がありません。', 403);

        $key    = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
        $picked = json_decode(wp_unslash($_POST['picked'] ?? '[]'), true);
        if ($key === '' || !is_array($picked)) wp_send_json_error('保存する内容がありません。', 400);

        $clean = array();
        foreach ($picked as $p) {
            $id = (int)($p['id'] ?? 0);
            if ($id <= 0) continue;
            $clean[] = array(
                'id'      => $id,
                'title'   => sanitize_text_field((string)($p['title'] ?? '')),
                'comment' => sanitize_text_field((string)($p['comment'] ?? '')),
            );
        }

        $drafts = get_option(FDR_DRAFT, array());
        if (!is_array($drafts)) $drafts = array();
        $drafts[$key] = array('picked' => $clean, 'updated' => time());

        // 古いものから捨てて24件までに保つ
        if (count($drafts) > 24) {
            uasort($drafts, function ($a, $b) { return ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0); });
            $drafts = array_slice($drafts, 0, 24, true);
        }
        update_option(FDR_DRAFT, $drafts, false);

        wp_send_json_success(array('saved' => count($clean)));
    }

    /* ===================================================================
     * 設定
     * =================================================================== */

    public static function render_settings() {
        if (!current_user_can(self::CAP)) wp_die('権限がありません。');

        if (isset($_POST['fdr_settings_save'])) {
            check_admin_referer('fdr_settings');
            FDR_Settings::save($_POST);
            echo '<div class="notice notice-success is-dismissible"><p>保存しました。</p></div>';
        }

        $opt    = FDR_Settings::get();
        $fields = FDR_Aggregator::detect_fields();
        $ranges = FDR_Aggregator::ranges();
        ?>
        <div class="wrap fdr-wrap">
            <h1>資料DLランキング — 設定</h1>
            <form method="post">
                <?php wp_nonce_field('fdr_settings'); ?>

                <h2>集計対象のカテゴリ</h2>
                <p class="description">ここに指定した2つの親カテゴリの配下だけを集計します。ほかのカテゴリ（書式ひな形・お役立ちツールなど）は自動的に対象外になります。</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="service_slug">① サービス一覧の親スラッグ</label></th>
                        <td><input type="text" id="service_slug" name="service_slug" value="<?php echo esc_attr($opt['service_slug']); ?>" class="regular-text">
                            <?php self::term_hint($opt['service_slug']); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="knowhow_slug">② ノウハウ資料の親スラッグ</label></th>
                        <td><input type="text" id="knowhow_slug" name="knowhow_slug" value="<?php echo esc_attr($opt['knowhow_slug']); ?>" class="regular-text">
                            <?php self::term_hint($opt['knowhow_slug']); ?></td>
                    </tr>
                </table>

                <h2>Flamingo のフィールド名</h2>
                <p class="description">受信データのどの項目を使うかです。実際に保存されている項目から選んでください。</p>
                <table class="form-table" role="presentation">
                    <?php
                    $map = array(
                        'field_title' => '資料名',
                        'field_url'   => '資料のURL',
                        'field_email' => 'メールアドレス',
                        'field_price' => '価格（表示のみ）',
                    );
                    foreach ($map as $key => $label) : ?>
                        <tr>
                            <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                            <td>
                                <?php if ($fields) : ?>
                                    <select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>">
                                        <?php
                                        $current = $opt[$key];
                                        $list = $fields;
                                        if (!in_array($current, $list, true)) $list[] = $current;
                                        foreach ($list as $f) : ?>
                                            <option value="<?php echo esc_attr($f); ?>" <?php selected($current, $f); ?>><?php echo esc_html($f); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else : ?>
                                    <input type="text" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($opt[$key]); ?>" class="regular-text">
                                    <p class="description">Flamingo の受信データがまだありません。フォームから1件届くと候補が出ます。</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2>集計の既定値</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">① サービスの既定期間</th>
                        <td><select name="service_range">
                            <?php foreach ($ranges as $v => $l) : ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($opt['service_range'], $v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">サービスは1ヶ月だと申込が少なく、同数が並びやすい傾向があります。順位が団子になるときは「直近3ヶ月」にすると差が出ます。</p></td>
                    </tr>
                    <tr>
                        <th scope="row">② ノウハウ本の既定期間</th>
                        <td><select name="knowhow_range">
                            <?php foreach ($ranges as $v => $l) : ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($opt['knowhow_range'], $v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select></td>
                    </tr>
                    <tr>
                        <th scope="row">候補として出す件数</th>
                        <td><input type="number" name="candidates" min="1" max="30" value="<?php echo esc_attr($opt['candidates']); ?>" class="small-text"> 件</td>
                    </tr>
                    <tr>
                        <th scope="row">掲載する件数</th>
                        <td><input type="number" name="picks" min="1" max="30" value="<?php echo esc_attr($opt['picks']); ?>" class="small-text"> 件</td>
                    </tr>
                    <tr>
                        <th scope="row">数えかた</th>
                        <td><label><input type="checkbox" name="unique_email" value="1" <?php checked($opt['unique_email'], 1); ?>> 同じメールアドレスからの重複ダウンロードを1件として数える</label></td>
                    </tr>
                </table>

                <h2>除外</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="exclude_urls">除外するURL</label></th>
                        <td><textarea id="exclude_urls" name="exclude_urls" rows="4" class="large-text code"><?php echo esc_textarea($opt['exclude_urls']); ?></textarea>
                            <p class="description">1行に1つ。確認用フォームなど、集計に入れたくないページを書きます。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="exclude_emails">除外するメール</label></th>
                        <td><textarea id="exclude_emails" name="exclude_emails" rows="3" class="large-text code"><?php echo esc_textarea($opt['exclude_emails']); ?></textarea>
                            <p class="description">1行に1つ。部分一致で判定します（例: <code>@f-mikata.co.jp</code> と書けば社内からの申込を全部外せます）。</p></td>
                    </tr>
                </table>

                <h2>メール本文</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="accent_color">番号の色</label></th>
                        <td><input type="text" id="accent_color" name="accent_color" value="<?php echo esc_attr($opt['accent_color']); ?>" class="regular-text code" placeholder="#ff8c21"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="heading_service">① の見出し</label></th>
                        <td><input type="text" id="heading_service" name="heading_service" value="<?php echo esc_attr($opt['heading_service']); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="heading_knowhow">② の見出し</label></th>
                        <td><input type="text" id="heading_knowhow" name="heading_knowhow" value="<?php echo esc_attr($opt['heading_knowhow']); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mail_intro">冒頭文</label></th>
                        <td><textarea id="mail_intro" name="mail_intro" rows="6" class="large-text"><?php echo esc_textarea($opt['mail_intro']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mail_outro">末尾文</label></th>
                        <td><textarea id="mail_outro" name="mail_outro" rows="4" class="large-text"><?php echo esc_textarea($opt['mail_outro']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="item_tpl_html">1件分のHTML</label></th>
                        <td><textarea id="item_tpl_html" name="item_tpl_html" rows="6" class="large-text code"><?php echo esc_textarea($opt['item_tpl_html']); ?></textarea>
                            <p class="description">使える差し込み: <code>{no}</code> 番号 / <code>{title}</code> 資料名 / <code>{comment}</code> 一言 / <code>{url}</code> URL / <code>{color}</code> 番号の色</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="item_tpl_text">1件分のテキスト</label></th>
                        <td><textarea id="item_tpl_text" name="item_tpl_text" rows="5" class="large-text code"><?php echo esc_textarea($opt['item_tpl_text']); ?></textarea></td>
                    </tr>
                </table>

                <p class="submit"><button type="submit" name="fdr_settings_save" value="1" class="button button-primary">設定を保存</button></p>
            </form>
        </div>
        <?php
    }

    /** 設定画面で、そのスラッグが実在するかをその場で見せる */
    private static function term_hint($slug) {
        $term = get_category_by_slug($slug);
        if (!$term) {
            echo '<p class="description fdr-hint fdr-hint--ng">このスラッグのカテゴリが見つかりません。</p>';
            return;
        }
        $children = get_term_children((int)$term->term_id, 'category');
        printf(
            '<p class="description fdr-hint fdr-hint--ok">%s（子カテゴリ %d 件）を集計します。</p>',
            esc_html($term->name),
            count($children)
        );
    }
}
