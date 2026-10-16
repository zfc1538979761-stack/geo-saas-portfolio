$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
. (Join-Path $root 'scripts/portfolio-process-tools.ps1')
$entries=Get-Content -LiteralPath (Join-Path $root 'runtime/portfolio-processes.json') -Raw -Encoding UTF8|ConvertFrom-Json
$owned=@($entries)+@(Get-PortfolioOwnedChildren $root $entries)
& (Join-Path $root 'scripts/stop-portfolio.ps1')
foreach($entry in $owned){$p=Get-Process -Id $entry.pid -ErrorAction SilentlyContinue;if($p -and $p.StartTime.ToUniversalTime().ToString('o') -eq $entry.started){throw 'FAIL: actual launched process survived stop'}}
Write-Output 'PASS: actual Web, supervisors and PHP children stop without leaving owned processes'
