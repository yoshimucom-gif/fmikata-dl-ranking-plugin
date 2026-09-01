<?php
/**
 * Flamingo の受信データを集計してランキングを作る。
 *
 * 判定はカテゴリだけで行う。フォームに残っている資料名やタグは表示にしか使わない。
 * （タグは運用の途中で意味が変わっており、いまのカテゴリ構成と一致しないため）
 */

if (!defined('ABSPATH')) exit;

class FDR_Aggregator {

    /** 使える期間プリセット。値 => 画面に出すラベル */
    public static function ranges() {
        return array(
            'prev1m'    => '先月（1日〜末日）',
            'thismonth' => '今月（1日〜今日）',
            '30d'       => '直近30日',
            '3m'        => '直近3ヶ月',
            '6m'        => '直近6ヶ月',
            'custom'    => '任意の期間',
        );
    }

    /**
     * プリセットから [開始, 終了) を返す。終了は「その瞬間を含まない」。
     * @return array [DateTimeImmutable $from, DateTimeImmutable $to]
     */
    public static function period($range, $custom_from = '', $custom_to = '') {
        $tz  = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $today = $now->setTime(0, 0, 0);

        switch ($range) {
            case 'prev1m':
                $to   = $today->modify('first day of this month');
                $from = $to->modify('-1 month');
                break;
            case 'thismonth':
                $from = $today->modify('first day of this month');
                $to   = $today->modify('+1 day');
                break;
            case '30d':
                $to   = $today->modify('+1 day');
                $from = $to->modify('-30 days');
                break;
            case '3m':
                $to   = $today->modify('+1 day');
                $from = $to->modify('-3 months');
                break;
            case '6m':
                $to   = $today->modify('+1 day');
                $from = $to->modify('-6 months');
                break;
            case 'custom':
            default:
                $f = self::parse_date($custom_from, $tz);
                $t = self::parse_date($custom_to, $tz);
                if (!$f) $f = $today->modify('first day of this month')->modify('-1 month');
                if (!$t) $t = $today;
                // 画面では「終了日を含む」ほうが直感的なので +1日して半開区間にする
                $from = $f;
                $to   = $t->modify('+1 day');
                if ($to <= $from) $to = $from->modify('+1 day');
                break;
        }
        return array($from, $to);
    }

