# 資料DLランキング — 通しテスト環境

本番サイトを触らずに、実データで集計とUIを検証する。

## 中身

| ファイル | 役割 |
|---|---|
| `wp_stub.php` | WordPress 関数と `$wpdb` のスタブ。実データを読み込む |
| `run_test.php` | 集計ロジックの検証（期待値は Python での機械集計と突合済み） |
| `run.py` | 上を実行する。`py run.py` |
| `make_payload.php` | 管理画面がJSに渡すのと同じ payload を作る |
| `ui.py` | 並べ替えUIをブラウザで試すページを `uitest/` に生成する |
| `store_posts.json` | store.f-mikata.jp の全記事（ID・URL・タイトル・カテゴリ） |
| `store_cats.json` | 同・カテゴリ階層 |

## 申込データ（CSV）は同梱しない

Flamingo のエクスポートには顧客のメールアドレスが入っているため、このフォルダには置かない。
実行時に環境変数で場所を渡す。`wp_stub.php` が読み込む時点でアドレスは伏せ字にする
（ドメイン部だけは、除外設定の検証に必要なので残す）。

```
set FDR_TEST_CSV=C:\Users\yoshi\Downloads\-flamingo-inbound-2026-09-01.csv
py run.py
```

指定がなければ上記のパスを既定で見る。

## 記事データを取り直す

カテゴリを組み替えたら、store.f-mikata.jp の REST から取り直す。

```powershell
$all=@(); for($p=1;$p -le 20;$p++){ $r=Invoke-RestMethod "https://store.f-mikata.jp/wp-json/wp/v2/posts?per_page=100&page=$p&status=publish&_fields=id,slug,link,title,categories"; if(-not $r){break}; $all+=$r; if($r.Count -lt 100){break} }
$all | ForEach-Object { [pscustomobject]@{ id=$_.id; link=$_.link; cats=($_.categories -join '|'); title=$_.title.rendered } } | ConvertTo-Json -Depth 3 | Out-File store_posts.json -Encoding utf8
```

## UIを触る

```
py ui.py
py -m http.server 4499 --directory uitest
```

`http://localhost:4499` で、ドラッグ並べ替え・載せる／外す・一言コメント編集・
本文生成をそのまま試せる。jQuery と jQuery UI は CDN から読むのでネット接続が要る。

## ハマりどころ

- 日本語を含む絶対パスを PHP に直書きしない。`__DIR__` 起点で書く
- Flamingo のCSVは CP932。`wp_stub.php` が自動判定して UTF-8 に直している
- ポータブルPHP: `C:\Users\yoshi\php-portable\php82\php.exe`
