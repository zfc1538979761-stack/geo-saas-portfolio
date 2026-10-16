[CmdletBinding()]
param(
    [string]$PhpBinary = $env:GEO_PHP_BINARY
)

$ErrorActionPreference = 'Stop'
$repoRoot = (& git rev-parse --show-toplevel).Trim()
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($repoRoot)) {
    throw 'Verification must run inside the Git repository.'
}
Set-Location -LiteralPath $repoRoot

if ([string]::IsNullOrWhiteSpace($PhpBinary)) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if (-not $phpCommand) {
        throw 'PHP binary not found. Pass -PhpBinary or set GEO_PHP_BINARY.'
    }
    $PhpBinary = $phpCommand.Source
}
if (-not (Test-Path -LiteralPath $PhpBinary -PathType Leaf)) {
    throw 'Configured PHP binary does not exist.'
}

function Invoke-Checked {
    param(
        [string]$Name,
        [scriptblock]$Action
    )

    Write-Output "RUN: $Name"
    # Merge native stderr into the captured stream so an uncaught PHP error
    # cannot be hidden behind a later successful verification summary.
    $capturedOutput = @(& $Action 2>&1)
    $capturedExitCode = $LASTEXITCODE
    $capturedOutput | ForEach-Object { Write-Output $_ }
    if ($capturedExitCode -ne 0) {
        throw "$Name failed with exit code $capturedExitCode."
    }
    if (($capturedOutput -join "`n") -match '(?m)^SKIP:') {
        throw "$Name was skipped; unified verification requires a real result."
    }
}

Invoke-Checked 'environment example validation' {
    & $PhpBinary scripts/check-env.php --file=.env.example --environment=development --allow-placeholders
}

if (Test-Path -LiteralPath '.env') {
    Invoke-Checked 'local environment key validation' {
        & $PhpBinary scripts/check-env.php --file=.env --environment=development
    }
}

Write-Output 'RUN: missing environment variable failure check'
$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$missingOutput = & $PhpBinary scripts/check-env.php `
    --file=tests/Fixtures/t01/missing-token.env `
    --environment=testing 2>&1
$missingExitCode = $LASTEXITCODE
$ErrorActionPreference = $previousErrorActionPreference
if ($missingExitCode -eq 0 -or ($missingOutput -join "`n") -notmatch 'app\.token_key') {
    throw 'Missing environment variable check did not fail safely.'
}
Write-Output 'PASS: missing required key fails with key name only.'

