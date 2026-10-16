param(
    [Parameter(Mandatory = $true)]
    [string]$ManifestPath
)

$ErrorActionPreference = 'Stop'
$utf8 = New-Object System.Text.UTF8Encoding($false)
[Console]::OutputEncoding = $utf8
$OutputEncoding = $utf8
Add-Type -AssemblyName Microsoft.VisualBasic

$manifest = (Resolve-Path -LiteralPath $ManifestPath -ErrorAction Stop).Path
$decodedPaths = Get-Content -LiteralPath $manifest -Raw -Encoding UTF8 | ConvertFrom-Json
$paths = @()
foreach ($decodedPath in $decodedPaths) {
    $paths += [string]$decodedPath
}
if ($paths.Count -eq 0) {
    throw 'Recycle manifest does not contain any paths.'
}

# Keep going past individual failures so one bad file does not block the rest of the batch.
$errors = New-Object System.Collections.Generic.List[string]
$targets = New-Object System.Collections.Generic.List[string]
foreach ($path in $paths) {
    if (-not (Test-Path -LiteralPath $path)) {
        continue
    }
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        $errors.Add("Failed: ${path}: Not a file")
        continue
    }
    $targets.Add((Resolve-Path -LiteralPath $path).Path)
}

# One multi-file shell operation is far faster than recycling each file separately.
# Same flags as the per-file fallback below: allow undo, no confirmation, silent, no error UI.
try {
    Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

public static class TgRecycleBin
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct SHFILEOPSTRUCT
    {
        public IntPtr hwnd;
        public uint wFunc;
        public string pFrom;
        public string pTo;
        public ushort fFlags;
        public int fAnyOperationsAborted;
        public IntPtr hNameMappings;
        public string lpszProgressTitle;
    }

    [DllImport("shell32.dll", CharSet = CharSet.Unicode)]
    private static extern int SHFileOperation(ref SHFILEOPSTRUCT operation);

    public static int Recycle(string[] paths)
    {
        var operation = new SHFILEOPSTRUCT
        {
            wFunc = 0x0003,
            pFrom = string.Join("\0", paths) + "\0\0",
            fFlags = 0x0040 | 0x0010 | 0x0004 | 0x0400,
        };

        return SHFileOperation(ref operation);
    }
}
'@
    for ($offset = 0; $offset -lt $targets.Count; $offset += 500) {
        $chunk = $targets.GetRange($offset, [Math]::Min(500, $targets.Count - $offset)).ToArray()
        [void][TgRecycleBin]::Recycle($chunk)
    }
} catch {
    # Fall through to the per-file path for whatever is left.
}

foreach ($path in $targets) {
    if (-not (Test-Path -LiteralPath $path)) {
        continue
    }

    try {
        [Microsoft.VisualBasic.FileIO.FileSystem]::DeleteFile(
            $path,
            [Microsoft.VisualBasic.FileIO.UIOption]::OnlyErrorDialogs,
            [Microsoft.VisualBasic.FileIO.RecycleOption]::SendToRecycleBin
        )
    } catch {
        $errors.Add("Failed: ${path}: $($_.Exception.Message)")
    }
}

if ($errors.Count -gt 0) {
    [Console]::Error.WriteLine(($errors -join [Environment]::NewLine))
    exit 1
}
