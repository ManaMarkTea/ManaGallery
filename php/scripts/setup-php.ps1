# Optional portable CLI runtime; does not modify PATH or install system software.
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$toolDirectory = Join-Path $projectRoot '.tools'
$runtimeDirectory = Join-Path $toolDirectory 'php'
if (Test-Path -LiteralPath (Join-Path $runtimeDirectory 'php.exe')) { throw 'A runtime already exists. Preserve it or move it aside before reinstalling.' }
New-Item -ItemType Directory -Force -Path $toolDirectory | Out-Null
$archivePath = Join-Path $toolDirectory 'php.zip'
Invoke-WebRequest -Uri 'https://downloads.php.net/~windows/releases/archives/php-8.5.12-nts-Win32-vs17-x64.zip' -OutFile $archivePath
$actualHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash
if ($actualHash -ne '2ab242af38d76bdf417cde5ec048b1bcd76a5a40f39a3d15d5f046f38571c477') { throw 'PHP download checksum mismatch; not extracted.' }
Expand-Archive -LiteralPath $archivePath -DestinationPath $runtimeDirectory
$extensionDirectory = (Join-Path $runtimeDirectory 'ext').Replace('\', '/')
@"
extension_dir="$extensionDirectory"
extension=fileinfo
extension=mbstring
extension=pdo_sqlite
extension=gd
extension=sodium
upload_max_filesize=10M
post_max_size=12M
memory_limit=256M
"@ | Set-Content -LiteralPath (Join-Path $runtimeDirectory 'php.ini') -Encoding utf8
& (Join-Path $runtimeDirectory 'php.exe') -v
