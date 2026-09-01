<?php
/**
 * 集計ロジックの検証。期待値は同じ元データを Python で機械集計したもの。
 */

require __DIR__ . '/wp_stub.php';

define('FDR_OPT', 'fmikata_dl_ranking_options');
define('FDR_DRAFT', 'fmikata_dl_ranking_drafts');
define('FDR_MAP_CACHE', 'fmikata_dl_ranking_urlmap');

$plugin = getenv('FDR_PLUGIN_DIR');
require $plugin . '/includes/class-fdr-settings.php';
require $plugin . '/includes/class-fdr-aggregator.php';

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

$tz = wp_timezone();
$d = function ($s) use ($tz) { return new DateTimeImmutable($s, $tz); };

/* ---- 1. URL→区分の対応表 ---- */
$map = FDR_Aggregator::url_map();
$svc = 0; $kno = 0;
foreach ($map as $m) { if ($m['group'] === 'service') $svc++; else $kno++; }
echo "対応表: サービス {$svc} 本 / ノウハウ {$kno} 本 / 合計 " . count($map) . " 本\n\n";
check('対応表にサービス配下が166本ある', $svc, 166);
check('対応表にノウハウ配下が73本ある', $kno, 73);

/* ---- 2. ノウハウ本ランキング（2026年8月） ---- */
$from = $d('2026-08-01 00:00:00');
$to   = $d('2026-09-01 00:00:00');
$k = FDR_Aggregator::candidates('knowhow', $from, $to, 15);
check('② 8月の件数列', array_column($k, 'count'), array(22, 16, 14, 12, 11, 11, 10, 7, 7, 6, 4, 3, 3, 3, 2));
check('② 8月の1位', $k[0]['title'], '中小の不動産会社のための最強営業マン育成ガイドブック～令和版源泉営業とは～PDF版');
check('② 8月の1位の前月', $k[0]['prev'], 34);
// 5位と6位は同じ11件。前月の多いほうを先に置く仕様なので 4 → 0 の順になる
check('同数のときは前月の多いほうが上', array($k[4]['prev'], $k[5]['prev']), array(4, 0));
check('同数の2件はどちらも11件', array($k[4]['count'], $k[5]['count']), array(11, 11));

echo "\n--- ② ノウハウ本 2026年8月 ---\n";
foreach ($k as $i => $it) {
    printf("%2d. %3d (前月%3d) %s [%s]\n", $i + 1, $it['count'], $it['prev'], $it['title'], $it['cats']);
}

/* ---- 3. サービスランキング（3ヶ月） ---- */
$from3 = $d('2026-06-01 00:00:00');
$s = FDR_Aggregator::candidates('service', $from3, $to, 15);
check('① 3ヶ月の件数列（上位12）', array_slice(array_column($s, 'count'), 0, 12), array(41, 18, 13, 12, 10, 9, 8, 8, 8, 6, 6, 6));

echo "\n--- ① サービス 2026年6〜8月 ---\n";
foreach ($s as $i => $it) {
    printf("%2d. %3d (前期%3d) %s [%s]\n", $i + 1, $it['count'], $it['prev'], $it['title'], $it['cats']);
}

/* ---- 4. 同数のときの並びが安定しているか ---- */
$again = FDR_Aggregator::candidates('service', $from3, $to, 15);
check('同じ条件なら並びが変わらない', array_column($again, 'id'), array_column($s, 'id'));

/* ---- 5. テンプレート類が混ざっていないか ---- */
$all_ids = array_merge(array_column($k, 'id'), array_column($s, 'id'));
$leak = array();
foreach ($all_ids as $pid) {
    foreach (get_the_category($pid) as $c) {
        if (in_array($c->slug, array('sales-template', 'marketing-template', 'other-tools', 'baibai', 'chintai', 'kanri'), true)) {
            $leak[] = get_the_title($pid) . ' → ' . $c->name;
        }
    }
}
check('テンプレート類が1本も混ざっていない', $leak, array());

