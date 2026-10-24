param([int]$Port = 8081)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpBinary = Join-Path $projectRoot '.tools/php/php.exe'
if (-not (Test-Path -LiteralPath $phpBinary)) { $phpBinary = (Get-Command php -ErrorAction Stop).Source }
Set-Location -LiteralPath $projectRoot
& $phpBinary -S "127.0.0.1:$Port" -t public router.php
