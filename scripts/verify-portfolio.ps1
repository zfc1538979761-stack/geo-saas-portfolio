param([switch]$IncludeHttp)
$ErrorActionPreference='Stop'
Set-Location ([IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..')))
$tests=@('runtime_guard','setup','ai_contract','seeder','storage','workflow','release_audit','recovery','initialization_guard')
if ($IncludeHttp) {$tests+='queue_http'}
$results=@()
foreach($name in $tests) {
    $out=@(& php "tests/portfolio/$($name)_test.php" 2>&1)
    $code=$LASTEXITCODE
    $text=$out -join "`n"
    $pass=($code -eq 0 -and $text -notmatch '(?i)FAIL:|Fatal error|uncaught|Warning:|^SKIP:')
    $out|Set-Content -LiteralPath "runtime/test-$name.log" -Encoding UTF8
    $results+=@{test=$name;exit=$code;passed=$pass;assertions=([regex]::Matches($text,'(?m)^PASS:')).Count}
    if(-not $pass) { $out|Write-Output;throw "Portfolio test failed: $name" }
}
$results|ConvertTo-Json|Set-Content -LiteralPath 'runtime/verification-results.json' -Encoding UTF8
$results|ForEach-Object {Write-Output "PASS: $($_.test) ($($_.assertions) assertions)"}
Write-Output 'PASS: all requested Portfolio suites; zero SKIP.'
