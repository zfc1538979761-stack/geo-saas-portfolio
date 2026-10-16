$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
. (Join-Path $PSScriptRoot 'portfolio-process-tools.ps1')
$record=Join-Path $root 'runtime/portfolio-processes.json'
if(-not(Test-Path $record)){Write-Output 'No launcher-owned process record.';exit 0}
$entries=Get-Content -LiteralPath $record -Encoding UTF8 -Raw|ConvertFrom-Json
Stop-PortfolioOwnedProcesses $entries
# Supervisors are stopped first, so no new child can be spawned after this read.
Stop-PortfolioOwnedProcesses (Get-PortfolioOwnedChildren $root $entries)
Move-Item -LiteralPath $record -Destination ($record+'.stopped-'+[guid]::NewGuid().ToString('N'))
Write-Output 'PASS: owned Web, supervisors and recorded PHP children stopped; DB/Redis unchanged.'
