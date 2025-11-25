Param()
$ErrorActionPreference = 'Stop'

if (-not $env:DNH_BASE -or -not $env:DNH_POST_ID) {
  Write-Error 'DNH_BASE and DNH_POST_ID are required'
}

$BASE = $env:DNH_BASE
$POST = $env:DNH_POST_ID
$USER = $env:DNH_USER
$PASS = $env:DNH_PASS

$auth = @()
if ($USER -and $PASS) { $auth = @('-u', "$USER`:$PASS") }

$mrV2 = "$BASE/wp-json/dual-native/v2/posts/$POST"
$catV2 = "$BASE/wp-json/dual-native/v2/catalog"
$writeV2 = "$BASE/wp-json/dual-native/v2/posts/$POST/blocks"

Function Get-Header([string]$file,[string]$name){
  (Get-Content $file | Where-Object { $_ -match "^$name:" }) -replace '^.*?:\s*',''
}

Write-Host "`n== GET MR (v2) =="
$h1 = New-TemporaryFile
$b1 = New-TemporaryFile
& curl.exe -sS -D $h1 -o $b1 @auth $mrV2 | Out-Null
$etag = (Get-Header $h1 'ETag').Trim() -replace '^W/','' -replace '"',''
if (-not $etag) { throw 'Missing ETag' }
Write-Host "ETag: $etag"

# Parse CID from body if present
try { $cid = (Get-Content $b1 -Raw | ConvertFrom-Json).cid } catch { $cid = '' }
if ($cid -and $cid -ne $etag) { throw "ETag != CID ($etag vs $cid)" }

# Digest parity
$bytes = [IO.File]::ReadAllBytes($b1)
$sha = [Convert]::ToBase64String([Security.Cryptography.SHA256]::Create().ComputeHash($bytes))
$cd = Get-Header $h1 'Content-Digest'
if (-not $cd.Contains("sha-256=:$sha:")) { throw 'Content-Digest mismatch' }
Write-Host 'Digest OK'

Write-Host "`n== 304 Not Modified (MR) =="
$h2 = New-TemporaryFile
& curl.exe -sS -o $null -D $h2 @auth -H "If-None-Match: `"$etag`"" $mrV2 | Out-Null
$status = (Get-Content $h2 | Select-Object -First 1).Split(' ')[1]
if ($status -ne '304') { throw "Expected 304, got $status" }
Write-Host '304 OK'

Write-Host "`n== 412 Precondition Failed (write) =="
$h3 = New-TemporaryFile
& curl.exe -sS -o $null -D $h3 -X POST @auth -H 'Content-Type: application/json' -H 'If-Match: "sha256-deadbeef"' --data '{"insert":"append","blocks":[{"type":"core/paragraph","content":"smoke"}]}' $writeV2 2>$null | Out-Null
$status = (Get-Content $h3 | Select-Object -First 1).Split(' ')[1]
if ($status -ne '412') { throw "Expected 412, got $status" }
Write-Host '412 OK'

Write-Host "`n== Catalog 304 =="
$hc1 = New-TemporaryFile
$c1 = New-TemporaryFile
& curl.exe -sS -D $hc1 -o $c1 @auth $catV2 | Out-Null
$ctag = (Get-Header $hc1 'ETag').Trim() -replace '^W/','' -replace '"',''
$hc2 = New-TemporaryFile
& curl.exe -sS -o $null -D $hc2 @auth -H "If-None-Match: `"$ctag`"" $catV2 | Out-Null
$status = (Get-Content $hc2 | Select-Object -First 1).Split(' ')[1]
if ($status -ne '304') { throw "Expected 304, got $status" }
Write-Host 'Catalog 304 OK'

Write-Host "`nSmoke checks passed."

