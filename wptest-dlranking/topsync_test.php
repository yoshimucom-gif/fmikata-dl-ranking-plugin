<?php
/**
 * TOPのランキング枠の書き換えの検証。
 * 素材は store.f-mikata.jp のメインビジュアル（firstview 202111）から切り出した実物。
 */

require __DIR__ . '/wp_stub.php';

define('FDR_OPT', 'fmikata_dl_ranking_options');
define('FDR_DRAFT', 'fmikata_dl_ranking_drafts');
define('FDR_MAP_CACHE', 'fmikata_dl_ranking_urlmap');
define('FDR_DBVER_OPT', 'fmikata_dl_ranking_dbver');

$plugin = getenv('FDR_PLUGIN_DIR');
require $plugin . '/includes/class-fdr-settings.php';
require $plugin . '/includes/class-fdr-aggregator.php';
require $plugin . '/includes/class-fdr-topsync.php';

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    $ok = ($got === $want);
    if (!$ok) $fail++;
    printf("[%s] %s\n", $ok ? ' OK ' : 'FAIL', $label);
    if (!$ok) {
        echo "        期待: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
        echo "        実際: " . json_encode($got,  JSON_UNESCAPED_UNICODE) . "\n";
    }
}

$content = file_get_contents(__DIR__ . '/fixture_firstview.txt');

/* ---- 走査 ---- */
$found = FDR_TopSync::scan_content($content);
check('ブロックを2つ見つける', count($found), 2);
check('1つ目は一覧ブロック', $found[0]['name'], 'dbp/article');
check('1つ目のID（末尾カンマを捨てる）', $found[0]['ids'], array(24177, 212307, 210190));
check('2つ目はスライダー', $found[1]['name'], 'dbp/slide-article');
check('2つ目のID10件', $found[1]['ids'], array(24177, 212307, 210190, 92973, 729, 24960, 160508, 141471, 142691, 181774));
check('表示件数を読む', array($found[0]['number'], $found[1]['number']), array(3, 10));

/* ---- 書き換え（本文だけを差し替える内部関数を、apply 経由でなく直接検証する） ---- */
$ref = new ReflectionMethod('FDR_TopSync', 'replace_ids');
$ref->setAccessible(true);

$new = $ref->invoke(null, $content, $found[0], array(214108, 24177, 92973));
check('書き換えでエラーにならない', is_wp_error($new), false);

$after = FDR_TopSync::scan_content($new);
check('1つ目が入れ替わる', $after[0]['ids'], array(214108, 24177, 92973));
check('2つ目は触っていない', $after[1]['ids'], $found[1]['ids']);

/* ---- 差分が本当に最小か ---- */
$diff_before = substr($content, 0, $found[0]['start']);
$diff_after  = substr($new, 0, $found[0]['start']);
check('ブロックより前は1バイトも変わらない', $diff_before, $diff_after);
$tail_len = strlen($content) - ($found[0]['start'] + $found[0]['len']);
check('ブロックより後ろは1バイトも変わらない',
    substr($content, -$tail_len), substr($new, -$tail_len));

$only = str_replace('"postId":"24177,212307,210190,"', '"postId":"214108,24177,92973"', $content);
check('変わったのは postId の値だけ', $new, $only);

/* ---- スライダー側 ---- */
$new2 = $ref->invoke(null, $content, $found[1], array(1, 2, 3));
$after2 = FDR_TopSync::scan_content($new2);
check('スライダーだけ入れ替わる', array($after2[0]['ids'], $after2[1]['ids']),
    array(array(24177, 212307, 210190), array(1, 2, 3)));

/* ---- 月見出し ---- */
$ref2 = new ReflectionMethod('FDR_TopSync', 'replace_month');
$ref2->setAccessible(true);
$count = 0;
$mnew = $ref2->invokeArgs(null, array($content, '2026年8月', &$count));
check('月見出しを1か所だけ書き換える', $count, 1);
check('新しい月が入る', strpos($mnew, '2026年8月') !== false, true);
check('古い月は残らない', strpos($mnew, '2026年7月') !== false, false);

/* ---- 壊れた入力 ---- */
check('IDが空なら走査で空配列', FDR_TopSync::scan_content('<!-- wp:dbp/article {"article":{"number":3}} /-->')[0]['ids'], array());
check('対象外ブロックは拾わない', FDR_TopSync::scan_content('<!-- wp:paragraph {"a":1} --><p>x</p><!-- /wp:paragraph -->'), array());
check('壊れたJSONで落ちない', FDR_TopSync::scan_content('<!-- wp:dbp/article {"article":{ -->'), array());

/* ---- 入れ子のかっこを正しく数えられるか ---- */
$nested = '<!-- wp:dbp/article {"article":{"column":{"a":{"b":1}},"postId":"5,6","x":"}"}} /-->';
check('入れ子と文字列中のかっこを誤らない', FDR_TopSync::scan_content($nested)[0]['ids'], array(5, 6));

echo "\n";
echo $fail === 0 ? "すべて通りました。\n" : "失敗 {$fail} 件\n";
exit($fail === 0 ? 0 : 1);
