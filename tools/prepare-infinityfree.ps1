param(
    [string]$DatabaseName = 'unistay',
    [string]$DatabaseUser = 'root',
    [string]$MySqlDump = 'C:\xampp\mysql\bin\mysqldump.exe',
    [switch]$IncludeLocalCredentials
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$output = Join-Path $project "scratch/infinityfree-$stamp"
$htdocs = Join-Path $output 'htdocs'
New-Item -ItemType Directory -Path $htdocs -Force | Out-Null

$directories = @('admin', 'api', 'assets', 'auth', 'config', 'landlord', 'pages', 'subscribe', 'tenant', 'tools', 'uploads', 'vendor')
foreach ($directory in $directories) {
    $source = Join-Path $project $directory
    if (-not (Test-Path $source)) { continue }
    Get-ChildItem -LiteralPath $source -File -Recurse -Force | ForEach-Object {
        $relative = $_.FullName.Substring($project.Length + 1).Replace('\', '/')
        if ($relative -match '^config/.*\.local\.php$' -or
            $relative -eq 'tools/prepare-infinityfree.ps1' -or
            $relative -eq 'tools/build-tailwind.mjs' -or
            $relative -match '^uploads/test_') { return }
        $destination = Join-Path $htdocs $relative
        New-Item -ItemType Directory -Path (Split-Path $destination) -Force | Out-Null
        Copy-Item -LiteralPath $_.FullName -Destination $destination
    }
}
Copy-Item -LiteralPath (Join-Path $project 'index.php') -Destination $htdocs
Copy-Item -LiteralPath (Join-Path $project '.htaccess') -Destination $htdocs
Copy-Item -LiteralPath (Join-Path $project 'config/hosting.example.php') -Destination (Join-Path $htdocs 'config/hosting.local.php')
New-Item -ItemType Directory -Path (Join-Path $htdocs 'data') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $project 'data/.htaccess') -Destination (Join-Path $htdocs 'data/.htaccess')
New-Item -ItemType Directory -Path (Join-Path $htdocs 'storage') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $project 'storage/.htaccess') -Destination (Join-Path $htdocs 'storage/.htaccess')

if ($IncludeLocalCredentials) {
    $mailFile = Join-Path $project 'config/mail.local.php'
    if (Test-Path -LiteralPath $mailFile) {
        Copy-Item -LiteralPath $mailFile -Destination (Join-Path $htdocs 'config/mail.local.php')
    }
    $oauthFile = Join-Path $project 'config/oauth.local.php'
    if (Test-Path -LiteralPath $oauthFile) {
        $oauth = [IO.File]::ReadAllText($oauthFile)
        $redirect = "'redirect_uri' => 'https://unistaysads.infinityfreeapp.com/auth/google_oauth.php'"
        $updated = [regex]::Replace($oauth, "'redirect_uri'\s*=>\s*'[^']*'", $redirect)
        if ($updated -eq $oauth) { throw 'Could not update the OAuth callback in oauth.local.php.' }
        [IO.File]::WriteAllText((Join-Path $htdocs 'config/oauth.local.php'), $updated, (New-Object System.Text.UTF8Encoding($false)))
    }
}

$oversize = Get-ChildItem -LiteralPath $htdocs -File -Recurse -Force | Where-Object {
    $limit = if ($_.Name -eq '.htaccess') { 10KB } elseif ($_.Extension -in @('.php', '.js', '.html')) { 1MB } else { 10MB }
    $_.Length -gt $limit
}
if ($oversize) {
    $oversize | Select-Object FullName, Length | Format-Table | Out-String | Write-Error
    throw 'One or more files exceed InfinityFree file limits.'
}

if (-not (Test-Path -LiteralPath $MySqlDump)) { throw "mysqldump not found: $MySqlDump" }
$dumpPath = Join-Path $output 'unistay-import.sql'
& $MySqlDump --single-transaction --skip-triggers --skip-routines --skip-add-drop-database --default-character-set=utf8mb4 --no-tablespaces --result-file=$dumpPath -u $DatabaseUser $DatabaseName
if ($LASTEXITCODE -ne 0) { throw 'Database export failed.' }
$sql = [IO.File]::ReadAllText($dumpPath)
$sql = [regex]::Replace($sql, 'DEFINER\s*=\s*`[^`]+`@`[^`]+`\s*', '')
[IO.File]::WriteAllText($dumpPath, $sql, (New-Object System.Text.UTF8Encoding($false)))

Write-Host "Prepared: $output"
Write-Host 'Fill htdocs/config/hosting.local.php, then import unistay-import.sql through phpMyAdmin and upload htdocs.'
