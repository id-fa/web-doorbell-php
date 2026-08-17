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
LINK_PID=""
DEMO_PID=""
RESP_PID=""
NAME_PID=""
VOICE_PID=""
RENAMED="" # 管理画面のリネーム検証で public/ に一時的に置くコピー

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    [ -n "$LINK_PID" ] && kill "$LINK_PID" 2>/dev/null
    [ -n "$DEMO_PID" ] && kill "$DEMO_PID" 2>/dev/null
    [ -n "$RESP_PID" ] && kill "$RESP_PID" 2>/dev/null
    [ -n "$NAME_PID" ] && kill "$NAME_PID" 2>/dev/null
    [ -n "$VOICE_PID" ] && kill "$VOICE_PID" 2>/dev/null
    [ -n "$RENAMED" ] && rm -f "$ROOT/public/$RENAMED"
    rm -rf "$WORK"
}
trap cleanup EXIT

# 検証先が指定されていなければ、一時DBの専用サーバーを起動する
if [ -z "$BASE" ]; then
    export DOORBELL_DB_PATH="$WORK/doorbell-e2e.sqlite"

    # 設置環境の config/config.php に影響されないよう、既定値だけの一時設定で動かす
    # （管理パスワードを変えてある環境でもテストが通るようにするため）
    cat > "$WORK/config.php" <<'PHP'
<?php
return [];
PHP
    export DOORBELL_CONFIG_PATH="$WORK/config.php"

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

echo "== 子機のログアウト =="
# 置きっぱなしの子機を勝手にログアウトされないよう、パスワードを再確認する
OUT=$(api child.cookie "$CC" '{"action":"logout"}')
grep -q 'invalid_password' <<< "$OUT" && R=1 || R=0
check "パスワードなしの子機ログアウトは拒否される" "$R" "$OUT"

OUT=$(api child.cookie "$CC" '{"action":"logout","password":"wrong"}')
grep -q 'invalid_password' <<< "$OUT" && R=1 || R=0
check "誤ったパスワードの子機ログアウトは拒否される" "$R" "$OUT"

OUT=$(api child.cookie "$CC" '{"action":"state"}')
grep -q '"role":"child"' <<< "$OUT" && R=1 || R=0
check "拒否された子機はログインしたまま" "$R" "$OUT"

# 子機がログアウトすると親機の一覧から消える
OUT=$(api child.cookie "$CC" "{\"action\":\"logout\",\"password\":\"$CPW\"}")
grep -q '"ok":true' <<< "$OUT" && R=1 || R=0
check "子機パスワードでログアウトできる" "$R" "$OUT"
OUT=$(api parent.cookie "$PC" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"$PPW\",\"display_name\":\"$PARENT_NAME\",\"device_key\":\"aaaaaaaaaaaaaaaa\"}")
grep -q '"children":\[\]' <<< "$OUT" && R=1 || R=0
check "ログアウトした子機は親機の一覧から消える" "$R" "$OUT"

echo "== 子機URL（自動ログイン） =="
# 既定では無効。有効化した場合の動作は、別ポートに専用サーバーを立てて検証する
BOGUS='00000000000000000000000000000000'
OUT=$(api parent.cookie "$PC" "{\"action\":\"link_login\",\"token\":\"$BOGUS\"}")
grep -q 'link_disabled' <<< "$OUT" && R=1 || R=0
check "既定では子機URLログインが無効" "$R" "$OUT"

if [ -n "$SERVER_PID" ]; then
    # 既定で無効な機能（子機URL・外部連携API）をまとめて有効にした専用サーバー
    cat > "$WORK/link_config.php" <<'PHP'
