#!/bin/bash
#
# HTTP 経由の統合テスト（管理画面でID発行 → 親機/子機ログイン → 呼び出し → 応答）。
#
# 使い方:
#   bash tests/e2e_test.sh              … 一時DBで専用サーバーを起動して検証する
#   BASE_URL=http://host/ bash tests/e2e_test.sh
#                                       … すでに動いているサーバーに対して検証する
#                                          （そのサーバーのデータにIDが追加される点に注意）
#
# 環境変数:
#   PORT      … 自前でサーバーを起動するときのポート（既定 8788）
#   BASE_URL  … 検証先。指定するとサーバーの起動は行わない
#
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
PORT="${PORT:-8788}"
BASE="${BASE_URL:-}"
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    rm -rf "$WORK"
}
trap cleanup EXIT

# 検証先が指定されていなければ、一時DBの専用サーバーを起動する
if [ -z "$BASE" ]; then
    export DOORBELL_DB_PATH="$WORK/doorbell-e2e.sqlite"
    php -S "127.0.0.1:$PORT" -t "$ROOT/public" > "$WORK/server.log" 2>&1 &
    SERVER_PID=$!
    BASE="http://127.0.0.1:$PORT"

    for _ in $(seq 1 50); do
        curl -s -o /dev/null "$BASE/index.php" && break
        sleep 0.2
    done
    if ! curl -s -o /dev/null "$BASE/index.php"; then
        echo "テスト用サーバーを起動できませんでした（ポート $PORT が使用中かもしれません）"
        cat "$WORK/server.log"
        exit 1
    fi
fi
BASE="${BASE%/}"

PARENT_NAME='受付デスク'
CHILD_NAME='正面玄関'
MSG_IN5='分以内に応対します'

pass=0
fail=0
check() {
    if [ "$2" = "1" ]; then
        echo "  OK   $1"
        pass=$((pass + 1))
    else
        echo "  FAIL $1 :: $3"
        fail=$((fail + 1))
    fi
}
csrf_of() { grep -o 'name="csrf_token" value="[a-f0-9]*"' "$1" | head -1 | grep -o '[a-f0-9]\{64\}'; }

api() { # $1=cookieファイル $2=CSRFトークン $3=JSONボディ
    # Git Bash から native curl へ非ASCII引数を渡すと文字化けするため、ボディはファイル経由で渡す
    printf '%s' "$3" > "$WORK/payload.json"
    curl -s -b "$WORK/$1" -c "$WORK/$1" \
         -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" \
         --data-binary "@$WORK/payload.json" "$BASE/api.php"
}

echo "検証先: $BASE"
echo "== 管理画面 =="
curl -s -c "$WORK/admin.cookie" -o "$WORK/a1.html" "$BASE/admin.php"
C=$(csrf_of "$WORK/a1.html")

curl -s -b "$WORK/admin.cookie" -o "$WORK/a_nocsrf.html" -d "action=login&password=1234" "$BASE/admin.php"
grep -q 'name="password"' "$WORK/a_nocsrf.html" && R=1 || R=0
check "CSRFトークンなしのログインは失敗する" "$R" ""

curl -s -b "$WORK/admin.cookie" -c "$WORK/admin.cookie" -o "$WORK/a2.html" \
     -d "csrf_token=$C&action=login&password=9999" "$BASE/admin.php"
grep -q 'class="error"' "$WORK/a2.html" && R=1 || R=0
check "誤った管理パスワードを拒否する" "$R" ""

C=$(csrf_of "$WORK/a2.html")
curl -s -b "$WORK/admin.cookie" -c "$WORK/admin.cookie" -o "$WORK/a3.html" \
     -d "csrf_token=$C&action=login&password=1234" "$BASE/admin.php"
grep -q 'action" value="issue"' "$WORK/a3.html" && R=1 || R=0
check "管理画面にログインできる" "$R" ""

C=$(csrf_of "$WORK/a3.html")
curl -s -b "$WORK/admin.cookie" -c "$WORK/admin.cookie" -o "$WORK/a4.html" \
     -d "csrf_token=$C&action=issue" "$BASE/admin.php"
