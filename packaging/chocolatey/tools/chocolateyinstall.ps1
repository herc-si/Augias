# PLACEHOLDER: This install script requires Windows binary support.
# See https://github.com/herc-si/Augias/issues for tracking.

$ErrorActionPreference = 'Stop'

$packageArgs = @{
    packageName    = 'augias'
    url64          = "https://github.com/herc-si/Augias/releases/download/${env:chocolateyPackageVersion}/augias-windows-amd64.exe"
    fileFullPath   = "$(Get-ToolsLocation)\augias.exe"
    checksum64     = '' # Updated by CI
    checksumType64 = 'sha256'
}

Get-ChocolateyWebFile @packageArgs

# Add to PATH
$toolsDir = Get-ToolsLocation
Install-ChocolateyPath -PathToInstall $toolsDir -PathType 'Machine'

Write-Output "Augias installed. Run 'augias run' to start."
