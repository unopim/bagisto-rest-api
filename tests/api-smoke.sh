set -uo pipefail

BASE="${API_BASE_URL:-http://127.0.0.1:8000}"
EMAIL="${API_ADMIN_EMAIL:-admin@example.com}"
PASSWORD="${API_ADMIN_PASSWORD:-admin123}"

pass=0
fail=0
ok()   { echo "  PASS  $1"; pass=$((pass + 1)); }
bad()  { echo "  FAIL  $1"; fail=$((fail + 1)); }
note() { echo "  ....  $1"; }

status() { curl -s -o /dev/null -w '%{http_code}' --max-time 30 -H 'Accept: application/json' "$@"; }

echo "REST API smoke tests against ${BASE}"
echo "------------------------------------------------------------"

# 1. Public — list categories
c=$(status "${BASE}/api/v1/categories")
[ "$c" = "200" ] && ok "GET /api/v1/categories -> 200" || bad "GET /api/v1/categories -> ${c} (expected 200)"

# 2. Public — list attributes, and the response carries a non-empty data array
body=$(curl -s --max-time 30 -H 'Accept: application/json' "${BASE}/api/v1/attributes")
if echo "$body" | python3 -c "import sys,json;d=json.load(sys.stdin);sys.exit(0 if isinstance(d.get('data'),list) and d['data'] else 1)" 2>/dev/null; then
  ok "GET /api/v1/attributes -> 200 with data"
else
  bad "GET /api/v1/attributes -> data check failed (body: $(echo "$body" | head -c 120))"
fi

# 3. Public — single product listing (soft: depends on sample data / cart bindings)
c=$(status "${BASE}/api/v1/products")
[ "$c" = "200" ] && ok "GET /api/v1/products -> 200" || note "GET /api/v1/products -> ${c} (non-fatal)"

# 4. Auth — admin login issues a token
login=$(curl -s --max-time 30 -H 'Accept: application/json' -X POST "${BASE}/api/v1/admin/login" \
  --data-urlencode "email=${EMAIL}" \
  --data-urlencode "password=${PASSWORD}" \
  --data-urlencode "device_name=ci")
TOKEN=$(echo "$login" | python3 -c "import sys,json;print(json.load(sys.stdin).get('token',''))" 2>/dev/null)
[ -n "$TOKEN" ] && ok "POST /api/v1/admin/login -> token issued" || bad "POST /api/v1/admin/login -> no token (body: $(echo "$login" | head -c 120))"

# 5. Auth — protected endpoint is rejected WITHOUT a token
c=$(status "${BASE}/api/v1/admin/catalog/products")
[ "$c" = "401" ] && ok "GET /api/v1/admin/catalog/products (no token) -> 401" || bad "no-token admin endpoint -> ${c} (expected 401)"

# 6. Auth — protected endpoint accepts the token
if [ -n "$TOKEN" ]; then
  c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 -H 'Accept: application/json' \
    -H "Authorization: Bearer ${TOKEN}" "${BASE}/api/v1/admin/catalog/products")
  [ "$c" = "200" ] && ok "GET /api/v1/admin/catalog/products (token) -> 200" || bad "token admin endpoint -> ${c} (expected 200)"
fi

# 7. Auth — bad credentials are rejected
c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 -H 'Accept: application/json' -X POST "${BASE}/api/v1/admin/login" \
  --data-urlencode "email=${EMAIL}" \
  --data-urlencode "password=definitely-wrong-${RANDOM}" \
  --data-urlencode "device_name=ci")
{ [ "$c" = "401" ] || [ "$c" = "422" ]; } && ok "POST /api/v1/admin/login (bad password) -> ${c}" || bad "bad login -> ${c} (expected 401/422)"

echo "------------------------------------------------------------"
echo "Passed: ${pass}   Failed: ${fail}"
[ "$fail" -eq 0 ]
