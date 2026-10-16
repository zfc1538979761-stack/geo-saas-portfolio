param([string]$PhpBinary=(Get-Command php -ErrorAction Stop).Source,[int]$PollSeconds=2,[switch]$Once)
& (Join-Path $PSScriptRoot 'portfolio-worker-supervisor.ps1') -Kind ai -PhpBinary $PhpBinary -PollSeconds $PollSeconds -Once:$Once
