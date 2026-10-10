param([string]$BaseUrl = 'http://127.0.0.1:8080', [int]$DebugPort = 9223)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$output = Join-Path $PSScriptRoot 'output'
New-Item -ItemType Directory -Force -Path $output | Out-Null
$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
if (-not (Test-Path -LiteralPath $chrome)) { throw 'Chrome is required for this optional browser test.' }
$profile = Join-Path $output 'browser-profile'
$browserProcess = Start-Process -FilePath $chrome -ArgumentList '--headless','--no-sandbox','--disable-gpu','--disable-software-rasterizer','--no-first-run',"--remote-debugging-port=$DebugPort",'--remote-debugging-address=127.0.0.1',"--user-data-dir=`"$profile`"",'about:blank' -WindowStyle Hidden -PassThru
$socket = $null
$results = [Collections.Generic.List[object]]::new()
$script:sequence = 0
function Cdp([string]$Method, $Parameters = @{}) {
    $script:sequence++
    $callId = $script:sequence
    $payload = @{ id=$callId; method=$Method; params=$Parameters } | ConvertTo-Json -Depth 30 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($payload)
    $segment = [ArraySegment[byte]]::new($bytes)
    $cancel = [Threading.CancellationTokenSource]::new(15000)
    try {
        $socket.SendAsync($segment,[Net.WebSockets.WebSocketMessageType]::Text,$true,$cancel.Token).GetAwaiter().GetResult() | Out-Null
        while ($true) {
            $stream = [IO.MemoryStream]::new()
            try {
                do {
                    $buffer = New-Object byte[] 65536
                    $received = $socket.ReceiveAsync([ArraySegment[byte]]::new($buffer),$cancel.Token).GetAwaiter().GetResult()
                    $stream.Write($buffer,0,$received.Count)
                } until ($received.EndOfMessage)
                $message = [Text.Encoding]::UTF8.GetString($stream.ToArray()) | ConvertFrom-Json
            } finally { $stream.Dispose() }
            if ($message.id -eq $callId) {
                if ($message.error) { throw ($message.error | ConvertTo-Json) }
                return $message.result
            }
        }
    } finally { $cancel.Dispose() }
}
function Evaluate([string]$Expression) {
    $result = Cdp 'Runtime.evaluate' @{expression=$Expression;returnByValue=$true;awaitPromise=$true}
    if ($result.exceptionDetails) { throw ($result.exceptionDetails | ConvertTo-Json -Depth 10) }
    return $result.result.value
}
function Navigate([string]$Page) {
    Cdp 'Page.navigate' @{url="$BaseUrl/index.php?page=$Page"} | Out-Null
    for ($attempt=0; $attempt -lt 60; $attempt++) {
        Start-Sleep -Milliseconds 100
        if ((Evaluate 'document.readyState') -eq 'complete') { break }
    }
}
function Check([bool]$Condition, [string]$Label) {
    $results.Add(@{check=$Label;result=$(if($Condition){'PASS'}else{'FAIL'})})
    Write-Output "$(if($Condition){'PASS'}else{'FAIL'}) $Label"
    if (-not $Condition) { throw "Browser check failed: $Label" }
}
function Screenshot([string]$Name, [bool]$Full = $false) {
    $parameters = @{format='png';captureBeyondViewport=$Full}
    if($Full) {
        $metrics = Cdp 'Page.getLayoutMetrics'
        $parameters.clip = @{x=0;y=0;width=$metrics.cssContentSize.width;height=$metrics.cssContentSize.height;scale=1}
    }
    $shot = Cdp 'Page.captureScreenshot' $parameters
    [IO.File]::WriteAllBytes((Join-Path $output "$Name.png"),[Convert]::FromBase64String($shot.data))
}
try {
    $tabs=$null
    for($attempt=0;$attempt -lt 40;$attempt++) {
        try { $tabs=Invoke-RestMethod "http://127.0.0.1:$DebugPort/json"; if($tabs){break} } catch { Start-Sleep -Milliseconds 150 }
    }
    $tab = $tabs | Where-Object type -eq 'page' | Select-Object -First 1
    $socket = [Net.WebSockets.ClientWebSocket]::new()
    $socket.ConnectAsync([Uri]$tab.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Cdp 'Page.enable' | Out-Null
    Cdp 'Runtime.enable' | Out-Null
    Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
    foreach($page in @('home','clubs','events','login','register','community-login','community-register','contact','about','privacy','club&id=1','event&id=1')) {
        Navigate $page
        Check ((Evaluate 'document.querySelector("h1") !== null') -eq $true) "Desktop content renders: $page"
        Check ((Evaluate 'document.documentElement.scrollWidth <= window.innerWidth + 1') -eq $true) "Desktop has no horizontal overflow: $page"
        Check ((Evaluate 'Array.from(document.querySelectorAll("a")).every(a => a.getAttribute("href") && a.getAttribute("href") !== "#")') -eq $true) "No placeholder links: $page"
        if($page -eq 'home'){Screenshot 'home-desktop' $true}
        if($page -eq 'login'){Screenshot 'login-desktop';Evaluate 'document.querySelector("[data-password]").click()' | Out-Null;Check ((Evaluate 'document.querySelector("#login-password").type === "text"') -eq $true) 'Show-password button works';Evaluate 'document.querySelector("[data-password]").click()' | Out-Null}
        if($page -eq 'register'){Screenshot 'register-desktop';Check ((Evaluate 'document.querySelector("input[name=student_number]").required') -eq $true) 'Personal registration requires a student ID'}
        if($page -eq 'community-register'){
            Screenshot 'community-register-desktop' $true
            Check ((Evaluate 'document.querySelectorAll("input[name^=leader_email_][required]").length === 4') -eq $true) 'Community form requires four leader emails'
            Check ((Evaluate 'document.querySelectorAll("input[name=student_number][required]").length === 1') -eq $true) 'Community verifies only the registering leader ID'
        }
    }
    Cdp 'Emulation.setDeviceMetricsOverride' @{width=390;height=844;deviceScaleFactor=1;mobile=$true} | Out-Null
    foreach($page in @('home','clubs','events','login','register','community-login','community-register','contact','about','club&id=1','event&id=1')) {
        Navigate $page
        Check ((Evaluate 'document.documentElement.scrollWidth <= window.innerWidth + 1') -eq $true) "Mobile has no horizontal overflow: $page"
        if($page -eq 'home'){
            Screenshot 'home-mobile' $true
            Evaluate 'document.querySelector(".menu-toggle").click()' | Out-Null
            Check ((Evaluate 'document.querySelector(".menu-toggle").getAttribute("aria-expanded") === "true" && getComputedStyle(document.querySelector("#navigation")).display !== "none"') -eq $true) 'Mobile menu opens'
            Evaluate 'document.querySelector(".menu-toggle").click()' | Out-Null
            Check ((Evaluate 'document.querySelector(".menu-toggle").getAttribute("aria-expanded") === "false"') -eq $true) 'Mobile menu closes'
        }
        if($page -eq 'register'){Screenshot 'register-mobile'}
        if($page -eq 'community-register'){Screenshot 'community-register-mobile' $true}
    }
    # Use locally generated fictional demo credentials without writing them to the report.
    $credentials = Get-Content -LiteralPath (Join-Path $root 'var/demo-accounts.txt') -Raw
    $initialPassword = [regex]::Match($credentials,'Initial password for these generated accounts: ([^\r\n]+)').Groups[1].Value
    Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
    foreach($account in @('student@campusconnect.test','alex@campusconnect.test','admin@campusconnect.test')) {
        Navigate 'login'
        $emailLiteral = ConvertTo-Json $account -Compress
        $passwordLiteral = ConvertTo-Json $initialPassword -Compress
        Evaluate "document.querySelector('[name=email]').value=$emailLiteral; document.querySelector('[name=password]').value=$passwordLiteral; document.querySelector('.auth-form form').requestSubmit();" | Out-Null
        Start-Sleep -Milliseconds 450
        Check ((Evaluate 'location.search.includes("page=dashboard") && document.querySelector(".tabs") !== null') -eq $true) "Browser login and dashboard: $account"
        Check ((Evaluate 'document.documentElement.scrollWidth <= innerWidth + 1') -eq $true) "Dashboard fits desktop: $account"
        Screenshot ($account.Split('@')[0] + '-dashboard') $true
        Cdp 'Emulation.setDeviceMetricsOverride' @{width=390;height=844;deviceScaleFactor=1;mobile=$true} | Out-Null
        Check ((Evaluate 'document.documentElement.scrollWidth <= innerWidth + 1') -eq $true) "Dashboard fits mobile: $account"
        Navigate 'profile'
        Check ((Evaluate 'document.querySelectorAll("form").length >= 3 && document.documentElement.scrollWidth <= innerWidth + 1') -eq $true) "Profile/security forms fit mobile: $account"
        if($account -eq 'admin@campusconnect.test'){
            Navigate 'admin'
            Check ((Evaluate 'document.body.textContent.includes("Enquiry inbox")') -eq $true) 'Admin inbox renders in browser'
            Check ((Evaluate 'document.querySelector("input[type=file][name=student_file]") !== null && document.querySelector("textarea[name=student_ids]") !== null') -eq $true) 'Admin has Excel upload and manual student ID controls'
            Check ((Evaluate 'document.body.textContent.includes("Community account requests")') -eq $true) 'Admin community approval section renders'
            Check ((Evaluate 'document.documentElement.scrollWidth <= innerWidth + 1') -eq $true) 'Admin registry fits mobile'
            Screenshot 'admin-registry-mobile' $true
            Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
            Check ((Evaluate 'document.documentElement.scrollWidth <= innerWidth + 1') -eq $true) 'Admin registry fits desktop'
            Screenshot 'admin-registry-desktop' $true
        }
        Evaluate 'document.querySelector(".header-actions form").requestSubmit()' | Out-Null
        Start-Sleep -Milliseconds 250
        Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
    }
} finally {
    $results | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath (Join-Path $output 'browser-results.json') -Encoding UTF8
    if($socket){try{Cdp 'Browser.close' | Out-Null}catch{};$socket.Dispose()}
}
Write-Output "$($results.Count) browser checks completed."
