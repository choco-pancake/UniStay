param(
    [Parameter(Mandatory = $true)][string]$PreparedDirectory,
    [Parameter(Mandatory = $true)][string]$RemoteHtdocs,
    [string]$FtpHost = 'ftpupload.net',
    [string]$NetrcFile = 'scratch/infinityfree-ftp.netrc',
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$prepared = (Resolve-Path -LiteralPath $PreparedDirectory).Path
$htdocs = Join-Path $prepared 'htdocs'
if (-not (Test-Path -LiteralPath (Join-Path $htdocs 'index.php'))) { throw 'PreparedDirectory must contain htdocs/index.php.' }
if (-not $DryRun -and -not (Test-Path -LiteralPath (Join-Path $htdocs 'config/hosting.local.php'))) { throw 'Add config/hosting.local.php to the prepared htdocs folder before upload.' }
if (-not $DryRun) {
    $hostingText = Get-Content -LiteralPath (Join-Path $htdocs 'config/hosting.local.php') -Raw
    if ($hostingText -match 'YOUR_HOSTING_PASSWORD|sqlXXX|if0_XXXXXXX') { throw 'Fill the InfinityFree database placeholders in hosting.local.php before upload.' }
}
$remote = '/' + $RemoteHtdocs.Trim('/') + '/'
if ($remote -notmatch '/htdocs/$' -or $remote.Contains('..')) { throw 'RemoteHtdocs must end in /htdocs and contain no parent traversal.' }
$files = Get-ChildItem -LiteralPath $htdocs -Recurse -File -Force
if ($DryRun) {
    Write-Host "Ready to upload $($files.Count) files to ftp://$FtpHost$remote"
    return
}
$netrc = if ([IO.Path]::IsPathRooted($NetrcFile)) { $NetrcFile } else { Join-Path $project $NetrcFile }
if (-not (Test-Path -LiteralPath $netrc)) { throw "Missing FTP credentials file: $netrc" }

& curl.exe --fail --silent --show-error --ssl-reqd --ftp-pasv --netrc-file $netrc --connect-timeout 20 --list-only "ftp://$FtpHost$remote" | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not list the remote htdocs folder. Check the FTP login and remote path.' }

$done = 0
foreach ($file in $files) {
    $relative = $file.FullName.Substring($htdocs.Length + 1).Replace('\', '/')
    $encoded = ($relative.Split('/') | ForEach-Object { [uri]::EscapeDataString($_) }) -join '/'
    $tlsMode = if ($relative -in @('config/hosting.local.php', 'config/mail.local.php', 'config/oauth.local.php') -or $relative -match '^(tenant/uploads|uploads/(landlord-applications|ownership|permits))/') { '--ssl-reqd' } else { '--ftp-ssl-control' }
    $uploaded = $false
    for ($attempt = 1; $attempt -le 4; $attempt++) {
        & curl.exe --fail --silent --show-error $tlsMode --ftp-pasv --netrc-file $netrc --connect-timeout 20 --max-time 180 --ftp-create-dirs --upload-file $file.FullName "ftp://$FtpHost$remote$encoded"
        if ($LASTEXITCODE -eq 0) { $uploaded = $true; break }
        $folder = $encoded.Substring(0, $encoded.LastIndexOf('/') + 1)
        & curl.exe --silent --ftp-ssl-control --ftp-pasv --netrc-file $netrc -Q ('+DELE ' + $file.Name) "ftp://$FtpHost$remote$folder" | Out-Null
        if ($attempt -lt 4) { Start-Sleep -Seconds (2 * $attempt) }
    }
    if (-not $uploaded) { throw "Upload stopped at $relative ($done of $($files.Count) files completed)." }
    $done++
    if ($done % 25 -eq 0) { Write-Host "Uploaded $done of $($files.Count) files" }
}
Write-Host "Uploaded $done files to $remote"