Invoke-Checked 'phase 0 security test' {
    & $PhpBinary tests/phase0_security_test.php
}
Invoke-Checked 'T01 foundation tests' {
    & $PhpBinary tests/t01/foundation_test.php
}
Invoke-Checked 'T01 safe database check contract' {
    & $PhpBinary tests/t01/database_check_test.php
}
Invoke-Checked 'T01 isolated migration test or explicit skip' {
    & $PhpBinary tests/t01/migration_test.php
}
Invoke-Checked 'T01 shared database migration namespace test or explicit skip' {
    & $PhpBinary tests/t01/migration_namespace_test.php
}
Invoke-Checked 'T02 identity service tests' {
    & $PhpBinary tests/t02/identity_service_test.php
}
Invoke-Checked 'T02 legacy authentication boundary test' {
    & $PhpBinary tests/t02/legacy_auth_guard_test.php
}
Invoke-Checked 'T02 isolated full migrate/rollback/re-migrate test or explicit skip' {
    & $PhpBinary tests/t02/migration_test.php
}
Invoke-Checked 'T02 HTTP authentication test or explicit skip' {
    & $PhpBinary tests/t02/http_auth_test.php
}
Invoke-Checked 'T02 retained official schema structure check beneath later migrations or explicit skip' {
    & $PhpBinary tests/t02/official_migration_safety_test.php
}
Invoke-Checked 'T03 trusted tenant context tests' {
    & $PhpBinary tests/t03/tenant_context_test.php
}
Invoke-Checked 'T03 source and onboarding guard tests' {
    & $PhpBinary tests/t03/source_guard_test.php
}
Invoke-Checked 'T03 A/B tenant scope test or explicit skip' {
    & $PhpBinary tests/t03/tenant_scope_test.php
}
Invoke-Checked 'T03 isolated full migrate/rollback/re-migrate test or explicit skip' {
    & $PhpBinary tests/t03/migration_test.php
}
Invoke-Checked 'T03 retained official schema structure check beneath T04 or explicit skip' {
    & $PhpBinary tests/t03/official_migration_safety_test.php
}
Invoke-Checked 'T04 company validation, encryption, and context contracts' {
    & $PhpBinary tests/t04/company_contract_test.php
}
Invoke-Checked 'T04 contact encryption key preflight contracts' {
    & $PhpBinary tests/t04/environment_key_test.php
}
Invoke-Checked 'T04 source and security boundary guards' {
    & $PhpBinary tests/t04/source_guard_test.php
}
Invoke-Checked 'T04 company profile service test or explicit skip' {
    & $PhpBinary tests/t04/company_profile_service_test.php
}
Invoke-Checked 'T04 HTTP company profile test or explicit skip' {
    & $PhpBinary tests/t04/http_company_profile_test.php
}
Invoke-Checked 'T04 isolated migration test or explicit skip' {
    & $PhpBinary tests/t04/migration_test.php
}
Invoke-Checked 'T04 official migration safety test or explicit skip' {
    & $PhpBinary tests/t04/official_migration_safety_test.php
}
Invoke-Checked 'T05 centralized content contracts' {
    & $PhpBinary tests/t05/content_contract_test.php
}
Invoke-Checked 'T05 source, service boundary, and safe DOM guards' {
    & $PhpBinary tests/t05/source_guard_test.php
}
Invoke-Checked 'T05 content service and tenant isolation test' {
    & $PhpBinary tests/t05/content_service_test.php
}
Invoke-Checked 'T05 HTTP keyword and question test' {
    & $PhpBinary tests/t05/http_keyword_question_test.php
}
Invoke-Checked 'T05 true concurrency uniqueness test' {
    & $PhpBinary tests/t05/concurrency_test.php
}
Invoke-Checked 'T05 query plan and N+1 test' {
    & $PhpBinary tests/t05/performance_test.php
}
Invoke-Checked 'T05 isolated migration test' {
    & $PhpBinary tests/t05/migration_test.php
}
Invoke-Checked 'T05 official migration safety test' {
    & $PhpBinary tests/t05/official_migration_safety_test.php
}
Invoke-Checked 'T06 centralized content and file contracts' {
    & $PhpBinary tests/t06/content_contract_test.php
}
Invoke-Checked 'T06 MediaMarket OSS Mock and production fail-closed contracts' {
    & $PhpBinary tests/t06/storage_mock_test.php
}
Invoke-Checked 'T06 source, immutable-version, safe DOM, and deferred-scope guards' {
    & $PhpBinary tests/t06/source_guard_test.php
}
Invoke-Checked 'T06 knowledge, prompt, article, tenant, storage, and audit services' {
    & $PhpBinary tests/t06/content_service_test.php
}
Invoke-Checked 'T06 HTTP auth, CRUD, upload, isolation, security, and pages' {
    & $PhpBinary tests/t06/http_content_test.php
}
Invoke-Checked 'T06 true prompt and article concurrency tests' {
    & $PhpBinary tests/t06/concurrency_test.php
}
Invoke-Checked 'T06 EXPLAIN and N+1 tests' {
    & $PhpBinary tests/t06/performance_test.php
}
Invoke-Checked 'T06 isolated migration, rollback, and re-migrate test' {
    & $PhpBinary tests/t06/migration_test.php
}
Invoke-Checked 'T06 official migration safety test' {
    & $PhpBinary tests/t06/official_migration_safety_test.php
}
Invoke-Checked 'T07 AI Provider, error classification, parsing, and cost contracts' {
    & $PhpBinary tests/t07/provider_contract_test.php
}
Invoke-Checked 'T07 task and Worker class contracts' {
    & $PhpBinary tests/t07/task_contract_test.php
}
Invoke-Checked 'T07 API and generation page contracts' {
    & $PhpBinary tests/t07/api_page_contract_test.php
}
Invoke-Checked 'T07 task service, tenant, idempotency, retry, and provenance tests' {
    & $PhpBinary tests/t07/task_service_test.php
}
Invoke-Checked 'T07 migration, rollback, and re-migrate test' {
    & $PhpBinary tests/t07/migration_test.php
}
Invoke-Checked 'T07 HTTP to Redis to separate Worker async loop' {
    & $PhpBinary tests/t07/http_worker_test.php
}
Invoke-Checked 'T19 visibility Provider and ToolNotOpen contracts' {
    & $PhpBinary tests/t19/provider_contract_test.php
}
Invoke-Checked 'T19 company intelligence Provider and ToolNotOpen contracts' {
    & $PhpBinary tests/t19/company_intelligence_provider_test.php
}
Invoke-Checked 'T19 schema migration, rollback, and re-migrate' {
    & $PhpBinary tests/t19/migration_test.php
}
Invoke-Checked 'T19 company intelligence discovery, confirmation, evidence, isolation, and T19 handoff' {
    & $PhpBinary tests/t19/company_intelligence_service_test.php
}
Invoke-Checked 'T19 company intelligence HTTP safe failure and enterprise onboarding page' {
    & $PhpBinary tests/t19/company_intelligence_http_test.php
}
Invoke-Checked 'T19 question, answer, evidence, and article-chain integration' {
    & $PhpBinary tests/t19/geo_core_service_test.php
}
Invoke-Checked 'T19 visibility history and safe external-capability failure' {
    & $PhpBinary tests/t19/visibility_test.php
}
Invoke-Checked 'T19 scheduling, job idempotency, trend, and optimization integration' {
    & $PhpBinary tests/t19/scheduler_trend_test.php
}
Invoke-Checked 'T08 unified supplier contract, Mock scenarios, and production fail-closed tests' {
    & $PhpBinary tests/t08/supplier_contract_test.php
}
Invoke-Checked 'T08 source boundaries and production Mock guard' {
    & $PhpBinary tests/t08/source_guard_test.php
}
Invoke-Checked 'T08 migration, rollback, and re-migrate test' {
    & $PhpBinary tests/t08/migration_test.php
}
Invoke-Checked 'T09 supplier contract, mapping, and production fail-closed tests' {
    & $PhpBinary tests/t09/supplier_test.php
}
Invoke-Checked 'T09 migration, rollback, and re-migrate test' {
    & $PhpBinary tests/t09/migration_test.php
}
Invoke-Checked 'T16 internal config service tests' {
    & $PhpBinary tests/t16/config_service_test.php
}
Invoke-Checked 'T16 internal access policy tests' {
    & $PhpBinary tests/t16/access_policy_test.php
}
Invoke-Checked 'T16 internal query service tests' {
    & $PhpBinary tests/t16/query_service_test.php
}
Invoke-Checked 'tenant schema catalog guard' {
    & $PhpBinary scripts/tenant-schema-guard.php
}

