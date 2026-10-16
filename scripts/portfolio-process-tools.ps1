function Get-PortfolioDriveTarget([string]$Drive) {
 if(-not ('PortfolioDriveNative' -as [type])) {
  Add-Type -TypeDefinition @'
using System;
using System.Text;
using System.Runtime.InteropServices;
public static class PortfolioDriveNative {
 [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
 public static extern uint QueryDosDevice(string name, StringBuilder target, int length);
}
'@
 }
 $buffer=New-Object Text.StringBuilder 32768
 if([PortfolioDriveNative]::QueryDosDevice($Drive,$buffer,$buffer.Capacity) -eq 0){return $null}
 $target=$buffer.ToString()
 if(-not $target.StartsWith('\??\')){return $null}
 return [IO.Path]::GetFullPath($target.Substring(4)).TrimEnd('\')
}
function Test-PortfolioDriveMapping([string]$Mapping,[string]$Drive,[string]$Expected) {
 $line=@($Mapping -split "`r?`n" | Where-Object {$_ -match ('^'+[regex]::Escape($Drive)+'\\: => ')})
 if($line.Count -ne 1){return $false}
 $actual=($line[0] -split ' => ',2)[1]
 return [IO.Path]::GetFullPath($actual).TrimEnd('\') -ieq [IO.Path]::GetFullPath($Expected).TrimEnd('\')
}
function Stop-PortfolioOwnedProcesses($Entries) {
 foreach($entry in $Entries){
  $owned=Get-Process -Id $entry.pid -ErrorAction SilentlyContinue
  if($owned -and $owned.StartTime.ToUniversalTime().ToString('o') -eq $entry.started){Stop-Process -Id $owned.Id -Force; $owned.WaitForExit(5000)|Out-Null}
 }
}
function Get-PortfolioOwnedChildren($Root,$Entries) {
 $out=@()
 foreach($kind in @('ai','publish')) {
  $path=Join-Path $Root "runtime/$kind-child.json"
  if(Test-Path $path){$child=Get-Content -LiteralPath $path -Encoding UTF8 -Raw|ConvertFrom-Json
   if(@($Entries|Where-Object {$_.pid -eq $child.supervisor_pid -and $_.started -eq $child.supervisor_started}).Count -eq 1){$out+=$child}
  }
 }
 return $out
}
