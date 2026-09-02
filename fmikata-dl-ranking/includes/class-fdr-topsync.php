<?php
/**
 * TOPのランキング枠に、選んだ並び順を書き戻す。
 *
 * 対象は re:Diver の記事一覧ブロック（dbp/article・dbp/slide-article）。
 * これらは記事IDをブロックコメントの JSON に "postId":"24177,212307,..." の形で持っている。
 *
 * ★ページ全体を parse_blocks → serialize_blocks で作り直すと、手で組んだ他のブロックの
 *   書き方まで正規化されてしまう。事故を避けるため、ここでは該当ブロックのコメント内にある
 *   postId の値だけを差し替える。他の場所は1バイトも変えない。
 *
 * 書き込みは wp_update_post。REST を通らないので WAF や SiteGuard の影響を受けない。
 * 直前の内容はリビジョンに残るので、管理画面から戻せる。
 */

if (!defined('ABSPATH')) exit;

class FDR_TopSync {

    /** 探しにいくブロック名 */
    public static function target_blocks() {
        return array('dbp/article', 'dbp/slide-article');
    }

    /* ===================================================================
     * 走査
     * =================================================================== */

    /**
     * 本文からブロックコメントを拾い、記事IDを持つものを列挙する。
     *
     * @return array 各要素: key, name, start, len, ids, titles, number, attrs
     */
    public static function scan($post_id) {
        $post = get_post($post_id);
        if (!$post) return array();
        return self::scan_content($post->post_content);
    }

    /** テストしやすいように本文だけを受ける版 */
    public static function scan_content($content) {
        $out = array();
        $names = self::target_blocks();
        $seq = array();

        foreach (self::block_comments($content) as $i => $b) {
            if (!in_array($b['name'], $names, true)) continue;

            $attrs = json_decode($b['json'], true);
            if (!is_array($attrs)) continue;

            $ids = self::read_ids($attrs);
            if ($ids === null) continue;

            // 印は「何番目のそのブロックか」で付ける。本文の位置で付けると、
            // 1つ目に書き込んだ時点で2つ目の位置がずれて見つからなくなる。
            $n = isset($seq[$b['name']]) ? $seq[$b['name']] : 0;
            $seq[$b['name']] = $n + 1;

            $out[] = array(
                'key'    => $b['name'] . '#' . $n,
                'name'   => $b['name'],
                'start'  => $b['start'],
                'len'    => $b['len'],
                'json'   => $b['json'],
                'ids'    => $ids,
                'titles' => array_map(array(__CLASS__, 'title_of'), $ids),
                'number' => isset($attrs['article']['number']) ? (int)$attrs['article']['number'] : count($ids),
                'label'  => self::label_of($b['name'], $attrs),
            );
        }
        return $out;
    }

    /**
     * ブロックコメントを頭から拾う。JSONは入れ子があるので、文字列を考慮しつつ
     * 波かっこの対応を数えて切り出す（正規表現では正しく取れない）。
     */
    private static function block_comments($content) {
        $out = array();
        $len = strlen($content);
        $pos = 0;

        while (($p = strpos($content, '<!-- wp:', $pos)) !== false) {
            $cursor = $p + 8;
            // ブロック名
            $name = '';
            while ($cursor < $len && preg_match('/[a-z0-9\/-]/', $content[$cursor])) {
                $name .= $content[$cursor];
                $cursor++;
            }
            // 空白を飛ばす
            while ($cursor < $len && ($content[$cursor] === ' ' || $content[$cursor] === "\t")) $cursor++;

            $json = '';
            if ($cursor < $len && $content[$cursor] === '{') {
                $end = self::match_brace($content, $cursor);
                if ($end === false) { $pos = $p + 8; continue; }
                $json = substr($content, $cursor, $end - $cursor + 1);
                $cursor = $end + 1;
            }

            $close = strpos($content, '-->', $cursor);
            if ($close === false) break;

            $out[] = array(
                'name'  => $name,
                'json'  => $json,
                'start' => $p,
                'len'   => $close + 3 - $p,
            );
            $pos = $close + 3;
        }
        return $out;
    }

