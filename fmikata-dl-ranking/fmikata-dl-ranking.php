<?php
/**
 * Plugin Name: 資料DLランキング（メルマガ生成）
 * Description: Flamingo に溜まった資料ダウンロードの申込データを、ストアのカテゴリ（不動産会社向けサービス一覧／不動産会社向けノウハウ資料）ごとに集計します。人気順の候補を15件出し、そこから掲載する10件を選んでドラッグで並べ替え、「1．資料名（URL）」の一覧と、一言コメント入りのメール本文をその場で生成します。
 * Version: 1.1.4
 * Author: ミカタ株式会社
 * License: GPLv2 or later
 * Text Domain: fmikata-dl-ranking
 *
 * ★集計に含めるもの／含めないもの:
 *   - 含める: 「不動産会社向けサービス一覧」配下と「不動産会社向けノウハウ資料」配下の記事のみ
 *   - 含めない: 不動産書式ひな形【無料】・営業／業務お役立ちツール などのテンプレート類、
 *              およびカテゴリが付いていない申込（LP投稿タイプ・確認用フォーム等）
 *   どちらに入るかはカテゴリだけで決まる。判定を変えたいときは記事側のカテゴリを直すこと。
 */

if (!defined('ABSPATH')) exit; // 直接アクセス禁止

define('FDR_VER', '1.1.4');
define('FDR_FILE', __FILE__);
define('FDR_DIR', plugin_dir_path(__FILE__));
define('FDR_URL', plugin_dir_url(__FILE__));

/** 設定の保存先 */
define('FDR_OPT', 'fmikata_dl_ranking_options');
/** 選定内容（並び順・一言コメント）の保存先 */
define('FDR_DRAFT', 'fmikata_dl_ranking_drafts');
/** 設定の移行済みバージョン。既定値を変えても保存済みの設定が優先されるので、移行はここで管理する */
define('FDR_DBVER_OPT', 'fmikata_dl_ranking_dbver');
/** URL→カテゴリの対応表キャッシュ */
define('FDR_MAP_CACHE', 'fmikata_dl_ranking_urlmap');

/**
 * 自動更新の置き場（update.json の URL）。
 * ※ 空なら自動更新は無効。
 */
define('FDR_UPDATE_URL', 'https://raw.githubusercontent.com/yoshimucom-gif/fmikata-dl-ranking-plugin/main/update.json');

require_once FDR_DIR . 'includes/class-fdr-settings.php';
require_once FDR_DIR . 'includes/class-fdr-aggregator.php';
require_once FDR_DIR . 'includes/class-fdr-topsync.php';
require_once FDR_DIR . 'includes/class-fdr-admin.php';
require_once FDR_DIR . 'includes/fix-extlink-icon.php';

if (is_admin() && FDR_UPDATE_URL) {
    require_once FDR_DIR . 'includes/plugin-updater.php';
    new FDR_Updater(__FILE__, FDR_UPDATE_URL);
}

add_action('plugins_loaded', array('FDR_Settings', 'maybe_upgrade'), 5);
add_action('plugins_loaded', array('FDR_Admin', 'init'));

/** 有効化時に既定値を書き込む（既存設定は壊さない） */
register_activation_hook(__FILE__, array('FDR_Settings', 'install'));

/** 停止時はキャッシュだけ捨てる（設定と選定内容は残す） */
register_deactivation_hook(__FILE__, function () {
    delete_transient(FDR_MAP_CACHE);
});