<?php
return [
    'child_link_login'      => true,
    'integration_api'       => true,
    'webhook_allowed_hosts' => ['hooks.slack.com'],
];
PHP
    LPORT=$((PORT + 1))
    DOORBELL_CONFIG_PATH="$WORK/link_config.php" \
        php -S "127.0.0.1:$LPORT" -t "$ROOT/public" > "$WORK/link_server.log" 2>&1 &
    LINK_PID=$!
    LBASE="http://127.0.0.1:$LPORT"
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "$LBASE/index.php" && break
        sleep 0.2
    done

    # 管理画面から子機URLを発行する
    curl -s -c "$WORK/l.cookie" -o "$WORK/l1.html" "$LBASE/admin.php"
    LC=$(csrf_of "$WORK/l1.html")
    curl -s -b "$WORK/l.cookie" -c "$WORK/l.cookie" -o "$WORK/l2.html" \
         -d "csrf_token=$LC&action=login&password=1234" "$LBASE/admin.php"
    LC=$(csrf_of "$WORK/l2.html")

    # 一覧のIDは詳細画面へのリンクになっている
    RAW=$(echo "$DID" | tr -d '-')
    grep -q "admin.php?id=$RAW" "$WORK/l2.html" && R=1 || R=0
    check "一覧のIDが詳細画面へのリンクになる" "$R" ""

    # 子機URL・外部連携の発行フォームは詳細画面にある
    curl -s -b "$WORK/l.cookie" -o "$WORK/l2d.html" "$LBASE/admin.php?id=$RAW"
    grep -q 'value="child_link"' "$WORK/l2d.html" && R=1 || R=0
    check "有効時は詳細画面に子機URLの発行フォームが出る" "$R" ""
    grep -q 'value="integration"' "$WORK/l2d.html" && R=1 || R=0
    check "有効時は詳細画面に外部連携の発行フォームが出る" "$R" ""

    curl -s -b "$WORK/admin.cookie" -o "$WORK/a5.html" "$BASE/admin.php?id=$RAW"
    grep -q 'value="child_link"' "$WORK/a5.html" && R=0 || R=1
    check "無効時は詳細画面に子機URLの発行フォームが出ない" "$R" ""
    grep -q 'value="integration"' "$WORK/a5.html" && R=0 || R=1
    check "無効時は詳細画面に外部連携の発行フォームが出ない" "$R" ""

    # 非ASCIIの表示名が化けないよう、ボディはファイル経由で渡す
    printf 'csrf_token=%s&action=child_link&doorbell_id=%s&display_name=%s' "$LC" "$DID" '勝手口' \
        > "$WORK/link_post.txt"
    curl -s -b "$WORK/l.cookie" -c "$WORK/l.cookie" -o "$WORK/l3.html" \
         -H 'Content-Type: application/x-www-form-urlencoded' \
         --data-binary "@$WORK/link_post.txt" "$LBASE/admin.php"
    TOKEN=$(grep -o 'index\.php?child=[0-9a-f]\{32\}' "$WORK/l3.html" | head -1 | grep -o '[0-9a-f]\{32\}')
    [ -n "$TOKEN" ] && R=1 || R=0
    check "管理画面から子機URLを発行できる" "$R" "$(grep -o 'class="error"[^<]*<[^<]*' "$WORK/l3.html")"

    # 子機URLを開くと、トークンがブートストラップに載る
    curl -s -c "$WORK/link.cookie" -o "$WORK/l4.html" "$LBASE/index.php?child=$TOKEN"
    LKC=$(grep -o '"csrfToken":"[a-f0-9]*"' "$WORK/l4.html" | grep -o '[a-f0-9]\{64\}')
    grep -q "\"childLink\":\"$TOKEN\"" "$WORK/l4.html" && R=1 || R=0
    check "子機URLのトークンが画面に渡る" "$R" ""

    curl -s -o "$WORK/l5.html" "$BASE/index.php?child=$TOKEN"
    grep -q '"childLink":""' "$WORK/l5.html" && R=1 || R=0
    check "無効なサーバーではトークンを渡さない" "$R" ""

    lapi() { # $1=CSRFトークン $2=JSONボディ
        printf '%s' "$2" > "$WORK/payload.json"
        curl -s -b "$WORK/link.cookie" -c "$WORK/link.cookie" \
             -H 'Content-Type: application/json' -H "X-CSRF-Token: $1" \
             --data-binary "@$WORK/payload.json" "$LBASE/api.php"
    }

    OUT=$(lapi "$LKC" "{\"action\":\"link_login\",\"token\":\"$TOKEN\",\"device_key\":\"eeeeeeeeeeeeeeee\"}")
    grep -q '"role":"child"' <<< "$OUT" && R=1 || R=0
    check "子機URLだけでログインできる" "$R" "$OUT"
    grep -q '"displayName":"勝手口"' <<< "$OUT" && R=1 || R=0
    check "リンクの表示名が子機名になる" "$R" "$OUT"

    OUT=$(lapi "$LKC" '{"action":"state"}')
    grep -q '"role":"child"' <<< "$OUT" && R=1 || R=0
    check "ログイン後は通常どおりポーリングできる" "$R" "$OUT"

    OUT=$(lapi "$LKC" "{\"action\":\"link_login\",\"token\":\"$BOGUS\"}")
    grep -q 'invalid_link' <<< "$OUT" && R=1 || R=0
    check "存在しないトークンは拒否される" "$R" "$OUT"

    # 失効させたトークンでは入れない
    LC=$(csrf_of "$WORK/l3.html")
    curl -s -b "$WORK/l.cookie" -c "$WORK/l.cookie" -o "$WORK/l6.html" \
         -d "csrf_token=$LC&action=child_link_delete&token=$TOKEN" "$LBASE/admin.php"
    OUT=$(lapi "$LKC" "{\"action\":\"link_login\",\"token\":\"$TOKEN\",\"device_key\":\"eeeeeeeeeeeeeeee\"}")
    grep -q 'invalid_link' <<< "$OUT" && R=1 || R=0
    check "失効させた子機URLでは入れない" "$R" "$OUT"

    # 子機URLで入った端末も、ログアウトにはパスワードが必要
    OUT=$(lapi "$LKC" '{"action":"logout"}')
    grep -q 'invalid_password' <<< "$OUT" && R=1 || R=0
    check "子機URLで入った端末もログアウトにパスワードが要る" "$R" "$OUT"

    echo "== 外部連携API =="
    # 既定では無効（メイン側のサーバーは integration_api を有効にしていない）
    OUT=$(curl -s -H 'Content-Type: application/json' -d '{"action":"state"}' "$BASE/integration.php")
    grep -q 'integration_disabled' <<< "$OUT" && R=1 || R=0
    check "既定では外部連携APIが無効" "$R" "$OUT"

    # 管理画面から連携トークンを発行する（通知先URLは省略＝受信専用）
    LC=$(csrf_of "$WORK/l3.html")
    printf 'csrf_token=%s&action=integration&doorbell_id=%s&label=%s&webhook_url=' "$LC" "$DID" 'Slack受付' \
        > "$WORK/hook_post.txt"
    curl -s -b "$WORK/l.cookie" -c "$WORK/l.cookie" -o "$WORK/l7.html" \
         -H 'Content-Type: application/x-www-form-urlencoded' \
         --data-binary "@$WORK/hook_post.txt" "$LBASE/admin.php"
    ITOKEN=$(grep -o 'dbi_[0-9a-f]\{48\}' "$WORK/l7.html" | head -1)
    [ -n "$ITOKEN" ] && R=1 || R=0
    check "管理画面から連携トークンを発行できる" "$R" "$(grep -o 'class="error"[^<]*<[^<]*' "$WORK/l7.html")"

    # 許可されていないホストの通知先は登録できない（サーバーを踏み台にされないため）
    printf 'csrf_token=%s&action=integration&doorbell_id=%s&label=x&webhook_url=%s' \
        "$LC" "$DID" 'https://example.com/hook' > "$WORK/hook_bad.txt"
    curl -s -b "$WORK/l.cookie" -c "$WORK/l.cookie" -o "$WORK/l8.html" \
         -H 'Content-Type: application/x-www-form-urlencoded' \
         --data-binary "@$WORK/hook_bad.txt" "$LBASE/admin.php"
    grep -q '許可されていないホスト' "$WORK/l8.html" && R=1 || R=0
    check "許可外ホストの通知先は登録できない" "$R" ""

    iapi() { # $1=JSONボディ（Authorization ヘッダでトークンを渡す）
        printf '%s' "$1" > "$WORK/payload.json"
        curl -s -H 'Content-Type: application/json' -H "Authorization: Bearer $ITOKEN" \
             --data-binary "@$WORK/payload.json" "$LBASE/integration.php"
    }

    OUT=$(curl -s -H 'Content-Type: application/json' -d '{"action":"state"}' "$LBASE/integration.php")
    grep -q 'invalid_token' <<< "$OUT" && R=1 || R=0
    check "トークンなしの連携APIは拒否される" "$R" "$OUT"

    OUT=$(curl -s -H "Authorization: Bearer $ITOKEN" "$LBASE/integration.php")
    grep -q 'method_not_allowed' <<< "$OUT" && R=1 || R=0
    check "GETでの連携API呼び出しは拒否される" "$R" "$OUT"

    # 子機URLで入った端末から呼び出し、連携API側で受け取る
    lapi "$LKC" '{"action":"call"}' > /dev/null
    OUT=$(iapi '{"action":"state"}')
    grep -q '"role":"integration"' <<< "$OUT" && R=1 || R=0
    check "連携トークンで状態を取得できる" "$R" "$OUT"
    grep -q '"childName":"勝手口"' <<< "$OUT" && R=1 || R=0
    check "連携APIに応答待ちの呼び出しが見える" "$R" "$OUT"
    grep -q '"responses":{"in1"' <<< "$OUT" && R=1 || R=0
    check "連携APIが応答ボタンの定義を返す" "$R" "$OUT"
    ICALL=$(grep -o '"activeCalls":\[{"id":[0-9]*' <<< "$OUT" | grep -o '[0-9]*$')

    # ボディでトークンを渡す経路（Authorization ヘッダが届かない環境向け）
    OUT=$(iapi "{\"action\":\"state\",\"token\":\"$ITOKEN\"}")
    grep -q '"ok":true' <<< "$OUT" && R=1 || R=0
    check "ボディのトークンでも認証できる" "$R" "$OUT"

    OUT=$(iapi "{\"action\":\"respond\",\"call_id\":$ICALL,\"response\":\"evil\"}")
    grep -q 'invalid_response' <<< "$OUT" && R=1 || R=0
    check "連携APIでも未定義の応答は拒否される" "$R" "$OUT"

    OUT=$(iapi "{\"action\":\"respond\",\"call_id\":$ICALL,\"response\":\"in1\",\"responder\":\"山田\"}")
    grep -q '"activeCalls":\[\]' <<< "$OUT" && R=1 || R=0
    check "連携APIから応答できる" "$R" "$OUT"

    OUT=$(lapi "$LKC" '{"action":"state"}')
    grep -q '"responder":"山田"' <<< "$OUT" && R=1 || R=0
    check "連携APIの応答が子機に届く" "$R" "$OUT"

    # 連携を失効させるとトークンは使えなくなる
    IID=$(grep -o 'name="integration_id" value="[0-9]*"' "$WORK/l7.html" | head -1 | grep -o '[0-9]*')
    curl -s -b "$WORK/l.cookie" -c "$WORK/l.cookie" -o "$WORK/l9.html" \
         -d "csrf_token=$LC&action=integration_delete&integration_id=$IID" "$LBASE/admin.php"
    OUT=$(iapi '{"action":"state"}')
    grep -q 'invalid_token' <<< "$OUT" && R=1 || R=0
    check "失効させた連携トークンでは拒否される" "$R" "$OUT"

    # 子機の枠を解放して後続のテストへ戻す
    lapi "$LKC" "{\"action\":\"logout\",\"password\":\"$CPW\"}" > /dev/null

    kill "$LINK_PID" 2>/dev/null
    LINK_PID=""

    echo "== デモ設置用のオプション =="
    # 管理パスワードを共有して設置する場合の設定（伏せ字・削除ロック・自動失効）
    cat > "$WORK/demo_config.php" <<'PHP'
