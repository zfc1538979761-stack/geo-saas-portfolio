$ErrorActionPreference = 'Stop'

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$runner = Join-Path $repoRoot 'scripts\run-geo-ai-worker.ps1'
$installer = Join-Path $repoRoot 'scripts\install-geo-ai-worker-task.ps1'
$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('geo-saas-worker-test-' + [Guid]::NewGuid().ToString('N'))
$fakePhp = Join-Path $tempRoot 'fake-php.ps1'
$capture = Join-Path $tempRoot 'args.txt'

try {
    [System.IO.Directory]::CreateDirectory($tempRoot) | Out-Null
    @'
param([Parameter(ValueFromRemainingArguments = $true)][string[]]$RemainingArgs)
[System.IO.File]::WriteAllLines($env:GEO_WORKER_TEST_CAPTURE, $RemainingArgs)
exit 0
'@ | Set-Content -LiteralPath $fakePhp -Encoding UTF8

    $env:GEO_WORKER_TEST_CAPTURE = $capture
    & $runner -Once -PhpBinary $fakePhp -PollSeconds 0
    if ($LASTEXITCODE -ne 0) { throw "Worker runner exited with $LASTEXITCODE" }
    if (-not (Test-Path -LiteralPath $capture)) { throw 'Worker runner did not launch the configured PHP process.' }

    $actual = [System.IO.File]::ReadAllLines($capture)
    $expected = @('think', 'queue:work', '--queue=geo_ai_generation', '--tries=3', '--sleep=1')
    if ([string]::Join('|', $actual) -ne [string]::Join('|', $expected)) {
        throw ('Worker runner arguments were unsafe or unexpected: ' + [string]::Join(' ', $actual))
    }
    if ($actual -contains '--daemon') { throw 'Worker runner must not reuse a daemon process across database restarts.' }

    Write-Output 'PASS: GEO AI worker uses a fresh queue:work process without --daemon'

    $definition = (& $installer -DefinitionOnly | ConvertFrom-Json)
    if ($definition.task_name -ne 'GEO-SaaS AI Queue Worker') { throw 'Worker task name is unstable.' }
    if ($definition.logon_trigger -ne $true) { throw 'Worker task must start at user logon.' }
    if ([int] $definition.recovery_interval_minutes -ne 1) { throw 'Worker task must have a one-minute recurring recovery trigger.' }
    if ($definition.multiple_instances -ne 'IgnoreNew') { throw 'Worker task must prevent duplicate supervisors.' }
    Write-Output 'PASS: GEO AI worker task defines logon startup and one-minute recovery'
} finally {
    Remove-Item Env:GEO_WORKER_TEST_CAPTURE -ErrorAction SilentlyContinue
    if (Test-Path -LiteralPath $tempRoot) {
        [System.IO.Directory]::Delete($tempRoot, $true)
    }
}