    /** $open の位置の { に対応する } の位置を返す。JSON文字列内のかっこは数えない */
    private static function match_brace($s, $open) {
        $depth = 0;
        $len = strlen($s);
        $in_str = false;
        for ($i = $open; $i < $len; $i++) {
            $c = $s[$i];
            if ($in_str) {
                if ($c === '\\') { $i++; continue; }
                if ($c === '"') $in_str = false;
                continue;
            }
            if ($c === '"') { $in_str = true; continue; }
            if ($c === '{') $depth++;
            elseif ($c === '}') {
                $depth--;
                if ($depth === 0) return $i;
            }
        }
        return false;
    }

    /** attrs から記事IDの並びを読む。無い／空なら空配列、そもそも枠が違えば null */
    private static function read_ids($attrs) {
        if (!isset($attrs['article']) || !is_array($attrs['article'])) return null;
        if (!array_key_exists('postId', $attrs['article'])) return array();

        $raw = (string)$attrs['article']['postId'];
        $ids = array();
        foreach (explode(',', $raw) as $v) {
            $v = trim($v);
            if ($v === '') continue;      // 末尾のカンマ対策
            $ids[] = (int)$v;
        }
        return $ids;
    }

    /** 画面に出す説明。何で絞っているブロックなのかが分かるように */
    private static function label_of($name, $attrs) {
        $a = isset($attrs['article']) ? $attrs['article'] : array();
        $bits = array();
        $bits[] = $name === 'dbp/slide-article' ? 'スライダー' : '一覧';
        if (!empty($a['number'])) $bits[] = (int)$a['number'] . '件';
        if (!empty($a['category'])) {
            $names = array();
            foreach (explode(',', (string)$a['category']) as $cid) {
                $cid = (int)trim($cid);
                if (!$cid) continue;
                $t = get_term($cid, 'category');
                if ($t && !is_wp_error($t)) $names[] = $t->name;
            }
            if ($names) $bits[] = 'カテゴリ: ' . implode('・', $names);
        }
        return implode(' / ', $bits);
    }

    public static function title_of($id) {
        $t = get_the_title($id);
        return $t !== '' ? html_entity_decode($t, ENT_QUOTES, 'UTF-8') : ('(ID ' . (int)$id . ' が見つかりません)');
    }

    /* ===================================================================
     * 書き換え
     * =================================================================== */

    /**
     * ブロックの postId を差し替える。
     *
     * @param bool $dry true なら保存しない
     * @return array|WP_Error
     */
    public static function apply($post_id, $key, $ids, $dry = true, $month = null) {
        $post = get_post($post_id);
        if (!$post) return new WP_Error('fdr_no_post', '反映先の記事が見つかりません。');

        $ids = array_values(array_filter(array_map('intval', (array)$ids), function ($v) { return $v > 0; }));
        if (!$ids) return new WP_Error('fdr_no_ids', '書き込む記事がありません。');

        $content = $post->post_content;
        $target = null;
        foreach (self::scan_content($content) as $b) {
            if ($b['key'] === $key) { $target = $b; break; }
        }
        if (!$target) {
            return new WP_Error('fdr_no_block', 'そのブロックが見つかりません。TOPを編集した直後は「読み直す」を押してください。');
        }

        // すでに同じ並びなら、書き換えるものがない。これは失敗ではない
        if ($target['ids'] === $ids) {
            return array(
                'before'        => $target['ids'],
                'after'         => $ids,
                'before_titles' => $target['titles'],
                'after_titles'  => $target['titles'],
                'months'        => 0,
                'saved'         => false,
                'nochange'      => true,
                'changed'       => 0,
            );
        }

        $new = self::replace_ids($content, $target, $ids);
        if (is_wp_error($new)) return $new;

        $months = 0;
        if ($month !== null && $month !== '') {
            $new = self::replace_month($new, $month, $months);
        }

        $result = array(
            'before'  => $target['ids'],
            'after'   => $ids,
            'before_titles' => $target['titles'],
            'after_titles'  => array_map(array(__CLASS__, 'title_of'), $ids),
            'months'  => $months,
            'saved'   => false,
            'changed' => strlen($new) - strlen($content),
        );
        if ($dry) return $result;

        if (trim($new) === '') return new WP_Error('fdr_empty', '書き換え後の本文が空になりました。中止しました。');

        // ★ wp_update_post は「スラッシュ付きの本文」を前提にしていて、内部で wp_unslash を通す。
        //   素のまま渡すと本文中のバックスラッシュが1段はがれる。re:Diver のブロックは
        //   色指定などを - のようなエスケープで持っているので、渡し方を誤ると壊れる。
        $updated = wp_update_post(array('ID' => $post_id, 'post_content' => wp_slash($new)), true);
        if (is_wp_error($updated)) return $updated;

        // 保存後に本当にその通り入ったか、DBから読み直して確かめる
        clean_post_cache($post_id);
        $saved = get_post($post_id);
        if (!$saved || $saved->post_content !== $new) {
            return new WP_Error(
                'fdr_verify_failed',
                '保存はしましたが、書き込んだ内容とDBの内容が一致しません。TOPの編集画面で確認してください。'
            );
        }

        $result['saved'] = true;
        return $result;
    }