CREDS=$(grep -o '<dd>[^<]*</dd>' "$WORK/a4.html" | sed 's/<dd>//; s|</dd>||')
DID=$(echo "$CREDS" | sed -n 1p)
PPW=$(echo "$CREDS" | sed -n 2p)
CPW=$(echo "$CREDS" | sed -n 3p)
{ [ -n "$DID" ] && [ -n "$PPW" ] && [ -n "$CPW" ]; } && R=1 || R=0
check "IDと親機/子機パスワードを発行できる" "$R" "$CREDS"

echo "$DID" | grep -qE '^[0-9]{4}-[0-9]{4}$' && R=1 || R=0
check "IDが4桁-4桁の形式で発行される" "$R" "$DID"

echo "== メイン画面 =="
curl -s -c "$WORK/parent.cookie" -o "$WORK/p1.html" "$BASE/index.php"
PC=$(grep -o '"csrfToken":"[a-f0-9]*"' "$WORK/p1.html" | grep -o '[a-f0-9]\{64\}')
curl -s -c "$WORK/child.cookie" -o "$WORK/c1.html" "$BASE/index.php"
CC=$(grep -o '"csrfToken":"[a-f0-9]*"' "$WORK/c1.html" | grep -o '[a-f0-9]\{64\}')

OUT=$(api parent.cookie "$PC" '{"action":"login","doorbell_id":"0000-0000","password":"x","display_name":"desk"}')
grep -q 'invalid_credentials' <<< "$OUT" && R=1 || R=0
check "無効なIDはエラーになる" "$R" "$OUT"
NOID="$OUT"

OUT=$(api parent.cookie "$PC" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"wrong\",\"display_name\":\"desk\"}")
grep -q 'invalid_credentials' <<< "$OUT" && R=1 || R=0
check "誤ったパスワードはエラーになる" "$R" "$OUT"

# ID の誤りとパスワードの誤りを区別すると、有効なIDを総当たりで発見できてしまう
[ "$NOID" = "$OUT" ] && R=1 || R=0
check "IDの誤りとパスワードの誤りが区別できない" "$R" "存在しないID=$NOID / 存在するID=$OUT"

OUT=$(api parent.cookie "$PC" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"$PPW\",\"display_name\":\"$PARENT_NAME\",\"device_key\":\"aaaaaaaaaaaaaaaa\"}")
grep -q '"role":"parent"' <<< "$OUT" && R=1 || R=0
check "親機パスワードで親機と判定される" "$R" "$OUT"
grep -q "\"displayName\":\"$PARENT_NAME\"" <<< "$OUT" && R=1 || R=0
check "日本語の表示名が正しく往復する" "$R" "$OUT"

OUT=$(api child.cookie "$CC" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"$CPW\",\"display_name\":\"$CHILD_NAME\",\"device_key\":\"bbbbbbbbbbbbbbbb\"}")
grep -q '"role":"child"' <<< "$OUT" && R=1 || R=0
check "子機パスワードで子機と判定される" "$R" "$OUT"
grep -q '"parentOnline":true' <<< "$OUT" && R=1 || R=0
check "子機から親機が稼働中に見える" "$R" "$OUT"

OUT=$(api parent.cookie "$PC" '{"action":"state"}')
grep -q "{\"name\":\"$CHILD_NAME\",\"online\":true" <<< "$OUT" && R=1 || R=0
check "親機の待ち受け状態に稼働中の子機名が入る" "$R" "$OUT"

OUT=$(api child.cookie "$CC" '{"action":"call"}')
grep -q '"status":"waiting"' <<< "$OUT" && R=1 || R=0
check "子機がコールできる" "$R" "$OUT"

OUT=$(api parent.cookie "$PC" '{"action":"state"}')
grep -q "\"childName\":\"$CHILD_NAME\"" <<< "$OUT" && R=1 || R=0
check "親機のポーリングで子機の表示名を受け取る" "$R" "$OUT"
CALLID=$(grep -o '"activeCalls":\[{"id":[0-9]*' <<< "$OUT" | grep -o '[0-9]*$')

