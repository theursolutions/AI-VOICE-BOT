<#
  Voice CRM Agent — end-to-end HTTP smoke test.

  Exercises: Python /healthz, Laravel /api/v1/sessions (start),
  /turn (text), /sessions/{id} (read-back), /end.

  Prereqs:
    - Laravel running (php artisan serve)
    - Python running (uvicorn app.api.http:app --port 8000)
    - Test project row exists in `projects` table with valid
      project_api_key + db_* credentials
    - Tenant DB created and migrated:
        php artisan migrate
        php artisan tenant:migrate <project_id>

  Usage:
    .\scripts\smoke-test.ps1 -ApiKey "abc123..." `
                             -LaravelUrl "http://127.0.0.1:8001" `
                             -PythonUrl  "http://127.0.0.1:8000"
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ApiKey,

    [string]$LaravelUrl = "http://127.0.0.1:8001",
    [string]$PythonUrl  = "http://127.0.0.1:8000",
    [string]$TestText   = "Hi, my name is Ali Khan and I'd like a demo. My email is ali@example.com.",
    [string]$RespondWith = "text"
)

$ErrorActionPreference = "Stop"

function Step($n, $msg) { Write-Host "`n[$n] $msg" -ForegroundColor Cyan }
function Ok($msg)       { Write-Host "    ok  — $msg" -ForegroundColor Green }
function Fail($msg)     { Write-Host "    FAIL — $msg" -ForegroundColor Red; exit 1 }
function Detail($obj)   { Write-Host ($obj | ConvertTo-Json -Depth 6 -Compress) -ForegroundColor DarkGray }

# ----- 1. Python health -------------------------------------------------------
Step 1 "Python /healthz"
try {
    $health = Invoke-RestMethod -Method Get -Uri "$PythonUrl/healthz" -TimeoutSec 10
    Ok "python alive"
    Detail $health
} catch {
    Fail "python unreachable at $PythonUrl — $($_.Exception.Message)"
}

# ----- 2. Start session -------------------------------------------------------
Step 2 "POST $LaravelUrl/api/v1/sessions"
$startBody = @{
    channel        = "web"
    customer_name  = "Smoke Test"
    customer_phone = "+60000000000"
    customer_email = "smoke@test.local"
    metadata       = @{ source = "smoke-test.ps1" }
} | ConvertTo-Json -Depth 4

try {
    $session = Invoke-RestMethod -Method Post `
        -Uri "$LaravelUrl/api/v1/sessions" `
        -Headers @{ "X-CLIENT-API-KEY" = $ApiKey; "Accept" = "application/json" } `
        -ContentType "application/json" `
        -Body $startBody
} catch {
    Fail "session start failed — $($_.Exception.Message)"
}

if (-not $session.session_id) { Fail "no session_id returned"; }
Ok "session_id = $($session.session_id), token len = $($session.token.Length)"
Detail $session

$sessionId = $session.session_id

# ----- 3. Send a text turn ----------------------------------------------------
Step 3 "POST /api/v1/sessions/$sessionId/turn"
$turnBody = @{
    text         = $TestText
    respond_with = $RespondWith
    stream       = $false
} | ConvertTo-Json -Depth 4

$start = Get-Date
try {
    $turn = Invoke-RestMethod -Method Post `
        -Uri "$LaravelUrl/api/v1/sessions/$sessionId/turn" `
        -Headers @{ "X-CLIENT-API-KEY" = $ApiKey; "Accept" = "application/json" } `
        -ContentType "application/json" `
        -Body $turnBody `
        -TimeoutSec 60
} catch {
    Fail "turn failed — $($_.Exception.Message)"
}
$elapsed = ((Get-Date) - $start).TotalMilliseconds

if (-not $turn.assistant.content) { Fail "assistant returned no text"; }
Ok ("assistant replied in {0:N0}ms — \"{1}\"" -f $elapsed, ($turn.assistant.content.Substring(0, [Math]::Min(80, $turn.assistant.content.Length))))
Detail @{
    user_message_id      = $turn.user_message.id
    assistant_message_id = $turn.assistant.id
    model_used           = $turn.assistant.model_used
    audio_url            = $turn.assistant.audio_url
    latency_ms_recorded  = $turn.assistant.latency_ms
}

# ----- 4. Read session back (verifies tenant DB persistence) ------------------
Step 4 "GET /api/v1/sessions/$sessionId"
try {
    $readBack = Invoke-RestMethod -Method Get `
        -Uri "$LaravelUrl/api/v1/sessions/$sessionId" `
        -Headers @{ "X-CLIENT-API-KEY" = $ApiKey; "Accept" = "application/json" }
} catch {
    Fail "session read failed — $($_.Exception.Message)"
}

$msgCount = ($readBack.messages | Measure-Object).Count
if ($msgCount -lt 2) { Fail "expected >=2 messages persisted, got $msgCount"; }
Ok "$msgCount messages persisted in tenant DB"
$readBack.messages | ForEach-Object {
    Write-Host ("      [{0}] {1}: {2}" -f $_.id, $_.role, ($_.content.Substring(0, [Math]::Min(60, $_.content.Length)))) -ForegroundColor DarkGray
}

# ----- 5. End session ---------------------------------------------------------
Step 5 "POST /api/v1/sessions/$sessionId/end"
try {
    $end = Invoke-RestMethod -Method Post `
        -Uri "$LaravelUrl/api/v1/sessions/$sessionId/end" `
        -Headers @{ "X-CLIENT-API-KEY" = $ApiKey; "Accept" = "application/json" }
} catch {
    Fail "session end failed — $($_.Exception.Message)"
}
if ($end.status -ne "ended") { Fail "expected status=ended, got $($end.status)"; }
Ok "session status = ended"

# ----- Summary ---------------------------------------------------------------
Write-Host "`n========================================" -ForegroundColor Green
Write-Host " All checks passed." -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host " Lead extraction runs in the queue. To verify:"
Write-Host "   php artisan queue:work --once    (in ai-voice-bot-admin)"
Write-Host "   then re-run this script's step 4 — leads row should populate."
