<?php
/**
 * 外部リンクアイコンの豆腐（□）を直す
 *
 * ── 原因（2026-10-30 store.f-mikata.jp で実測）
 * re:Diver の style.min.css に次の1行がある:
 *   .wp-block-paragraph a[target=_blank]:not([class]):after{
 *     content:"\e89e";  font-family:Google Symbols }
 *
 * ところがテーマは Google Symbols を `&text=` で必要な字だけ切り出して読み込み、
 * そこに U+E89E（open_in_new）が入っていない。本文の外部リンクが全部豆腐になる。
 *
 * ── 直し方
 * 同じセレクタに、フォントに依存しない実文字の矢印を上書きする。記事本文には触らない。
 * CSS に "\2197" のようなエスケープは書かない（保存経路でバックスラッシュが落ちるため）。
 *
 * 止めたいときは functions.php 等で:
 *   add_filter('fdr_extlink_icon_fix', '__return_false');
 */

if (!defined('ABSPATH')) exit;

add_action('wp_head', function () {
    if (is_admin()) return;
    if (!apply_filters('fdr_extlink_icon_fix', true)) return;
    // Re:Diverヘルパーが同じ直しを出しているサイトでは二重に出さない
    if (defined('RDH_VERSION') && apply_filters('rdh_extlink_icon_fix', true)) return;

    // テーマ本体の style.min.css より後に出す（wp_head の優先度で担保）
    $css = '.wp-block-paragraph a[target="_blank"]:not([class])::after{'
         . 'content:"↗";'
         . 'font-family:inherit;'
         . 'font-size:.82em;'
         . 'line-height:1;'
         . 'margin-left:.15em;'
         . 'opacity:.65;'
         . 'vertical-align:.05em;'
         . '}';

    echo "\n<style id=\"fdr-extlink-icon\">" . $css . "</style>\n";
}, 100);
