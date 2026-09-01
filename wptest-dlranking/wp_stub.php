<?php
/**
 * 資料DLランキング プラグインの通しテスト用 WordPress スタブ。
 * 本番を触らずに、実際のCSV（Flamingoエクスポート）と実際のカテゴリ構成で集計を検証する。
 */

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();

/* ---------- データ読み込み ---------- */

function t_load_json($path) {
    $raw = file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    return json_decode($raw, true);
}

/** 実データ: 記事とカテゴリ */
$GLOBALS['__cats'] = array();
foreach (t_load_json(__DIR__ . '/store_cats.json') as $c) {
    $GLOBALS['__cats'][(int)$c['id']] = (object)array(
        'term_id' => (int)$c['id'],
        'slug'    => $c['slug'],
        'name'    => $c['name'],
        'parent'  => (int)$c['parent'],
    );
}

$GLOBALS['__posts'] = array();
foreach (t_load_json(__DIR__ . '/store_posts.json') as $p) {
    $cats = array();
    if (!empty($p['cats'])) {
        foreach (explode('|', $p['cats']) as $c) if ($c !== '') $cats[] = (int)$c;
    }
    $GLOBALS['__posts'][(int)$p['id']] = (object)array(
        'ID'           => (int)$p['id'],
        'link'         => $p['link'],
        'post_title'   => html_entity_decode($p['title'], ENT_QUOTES, 'UTF-8'),
        'post_excerpt' => '',
        'post_content' => 'ダミー本文。' . $p['title'] . 'の説明がここに入ります。',
        'cats'         => $cats,
    );
}

/**
 * 実データ: Flamingo の申込。
 *
 * CSV は顧客のメールアドレスを含むので、このフォルダには置かない。
 * 環境変数 FDR_TEST_CSV に Flamingo のエクスポート（CP932）のパスを渡すこと。
 * 読み込む時点でアドレスは伏せ字にする。ドメイン部だけは除外設定の検証に要るので残す。
 */
$csv = getenv('FDR_TEST_CSV');
if (!$csv || !is_readable($csv)) {
    fwrite(STDERR, "FDR_TEST_CSV に Flamingo のエクスポートCSVのパスを指定してください\n");
    exit(2);
}
$GLOBALS['__inbound'] = array();
$text = file_get_contents($csv);
if (!preg_match('//u', $text)) $text = mb_convert_encoding($text, 'UTF-8', 'CP932');
$fh = fopen('php://memory', 'r+');
fwrite($fh, $text);
rewind($fh);
$head = fgetcsv($fh);
$idx = array_flip($head);
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) < count($head)) continue;
    $dt = new DateTimeImmutable($r[$idx['日付']]);
    $mail = (string)$r[$idx['your-email']];
    $at = strrpos($mail, '@');
    $anon = $at === false ? 'anon' : substr(md5($mail), 0, 10) . substr($mail, $at);
    $GLOBALS['__inbound'][] = array(
        'date'  => $dt->format('Y-m-d H:i:s'),
        'title' => $r[$idx['service']],
        'url'   => $r[$idx['URL']],
        'email' => $anon,
        'price' => $r[$idx['price']],
    );
}
fclose($fh);

/* ---------- WordPress 関数のスタブ ---------- */

function wp_timezone() { return new DateTimeZone('Asia/Tokyo'); }

function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__options']) ? $GLOBALS['__options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__options'][$k] = $v; return true; }
function add_option($k, $v, $x = '', $a = null) { if (!isset($GLOBALS['__options'][$k])) $GLOBALS['__options'][$k] = $v; return true; }

function get_transient($k) { return array_key_exists($k, $GLOBALS['__transients']) ? $GLOBALS['__transients'][$k] : false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }

function post_type_exists($t) { return $t === 'flamingo_inbound'; }

function get_category_by_slug($slug) {
    foreach ($GLOBALS['__cats'] as $c) if ($c->slug === $slug) return $c;
    return false;
}