<?php
return [
    'child_link_login'       => true,
    'integration_api'        => true,
    'mask_secrets'           => true,
    'mask_client_ip'         => true,
    'deletion_grace_seconds' => 600,
    'id_lifetime'            => 3600,
];
PHP
    DPORT=$((PORT + 2))
    DOORBELL_CONFIG_PATH="$WORK/demo_config.php" \
        php -S "127.0.0.1:$DPORT" -t "$ROOT/public" > "$WORK/demo_server.log" 2>&1 &
    DEMO_PID=$!
    DBASE="http://127.0.0.1:$DPORT"
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "$DBASE/index.php" && break
        sleep 0.2
    done

    dadmin() { # $1=出力先 $2以降=curl の追加引数
        local out="$1"; shift
        curl -s -b "$WORK/dm.cookie" -c "$WORK/dm.cookie" -o "$WORK/$out" "$@"
    }

    curl -s -c "$WORK/dm.cookie" -o "$WORK/m1.html" "$DBASE/admin.php"
    MC=$(csrf_of "$WORK/m1.html")
    dadmin m2.html -d "csrf_token=$MC&action=login&password=1234" "$DBASE/admin.php"
    MC=$(csrf_of "$WORK/m2.html")
    dadmin m3.html -d "csrf_token=$MC&action=issue" "$DBASE/admin.php"
    MDID=$(grep -o '<dd>[0-9]\{4\}-[0-9]\{4\}</dd>' "$WORK/m3.html" | head -1 | grep -o '[0-9]\{4\}-[0-9]\{4\}')
    MRAW=$(echo "$MDID" | tr -d '-')
    [ -n "$MRAW" ] && R=1 || R=0
    check "デモ設定でもIDを発行できる" "$R" "$(grep -o 'class="error"[^<]*<[^<]*' "$WORK/m3.html")"

    grep -q 'あと' "$WORK/m3.html" && R=1 || R=0
    check "一覧に自動失効までの残り時間が出る" "$R" ""

    # 子機URLと外部連携を発行する（発行した直後は全体が見える）
    printf 'csrf_token=%s&action=child_link&doorbell_id=%s&display_name=%s' "$MC" "$MRAW" '通用口' > "$WORK/m_link.txt"
    dadmin m4.html -H 'Content-Type: application/x-www-form-urlencoded' \
        --data-binary "@$WORK/m_link.txt" "$DBASE/admin.php?id=$MRAW"
    MTOKEN=$(grep -o 'index\.php?child=[0-9a-f]\{32\}' "$WORK/m4.html" | head -1 | grep -o '[0-9a-f]\{32\}')
    [ -n "$MTOKEN" ] && R=1 || R=0
    check "発行した直後の子機URLは全体が見える" "$R" ""

    printf 'csrf_token=%s&action=integration&doorbell_id=%s&label=x&webhook_url=%s' \
        "$MC" "$MRAW" 'https://hooks.slack.com/services/AAA/BBB/CCC' > "$WORK/m_hook.txt"
    dadmin m5.html -H 'Content-Type: application/x-www-form-urlencoded' \
        --data-binary "@$WORK/m_hook.txt" "$DBASE/admin.php?id=$MRAW"
    MIID=$(grep -o 'name="integration_id" value="[0-9]*"' "$WORK/m5.html" | head -1 | grep -o '[0-9]*')
    grep -q 'dbi_[0-9a-f]\{48\}' "$WORK/m5.html" && R=1 || R=0
    check "発行した直後の連携トークンは全体が見える" "$R" ""

    # 開き直すと伏せ字になる
    dadmin m6.html "$DBASE/admin.php?id=$MRAW"
    grep -q "child=$MTOKEN" "$WORK/m6.html" && R=0 || R=1
    check "開き直すと子機URLが伏せ字になる" "$R" "$(grep -o 'index\.php?child=[^<"]*' "$WORK/m6.html" | head -1)"
    grep -q '伏せ字' "$WORK/m6.html" && R=1 || R=0
    check "伏せ字であることが画面に出る" "$R" ""
    grep -q 'hooks.slack.com/services/AAA' "$WORK/m6.html" && R=0 || R=1
    check "通知先URLのパスが伏せ字になる" "$R" ""
    grep -q 'data-copy="url-' "$WORK/m6.html" && R=0 || R=1
    check "伏せ字のときはコピーボタンを出さない" "$R" ""

    # 発行元IPは後半を伏せる（誰がどのIDを作ったか他の利用者から辿れないようにする）
    grep -q '<dd>127\.0\.0\.1</dd>' "$WORK/m6.html" && R=0 || R=1
    check "発行元IPがそのまま出ない" "$R" "$(grep -A1 '発行元IP' "$WORK/m6.html" | tr -d '\n')"
    grep -q '127\.0\.x\.x' "$WORK/m6.html" && R=1 || R=0
    check "発行元IPの後半が伏せ字になる" "$R" ""

    # 既定の設定では今までどおり全体を表示する
    curl -s -b "$WORK/admin.cookie" -o "$WORK/a6.html" "$BASE/admin.php?id=$RAW"
    grep -q '127\.0\.0\.1' "$WORK/a6.html" && R=1 || R=0
    check "既定では発行元IPをそのまま表示する" "$R" ""

    # 発行直後は削除・失効できない（画面で無効にするだけでなくサーバー側でも拒否する）
    OUT=$(curl -s -b "$WORK/dm.cookie" -d "csrf_token=$MC&action=delete&doorbell_id=$MRAW" "$DBASE/admin.php")
    grep -q '削除・失効できません' <<< "$OUT" && R=1 || R=0
    check "発行直後のID削除は拒否される" "$R" "$(grep -o '<p class="error">[^<]*' <<< "$OUT")"

    OUT=$(curl -s -b "$WORK/dm.cookie" -d "csrf_token=$MC&action=child_link_delete&token=$MTOKEN" "$DBASE/admin.php?id=$MRAW")
    grep -q '削除・失効できません' <<< "$OUT" && R=1 || R=0
    check "発行直後の子機URL失効は拒否される" "$R" "$(grep -o '<p class="error">[^<]*' <<< "$OUT")"

    OUT=$(curl -s -b "$WORK/dm.cookie" -d "csrf_token=$MC&action=integration_delete&integration_id=$MIID" "$DBASE/admin.php?id=$MRAW")
    grep -q '削除・失効できません' <<< "$OUT" && R=1 || R=0
    check "発行直後の連携失効は拒否される" "$R" "$(grep -o '<p class="error">[^<]*' <<< "$OUT")"

    # 既定（両方とも無効）のサーバーでは今までどおり削除できる
    grep -q '削除・失効できません' "$WORK/l9.html" && R=0 || R=1
    check "既定の設定では削除ロックが掛からない" "$R" ""

    # 発行時刻を寿命より前にずらすと、次のアクセスで消える
    php -r '
        $pdo = new PDO("sqlite:" . $argv[1]);
        $pdo->prepare("UPDATE doorbell_ids SET created_at = created_at - 4000 WHERE doorbell_id = :id")
            ->execute([":id" => $argv[2]]);
    ' "$DOORBELL_DB_PATH" "$MRAW"
    dadmin m7.html "$DBASE/admin.php"
    grep -q "admin.php?id=$MRAW" "$WORK/m7.html" && R=0 || R=1
    check "寿命を過ぎたIDが自動的に消える" "$R" ""

    LEFT=$(php -r '
        $pdo = new PDO("sqlite:" . $argv[1]);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM child_links WHERE doorbell_id = :id");
        $stmt->execute([":id" => $argv[2]]);
        echo (int) $stmt->fetchColumn();
    ' "$DOORBELL_DB_PATH" "$MRAW")
    [ "$LEFT" = "0" ] && R=1 || R=0
    check "自動失効で子機URLも一緒に消える" "$R" "$LEFT 件残っている"

    kill "$DEMO_PID" 2>/dev/null
    DEMO_PID=""

    echo "== カスタム応答メッセージ =="
    # 応答ボタンの文言・短縮ラベル・キーを設定で差し替えられる
    cat > "$WORK/resp_config.php" <<'PHP'
<?php
return [
    'responses' => [
        'now'  => ['message' => 'すぐ伺います', 'label' => 'すぐ'],
        'wait' => ['message' => '少々お待ちください', 'label' => 'お待ち'],
    ],
];
PHP
    RPORT=$((PORT + 3))
    DOORBELL_CONFIG_PATH="$WORK/resp_config.php" \
        php -S "127.0.0.1:$RPORT" -t "$ROOT/public" > "$WORK/resp_server.log" 2>&1 &
    RESP_PID=$!
    RBASE="http://127.0.0.1:$RPORT"
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "$RBASE/index.php" && break
        sleep 0.2
    done

    # 画面に渡されるのは設定した文言だけ
    curl -s -c "$WORK/r.cookie" -o "$WORK/r1.html" "$RBASE/index.php"
    RC=$(grep -o '"csrfToken":"[a-f0-9]*"' "$WORK/r1.html" | grep -o '[a-f0-9]\{64\}')
    grep -q '"now":"すぐ伺います"' "$WORK/r1.html" && R=1 || R=0
    check "設定した応答メッセージが画面に渡る" "$R" ""
    grep -q '"responseLabels":{"now":"すぐ","wait":"お待ち"}' "$WORK/r1.html" && R=1 || R=0
    check "設定した短縮ラベルが画面に渡る" "$R" "$(grep -o '"responseLabels":{[^}]*}' "$WORK/r1.html")"
    grep -q '1分以内に応対します' "$WORK/r1.html" && R=0 || R=1
    check "既定の応答メッセージは残らない" "$R" ""

    rapi() { # $1=cookieファイル $2=CSRF $3=JSONボディ
        printf '%s' "$3" > "$WORK/payload.json"
        curl -s -b "$WORK/$1" -c "$WORK/$1" \
             -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" \
             --data-binary "@$WORK/payload.json" "$RBASE/api.php"
    }

    # 既定のIDはこのサーバーでも使える（DBは共通）
    curl -s -c "$WORK/rc.cookie" -o "$WORK/r2.html" "$RBASE/index.php"
    RCC=$(grep -o '"csrfToken":"[a-f0-9]*"' "$WORK/r2.html" | grep -o '[a-f0-9]\{64\}')
    rapi r.cookie "$RC" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"$PPW\",\"display_name\":\"$PARENT_NAME\",\"device_key\":\"aaaaaaaaaaaaaaaa\"}" > /dev/null
    rapi rc.cookie "$RCC" "{\"action\":\"login\",\"doorbell_id\":\"$DID\",\"password\":\"$CPW\",\"display_name\":\"$CHILD_NAME\",\"device_key\":\"bbbbbbbbbbbbbbbb\"}" > /dev/null
    OUT=$(rapi rc.cookie "$RCC" '{"action":"call"}')
    RCALL=$(grep -o '"currentCall":{"id":[0-9]*' <<< "$OUT" | grep -o '[0-9]*$')

    OUT=$(rapi r.cookie "$RC" "{\"action\":\"respond\",\"call_id\":$RCALL,\"response\":\"in1\"}")
    grep -q 'invalid_response' <<< "$OUT" && R=1 || R=0
    check "設定から外したキーでは応答できない" "$R" "$OUT"

    OUT=$(rapi r.cookie "$RC" "{\"action\":\"respond\",\"call_id\":$RCALL,\"response\":\"now\"}")
    grep -q '"activeCalls":\[\]' <<< "$OUT" && R=1 || R=0
    check "設定したキーで応答できる" "$R" "$OUT"

    OUT=$(rapi rc.cookie "$RCC" '{"action":"state"}')
    grep -q 'すぐ伺います' <<< "$OUT" && R=1 || R=0
    check "子機に設定した文言が届く" "$R" "$OUT"

    # 枠を解放して後続のテストへ戻す
    rapi r.cookie "$RC" '{"action":"logout"}' > /dev/null
    rapi rc.cookie "$RCC" "{\"action\":\"logout\",\"password\":\"$CPW\"}" > /dev/null

    kill "$RESP_PID" 2>/dev/null
    RESP_PID=""
fi

if [ -n "$SERVER_PID" ]; then
    echo "== ID発行画面のリネーム =="
    # 総当たりで見つけられにくくするため admin.php を別名にしても、画面内のリンクが壊れないこと。
    # 実ファイルのコピーを public/ に一時的に置いて検証する（cleanup で必ず消す）
    RENAMED="admin.e2e-renamed.php"
    cp "$ROOT/public/admin.php" "$ROOT/public/$RENAMED"
    RAWID=$(echo "$DID" | tr -d '-')

    # 設定なしでも、開いているファイル名に追従する
    curl -s -c "$WORK/n1.cookie" -o "$WORK/n1.html" "$BASE/$RENAMED"
    NC=$(csrf_of "$WORK/n1.html")
    curl -s -b "$WORK/n1.cookie" -c "$WORK/n1.cookie" -o "$WORK/n2.html" \
         -d "csrf_token=$NC&action=login&password=1234" "$BASE/$RENAMED"
    grep -q "$RENAMED?id=$RAWID" "$WORK/n2.html" && R=1 || R=0
    check "リネームすると一覧のリンクが新しい名前になる" "$R" ""
    grep -q 'admin\.php?id=' "$WORK/n2.html" && R=0 || R=1
    check "元のファイル名はリンクに残らない" "$R" ""

    # 設定 admin_script があれば、開いているファイル名より優先される
    cat > "$WORK/name_config.php" <<PHP
<?php
return ['admin_script' => '$RENAMED'];
PHP
    NPORT=$((PORT + 4))
    DOORBELL_CONFIG_PATH="$WORK/name_config.php" \
        php -S "127.0.0.1:$NPORT" -t "$ROOT/public" > "$WORK/name_server.log" 2>&1 &
    NAME_PID=$!
    NBASE="http://127.0.0.1:$NPORT"
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "$NBASE/index.php" && break
        sleep 0.2
    done

    curl -s -c "$WORK/n3.cookie" -o "$WORK/n3.html" "$NBASE/admin.php"
    NC=$(csrf_of "$WORK/n3.html")
    curl -s -b "$WORK/n3.cookie" -c "$WORK/n3.cookie" -o "$WORK/n4.html" \
         -d "csrf_token=$NC&action=login&password=1234" "$NBASE/admin.php"
    grep -q "$RENAMED?id=$RAWID" "$WORK/n4.html" && R=1 || R=0
    check "設定した名前が画面内のリンクに使われる" "$R" ""

    # 設定とファイル名がずれていたら（＝設定した名前が存在しなければ）自動判定に戻す。
    # リンクが全滅して詳細画面へ入れなくなるのを避けるため
    rm -f "$ROOT/public/$RENAMED"
    RENAMED=""
    curl -s -b "$WORK/n3.cookie" -c "$WORK/n3.cookie" -o "$WORK/n5.html" "$NBASE/admin.php"
    grep -q "admin\.php?id=$RAWID" "$WORK/n5.html" && R=1 || R=0
    check "設定した名前のファイルがなければ自動判定に戻す" "$R" ""

    kill "$NAME_PID" 2>/dev/null
    NAME_PID=""
fi

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

OUT=$(api parent.cookie "$PC" '{"action":"voice_start","call_id":1,"sdp":{"type":"offer","sdp":"v=0"}}')
grep -q 'voice_disabled' <<< "$OUT" && R=1 || R=0
check "既定では通話を開始できない" "$R" "$OUT"

if [ -n "$SERVER_PID" ]; then
    # 通話（テスト版機能）。既定で無効なので専用サーバーを立てる。
    # WebRTC 本体（getUserMedia / ICE）はブラウザがないと動かないため、
    # ここで確かめるのはシグナリングの受け渡しと呼び出しの決着だけ。
    cat > "$WORK/voice_config.php" <<'PHP'
<?php
return [
    'voice_call' => true,
    // DB もレート制限の集計も他のサーバーと共有しているため、
    // ここまでの検証で使った分に引っかからないよう上限を緩めておく
    'max_ids_per_ip' => 100,
    'rate_limit'     => ['max_requests' => 3000, 'max_logins' => 1000, 'max_calls' => 1000],
];
PHP
    VPORT=$((PORT + 5))
    DOORBELL_CONFIG_PATH="$WORK/voice_config.php" \
        php -S "127.0.0.1:$VPORT" -t "$ROOT/public" > "$WORK/voice_server.log" 2>&1 &
    VOICE_PID=$!
    VBASE="http://127.0.0.1:$VPORT"
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "$VBASE/index.php" && break
        sleep 0.2
    done

    vapi() { # $1=cookieファイル $2=CSRFトークン $3=JSONボディ
        printf '%s' "$3" > "$WORK/payload.json"
        curl -s -b "$WORK/$1" -c "$WORK/$1" \
             -H 'Content-Type: application/json' -H "X-CSRF-Token: $2" \
             --data-binary "@$WORK/payload.json" "$VBASE/api.php"
    }

    echo "== 通話（テスト版機能） =="

    # 既定のIDには別サーバーの親機・子機が居座っているので、専用のIDを発行する
    curl -s -c "$WORK/va.cookie" -o "$WORK/va1.html" "$VBASE/admin.php"
    VA=$(csrf_of "$WORK/va1.html")
    curl -s -b "$WORK/va.cookie" -c "$WORK/va.cookie" -o "$WORK/va2.html" \
         -d "csrf_token=$VA&action=login&password=1234" "$VBASE/admin.php"
    VA=$(csrf_of "$WORK/va2.html")
    curl -s -b "$WORK/va.cookie" -c "$WORK/va.cookie" -o "$WORK/va3.html" \
         -d "csrf_token=$VA&action=issue" "$VBASE/admin.php"
    VCREDS=$(grep -o '<dd>[^<]*</dd>' "$WORK/va3.html" | sed 's/<dd>//; s|</dd>||')
    VID=$(echo "$VCREDS" | sed -n 1p)
    VPPW=$(echo "$VCREDS" | sed -n 2p)
    VCPW=$(echo "$VCREDS" | sed -n 3p)

    curl -s -c "$WORK/vp.cookie" -o "$WORK/vp1.html" "$VBASE/index.php"
    VPC=$(grep -o '"csrfToken":"[a-f0-9]*"' "$WORK/vp1.html" | grep -o '[a-f0-9]\{64\}')
    curl -s -c "$WORK/vc.cookie" -o "$WORK/vc1.html" "$VBASE/index.php"
    VCC=$(grep -o '"csrfToken":"[a-f0-9]*"' "$WORK/vc1.html" | grep -o '[a-f0-9]\{64\}')

    { [ -n "$VID" ] && [ -n "$VPPW" ] && [ -n "$VCPW" ]; } && R=1 || R=0
    check "通話の検証用にIDを発行できる" "$R" "$VCREDS"

    grep -q '"voiceCall":true' "$WORK/vp1.html" && R=1 || R=0
    check "通話の設定が画面に渡る" "$R" ""

    grep -q 'assets/voice.js' "$WORK/vp1.html" && R=1 || R=0
    check "通話のスクリプトが読み込まれる" "$R" ""

    vapi vp.cookie "$VPC" "{\"action\":\"login\",\"doorbell_id\":\"$VID\",\"password\":\"$VPPW\",\"display_name\":\"受付\",\"device_key\":\"vvvvvvvvvvvvvvvv\"}" > /dev/null
    vapi vc.cookie "$VCC" "{\"action\":\"login\",\"doorbell_id\":\"$VID\",\"password\":\"$VCPW\",\"display_name\":\"玄関\",\"device_key\":\"wwwwwwwwwwwwwwww\"}" > /dev/null

    OUT=$(vapi vc.cookie "$VCC" '{"action":"call"}')
    VCALL=$(grep -o '"activeCalls":\[{"id":[0-9]*' <<< "$(vapi vp.cookie "$VPC" '{"action":"state"}')" | grep -o '[0-9]*$')
    [ -n "$VCALL" ] && R=1 || R=0
    check "呼び出しを立てられる" "$R" "$OUT"

    # 子機は親機のみが通話を開始できる
    OUT=$(vapi vc.cookie "$VCC" "{\"action\":\"voice_start\",\"call_id\":$VCALL,\"sdp\":{\"type\":\"offer\",\"sdp\":\"v=0\"}}")
    grep -q 'forbidden' <<< "$OUT" && R=1 || R=0
    check "子機からは通話を開始できない" "$R" "$OUT"

    OUT=$(vapi vp.cookie "$VPC" "{\"action\":\"voice_start\",\"call_id\":$VCALL,\"sdp\":{\"type\":\"offer\",\"sdp\":\"v=0 offer\"}}")
    VSESS=$(grep -o '"session":{"id":[0-9]*' <<< "$OUT" | grep -o '[0-9]*$')
    [ -n "$VSESS" ] && R=1 || R=0
    check "親機から通話を開始できる" "$R" "$OUT"

    OUT=$(vapi vp.cookie "$VPC" '{"action":"state"}')
    grep -q '"status":"waiting"' <<< "$OUT" && R=1 || R=0
    check "通話の接続待ちのあいだ呼び出しは応答待ちのまま" "$R" "$OUT"

    OUT=$(vapi vc.cookie "$VCC" '{"action":"voice_poll"}')
    grep -q '"kind":"offer"' <<< "$OUT" && R=1 || R=0
    check "子機がセッションIDなしで offer を受け取る" "$R" "$OUT"

    OUT=$(vapi vc.cookie "$VCC" "{\"action\":\"voice_poll\",\"session_id\":$VSESS}")
    grep -q '"signals":\[\]' <<< "$OUT" && R=1 || R=0
    check "配送済みの offer は二度届かない" "$R" "$OUT"

    OUT=$(vapi vc.cookie "$VCC" "{\"action\":\"voice_signal\",\"session_id\":$VSESS,\"kind\":\"answer\",\"payload\":{\"type\":\"answer\",\"sdp\":\"v=0 answer\"}}")
    grep -q '"ok":true' <<< "$OUT" && R=1 || R=0
    check "子機から answer を返せる" "$R" "$OUT"

    OUT=$(vapi vp.cookie "$VPC" "{\"action\":\"voice_poll\",\"session_id\":$VSESS}")
    grep -q '"kind":"answer"' <<< "$OUT" && R=1 || R=0
    check "親機に answer が届く" "$R" "$OUT"

    OUT=$(vapi vp.cookie "$VPC" "{\"action\":\"voice_connected\",\"session_id\":$VSESS}")
    grep -q '"status":"connected"' <<< "$OUT" && R=1 || R=0
    check "接続成立を記録できる" "$R" "$OUT"

    grep -q 'ここから通話します' <<< "$OUT" && R=1 || R=0
    check "接続成立で呼び出しが通話の応答として決着する" "$R" "$OUT"

    OUT=$(vapi vp.cookie "$VPC" "{\"action\":\"voice_end\",\"session_id\":$VSESS,\"reason\":\"hangup\"}")
    grep -q '"ok":true' <<< "$OUT" && R=1 || R=0
    check "通話を終了できる" "$R" "$OUT"

    OUT=$(vapi vp.cookie "$VPC" "{\"action\":\"voice_poll\",\"session_id\":$VSESS}")
    grep -q '"status":"ended"' <<< "$OUT" && R=1 || R=0
    check "終了した通話は ended として返る" "$R" "$OUT"

    # SDP は相手のブラウザに渡るので、当事者以外が割り込めてはいけない
    OUT=$(vapi va.cookie "$VA" "{\"action\":\"voice_poll\",\"session_id\":$VSESS}")
    grep -qE 'unauthenticated|csrf' <<< "$OUT" && R=1 || R=0
    check "ログインしていない相手は通話を覗けない" "$R" "$OUT"

    kill "$VOICE_PID" 2>/dev/null
    VOICE_PID=""
fi

echo "== 非公開ファイルの秘匿 =="
for path in config/config.php data/doorbell.sqlite src/Doorbell.php; do
    BODY=$(curl -s "$BASE/$path")
    if grep -qE 'admin_password|namespace Doorbell|SQLite format' <<< "$BODY"; then R=0; else R=1; fi
    check "$path の内容が露出しない" "$R" "$(head -c 80 <<< "$BODY")"
done

echo ""
echo "結果: $pass 件成功 / $fail 件失敗"
[ "$fail" -eq 0 ]