$phpFiles = @(
    & rg --files `
        -g '*.php' `
        -g '!vendor/**' `
        -g '!thinkphp/**' `
        -g '!node_modules/**' `
        -g '!runtime/**' `
        -g '!public/assets/**'
)
Write-Output "RUN: PHP syntax check ($($phpFiles.Count) files)"
foreach ($file in $phpFiles) {
    $lintOutput = & $PhpBinary -l $file 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Error "PHP syntax check failed: $file"
        Write-Error ($lintOutput -join "`n")
        throw 'PHP syntax check failed.'
    }
}
Write-Output "PASS: PHP syntax check ($($phpFiles.Count) files)."

Invoke-Checked 'Composer platform requirements' {
    & composer check-platform-reqs --no-dev --quiet
}
Invoke-Checked 'ThinkPHP GEO command discovery' {
    & $PhpBinary think list geo
}
Invoke-Checked 'repository secret scan' {
    & powershell -NoProfile -ExecutionPolicy Bypass -File scripts/secret-scan.ps1
}
Invoke-Checked 'Git whitespace check' {
    # Git emits local autocrlf conversion notices on stderr even when the
    # whitespace check succeeds; suppress only those notices. Actual diff
    # errors remain on stdout and keep the native non-zero exit code.
    & git diff --check 2>$null
}

Write-Output 'PASS: configured T01-T09, T16, and T19 verification completed with zero skipped tests.'
