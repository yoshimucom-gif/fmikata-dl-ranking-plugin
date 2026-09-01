#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""資料DLランキング（メルマガ生成）プラグイン ビルドスクリプト。

使い方:
    py build.py 1.0.1 "・○○を修正"

やること:
  1. fmikata-dl-ranking/fmikata-dl-ranking.php の Version ヘッダーと FDR_VER 定数を更新
  2. update.json の version と changelog を更新
  3. fmikata-dl-ranking.zip を再生成（/区切り＝WPで正しく解凍される）

このあと `git add -A && git commit -m "v1.0.1" && git push` すれば、
プラグインを入れたサイトの管理画面に「更新可能」バッジが出る。
"""
import sys
import re
import json
import zipfile
from pathlib import Path

BASE = Path(__file__).parent
PLUGIN_DIR = BASE / "fmikata-dl-ranking"
MAIN_PHP = PLUGIN_DIR / "fmikata-dl-ranking.php"
UPDATE_JSON = BASE / "update.json"
ZIP_PATH = BASE / "fmikata-dl-ranking.zip"

VERSION_RE = re.compile(r"^(\s*\*\s*Version:\s*)(\S+)", re.MULTILINE)
FDRVER_RE = re.compile(r"(define\('FDR_VER',\s*')([^']+)('\))")


def bump(version: str, changelog: str):
    php = MAIN_PHP.read_text(encoding="utf-8")
    if not VERSION_RE.search(php):
        sys.exit("エラー: Version ヘッダーが見つかりません")
    if not FDRVER_RE.search(php):
        sys.exit("エラー: FDR_VER 定数が見つかりません")
    php = VERSION_RE.sub(rf"\g<1>{version}", php, count=1)
    php = FDRVER_RE.sub(rf"\g<1>{version}\g<3>", php, count=1)
    MAIN_PHP.write_text(php, encoding="utf-8")
    print(f"[php] Version / FDR_VER -> {version}")

    meta = json.loads(UPDATE_JSON.read_text(encoding="utf-8"))
    meta["version"] = version
    if changelog:
        meta.setdefault("sections", {})["changelog"] = f"{version}\n{changelog}"
    UPDATE_JSON.write_text(json.dumps(meta, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"[json] update.json version -> {version}")


def build_zip():
    if ZIP_PATH.exists():
        ZIP_PATH.unlink()
    files = [p for p in PLUGIN_DIR.rglob("*") if p.is_file()]
    with zipfile.ZipFile(ZIP_PATH, "w", zipfile.ZIP_DEFLATED) as z:
        for f in files:
            # アーカイブ内パスは常に "fmikata-dl-ranking/..."（/区切り）
            arc = "fmikata-dl-ranking/" + f.relative_to(PLUGIN_DIR).as_posix()
            z.write(f, arc)
    print(f"[zip] {ZIP_PATH.name} ({ZIP_PATH.stat().st_size} bytes)")
    with zipfile.ZipFile(ZIP_PATH) as z:
        for n in z.namelist():
            print("   ", n)


def main():
    if len(sys.argv) < 2:
        sys.exit('使い方: py build.py <version> ["変更内容"]')
    version = sys.argv[1]
    changelog = sys.argv[2] if len(sys.argv) > 2 else ""
    if not re.match(r"^\d+\.\d+\.\d+$", version):
        sys.exit("バージョンは x.y.z 形式で指定してください")
    bump(version, changelog)
    build_zip()
    print("\n完了。次: git add -A && git commit -m \"v%s\" && git push" % version)


if __name__ == "__main__":
    main()
