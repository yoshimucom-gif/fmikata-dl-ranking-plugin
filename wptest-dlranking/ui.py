# -*- coding: utf-8 -*-
"""管理画面のJS/CSSを WordPress 抜きでブラウザ検証するページを作る。

    py ui.py
    py -m http.server 4499 --directory uitest

ブラウザで http://localhost:4499 を開くと、実データで並べ替えと本文生成を試せる。
jQuery と jQuery UI は CDN から読む（この検証ページだけの話で、プラグイン本体は
WordPress 同梱のものを使う）。
"""
import json, os, pathlib, shutil, subprocess, sys

HERE = pathlib.Path(__file__).parent
OUT = HERE / "uitest"
PHP = os.environ.get("FDR_PHP", r"C:\Users\yoshi\php-portable\php82\php.exe")
PLUGIN = pathlib.Path(os.environ.get(
    "FDR_PLUGIN_DIR",
    str(HERE.parent / "fmikata-dl-ranking")))
CSV = os.environ.get("FDR_TEST_CSV") or r"C:\Users\yoshi\Downloads\-flamingo-inbound-2026-09-01.csv"

env = dict(os.environ)
env["FDR_PLUGIN_DIR"] = str(PLUGIN)
env["FDR_TEST_CSV"] = CSV
p = subprocess.run([PHP, str(HERE / "make_payload.php")], env=env, capture_output=True, cwd=str(HERE))
sys.stdout.write(p.stdout.decode("utf-8", "replace"))
if p.returncode != 0:
    sys.stdout.write(p.stderr.decode("utf-8", "replace"))
    sys.exit(p.returncode)

OUT.mkdir(exist_ok=True)
shutil.copy(PLUGIN / "assets" / "admin.js", OUT / "admin.js")
shutil.copy(PLUGIN / "assets" / "admin.css", OUT / "admin.css")

data = json.loads((OUT / "data.json").read_text(encoding="utf-8"))

html = """<!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<title>資料DLランキング UI検証</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.2/themes/base/jquery-ui.min.css">
<link rel="stylesheet" href="admin.css">
<style>
  body{font-family:-apple-system,"Segoe UI","Hiragino Kaku Gothic ProN",Meiryo,sans-serif;margin:20px;background:#f0f0f1;color:#1d2327}
  .button,.button-link{cursor:pointer}
  .button{background:#f6f7f7;border:1px solid #2271b1;color:#2271b1;border-radius:3px;padding:3px 10px}
  .button-primary{background:#2271b1;color:#fff}
  .button-small{font-size:12px;padding:1px 8px}
  .button-link{background:none;border:0}
  .description{color:#646970;font-size:12px}
  h1{font-size:23px;font-weight:400}
</style>
</head><body>
<div class="wrap fdr-wrap">
  <h1>資料DLランキング（UI検証）</h1>
  <div id="fdr-app"></div>
</div>
<script type="application/json" id="fdr-data">__DATA__</script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script src="admin.js"></script>
</body></html>
"""

html = html.replace("__DATA__", json.dumps(data, ensure_ascii=False).replace("<", "\\u003c"))
(OUT / "index.html").write_text(html, encoding="utf-8")
print("wrote uitest/index.html")
print("次: py -m http.server 4499 --directory uitest")
