#!/bin/bash
#
# 全テストをまとめて実行する。
#   bash tests/run.sh
#
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
status=0

echo "### 構文チェック ###"
for file in "$ROOT"/src/*.php "$ROOT"/public/*.php "$ROOT"/config/*.php "$ROOT"/tests/*.php; do
    php -l "$file" > /dev/null || status=1
done
if command -v node > /dev/null 2>&1; then
    node --check "$ROOT/public/assets/app.js" || status=1
fi
[ "$status" -eq 0 ] && echo "  OK   構文エラーなし"

echo ""
echo "### ロジックテスト ###"
php "$ROOT/tests/logic_test.php" || status=1

echo ""
echo "### HTTP統合テスト ###"
bash "$ROOT/tests/e2e_test.sh" || status=1

echo ""
[ "$status" -eq 0 ] && echo "すべてのテストに成功しました。" || echo "失敗したテストがあります。"
exit "$status"