function get_term_children($id, $tax = 'category') {
    $out = array();
    foreach ($GLOBALS['__cats'] as $c) {
        if ($c->parent === (int)$id) {
            $out[] = $c->term_id;
            $out = array_merge($out, get_term_children($c->term_id, $tax));
        }
    }
    return $out;
}

function get_posts($args) {
    $in = isset($args['category__in']) ? array_map('intval', $args['category__in']) : array();
    $out = array();
    foreach ($GLOBALS['__posts'] as $p) {
        if (array_intersect($p->cats, $in)) $out[] = $p->ID;
    }
    sort($out);
    return $out;
}

function get_permalink($id) { return isset($GLOBALS['__posts'][$id]) ? $GLOBALS['__posts'][$id]->link : ''; }
function get_the_title($id) { return isset($GLOBALS['__posts'][$id]) ? $GLOBALS['__posts'][$id]->post_title : ''; }
function get_post($id) { return isset($GLOBALS['__posts'][$id]) ? $GLOBALS['__posts'][$id] : null; }
function get_post_meta($id, $key, $single = false) { return ''; }

function get_the_category($id) {
    $out = array();
    if (!isset($GLOBALS['__posts'][$id])) return $out;
    foreach ($GLOBALS['__posts'][$id]->cats as $cid) {
        if (isset($GLOBALS['__cats'][$cid])) $out[] = $GLOBALS['__cats'][$cid];
    }
    return $out;
}

function wp_strip_all_tags($s) { return trim(strip_tags((string)$s)); }
function strip_shortcodes($s) { return preg_replace('/\[[^\]]*\]/', '', (string)$s); }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string)$s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string)$s)); }

/* ---------- $wpdb ---------- */

class FakeWpdb {
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    private $last = null;

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $this->last = array('sql' => $sql, 'args' => $args);
        // 実際に文字列化もしておく（%s/%d の数が合わないと崩れるので、そこも検査になる）
        $i = 0;
        $out = preg_replace_callback('/%[sdf]/', function ($m) use (&$i, $args) {
            $v = $args[$i] ?? null;
            $i++;
            return $m[0] === '%d' ? (string)(int)$v : "'" . addslashes((string)$v) . "'";
        }, $sql);
        if ($i !== count($args)) {
            throw new RuntimeException('prepare のプレースホルダ数が引数と合いません: ' . $i . ' vs ' . count($args));
        }
        return $out;
    }

    public function esc_like($s) { return addcslashes((string)$s, '_%\\'); }

    public function get_results($sql) {
        $a = $this->last['args'];
        // 順に: field_title, field_url, field_email, from, to
        list($kt, $ku, $ke, $from, $to) = $a;
        if ($kt !== '_field_service' || $ku !== '_field_URL' || $ke !== '_field_your-email') {
            throw new RuntimeException('想定外のフィールド名: ' . $kt . ' / ' . $ku . ' / ' . $ke);
        }
        $rows = array();
        foreach ($GLOBALS['__inbound'] as $r) {
            if ($r['date'] < $from || $r['date'] >= $to) continue;
            $rows[] = (object)array(
                'ID'         => count($rows) + 1,
                'post_date'  => $r['date'],
                'form_title' => $r['title'],
                'form_url'   => $r['url'],
                'form_email' => $r['email'],
            );
        }
        return $rows;
    }

    public function get_col($sql) {
        $keys = array();
        foreach ($GLOBALS['__inbound'] as $r) {
            $keys['_field_service'] = 1; $keys['_field_URL'] = 1;
            $keys['_field_your-email'] = 1; $keys['_field_price'] = 1;
            $keys['_field_ID'] = 1; $keys['_field_tags'] = 1;
        }
        $out = array_keys($keys);
        sort($out);
        return $out;
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();
$wpdb = $GLOBALS['wpdb'];