api child.cookie "$CC" '{"action":"call"}' > /dev/null
OUT=$(api parent.cookie "$PC" '{"action":"state"}')
grep -q '"callCount":2' <<< "$OUT" && R=1 || R=0
check "応答待ち中の連打は1件にまとまる" "$R" "$OUT"

OUT=$(api child.cookie "$CC" "{\"action\":\"respond\",\"call_id\":$CALLID,\"response\":\"in1\"}")
grep -q 'forbidden' <<< "$OUT" && R=1 || R=0
check "子機は応答できない" "$R" "$OUT"

OUT=$(api parent.cookie "$PC" '{"action":"call"}')
grep -q 'forbidden' <<< "$OUT" && R=1 || R=0
check "親機はコールできない" "$R" "$OUT"

OUT=$(api parent.cookie "$PC" "{\"action\":\"respond\",\"call_id\":$CALLID,\"response\":\"evil\"}")
grep -q 'invalid_response' <<< "$OUT" && R=1 || R=0
check "未定義の応答は拒否される" "$R" "$OUT"

OUT=$(api parent.cookie "$PC" "{\"action\":\"respond\",\"call_id\":$CALLID,\"response\":\"in5\"}")
grep -q '"activeCalls":\[\]' <<< "$OUT" && R=1 || R=0
check "応答すると親機は待ち受けに戻る" "$R" "$OUT"
grep -q '"history":\[{' <<< "$OUT" && R=1 || R=0
check "親機の状態に応答履歴が含まれる" "$R" "$OUT"
grep -q "\"childName\":\"$CHILD_NAME\"" <<< "$OUT" && R=1 || R=0
check "親機の履歴に呼び出し元の子機名が含まれる" "$R" "$OUT"

OUT=$(api parent.cookie "$PC" "{\"action\":\"respond\",\"call_id\":$CALLID,\"response\":\"in1\"}")
grep -q 'call_closed' <<< "$OUT" && R=1 || R=0
check "終了済みの呼び出しには再応答できない" "$R" "$OUT"

OUT=$(api child.cookie "$CC" '{"action":"state"}')
grep -q "$MSG_IN5" <<< "$OUT" && R=1 || R=0
check "子機が応答メッセージを受け取る" "$R" "$OUT"
grep -q "\"responder\":\"$PARENT_NAME\"" <<< "$OUT" && R=1 || R=0
check "子機に親機の表示名が表示される" "$R" "$OUT"
grep -q '"history":\[{' <<< "$OUT" && R=1 || R=0
check "応答履歴が返る" "$R" "$OUT"

OUT=$(curl -s -H 'Content-Type: application/json' -H "X-CSRF-Token: bad" -d '{"action":"state"}' "$BASE/api.php")
grep -q 'csrf' <<< "$OUT" && R=1 || R=0
check "不正なCSRFトークンは拒否される" "$R" "$OUT"

OUT=$(curl -s -b "$WORK/parent.cookie" -H "X-CSRF-Token: $PC" "$BASE/api.php")
grep -q 'method_not_allowed' <<< "$OUT" && R=1 || R=0
check "GETでのAPI呼び出しは拒否される" "$R" "$OUT"

api parent.cookie "$PC" '{"action":"logout"}' > /dev/null
OUT=$(api parent.cookie "$PC" '{"action":"state"}')
grep -q 'unauthenticated' <<< "$OUT" && R=1 || R=0
check "ログアウト後は未認証になる" "$R" "$OUT"

OUT=$(api child.cookie "$CC" '{"action":"state"}')
grep -q '"parentOnline":false' <<< "$OUT" && R=1 || R=0
check "ログアウトで親機オフラインになる" "$R" "$OUT"

# 子機がログアウトすると親機の一覧から消える
api child.cookie "$CC" '{"action":"logout"}' > /dev/null
OUT=$(api parent.cookie "$PC" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"$PPW\",\"display_name\":\"$PARENT_NAME\",\"device_key\":\"aaaaaaaaaaaaaaaa\"}")
grep -q '"children":\[\]' <<< "$OUT" && R=1 || R=0
check "ログアウトした子機は親機の一覧から消える" "$R" "$OUT"

