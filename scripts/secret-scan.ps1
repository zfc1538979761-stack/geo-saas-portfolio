[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
# Standalone public candidate scan: do not depend on source Git history.
# Local credentials/data and separately installed dependencies are not release inputs.
$files=@()
$pending=New-Object 'System.Collections.Generic.Stack[string]'
$pending.Push($repoRoot)
while($pending.Count -gt 0){
 $directory=$pending.Pop()
 foreach($item in Get-ChildItem -LiteralPath $directory -Force){
  if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Reparse point requires explicit review.'}
  if($item.PSIsContainer){if($item.Name -notin @('runtime','vendor','node_modules','thinkphp','libs','.git','.pnpm-store')){$pending.Push($item.FullName)}}
  elseif($item.Name -ne '.env' -and ($item.Name -match '\.(php|md|js|html|css|sql|json|xml|yml|yaml|ini|sample|example|ps1|txt|bat|cmd)$' -or $item.Name -in @('.gitignore','.npmrc'))){$files+=$item.FullName.Substring($repoRoot.Length+1)}
 }
}

$patterns = @(
    [PSCustomObject]@{
        Name = 'private-key'
        Regex = '-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----\s+[A-Za-z0-9+/=\r\n]{64,}-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----'
    },
    [PSCustomObject]@{
        Name = 'credential-in-url'
        Regex = '(?i)https?://[^/\s:@]+:[^/\s@]+@'
    },
    [PSCustomObject]@{
        Name = 'known-token-prefix'
        Regex = '(?<![A-Za-z0-9])(?:AKIA[0-9A-Z]{16}|sk-[A-Za-z0-9_-]{20,})(?![A-Za-z0-9])'
    },
    [PSCustomObject]@{
        Name = 'generic-secret-assignment'
        Regex = '(?i)(?:api[_-]?key|access[_-]?secret|access[_-]?code|password|passwd|token)\s*[:=]\s*["'']([A-Za-z0-9+/_=.-]{12,})["'']'
    },
    [PSCustomObject]@{
        Name = 'ini-secret-assignment'
        Regex = '(?im)^\s*(?:api[_-]?key|access[_-]?secret|access[_-]?code|password|passwd|token)\s*=\s*([A-Za-z0-9+/_=.-]{12,})\s*$'
    }
)
$allowed = '(?i)(change-me|placeholder|example|dummy|test-only|secret-value|temporary|configured|redacted)'
$findings = New-Object System.Collections.Generic.List[object]

foreach ($relativePath in $files) {
    $fullPath = Join-Path $repoRoot $relativePath
    if (-not (Test-Path -LiteralPath $fullPath -PathType Leaf)) {
        continue
    }
    $content = [string](Get-Content -LiteralPath $fullPath -Encoding utf8 -Raw -ErrorAction Stop)
    foreach ($pattern in $patterns) {
        foreach ($match in [regex]::Matches($content, $pattern.Regex)) {
            if ($match.Value -match $allowed) {
                continue
            }
            $findings.Add([PSCustomObject]@{
                File = $relativePath
                Category = $pattern.Name
            })
        }
    }
}

if ($findings.Count -gt 0) {
    $findings |
        Sort-Object File, Category -Unique |
        Format-Table -AutoSize |
        Out-String |
        Write-Error
    throw 'Secret scan found possible sensitive material. Values were not printed.'
}

Write-Output "PASS: secret scan checked $($files.Count) repository files; no credential pattern found."
