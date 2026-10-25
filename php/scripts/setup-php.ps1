# Optional portable CLI runtime; does not modify PATH or install system software.
param([ValidatePattern('^8\.[3-9]$')][string]$Branch = '8.5')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$toolDirectory = Join-Path $projectRoot '.tools'
$runtimeDirectory = Join-Path $toolDirectory 'php'
if (Test-Path -LiteralPath (Join-Path $runtimeDirectory 'php.exe')) { throw 'A runtime already exists. Preserve it or move it aside before reinstalling.' }
New-Item -ItemType Directory -Force -Path $toolDirectory | Out-Null
$archivePath = Join-Path $toolDirectory 'php.zip'
$releases = Invoke-RestMethod -Uri 'https://downloads.php.net/~windows/releases/releases.json'
$release = $releases.PSObject.Properties[$Branch].Value
if (!$release) { throw "PHP $Branch is not listed by the Windows release service." }
$builds = @($release.PSObject.Properties | Where-Object Name -Match '^nts-vs[0-9]+-x64$')
if ($builds.Count -ne 1) { throw 'Expected one official x64 non-thread-safe PHP build.' }
$package = $builds[0].Value.zip
$expectedPattern = '^php-' + [regex]::Escape($Branch) + '\.[0-9]+-nts-Win32-vs[0-9]+-x64\.zip$'
if ($package.path -notmatch $expectedPattern -or $package.sha256 -notmatch '^[a-f0-9]{64}$') {
    throw 'Unexpected PHP release metadata.'
}
Write-Output "Downloading PHP $($release.version) with the publisher's SHA-256 check."
Invoke-WebRequest -Uri ('https://downloads.php.net/~windows/releases/' + $package.path) -OutFile $archivePath
$actualHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash
if ($actualHash -ne $package.sha256) { throw 'PHP download checksum mismatch; not extracted.' }
Expand-Archive -LiteralPath $archivePath -DestinationPath $runtimeDirectory
$extensionDirectory = (Join-Path $runtimeDirectory 'ext').Replace('\', '/')
@"
extension_dir="$extensionDirectory"
extension=fileinfo
extension=mbstring
extension=pdo_sqlite
extension=gd
extension=sodium
extension=openssl
extension=curl
extension=zip
upload_max_filesize=10M
post_max_size=12M
memory_limit=256M
"@ | Set-Content -LiteralPath (Join-Path $runtimeDirectory 'php.ini') -Encoding utf8
& (Join-Path $runtimeDirectory 'php.exe') -v
