# Compatible with Windows PowerShell 5.1 and PowerShell 7.
[CmdletBinding()]
param(
    [string]$SourceRoot,
    [string]$OutputDirectory
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if (!$SourceRoot) { $SourceRoot = Join-Path (Split-Path $PSScriptRoot -Parent) 'Simple_Like_Button' }
if (!$OutputDirectory) { $OutputDirectory = Join-Path (Split-Path $PSScriptRoot -Parent) '.vscode/pollyanna-releases' }
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

# Review every addition. Neither directory enumeration nor wildcards define the package.
$allowlist = @(
    'README.md', 'README.en.md', 'LICENSE',
    'admin/index.php', 'admin/widget-test-view.php',
    'api/bootstrap.php', 'api/state.php', 'api/like.php',
    'assets/admin.css', 'assets/admin.js', 'assets/installer.css',
    'assets/installer.js', 'assets/like.css', 'assets/like.js',
    'src/bootstrap.php', 'src/AdminPasswordPolicy.php', 'src/InstallerState.php',
    'src/OriginPolicy.php', 'src/LikeService.php', 'src/AggregationService.php',
    'src/TimezoneChangeService.php', 'src/AccessProtectionService.php',
    'lang/admin.ja.json', 'lang/admin.en.json', 'lang/widget.ja.json', 'lang/widget.en.json',
    'config/.htaccess', 'config/config.example.php', 'log/.htaccess',
    'install/index.php', 'install/install.sql', 'install/readme.php'
)

function Assert-NoLink([string]$Path) {
    $cursor = [IO.Path]::GetFullPath($Path)
    while ($cursor) {
        $item = Get-Item -LiteralPath $cursor -Force
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw 'Symbolic links and junctions are not allowed.'
        }
        $parent = Split-Path -Path $cursor -Parent
        if (!$parent -or $parent -eq $cursor) { break }
        $cursor = $parent
    }
}

function Get-BytesHash([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

$source = [IO.Path]::GetFullPath($SourceRoot)
Assert-NoLink $source
$seen = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
$expected = @{}
$payloads = @{}
$utf8 = New-Object Text.UTF8Encoding($false, $true)
foreach ($relative in $allowlist) {
    if (!$seen.Add($relative)) { throw 'Duplicate allowlist entry.' }
    if ($relative -match '(^/|\\|(^|/)\.\.(/|$)|:)' -or
        $relative -match '(^|/)(tests|legacy|docs|\.git|\.vscode|backups)(/|$)' -or
        $relative -match '(^|/)(config\.php|admin_credentials\.json|.*\.log|upgrade-.*\.sql)$' -or
        ($relative.StartsWith('config/') -and $relative -notin @('config/.htaccess','config/config.example.php')) -or
        ($relative.StartsWith('log/') -and $relative -ne 'log/.htaccess')) {
        throw 'Forbidden allowlist entry.'
    }
    $path = [IO.Path]::GetFullPath((Join-Path $source $relative))
    if (!$path.StartsWith($source.TrimEnd('\','/') + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Source path escapes the application root.'
    }
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) { throw "Missing required file: $relative" }
    Assert-NoLink $path
    $bytes = [IO.File]::ReadAllBytes($path)
    # GNU's unmodified https://www.gnu.org/licenses/gpl-3.0.txt (35,149 bytes).
    if ($relative -eq 'LICENSE' -and (Get-BytesHash $bytes) -ne '3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986') {
        throw 'LICENSE does not match the approved GNU GPL version 3 text.'
    }
    $text = $utf8.GetString($bytes)
    # Fail closed on secret-like material; report only the path, never a matched value.
    if ($text -match '-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|\$2[aby]\$\d\d\$[./A-Za-z0-9]{53}|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{30,}|["\x27](?i:password|app_secret)["\x27]\s*=>\s*["\x27](?!replace-with-)[^"\x27]+["\x27]') {
        throw "Potential secret detected in $relative; details suppressed."
    }
    if ($relative -eq 'config/config.example.php' -and
        (!$text.Contains('replace-with-database-password') -or !$text.Contains('replace-with-64-hexadecimal-characters-generated-randomly'))) {
        throw 'Configuration template placeholders are missing.'
    }
    if ($relative -in @('config/.htaccess','log/.htaccess') -and $text.Trim() -ne 'Require all denied') {
        throw 'Required web access protection differs from the approved rule.'
    }
    $name = 'pollyanna/' + $relative
    $payloads[$name] = $bytes
    $expected[$name] = Get-BytesHash $bytes
}

$output = [IO.Path]::GetFullPath($OutputDirectory)
# Check existing parents before creating any directory.
$ancestor = $output
while (!(Test-Path -LiteralPath $ancestor)) { $ancestor = Split-Path $ancestor -Parent; if (!$ancestor) { throw 'Invalid output path.' } }
Assert-NoLink $ancestor
if (!(Test-Path -LiteralPath $output)) { New-Item -ItemType Directory -Path $output | Out-Null }
Assert-NoLink $output
$final = Join-Path $output 'Pollyanna-v1.0.0-beta1.zip'
if (Test-Path -LiteralPath $final) { throw 'A completed ZIP already exists; refusing to overwrite it.' }
$temporary = Join-Path $output ('.pollyanna-' + [Guid]::NewGuid().ToString('N') + '.tmp')
try {
    $stream = [IO.File]::Open($temporary, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try {
        $zip = New-Object IO.Compression.ZipArchive($stream, [IO.Compression.ZipArchiveMode]::Create, $true, $utf8)
        try {
            foreach ($name in ($expected.Keys | Sort-Object)) {
                $entry = $zip.CreateEntry($name, [IO.Compression.CompressionLevel]::Optimal)
                $entryStream = $entry.Open()
                try { $entryStream.Write($payloads[$name], 0, $payloads[$name].Length) }
                finally { $entryStream.Dispose() }
            }
        } finally { $zip.Dispose() }
    } finally { $stream.Dispose() }

    $zip = [IO.Compression.ZipFile]::OpenRead($temporary)
    try {
        if ($zip.Entries.Count -ne $allowlist.Count) { throw 'ZIP entry count mismatch.' }
        $actual = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
        foreach ($entry in $zip.Entries) {
            if (!$actual.Add($entry.FullName) -or !$expected.ContainsKey($entry.FullName)) { throw 'Unexpected or duplicate ZIP entry.' }
            $entryStream = $entry.Open(); $memory = New-Object IO.MemoryStream
            try {
                $entryStream.CopyTo($memory)
                if ((Get-BytesHash $memory.ToArray()) -ne $expected[$entry.FullName]) { throw 'ZIP content hash mismatch.' }
            } finally { $entryStream.Dispose(); $memory.Dispose() }
        }
    } finally { $zip.Dispose() }
    # Detect edits during the build before publishing the verified artifact.
    foreach ($relative in $allowlist) {
        $path = Join-Path $source $relative
        Assert-NoLink $path
        if ((Get-BytesHash ([IO.File]::ReadAllBytes($path))) -ne $expected['pollyanna/' + $relative]) { throw 'Source changed during packaging.' }
    }
    [IO.File]::Move($temporary, $final)
    [pscustomobject]@{ Path = $final; Files = $allowlist.Count; Bytes = (Get-Item -LiteralPath $final).Length; SHA256 = (Get-FileHash -LiteralPath $final -Algorithm SHA256).Hash }
} finally {
    # Delete only this exact temporary file, never a directory or existing ZIP.
    if (Test-Path -LiteralPath $temporary) { [IO.File]::Delete($temporary) }
}
