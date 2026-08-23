#!/usr/bin/env bash
#
# Drive a real ePHPm instance serving switchboard-api as a vhost, with curl.
#
# The unit suite (tools/test.php) exercises the handler in-process. This checks
# the things only a real server can answer: that ePHPm actually refuses to serve
# the state directory and the application internals, and that the webhook works
# through the SAPI's own request handling.
#
# Setup:
#   1. Deploy this repo to <sites_dir>/<name>/ and run `composer install`.
#   2. Write a webhook secret to <site>/.switchboard/webhook_secret.
#   3. Configure ePHPm per examples/ephpm.toml and start it.
#   4. Export the variables below and run this script.
#
# Required:
#   SB_SECRET   the same secret as <site>/.switchboard/webhook_secret
#   SB_SITE     filesystem path to the deployed vhost directory
# Optional:
#   SB_URL      base URL          (default http://127.0.0.1:8080)
#   SB_HOST     Host header       (default switchboard.localhost)
#   SB_PHP      PHP binary used to compute HMACs (default: php)
#   SB_WORKDIR  scratch directory (default: mktemp -d)
#
# Note for Windows/Git Bash: curl.exe is a native binary, so the paths handed to
# `-o` and `--data-binary @` must be Windows-shaped. Set SB_WORKDIR_WIN to the
# same directory as SB_WORKDIR in that spelling; it defaults to SB_WORKDIR.
set -u

: "${SB_SECRET:?set SB_SECRET to the webhook secret this instance is configured with}"
: "${SB_SITE:?set SB_SITE to the deployed vhost directory}"
URL="${SB_URL:-http://127.0.0.1:8080}"
HOST="${SB_HOST:-switchboard.localhost}"
PHP="${SB_PHP:-php}"
WORK="${SB_WORKDIR:-$(mktemp -d)}"
WORK_WIN="${SB_WORKDIR_WIN:-$WORK}"

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS $1 ($3)"; pass=$((pass+1));
          else echo "  FAIL $1: expected $2, got $3"; fail=$((fail+1)); fi; }
