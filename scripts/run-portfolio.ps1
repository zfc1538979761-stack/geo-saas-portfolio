param([string]$PhpBinary=(Get-Command php -ErrorAction Stop).Source,[int]$Port=8877,[switch]$DryRun)
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
. (Join-Path $PSScriptRoot 'portfolio-process-tools.ps1')
if($Port -ne 8877){throw 'Demo uses port 8877.'}
if(-not(Test-Path (Join-Path $root '.env'))){throw 'Run setup first.'}
$record=Join-Path $root 'runtime/portfolio-processes.json'
if(Test-Path $record){throw 'Owned process record exists; stop or inspect first.'}
if($DryRun){Write-Output 'DRY RUN: Web + AI/publish supervisors; no process started.';exit 0}
if([Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners()|Where-Object {$_.Port -eq $Port}){throw 'Web port occupied; not adopted.'}
Push-Location $root
try {$health=@(& $PhpBinary scripts/portfolio-health.php 2>&1);if($LASTEXITCODE -ne 0 -or ($health -join "`n") -notmatch 'PASS: isolated'){throw 'Database/Redis preflight failed.'}}finally{Pop-Location}
$entries=@()
try {
 $web=Start-Process $PhpBinary -ArgumentList @('-S',"127.0.0.1:$Port",'-t','public','public/router.php') -WorkingDirectory $root -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root 'runtime/web.out.log') -RedirectStandardError (Join-Path $root 'runtime/web.err.log')
 $entries+=@{pid=$web.Id;started=$web.StartTime.ToUniversalTime().ToString('o');role='web'}
 foreach($kind in @('ai','publish')){
  $runner=Join-Path $PSScriptRoot "run-geo-$kind-worker.ps1"
  $arguments='-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -PhpBinary "{1}"' -f $runner,$PhpBinary
  $p=Start-Process powershell.exe -ArgumentList $arguments -WorkingDirectory $root -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root "runtime/$kind.out.log") -RedirectStandardError (Join-Path $root "runtime/$kind.err.log")
  $entries+=@{pid=$p.Id;started=$p.StartTime.ToUniversalTime().ToString('o');role=$kind}
 }
 $entries|ConvertTo-Json|Set-Content -LiteralPath $record -Encoding UTF8
 $deadline=[DateTime]::UtcNow.AddSeconds(20)
 do {
  foreach($entry in $entries){if(-not(Get-Process -Id $entry.pid -ErrorAction SilentlyContinue)){throw 'A launched service exited before readiness.'}}
  $ready=$true
  foreach($kind in @('ai','publish')){
   $path=Join-Path $root "runtime/$kind-worker-heartbeat.json"
   try {$h=Get-Content -LiteralPath $path -Encoding UTF8 -Raw -ErrorAction Stop|ConvertFrom-Json;$owner=@($entries|Where-Object {$_.role -eq $kind})[0]
    if($h.state -ne 'ready' -or $h.supervisor_pid -ne $owner.pid -or $h.updated_at_epoch -lt ([DateTimeOffset][datetime]$owner.started).ToUnixTimeSeconds()){$ready=$false}
   }catch{$ready=$false;$lastReason=$_.Exception.Message}
  }
  try{$response=Invoke-WebRequest "http://127.0.0.1:$Port/index/enterprise/index" -UseBasicParsing -TimeoutSec 2;if($response.StatusCode -ne 200 -or $response.Content -notmatch 'Portfolio Demo'){$ready=$false}}catch{$ready=$false}
  if($ready){break};Start-Sleep -Milliseconds 250
 }while([DateTime]::UtcNow -lt $deadline)
 if(-not $ready){throw ('Services failed readiness deadline: '+$lastReason)}
 Write-Output 'PASS: Web and both local workers ready. http://127.0.0.1:8877/index/enterprise/index'
}catch{
 Stop-PortfolioOwnedProcesses $entries
 Stop-PortfolioOwnedProcesses (Get-PortfolioOwnedChildren $root $entries)
 if(Test-Path $record){Move-Item -LiteralPath $record -Destination ($record+'.failed-'+[guid]::NewGuid().ToString('N'))}
 throw
}