/* ---- 6. 重複除去モード ---- */
$u = FDR_Aggregator::candidates('knowhow', $from, $to, 15, true);
$dup_ok = true;
foreach ($u as $i => $it) {
    $plain = null;
    foreach ($k as $x) if ($x['id'] === $it['id']) $plain = $x;
    if ($plain && $it['count'] > $plain['count']) $dup_ok = false;
}
check('重複除去モードの件数が通常以下になる', $dup_ok, true);
echo "  重複除去した②の件数列: " . implode(',', array_column($u, 'count')) . "\n";

/* ---- 7. 除外設定 ---- */
FDR_Settings::save(array('exclude_urls' => "https://store.f-mikata.jp/yu-mashouji/"));
$k2 = FDR_Aggregator::candidates('knowhow', $from, $to, 15);
check('除外URLに入れた資料が消える', in_array('中小の不動産会社のための最強営業マン育成ガイドブック～令和版源泉営業とは～PDF版', array_column($k2, 'title'), true), false);
FDR_Settings::save(array('exclude_urls' => "https://store.f-mikata.jp/cv/"));

/* ---- 8. メールでの除外（部分一致） ---- */
FDR_Settings::save(array('exclude_emails' => "@gmail.com"));
$k3 = FDR_Aggregator::candidates('knowhow', $from, $to, 15);
$total_before = array_sum(array_column($k, 'count'));
$total_after  = array_sum(array_column($k3, 'count'));
check('gmail を除外すると件数が減る', $total_after < $total_before, true);
echo "  gmail除外前 {$total_before} → 除外後 {$total_after}\n";
FDR_Settings::save(array('exclude_emails' => ""));

/* ---- 9. 期間プリセット ---- */
list($pf, $pt) = FDR_Aggregator::period('prev1m');
echo "\n先月プリセット: " . $pf->format('Y-m-d') . " 〜 " . $pt->modify('-1 day')->format('Y-m-d') . "\n";
check('先月プリセットが1日始まり', $pf->format('d'), '01');
list($cf, $ct) = FDR_Aggregator::period('custom', '2026-08-01', '2026-08-31');
check('任意期間は終了日を含む', array($cf->format('Y-m-d'), $ct->format('Y-m-d')), array('2026-08-01', '2026-09-01'));
list($bf, $bt) = FDR_Aggregator::period('custom', 'おかしな値', '');
check('壊れた日付でも例外にならない', $bt > $bf, true);

/* ---- 10. 前期間の計算 ---- */
list($qf, $qt) = FDR_Aggregator::previous_period($from, $to);
check('8月の前期間は7月', array($qf->format('Y-m-d'), $qt->format('Y-m-d')), array('2026-07-01', '2026-08-01'));

/* ---- 11. URL 正規化 ---- */
check('URL正規化: 末尾スラッシュ', FDR_Aggregator::norm_url('https://store.f-mikata.jp/abc/'), 'store.f-mikata.jp/abc');
check('URL正規化: www とクエリ', FDR_Aggregator::norm_url('http://www.store.f-mikata.jp/abc?x=1#y'), 'store.f-mikata.jp/abc');
check('URL正規化: 空', FDR_Aggregator::norm_url(''), '');

/* ---- 12. 既定の集計期間 ---- */
check('①②とも既定は先月', array(FDR_Settings::get('service_range'), FDR_Settings::get('knowhow_range')), array('prev1m', 'prev1m'));
check('既定の期間キーが実在する', array_key_exists(FDR_Settings::get('service_range'), FDR_Aggregator::ranges()), true);

/* ---- 13. フィールド検出 ---- */
check('Flamingoのフィールド候補が拾える', in_array('_field_service', FDR_Aggregator::detect_fields(), true), true);

echo "\n";
echo $fail === 0 ? "すべて通りました。\n" : "失敗 {$fail} 件\n";
exit($fail === 0 ? 0 : 1);
