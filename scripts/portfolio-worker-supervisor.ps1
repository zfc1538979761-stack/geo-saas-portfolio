param([ValidateSet('ai','publish')][string]$Kind,[string]$PhpBinary,[int]$PollSeconds=2,[switch]$Once)
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
. (Join-Path $PSScriptRoot 'portfolio-process-tools.ps1')
$self=(Get-Process -Id $PID).StartTime.ToUniversalTime().ToString('o')
$heartbeat=Join-Path $root "runtime/$Kind-worker-heartbeat.json"
$lockPath=[IO.Path]::ChangeExtension($heartbeat,'lock')
$lock=[IO.File]::Open($lockPath,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
$childRecord=Join-Path $root "runtime/$Kind-child.json"
function Invoke-TrackedPhp([string]$Arguments) {
 $p=Start-Process -FilePath $PhpBinary -ArgumentList $Arguments -WorkingDirectory $root -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root "runtime/$Kind-child.out.log") -RedirectStandardError (Join-Path $root "runtime/$Kind-child.err.log")
 @{pid=$p.Id;started=$p.StartTime.ToUniversalTime().ToString('o');role="$Kind-child";supervisor_pid=$PID;supervisor_started=$self}|ConvertTo-Json|Set-Content -LiteralPath $childRecord -Encoding UTF8
 $handle=$p.Handle
 $handle=$p.Handle
 $p.WaitForExit()
 $p.Refresh()
 $p.Refresh()
 if($p.ExitCode -ne 0){throw 'Worker command failed; inspect local runtime logs.'}
}
try {
 do {
  $queue=if($Kind -eq 'ai'){'geo_ai_generation'}else{'geo_publish_submit'}
  Invoke-TrackedPhp "think queue:work --queue=$queue --tries=3 --sleep=1"
  if($Kind -eq 'publish'){Invoke-TrackedPhp 'scripts/sync-portfolio-orders.php'}
  $payload=@{queue=$queue;state='ready';supervisor_pid=$PID;lock_path=$lockPath;last_exit_code=0;updated_at_epoch=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()}|ConvertTo-Json -Compress
  [IO.File]::WriteAllText($heartbeat,$payload,[Text.UTF8Encoding]::new($false))
  if($Once){break}
  Start-Sleep -Seconds $PollSeconds
 } while($true)
} finally {
 $children=Get-PortfolioOwnedChildren $root @(@{pid=$PID;started=$self})
 Stop-PortfolioOwnedProcesses $children
 $lock.Dispose()
}
