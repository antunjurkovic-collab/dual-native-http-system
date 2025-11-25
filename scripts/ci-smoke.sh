#!/usr/bin/env bash
set -euo pipefail

BASE=${DNH_BASE:-}
POST_ID=${DNH_POST_ID:-}
USER=${DNH_USER:-}
PASS=${DNH_PASS:-}

if [[ -z "$BASE" || -z "$POST_ID" ]]; then
  echo "DNH_BASE and DNH_POST_ID are required" >&2
  exit 2
fi

AUTH=( )
if [[ -n "${USER}" && -n "${PASS}" ]]; then
  AUTH=( -u "${USER}:${PASS}" )
fi

MR_URL_V1="$BASE/wp-json/dual-native/v1/posts/$POST_ID"
MR_URL_V2="$BASE/wp-json/dual-native/v2/posts/$POST_ID"
CAT_URL_V2="$BASE/wp-json/dual-native/v2/catalog"
WRITE_URL_V2="$BASE/wp-json/dual-native/v2/posts/$POST_ID/blocks"

tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

header() { echo; echo "== $* =="; }

get_header() { # $1: header name, read from file
  awk -v IGNORECASE=1 -v key="$1:" '$0 ~ key {print substr($0, index($0,$2))}' "$2"
}

header "GET MR (v2)"
curl -sS -D "$tmpdir/h1" -o "$tmpdir/b1.json" "${AUTH[@]}" "$MR_URL_V2"
ETAG=$(awk -v IGNORECASE=1 '/^ETag:/{gsub("\r$","",$0); print $2}' "$tmpdir/h1" | sed 's/^W\///; s/^\"//; s/\"$//')
[[ -n "$ETAG" ]] || { echo "Missing ETag"; exit 1; }
echo "ETag: $ETAG"

# ETag == CID check (body has a cid field)
CID=$(jq -r '.cid // .CID // empty' "$tmpdir/b1.json" 2>/dev/null || true)
if [[ -n "$CID" && "$CID" != "$ETAG" ]]; then
  echo "FAIL: ETag != CID ($ETAG vs $CID)"; exit 1; fi

# Digest parity (sha-256 bytes)
BD64=$(python3 - <<'PY'
import sys,hashlib,base64
with open(sys.argv[1],'rb') as f:
  b=f.read()
print(base64.b64encode(hashlib.sha256(b).digest()).decode('ascii'))
PY
"$tmpdir/b1.json")
HDR_CD=$(awk -v IGNORECASE=1 '/^Content-Digest:/{gsub("\r$","",$0); print $2}' "$tmpdir/h1")
[[ "$HDR_CD" == *"sha-256=:${BD64}:"* ]] || { echo "FAIL: Content-Digest mismatch"; exit 1; }
echo "Digest OK"

header "304 Not Modified (MR)"
curl -sS -o /dev/null -D "$tmpdir/h2" "${AUTH[@]}" -H "If-None-Match: \"$ETAG\"" "$MR_URL_V2"
STATUS=$(awk 'NR==1{print $2}' "$tmpdir/h2")
[[ "$STATUS" == "304" ]] || { echo "FAIL: expected 304, got $STATUS"; exit 1; }
echo "304 OK"

header "412 Precondition Failed (write with bad If-Match)"
curl -sS -o /dev/null -D "$tmpdir/h3" -X POST "${AUTH[@]}" \
  -H 'Content-Type: application/json' \
  -H 'If-Match: "sha256-deadbeef"' \
  --data '{"insert":"append","blocks":[{"type":"core/paragraph","content":"smoke"}]}' \
  "$WRITE_URL_V2" || true
STATUS=$(awk 'NR==1{print $2}' "$tmpdir/h3")
[[ "$STATUS" == "412" ]] || { echo "FAIL: expected 412, got $STATUS"; exit 1; }
echo "412 OK"

header "Catalog (v2) + 304"
curl -sS -D "$tmpdir/hc1" -o "$tmpdir/c1.json" "${AUTH[@]}" "$CAT_URL_V2"
CTAG=$(awk -v IGNORECASE=1 '/^ETag:/{gsub("\r$","",$0); print $2}' "$tmpdir/hc1" | sed 's/^W\///; s/^\"//; s/\"$//')
curl -sS -o /dev/null -D "$tmpdir/hc2" "${AUTH[@]}" -H "If-None-Match: \"$CTAG\"" "$CAT_URL_V2"
STATUS=$(awk 'NR==1{print $2}' "$tmpdir/hc2")
[[ "$STATUS" == "304" ]] || { echo "FAIL: catalog expected 304, got $STATUS"; exit 1; }
echo "Catalog 304 OK"

echo
echo "Smoke checks passed."

