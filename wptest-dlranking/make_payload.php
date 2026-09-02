<?php
/** 管理画面が JS に渡すのと同じ形の payload を作り、UI検証用に書き出す */

require __DIR__ . '/wp_stub.php';

define('FDR_OPT', 'fmikata_dl_ranking_options');
define('FDR_DRAFT', 'fmikata_dl_ranking_drafts');
define('FDR_MAP_CACHE', 'fmikata_dl_ranking_urlmap');

$plugin = getenv('FDR_PLUGIN_DIR');
require $plugin . '/includes/class-fdr-settings.php';
require $plugin . '/includes/class-fdr-aggregator.php';

$opt = FDR_Settings::get();
$tz  = wp_timezone();
$picks = 10;
$cands = 15;

$spec = array(
    'service' => array('from' => '2026-06-01 00:00:00', 'to' => '2026-09-01 00:00:00', 'label' => '① サービスランキング', 'heading' => $opt['heading_service']),
    'knowhow' => array('from' => '2026-08-01 00:00:00', 'to' => '2026-09-01 00:00:00', 'label' => '② ノウハウ本ランキング', 'heading' => $opt['heading_knowhow']),
);

$blocks = array();
foreach ($spec as $group => $s) {
    $from = new DateTimeImmutable($s['from'], $tz);
    $to   = new DateTimeImmutable($s['to'], $tz);
    list($pf, $pt) = FDR_Aggregator::previous_period($from, $to);
    $tally      = FDR_Aggregator::tally($from, $to, false);
    $prev_tally = FDR_Aggregator::tally($pf, $pt, false);
    $items = FDR_Aggregator::candidates($group, $from, $to, $cands, false, $tally, $prev_tally);
    foreach ($items as $i => $it) $items[$i]['picked'] = ($i < $picks);

    $blocks[] = array(
        'group'   => $group,
        'key'     => $group . '|' . $from->format('Y-m-d') . '|' . $to->format('Y-m-d') . '|a',
        'label'   => $s['label'],
        'heading' => $s['heading'],
        'from'    => $from->format('Y-m-d'),
        'to'      => $to->modify('-1 day')->format('Y-m-d'),
        'prevfrom' => $pf->format('Y-m-d'),
        'prevto'   => $pt->modify('-1 day')->format('Y-m-d'),
        'items'   => $items,
        'picks'   => $picks,
        'matched' => array_sum(array_map(function ($n) use ($tally, $group) {
            return ($tally['groups'][$n] ?? '') === $group ? $tally['counts'][$n] : 0;
        }, array_keys($tally['counts']))),
        'kinds'   => count(array_filter($tally['groups'], function ($v) use ($group) { return $v === $group; })),
    );
}

// TOPへの反映パネル。実物のブロック記述から組み立てる
require $plugin . '/includes/class-fdr-topsync.php';
$top = array(
    'postId'     => 202111,
    'postTitle'  => 'メインビジュアル',
    'editLink'   => '#',
    'monthLabel' => 1,
    'blocks'     => array(),
);
foreach (FDR_TopSync::scan_content(file_get_contents(__DIR__ . '/fixture_firstview.txt')) as $b) {
    $top['blocks'][] = array(
        'key'    => $b['key'],
        'name'   => $b['name'],
        'label'  => $b['label'],
        'number' => $b['number'],
        'ids'    => $b['ids'],
        'titles' => $b['titles'],
        'month'  => $b['name'] === 'dbp/slide-article',
    );
}

$payload = array(
    'nonce'   => 'test',
    'ajaxurl' => '/noop',
    'top'     => $top,
    'tplList' => $opt['item_tpl_list'],
    'tplText' => $opt['item_tpl_text'],
    'intro'   => $opt['mail_intro'],
    'outro'   => $opt['mail_outro'],
    'picks'   => $picks,
    'blocks'  => $blocks,
);

if (!is_dir(__DIR__ . '/uitest')) mkdir(__DIR__ . '/uitest', 0777, true);
file_put_contents(__DIR__ . '/uitest/data.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
echo "wrote uitest/data.json (" . count($blocks) . " blocks)\n";
