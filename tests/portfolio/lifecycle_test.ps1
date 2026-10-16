$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$helper=Join-Path $root 'scripts/portfolio-process-tools.ps1'
if(-not(Test-Path $helper)){throw 'FAIL: lifecycle/path helpers absent'}
. $helper
if(-not(Test-PortfolioDriveMapping 'P:\: => E:\copy-a' 'P:' 'E:\copy-a')){throw 'FAIL: exact drive target rejected'}
if(Test-PortfolioDriveMapping 'P:\: => E:\copy-a' 'P:' 'E:\copy-b'){throw 'FAIL: another same-content copy accepted'}
Write-Output 'PASS: drive ownership compares actual paths'
if((Get-PortfolioDriveTarget 'P:') -ine $root.TrimEnd('\')){throw 'FAIL: Unicode drive target mismatch'}
Write-Output 'PASS: actual Unicode drive target read through Windows API'
$parent=Start-Process powershell.exe -ArgumentList '-NoProfile -NonInteractive -WindowStyle Hidden -Command Start-Sleep -Seconds 50' -WindowStyle Hidden -PassThru
$child=Start-Process powershell.exe -ArgumentList '-NoProfile -NonInteractive -WindowStyle Hidden -Command Start-Sleep -Seconds 50' -WindowStyle Hidden -PassThru
try {
 $entries=@(@{pid=$parent.Id;started=$parent.StartTime.ToUniversalTime().ToString('o');role='test-parent'},@{pid=$child.Id;started=$child.StartTime.ToUniversalTime().ToString('o');role='test-child'})
 Stop-PortfolioOwnedProcesses $entries
 if((Get-Process -Id $parent.Id,$child.Id -ErrorAction SilentlyContinue)){throw 'FAIL: owned process survives stop'}
 Write-Output 'PASS: owned parent and child processes stopped'
 $self=@{pid=$PID;started='1900-01-01';role='stale'}
 Stop-PortfolioOwnedProcesses @($self)
 Write-Output 'PASS: stale PID ownership refuses termination'
} finally {foreach($p in @($parent,$child)){if(-not $p.HasExited){$p.Kill()}}}
$fake=Join-Path $root 'runtime/failing-php.cmd'
$realPhp=(Get-Command php).Source
[IO.File]::WriteAllText($fake,('@echo off' + "`r`n" + 'if "%1"=="scripts/portfolio-health.php" ("'+$realPhp+'" %* & exit /b 0)' + "`r`nexit /b 1`r`n"))
$ErrorActionPreference='Continue'
$output=& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'scripts/run-portfolio.ps1') -PhpBinary $fake 2>&1
$exit=$LASTEXITCODE
$ErrorActionPreference='Stop'
if($exit -eq 0){throw 'FAIL: failed startup reported success'}
if(Test-Path (Join-Path $root 'runtime/portfolio-processes.json')){throw 'FAIL: failed startup left active process record'}
Write-Output 'PASS: failed startup returns error and rolls back owned processes'
