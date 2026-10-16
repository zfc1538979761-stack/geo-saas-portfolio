param([string]$MysqlBase,[int]$Port=33329)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'portfolio-process-tools.ps1')
if ($Port -ne 33329) { throw 'Portfolio uses isolated port 33329.' }
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ([string]::IsNullOrWhiteSpace($MysqlBase)) { throw 'Pass -MysqlBase pointing to an installed MySQL 8 distribution.' }
$binary=Join-Path $MysqlBase 'bin/mysqld.exe'
if (-not (Test-Path -LiteralPath $binary)) { throw 'MySQL server binary not found.' }
$data=Join-Path $root 'runtime/mysql'
$pidRecord=Join-Path $root 'runtime/mysql-process.json'
# Existing record is retained; a listening port is never adopted automatically.
$portInUse=[Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners() | Where-Object {$_.Port -eq $Port}
if ($portInUse) { throw 'Port 33329 already in use; existing server is not adopted.' }
if (-not (Test-Path -LiteralPath $data)) {
    [IO.Directory]::CreateDirectory($data)|Out-Null
    & $binary --no-defaults --initialize-insecure "--basedir=$MysqlBase" "--datadir=$data" "--log-error=$root/runtime/mysql-init.log"
    if ($LASTEXITCODE -ne 0) { throw 'MySQL initialization failed; inspect local runtime log.' }
}
if ($root -match '[^\x00-\x7f]') {
    if (-not (Test-Path 'P:\')) { & subst.exe P: $root; if ($LASTEXITCODE -ne 0) { throw 'Could not create ASCII drive P:.' } }
    if ((Get-PortfolioDriveTarget 'P:') -ine $root.TrimEnd('\')) { throw 'P: belongs to a different directory; no changes made.' }
    $data='P:\runtime\mysql'
    $root='P:\'
}
$arguments=@('--no-defaults',"--basedir=`"$MysqlBase`"","--datadir=`"$data`"",'--bind-address=127.0.0.1',"--port=$Port",'--mysqlx=0',"--log-error=`"$root/runtime/mysql-server.log`"")
$process=Start-Process -FilePath $binary -ArgumentList $arguments -WindowStyle Hidden -PassThru
@{pid=$process.Id;port=$Port;datadir=$data}|ConvertTo-Json|Set-Content -LiteralPath $pidRecord -Encoding UTF8
$deadline=[DateTime]::UtcNow.AddSeconds(25)
do {
    Start-Sleep -Milliseconds 250
    $tcp=New-Object Net.Sockets.TcpClient
    try { $tcp.Connect('127.0.0.1',$Port);$ready=$true } catch { $ready=$false } finally {$tcp.Dispose()}
    if ($ready) {break}
} while ([DateTime]::UtcNow -lt $deadline -and -not $process.HasExited)
if (-not $ready) { throw 'New isolated MySQL failed to listen.' }
Write-Output 'PASS: new isolated loopback MySQL started on 33329; no source database used.'