# `case` rather than grep: some Git Bash builds ship a broken grep.
contains() {
  case "$(cat "$WORK/last.out" 2>/dev/null)" in
    *"$2"*) echo "  PASS $1"; pass=$((pass+1));;
    *)      echo "  FAIL $1 (body lacks '$2')"; fail=$((fail+1));;
  esac
}
jobs() { ls "$SB_SITE/.switchboard/queue"/*.json 2>/dev/null | wc -l | tr -d ' '; }
# $PHP is deliberately unquoted: it may carry arguments, e.g. SB_PHP="ephpm php".
# shellcheck disable=SC2086
sign() { $PHP -r 'echo "sha256=".hash_hmac("sha256", file_get_contents($argv[1]), $argv[2]);' "$1" "$SB_SECRET" 2>/dev/null; }

cat > "$WORK/payload.json" <<'JSON'
{"action":"opened","number":7,"pull_request":{"number":7,"title":"Live test","head":{"ref":"feature/live","sha":"0123456789abcdef0123456789abcdef01234567","repo":{"full_name":"ephpm/wordpress-sample","clone_url":"https://github.com/ephpm/wordpress-sample.git"}},"base":{"ref":"main"}},"repository":{"full_name":"ephpm/wordpress-sample","name":"wordpress-sample","owner":{"login":"ephpm"},"clone_url":"https://github.com/ephpm/wordpress-sample.git","default_branch":"main","private":false},"sender":{"login":"octocat"},"installation":{"id":999}}
JSON
sed 's/"number":7/"number":8/' "$WORK/payload.json" > "$WORK/tampered.json"
sed 's|"full_name":"ephpm/wordpress-sample","clone_url":"https://github.com/ephpm/wordpress-sample.git"}},"base"|"full_name":"outsider/wordpress-sample","clone_url":"https://github.com/outsider/wordpress-sample.git"}},"base"|' \
  "$WORK/payload.json" > "$WORK/fork.json"

SIG=$(sign "$WORK_WIN/payload.json")

post() { # delivery signature event body-path -> status
  if [ -n "$2" ]; then
    curl -s -o "$WORK_WIN/last.out" -w '%{http_code}' -X POST "$URL/webhook" \
      -H "Host: $HOST" -H 'Content-Type: application/json' \
      -H "X-GitHub-Event: $3" -H "X-GitHub-Delivery: $1" -H "X-Hub-Signature-256: $2" \
      --data-binary "@$4"
  else
    curl -s -o "$WORK_WIN/last.out" -w '%{http_code}' -X POST "$URL/webhook" \
      -H "Host: $HOST" -H 'Content-Type: application/json' \
      -H "X-GitHub-Event: $3" -H "X-GitHub-Delivery: $1" \
      --data-binary "@$4"
  fi
}
get() { curl -s -o "$WORK_WIN/last.out" -w '%{http_code}' "$URL$1" -H "Host: $HOST" "${@:2}"; }

echo "== health =="
check "GET /healthz" 200 "$(get /healthz)"
contains "secret loaded from .switchboard/webhook_secret" '"webhook_configured": true'

echo "== confinement: state directory unreachable over HTTP =="
check "GET /.switchboard/webhook_secret" 403 "$(get /.switchboard/webhook_secret)"
check "GET /.switchboard/queue/"         403 "$(get /.switchboard/queue/)"
check "GET /.switchboard/deliveries/"    403 "$(get /.switchboard/deliveries/)"

echo "== confinement: app internals blocked by blocked_paths =="
check "GET /composer.json"                          403 "$(get /composer.json)"
check "GET /vendor/autoload.php"                    403 "$(get /vendor/autoload.php)"
check "GET /vendor/laminas-diactoros/composer.json" 403 "$(get /vendor/laminas-diactoros/composer.json)"
check "GET /src/Config.php"                         403 "$(get /src/Config.php)"
check "GET /tests/Support/Fixtures.php"             403 "$(get /tests/Support/Fixtures.php)"
check "GET /worker.php"                             403 "$(get /worker.php)"

echo "== webhook: valid signature =="
before=$(jobs)
check "POST /webhook (signed)" 202 "$(post 'aaaaaaaa-1111-2222-3333-000000000001' "$SIG" pull_request "$WORK_WIN/payload.json")"
contains "response names the preview label" 'ephpm-wordpress-sample-pr-7'
after=$(jobs); check "job written" "$((before+1))" "$after"

echo "== webhook: duplicate delivery =="
check "POST /webhook (same delivery)" 200 "$(post 'aaaaaaaa-1111-2222-3333-000000000001' "$SIG" pull_request "$WORK_WIN/payload.json")"
contains "reported as duplicate" '"duplicate": true'
check "no second job queued" "$after" "$(jobs)"

echo "== webhook: rejections =="
before=$(jobs)
check "wrong signature"   401 "$(post 'aaaaaaaa-1111-2222-3333-000000000002' 'sha256=0000000000000000000000000000000000000000000000000000000000000000' pull_request "$WORK_WIN/payload.json")"
check "missing signature" 401 "$(post 'aaaaaaaa-1111-2222-3333-000000000003' '' pull_request "$WORK_WIN/payload.json")"
check "tampered body"     401 "$(post 'aaaaaaaa-1111-2222-3333-000000000004' "$SIG" pull_request "$WORK_WIN/tampered.json")"
check "unknown event"     202 "$(post 'aaaaaaaa-1111-2222-3333-000000000005' "$(sign "$WORK_WIN/payload.json")" push "$WORK_WIN/payload.json")"
check "ping"              200 "$(post 'aaaaaaaa-1111-2222-3333-000000000007' "$(sign "$WORK_WIN/payload.json")" ping "$WORK_WIN/payload.json")"
check "no jobs from rejected deliveries" "$before" "$(jobs)"

echo "== fork policy (default: refuse) =="
check "fork PR refused" 202 "$(post 'aaaaaaaa-1111-2222-3333-000000000006' "$(sign "$WORK_WIN/fork.json")" pull_request "$WORK_WIN/fork.json")"
contains "refusal names the fork gate" '"ignored": "fork"'
check "fork queued nothing" "$before" "$(jobs)"

echo "== removed surfaces are gone, not dormant =="
check "GET / (not an allowed PHP path)"             403 "$(get /)"
check "GET /api/previews (not an allowed PHP path)" 403 "$(get /api/previews)"

echo
echo "RESULT: $pass passed, $fail failed"
echo "--- the queued job the daemon would consume ---"
cat "$SB_SITE/.switchboard/queue"/*.json 2>/dev/null
exit $((fail > 0 ? 1 : 0))
