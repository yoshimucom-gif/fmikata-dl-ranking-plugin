# 資料DLランキング v1.1.1 以前の不具合で壊れた本文を修復する。
#
# 何が起きたか:
#   wp_update_post() は「スラッシュ付きの本文」を前提にしていて、内部で wp_unslash() を通す。
#   スラッシュを付けずに渡したため、本文中の -（JSONエスケープ）から
#   バックスラッシュが剥がれ、u002d という壊れた文字列になった。
#   影響は re:Diver ブロックの色指定（var(--wp--preset--color--…)）。
#   いずれも fallback 色が併記されているため見た目は保たれるが、記述としては壊れている。
#
# このスクリプトは剥がれたバックスラッシュを戻すだけ。他は一切変更しない。
# 送信前にブロックJSONが全件読めることを確認し、ひとつでも壊れていれば中止する。
#
# 使い方:
#   powershell -ExecutionPolicy Bypass -File repair-firstview-escapes.ps1
#
# v1.1.2 以降のプラグインでは wp_slash() を通すようにしたので、もう起きない。

param(
    [string]$SiteUrl = 'https://store.f-mikata.jp',
    [string]$PostType = 'firstview',
    [int]$PostId = 202111,
    [string]$User = 'mikata'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

Write-Host "対象: $SiteUrl / $PostType / ID $PostId"
$pw = Read-Host "アプリケーションパスワード（スペースありのまま）"
$b64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("${User}:${pw}"))
$headers = @{ Authorization = "Basic $b64"; 'User-Agent' = 'repair-script' }

$endpoint = "$SiteUrl/wp-json/wp/v2/$PostType/$PostId"
$post = Invoke-RestMethod -Uri "${endpoint}?context=edit" -Headers $headers -TimeoutSec 60
$content = $post.content.raw

$broken = ([regex]::Matches($content, '(?<!\\)u002d')).Count
$intact = ([regex]::Matches($content, '\\u002d')).Count
Write-Host "現在: 壊れている $broken か所 / 正常な $intact か所 / 本文 $($content.Length) 文字"

if ($broken -eq 0) {
    Write-Host "壊れている箇所はありません。何もせず終了します。" -ForegroundColor Green
    exit 0
}

# 送信前に手元へ控えを取る
$backup = Join-Path $PSScriptRoot ("firstview-{0}-before-{1}.txt" -f $PostId, (Get-Date -Format 'yyyyMMdd-HHmmss'))
[System.IO.File]::WriteAllText($backup, $content, (New-Object System.Text.UTF8Encoding($false)))
Write-Host "修復前の控え: $backup"

# 元のバイト列に戻す。ここを素のハイフンにしてはいけない。
# Gutenberg がハイフンをわざわざエスケープしているのは、ブロックコメントの中に
# コメントを閉じる並び（ハイフン2つ＋大なり）が現れるのを防ぐため。
# エスケープされた形のまま復元する。
$escape = 'u002d'
$fixed = $content -replace '(?<!\\)u002d', ('\' + $escape)

# ブロックのJSONが全件読めることを確かめる。ひとつでも駄目なら送らない
$bad = 0
$total = 0
foreach ($m in [regex]::Matches($fixed, '<!-- wp:[a-z0-9/-]+ (\{.*?\}) /?-->')) {
    $total++
    try { $null = $m.Groups[1].Value | ConvertFrom-Json } catch { $bad++ }
}
Write-Host "検査: ブロックJSON $total 件中、読めないもの $bad 件"
if ($bad -gt 0) {
    Write-Host "壊れたJSONが残るため中止しました。何も送信していません。" -ForegroundColor Red
    exit 1
}

$body = @{ content = $fixed } | ConvertTo-Json -Depth 3 -Compress
$headers['Content-Type'] = 'application/json; charset=utf-8'
$res = Invoke-RestMethod -Uri $endpoint -Method Post -Headers $headers -Body ([Text.Encoding]::UTF8.GetBytes($body)) -TimeoutSec 90

# 書き込んだ結果を読み直して確かめる
$after = (Invoke-RestMethod -Uri "${endpoint}?context=edit" -Headers $headers -TimeoutSec 60).content.raw
$brokenAfter = ([regex]::Matches($after, '(?<!\\)u002d')).Count
$intactAfter = ([regex]::Matches($after, '\\u002d')).Count
Write-Host "修復後: 壊れている $brokenAfter か所 / 正常な $intactAfter か所 / 本文 $($after.Length) 文字"

if ($brokenAfter -eq 0 -and $intactAfter -eq ($intact + $broken)) {
    Write-Host "修復しました。" -ForegroundColor Green
} else {
    Write-Host "想定と違う結果です。控えを見て確認してください: $backup" -ForegroundColor Red
    exit 1
}
