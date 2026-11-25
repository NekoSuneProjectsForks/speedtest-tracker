#Requires -Version 7.0
[CmdletBinding()]
param(
    [Parameter(Mandatory)] [uri] $Server,
    [Parameter(Mandatory)] [string] $InterfaceName,
    [Parameter(Mandatory)] [string] $TokenFile,
    [ValidateRange(10, 300)] [int] $IntervalSeconds = 60
)

if (!$Server.IsAbsoluteUri -or $Server.UserInfo -or $Server.Query -or $Server.Fragment -or
    ($Server.Scheme -ne 'https' -and !($Server.Scheme -eq 'http' -and $Server.IsLoopback))) {
    throw 'Supply an HTTPS URL or an HTTP loopback URL'
}

$endpoint = $Server.AbsoluteUri.TrimEnd('/') + '/api/network-monitor/v1/agent/samples'
$token = (Get-Content -LiteralPath $TokenFile -Raw -ErrorAction Stop).Trim()
$headers = @{ Accept = 'application/json'; Authorization = "Bearer $token" }
$session = [guid]::NewGuid()
$sequence = 0
$previous = $null
$escapedName = [WildcardPattern]::Escape($InterfaceName)

while ($true) {
    $timer = [Diagnostics.Stopwatch]::StartNew()
    try {
        $adapter = @(Get-NetAdapter -Name $escapedName -ErrorAction Stop | Where-Object Name -eq $InterfaceName)
        if ($adapter.Count -ne 1) {
            throw 'The specified interface name must match one adapter'
        }

        $identity = $adapter[0].InterfaceGuid
        if ($identity -ne $previous) {
            $session = [guid]::NewGuid()
            $sequence = 0
            $previous = $identity
        }

        $stats = @(Get-NetAdapterStatistics -Name $escapedName -ErrorAction Stop)
        if ($stats.Count -ne 1 -or $null -eq $stats[0].ReceivedBytes -or $null -eq $stats[0].SentBytes) {
            throw 'The selected adapter did not return byte counters'
        }
        $sequence++
        $body = @{
            session = $session.ToString()
            sequence = $sequence
            rx_bytes = [uint64] $stats[0].ReceivedBytes
            tx_bytes = [uint64] $stats[0].SentBytes
        } | ConvertTo-Json -Compress

        Invoke-RestMethod -Uri $endpoint -Method Post -Headers $headers -Body $body -ContentType 'application/json' `
            -MaximumRedirection 0 -TimeoutSec 8 -AllowUnencryptedAuthentication:$($Server.IsLoopback) -NoProxy -ErrorAction Stop |
            Out-Null
    } catch {
        $failure = $_.Exception
        if ($failure.Response -and $failure.Response.StatusCode) {
            Write-Warning ("Agent sample failed (HTTP {0})" -f [int] $failure.Response.StatusCode)
        } else {
            Write-Warning ("Agent sample failed ({0})" -f $failure.GetType().Name)
        }
    }
    Start-Sleep -Seconds ([Math]::Max(1, $IntervalSeconds - $timer.Elapsed.TotalSeconds))
}