    /** 該当ブロックのコメント内にある postId の値だけを差し替える */
    private static function replace_ids($content, $target, $ids) {
        $block = substr($content, $target['start'], $target['len']);
        $value = implode(',', $ids);

        if (strpos($block, '"postId"') !== false) {
            $updated = preg_replace(
                '/("postId"\s*:\s*")[^"]*(")/',
                '${1}' . str_replace('$', '\\$', $value) . '${2}',
                $block,
                1
            );
        } else {
            // postId を持っていないブロックには article の直下に足す
            $updated = preg_replace(
                '/("article"\s*:\s*\{)/',
                '${1}"postId":"' . str_replace('$', '\\$', $value) . '",',
                $block,
                1
            );
        }

        if ($updated === null) {
            return new WP_Error('fdr_replace_failed', 'ブロックの書き換えでエラーが出ました。中止しました。');
        }
        if ($updated === $block) {
            return new WP_Error(
                'fdr_replace_failed',
                sprintf('ブロックの中身が変わりませんでした（入れようとした並び: %s）。中止しました。', $value)
            );
        }

        // 差し替えた結果がJSONとして壊れていないか必ず確かめる
        $check = self::scan_content($updated);
        if (!$check) {
            return new WP_Error('fdr_replace_failed', '書き換え後のブロックを読み直せませんでした。中止しました。');
        }
        if ($check[0]['ids'] !== $ids) {
            return new WP_Error(
                'fdr_replace_failed',
                sprintf(
                    '書き換え後の並びが一致しません（入れたかった: %s / 実際: %s）。中止しました。',
                    implode(',', $ids),
                    implode(',', $check[0]['ids'])
                )
            );
        }

        return substr($content, 0, $target['start']) . $updated . substr($content, $target['start'] + $target['len']);
    }

    /** 「2026年7月」のような見出しの月を差し替える */
    private static function replace_month($content, $month, &$count) {
        $count = 0;
        $out = preg_replace_callback('/\d{4}年\s*\d{1,2}月/u', function ($m) use ($month, &$count) {
            $count++;
            return $month;
        }, $content);
        return $out === null ? $content : $out;
    }

    /**
     * 反映先の候補を探す。固定ページとメインビジュアル（firstview）を見る。
     * TOPの枠が固定ページではなく firstview 側にあることがあるため。
     */
    public static function candidates() {
        $types = array('page');
        foreach (array('firstview', 'lp', 'cta') as $t) {
            if (post_type_exists($t)) $types[] = $t;
        }

        $posts = get_posts(array(
            'post_type'        => $types,
            'post_status'      => array('publish', 'draft', 'private'),
            'numberposts'      => 200,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ));

        $out = array();
        foreach ($posts as $pid) {
            $found = self::scan($pid);
            if (!$found) continue;
            $out[] = array(
                'id'     => (int)$pid,
                'type'   => get_post_type($pid),
                'title'  => get_the_title($pid),
                'blocks' => count($found),
            );
        }
        return $out;
    }
}
