<?php
/**
 * 設定の既定値と読み書き。
 *
 * カテゴリのスラッグと Flamingo のフィールド名は、サイト側の都合で変わりうる。
 * ここをハードコードせず設定に逃がしてあるのは、記事のカテゴリ構成を作り直したときに
 * プラグインを触らずに追随できるようにするため。
 */

if (!defined('ABSPATH')) exit;

class FDR_Settings {

    /** 既定値。install() と get() の両方から参照する */
    public static function defaults() {
        return array(
            // ── 集計対象のカテゴリ（親スラッグ）
            'service_slug'   => 'service',
            'knowhow_slug'   => 'knowhow',

            // ── Flamingo の受信データ側のフィールド名（CF7 のフィールド名に対応）
            'field_title'    => '_field_service',
            'field_url'      => '_field_URL',
            'field_email'    => '_field_your-email',
            'field_price'    => '_field_price',

            // ── 集計の既定
            'candidates'     => 15,   // 候補として出す件数
            'picks'          => 10,   // 掲載する件数
            'unique_email'   => 0,    // 1 なら同一メールアドレスの重複を除いて数える
            'service_range'  => '3m', // ①の既定期間（母数が薄いので3ヶ月）
            'knowhow_range'  => 'prev1m', // ②の既定期間

            // ── 除外
            'exclude_urls'   => "https://store.f-mikata.jp/cv/",
            'exclude_emails' => '',

            // ── メール本文
            'accent_color'   => '#ff8c21',
            'mail_intro'     => "いつもご利用いただきありがとうございます。\n本メールでは、直近1ヶ月でよくダウンロードされた資料をランキング形式でご紹介いたします。\n\nまだご覧になっていない方は、ぜひこの機会にダウンロードしてください！\n\n‼資料は予告なく掲載停止する可能性がございます。あらかじめご了承ください。",
            'mail_outro'     => "是非、集客や営業活動にお役立てください。\n最後までご覧いただきありがとうございました。\n今後ともよろしくお願いいたします。",
            'heading_service' => '不動産会社向けサービス 人気ランキング',
            'heading_knowhow' => '不動産会社向けノウハウ資料 人気ランキング',

            'item_tpl_html'  => "<strong><span style=\"color: {color};\">{no}</span>{title}</strong><br />\n<span style=\"font-size: 14px;\">≪{comment}≫</span><br />\n<a href=\"{url}\">{url}</a><br />\n<br />",
            'item_tpl_text'  => "{no}{title}\n≪{comment}≫\n{url}\n",
        );
    }

    /** 設定を1件取得 */
    public static function get($key = null) {
        $opt = get_option(FDR_OPT, array());
        if (!is_array($opt)) $opt = array();
        $opt = array_merge(self::defaults(), $opt);
        if ($key === null) return $opt;
        return isset($opt[$key]) ? $opt[$key] : null;
    }

    /** 設定を保存（既定値にないキーは捨てる） */
    public static function save($input) {
        $defaults = self::defaults();
        $clean = self::get();

        foreach ($defaults as $key => $default) {
            if (!isset($input[$key])) continue;
            $val = $input[$key];

            switch ($key) {
                case 'candidates':
                case 'picks':
                    $val = max(1, min(30, (int)$val));
                    break;
                case 'unique_email':
                    $val = $val ? 1 : 0;
                    break;
                case 'accent_color':
                    $val = preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($val)) ? trim($val) : $default;
                    break;
                case 'service_range':
                case 'knowhow_range':
                    $val = array_key_exists($val, FDR_Aggregator::ranges()) ? $val : $default;
                    break;
                case 'item_tpl_html':
                    // メール本文のテンプレートなので、意図的にタグを許可する。
                    // ただし script / style / on* は落とす。
                    $val = self::strip_scripting(wp_unslash($val));
                    break;
                case 'mail_intro':
                case 'mail_outro':
                case 'item_tpl_text':
                case 'exclude_urls':
                case 'exclude_emails':
                    $val = sanitize_textarea_field(wp_unslash($val));
                    break;
                default:
                    $val = sanitize_text_field(wp_unslash($val));
            }
            $clean[$key] = $val;
        }

        // 掲載件数が候補件数を超えないようにそろえる
        if ($clean['picks'] > $clean['candidates']) {
            $clean['candidates'] = $clean['picks'];
        }

        update_option(FDR_OPT, $clean, false);
        delete_transient(FDR_MAP_CACHE);
        return $clean;
    }

    /** テンプレートから script/style/イベント属性を落とす */
    private static function strip_scripting($html) {
        $html = preg_replace('#<\s*(script|style)\b.*?<\s*/\s*\1\s*>#is', '', (string)$html);
        $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#javascript\s*:#i', '', $html);
        return $html;
    }

    /** 有効化時。既存の設定があれば触らない */
    public static function install() {
        $opt = get_option(FDR_OPT, null);
        if (!is_array($opt)) {
            add_option(FDR_OPT, self::defaults(), '', false);
        }
    }

    /** 改行区切りの設定値を配列にする */
    public static function lines($key) {
        $raw = (string)self::get($key);
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }
}