    private static function parse_date($s, $tz) {
        $s = trim((string)$s);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $s . ' 00:00:00', $tz);
        return $d ?: null;
    }

    /** 直前の同じ長さの期間（前月比・前期比に使う） */
    public static function previous_period($from, $to) {
        $len  = $to->getTimestamp() - $from->getTimestamp();
        $pto  = $from;
        $pfrom = $from->setTimestamp($from->getTimestamp() - $len);
        return array($pfrom, $pto);
    }

    /** 比較用に URL を正規化する */
    public static function norm_url($u) {
        $u = trim((string)$u);
        if ($u === '') return '';
        $u = preg_replace('#^https?://#i', '', $u);
        $u = preg_replace('#^www\.#i', '', $u);
        $parts = explode('#', $u);
        $u = $parts[0];
        $parts = explode('?', $u);
        $u = $parts[0];
        $u = rtrim($u, '/');
        return strtolower($u);
    }

    /** Flamingo が入っているか */
    public static function flamingo_active() {
        return post_type_exists('flamingo_inbound');
    }

    /**
     * 「サービス一覧」「ノウハウ資料」配下の記事から URL→区分 の対応表を作る。
     * ここに載っていない URL は集計から落ちる＝テンプレート類が自動的に除外される。
     */
    public static function url_map($force = false) {
        if (!$force) {
            $cached = get_transient(FDR_MAP_CACHE);
            if (is_array($cached)) return $cached;
        }

        $map = array();
        foreach (array('service', 'knowhow') as $group) {
            $slug = FDR_Settings::get($group . '_slug');
            $term = get_category_by_slug($slug);
            if (!$term) continue;

            $ids = array_merge(
                array((int)$term->term_id),
                array_map('intval', get_term_children((int)$term->term_id, 'category'))
            );

            $post_ids = get_posts(array(
                'post_type'        => 'post',
                'post_status'      => 'publish',
                'numberposts'      => -1,
                'category__in'     => $ids,
                'fields'           => 'ids',
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'suppress_filters' => false,
            ));

            foreach ($post_ids as $pid) {
                $n = self::norm_url(get_permalink($pid));
                if ($n === '') continue;
                // 両方のツリーに入っている記事は先に見たほう（サービス）を採る
                if (isset($map[$n])) continue;
                $map[$n] = array('post_id' => (int)$pid, 'group' => $group);
            }
        }

        set_transient(FDR_MAP_CACHE, $map, HOUR_IN_SECONDS);
        return $map;
    }

    /** 期間内の申込を1件1行で取り出す */
    public static function collect($from, $to) {
        global $wpdb;
        if (!self::flamingo_active()) return array();

        $kt = FDR_Settings::get('field_title');
        $ku = FDR_Settings::get('field_url');
        $ke = FDR_Settings::get('field_email');

        $sql = $wpdb->prepare(
            "SELECT p.ID, p.post_date,
                    MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS form_title,
                    MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS form_url,
                    MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS form_email
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
              WHERE p.post_type = 'flamingo_inbound'
                AND p.post_status = 'publish'
                AND p.post_date >= %s
                AND p.post_date <  %s
              GROUP BY p.ID, p.post_date",
            $kt, $ku, $ke,
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s')
        );

        $rows = $wpdb->get_results($sql);
        return is_array($rows) ? $rows : array();
    }

    /**
     * URL 単位の件数を数える。
     * @return array ['counts'=>[norm=>件数], 'titles'=>[norm=>フォーム上の資料名],
     *                'total'=>全件, 'excluded'=>除外件, 'unmatched'=>[norm=>件数]]
     */
    public static function tally($from, $to, $unique_email = false) {
        $rows       = self::collect($from, $to);
        $map        = self::url_map();
        $excl_urls  = array();
        foreach (FDR_Settings::lines('exclude_urls') as $u) {
            $n = self::norm_url($u);
            if ($n !== '') $excl_urls[$n] = true;
        }
        $excl_mails = array_map('strtolower', FDR_Settings::lines('exclude_emails'));

        $counts = array();
        $titles = array();
        $groups = array();
        $unmatched = array();
        $seen = array();
        $excluded = 0;

        foreach ($rows as $r) {
            $n = self::norm_url($r->form_url);
            if ($n === '' || isset($excl_urls[$n])) { $excluded++; continue; }

            $email = strtolower(trim((string)$r->form_email));
            $skip = false;
            foreach ($excl_mails as $pat) {
                if ($pat !== '' && strpos($email, $pat) !== false) { $skip = true; break; }
            }
            if ($skip) { $excluded++; continue; }

            if (!isset($map[$n])) {
                if (!isset($unmatched[$n])) $unmatched[$n] = 0;
                $unmatched[$n]++;
                if (!isset($titles[$n])) $titles[$n] = (string)$r->form_title;
                continue;
            }

            if ($unique_email && $email !== '') {
                $key = $n . '|' . $email;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
            }

            if (!isset($counts[$n])) { $counts[$n] = 0; }
            $counts[$n]++;
            $groups[$n] = $map[$n]['group'];
            if (!isset($titles[$n])) $titles[$n] = (string)$r->form_title;
        }

        return array(
            'counts'    => $counts,
            'groups'    => $groups,
            'titles'    => $titles,
            'total'     => count($rows),
            'excluded'  => $excluded,
            'unmatched' => $unmatched,
        );
    }

    /**
     * 候補リストを作る。
     *
     * @return array 0件でも配列を返す。要素は画面と本文生成の両方で使う形。
     */
    public static function candidates($group, $from, $to, $limit, $unique_email = false, $tally = null, $prev_tally = null) {
        if ($tally === null)      $tally = self::tally($from, $to, $unique_email);
        if ($prev_tally === null) {
            list($pf, $pt) = self::previous_period($from, $to);
            $prev_tally = self::tally($pf, $pt, $unique_email);
        }

        $map = self::url_map();
        $rows = array();
        foreach ($tally['counts'] as $n => $count) {
            if (($tally['groups'][$n] ?? '') !== $group) continue;
            $pid = $map[$n]['post_id'];
            $prev = isset($prev_tally['counts'][$n]) ? (int)$prev_tally['counts'][$n] : 0;
            $rows[] = array(
                'id'      => (int)$pid,
                'url'     => get_permalink($pid),
                'title'   => html_entity_decode(get_the_title($pid), ENT_QUOTES, 'UTF-8'),
                'form'    => isset($tally['titles'][$n]) ? $tally['titles'][$n] : '',
                'count'   => (int)$count,
                'prev'    => $prev,
                'cats'    => self::child_category_names($pid, $group),
                'comment' => self::default_comment($pid),
            );
        }

        // 件数降順 → 前期間降順 → タイトル昇順。同数でも並びがぶれないようにする
        usort($rows, function ($a, $b) {
            if ($a['count'] !== $b['count']) return $b['count'] - $a['count'];
            if ($a['prev']  !== $b['prev'])  return $b['prev']  - $a['prev'];
            return strcmp($a['title'], $b['title']);
        });

        $limit = max(1, (int)$limit);
        return array_slice($rows, 0, $limit);
    }

    /** その記事が属する子カテゴリ名（親は除く） */
    private static function child_category_names($post_id, $group) {
        $parent_slug = FDR_Settings::get($group . '_slug');
        $parent = get_category_by_slug($parent_slug);
        $names = array();
        foreach (get_the_category($post_id) as $c) {
            if (!$parent) continue;
            if ((int)$c->term_id === (int)$parent->term_id) continue;
            if ((int)$c->parent === (int)$parent->term_id) $names[] = $c->name;
        }
        return implode('／', $names);
    }

    /** 一言コメントの初期値。抜粋 → SEO説明文 → 本文冒頭 の順に拾う */
    private static function default_comment($post_id) {
        $post = get_post($post_id);
        if (!$post) return '';

        $text = trim((string)$post->post_excerpt);

        if ($text === '') {
            foreach (array('_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_genesis_description') as $key) {
                $v = trim((string)get_post_meta($post_id, $key, true));
                if ($v !== '') { $text = $v; break; }
            }
        }

        if ($text === '') {
            $text = wp_strip_all_tags(strip_shortcodes((string)$post->post_content));
        }

        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        if (function_exists('mb_substr') && mb_strlen($text, 'UTF-8') > 60) {
            $text = mb_substr($text, 0, 60, 'UTF-8');
        }
        return $text;
    }

    /**
     * 設定画面用。Flamingo に実在するフィールド名を拾う。
     * 手で打たせるとタイプミスで静かに0件になるので、必ず候補から選ばせる。
     */
    public static function detect_fields($limit = 60) {
        global $wpdb;
        if (!self::flamingo_active()) return array();

        $keys = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_key
               FROM {$wpdb->postmeta} pm
               INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE p.post_type = 'flamingo_inbound'
                AND pm.meta_key LIKE %s
              ORDER BY pm.meta_key ASC
              LIMIT %d",
            $wpdb->esc_like('_field_') . '%',
            (int)$limit
        ));
        return is_array($keys) ? $keys : array();
    }
}
