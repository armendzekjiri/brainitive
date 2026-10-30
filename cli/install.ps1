# Install the Brainitive CLI on Windows (PowerShell 5.1+):
#
#   irm https://github.com/armendzekjiri/brainitive/releases/latest/download/install.ps1 | iex
#
# Downloads the latest release for this machine from GitHub, verifies checksums.txt, and
# installs brainitive.exe into %LOCALAPPDATA%\Brainitive\bin (added to your user PATH).
# $env:BRAINITIVE_VERSION = "1.47.0" pins a version; $env:BRAINITIVE_INSTALL_DIR overrides the dir.
$ErrorActionPreference = 'Stop'

$repo = 'armendzekjiri/brainitive'
$arch = if ([System.Runtime.InteropServices.RuntimeInformation]::OSArchitecture -eq 'Arm64') { 'arm64' } else { 'amd64' }
$asset = "brainitive_windows_$arch.zip"
$base = if ($env:BRAINITIVE_VERSION) { "https://github.com/$repo/releases/download/v$($env:BRAINITIVE_VERSION.TrimStart('v'))" } else { "https://github.com/$repo/releases/latest/download" }

$tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("brainitive-" + [System.Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tmp | Out-Null
try {
    Write-Host "Downloading $asset …"
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri "$base/$asset" -OutFile (Join-Path $tmp $asset) -UseBasicParsing
    Invoke-WebRequest -Uri "$base/checksums.txt" -OutFile (Join-Path $tmp 'checksums.txt') -UseBasicParsing

    $expected = (Get-Content (Join-Path $tmp 'checksums.txt') | Where-Object { $_ -match "\s\*?$([regex]::Escape($asset))$" } | Select-Object -First 1) -split '\s+' | Select-Object -First 1
    if (-not $expected) { throw "$asset is not listed in checksums.txt" }
    $actual = (Get-FileHash -Algorithm SHA256 (Join-Path $tmp $asset)).Hash.ToLower()
    if ($actual -ne $expected.ToLower()) { throw "checksum mismatch for $asset" }

    Expand-Archive -Path (Join-Path $tmp $asset) -DestinationPath $tmp -Force
    $exe = Get-ChildItem -Path $tmp -Recurse -Filter 'brainitive.exe' | Select-Object -First 1
    if (-not $exe) { throw 'the archive has no brainitive.exe' }

    $dir = if ($env:BRAINITIVE_INSTALL_DIR) { $env:BRAINITIVE_INSTALL_DIR } else { Join-Path $env:LOCALAPPDATA 'Brainitive\bin' }
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    $target = Join-Path $dir 'brainitive.exe'
    if (Test-Path $target) { Move-Item -Path $target -Destination "$target.old" -Force }
    Move-Item -Path $exe.FullName -Destination $target -Force
    Remove-Item -Path "$target.old" -Force -ErrorAction SilentlyContinue

    $shortcut = Join-Path $dir 'bi.cmd'
    if (-not (Get-Command bi -ErrorAction SilentlyContinue) -and -not (Test-Path $shortcut) -and -not (Test-Path (Join-Path $dir 'bi.exe')) -and -not (Test-Path (Join-Path $dir 'bi.bat'))) {
        [System.IO.File]::WriteAllText($shortcut, '@"%~dp0brainitive.exe" %*' + "`r`n", [System.Text.Encoding]::ASCII)
        Write-Host 'Short command: bi'
    } else {
        Write-Host 'Skipped bi: a command with that name already exists.'
    }

    $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
    if (($userPath -split ';') -notcontains $dir) {
        [Environment]::SetEnvironmentVariable('Path', "$dir;$userPath", 'User')
        $env:Path = "$dir;$env:Path"
        Write-Host "Added $dir to your user PATH (open a new terminal for other windows to see it)."
    }

    Write-Host "Installed $(& $target version) to $target"
    Write-Host ''
    Write-Host 'Next: brainitive login            # Brainitive Cloud'
    Write-Host '      brainitive login --server brainitive.example.com   # self-hosted'
}
finally {
    Remove-Item -Path $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