echo "== 端末キーの使い回しによる同時接続上限の回避 =="
# device_key はクライアント由来なので、同じ値を送れば上限のカウントから外れてしまう。
# 端末ごとに有効なセッションを1つに絞ることで、枠を増やせないようにしている。
curl -s -c "$WORK/dk1.cookie" -o "$WORK/d1.html" "$BASE/index.php"
D1=$(grep -o '"csrfToken":"[a-f0-9]*"' "$WORK/d1.html" | grep -o '[a-f0-9]\{64\}')
curl -s -c "$WORK/dk2.cookie" -o "$WORK/d2.html" "$BASE/index.php"
D2=$(grep -o '"csrfToken":"[a-f0-9]*"' "$WORK/d2.html" | grep -o '[a-f0-9]\{64\}')
DK='cccccccccccccccc'

api dk1.cookie "$D1" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"$CPW\",\"display_name\":\"端末A\",\"device_key\":\"$DK\"}" > /dev/null
OUT=$(api dk2.cookie "$D2" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"$CPW\",\"display_name\":\"端末B\",\"device_key\":\"$DK\"}")
grep -q '"role":"child"' <<< "$OUT" && R=1 || R=0
check "同じ端末キーでの再ログインは受け付ける" "$R" "$OUT"

OUT=$(api dk1.cookie "$D1" '{"action":"state"}')
grep -q 'unauthenticated' <<< "$OUT" && R=1 || R=0
check "先にログインしたセッションは無効になる" "$R" "$OUT"

OUT=$(api dk2.cookie "$D2" '{"action":"state"}')
grep -q '"role":"child"' <<< "$OUT" && R=1 || R=0
check "後からログインしたセッションだけが有効" "$R" "$OUT"

echo "== レート制限のバケット分離 =="
# 管理画面の試行回数を使い切る
curl -s -c "$WORK/adm2.cookie" -o "$WORK/b1.html" "$BASE/admin.php"
BC=$(csrf_of "$WORK/b1.html")
admin_login() { # $1=パスワード
    curl -s -b "$WORK/adm2.cookie" -c "$WORK/adm2.cookie" -o "$WORK/b2.html" \
         -d "csrf_token=$BC&action=login&password=$1" "$BASE/admin.php"
    grep -q 'アクセスが集中' "$WORK/b2.html" && echo limited || echo tried
}
LIMITED=0
for i in $(seq 1 15); do
    [ "$(admin_login "bad$i")" = "limited" ] && { LIMITED=$i; break; }
done
[ "$LIMITED" -gt 0 ] && R=1 || R=0
check "管理画面のログイン試行がレート制限される" "$R" "15回試行しても制限されなかった"

# メイン画面のログイン成功で管理画面の試行回数が戻らないこと（バケットを共有していると戻ってしまう）
for i in 1 2 3; do
    api dk2.cookie "$D2" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"$CPW\",\"display_name\":\"端末B\",\"device_key\":\"$DK\"}" > /dev/null
done
[ "$(admin_login 'bad99')" = "limited" ] && R=1 || R=0
check "メイン画面のログイン成功で管理画面の制限が戻らない" "$R" "制限が解除された"

echo "== メイン画面のログイン試行のレート制限 =="
LIMITED=0
for i in $(seq 1 15); do
    OUT=$(api dk1.cookie "$D1" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"bad$i\",\"display_name\":\"x\"}")
    grep -q 'rate_limited' <<< "$OUT" && { LIMITED=$i; break; }
done
[ "$LIMITED" -gt 0 ] && R=1 || R=0
check "メイン画面のログイン試行がレート制限される" "$R" "15回試行しても制限されなかった"

echo "== 非公開ファイルの秘匿 =="
for path in config/config.php data/doorbell.sqlite src/Doorbell.php; do
    BODY=$(curl -s "$BASE/$path")
    if grep -qE 'admin_password|namespace Doorbell|SQLite format' <<< "$BODY"; then R=0; else R=1; fi
    check "$path の内容が露出しない" "$R" "$(head -c 80 <<< "$BODY")"
done

echo ""
echo "結果: $pass 件成功 / $fail 件失敗"
[ "$fail" -eq 0 ]
