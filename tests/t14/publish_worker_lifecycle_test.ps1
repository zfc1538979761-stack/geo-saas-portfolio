$ErrorActionPreference = 'Stop'

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$runner = Join-Path $repoRoot 'scripts\run-geo-publish-worker.ps1'
$installer = Join-Path $repoRoot 'scripts\install-geo-publish-worker-task.ps1'
$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('geo-saas-publish-worker-test-' + [Guid]::NewGuid().ToString('N'))
$fakePhp = Join-Path $tempRoot 'fake-php.ps1'
$capture = Join-Path $tempRoot 'args.txt'
$heartbeat = Join-Path $tempRoot 'publish-worker-heartbeat.json'

try {
    [System.IO.Directory]::CreateDirectory($tempRoot) | Out-Null
    @'
param([Parameter(ValueFromRemainingArguments = $true)][string[]]$RemainingArgs)
[System.IO.File]::WriteAllLines($env:GEO_PUBLISH_WORKER_TEST_CAPTURE, $RemainingArgs)
exit 0
'@ | Set-Content -LiteralPath $fakePhp -Encoding UTF8

    $env:GEO_PUBLISH_WORKER_TEST_CAPTURE = $capture
    & $runner -Once -PhpBinary $fakePhp -PollSeconds 0 -HeartbeatPath $heartbeat
    if ($LASTEXITCODE -ne 0) { throw "Publish Worker runner exited with $LASTEXITCODE" }
    if (-not (Test-Path -LiteralPath $capture)) { throw 'Publish Worker runner did not launch PHP.' }

    $actual = [System.IO.File]::ReadAllLines($capture)
    $expected = @('think', 'queue:work', '--queue=geo_publish_submit', '--tries=3', '--sleep=1')
    if ([string]::Join('|', $actual) -ne [string]::Join('|', $expected)) {
        throw ('Publish Worker arguments were unsafe or unexpected: ' + [string]::Join(' ', $actual))
    }
    if ($actual -contains '--daemon') { throw 'Publish Worker must use fresh queue:work processes.' }

    if (-not (Test-Path -LiteralPath $heartbeat -PathType Leaf)) { throw 'Publish Worker heartbeat was not written.' }
    $heartbeatPayload = Get-Content -LiteralPath $heartbeat -Raw | ConvertFrom-Json
    if ($heartbeatPayload.queue -ne 'geo_publish_submit') { throw 'Publish Worker heartbeat identifies the wrong queue.' }
    if ([int]$heartbeatPayload.supervisor_pid -le 0) { throw 'Publish Worker heartbeat must identify its supervisor.' }
    if ([string]::IsNullOrWhiteSpace([string]$heartbeatPayload.updated_at)) { throw 'Publish Worker heartbeat must include an update time.' }

    Write-Output 'PASS: publish Worker consumes only geo_publish_submit with a fresh process and heartbeat'

    $definition = (& $installer -DefinitionOnly | ConvertFrom-Json)
    if ($definition.task_name -ne 'GEO-SaaS Publish Queue Worker') { throw 'Publish Worker task name is unstable.' }
    if ($definition.logon_trigger -ne $true) { throw 'Publish Worker must start at user logon.' }
    if ([int] $definition.recovery_interval_minutes -ne 1) { throw 'Publish Worker must recover every minute.' }
    if ($definition.multiple_instances -ne 'IgnoreNew') { throw 'Publish Worker must prevent duplicate supervisors.' }
    Write-Output 'PASS: publish Worker task defines startup and recovery without being installed by this test'
} finally {
    Remove-Item Env:GEO_PUBLISH_WORKER_TEST_CAPTURE -ErrorAction SilentlyContinue
    if (Test-Path -LiteralPath $tempRoot) {
        [System.IO.Directory]::Delete($tempRoot, $true)
    }
}
