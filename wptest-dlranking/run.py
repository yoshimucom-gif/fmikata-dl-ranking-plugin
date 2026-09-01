# -*- coding: utf-8 -*-
"""集計ロジックの検証を実行する。

    py run.py

CSV の場所は環境変数 FDR_TEST_CSV で渡す（既定は Downloads の直近エクスポート）。
"""
import os
import pathlib
import subprocess
import sys

HERE = pathlib.Path(__file__).parent
PHP = os.environ.get("FDR_PHP", r"C:\Users\yoshi\php-portable\php82\php.exe")
PLUGIN = pathlib.Path(os.environ.get("FDR_PLUGIN_DIR", str(HERE.parent / "fmikata-dl-ranking")))
CSV = os.environ.get("FDR_TEST_CSV") or r"C:\Users\yoshi\Downloads\-flamingo-inbound-2026-09-01.csv"

env = dict(os.environ)
env["FDR_PLUGIN_DIR"] = str(PLUGIN)
env["FDR_TEST_CSV"] = CSV
env["PYTHONUTF8"] = "1"

p = subprocess.run(
    [PHP, "-d", "error_reporting=E_ALL", "-d", "display_errors=1", str(HERE / "run_test.php")],
    env=env, capture_output=True, cwd=str(HERE),
)
sys.stdout.write(p.stdout.decode("utf-8", "replace"))
err = p.stderr.decode("utf-8", "replace")
if err.strip():
    sys.stdout.write("\n[stderr]\n" + err)
sys.stdout.write("\nexit=%d\n" % p.returncode)
sys.exit(p.returncode)
