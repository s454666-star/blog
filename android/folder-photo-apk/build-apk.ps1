$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Resolve-Path (Join-Path $projectRoot '..\..')
$versionCode = 3
$versionName = '2026.07.11.3'
$sdkRoot = $env:ANDROID_SDK_ROOT
if (-not $sdkRoot) {
    $sdkRoot = $env:ANDROID_HOME
}
if (-not $sdkRoot) {
    $sdkRoot = Join-Path $env:LOCALAPPDATA 'Android\Sdk'
}
if (-not $env:JAVA_HOME) {
    $jbrRoot = 'C:\Program Files\JetBrains\PhpStorm 2026.1.4\jbr'
    if (Test-Path (Join-Path $jbrRoot 'bin\javac.exe')) {
        $env:JAVA_HOME = $jbrRoot
    }
}

$buildTools = $null
foreach ($version in @('36.1.0', '34.0.0', '35.0.0')) {
    $candidate = Join-Path $sdkRoot "build-tools\$version"
    if (Test-Path $candidate) {
        $buildTools = $candidate
        break
    }
}
if (-not $buildTools) {
    throw "Missing Android build-tools under $sdkRoot"
}
$platformJar = Join-Path $sdkRoot 'platforms\android-35\android.jar'
$aapt2 = Join-Path $buildTools 'aapt2.exe'
$d8 = Join-Path $buildTools 'd8.bat'
$zipalign = Join-Path $buildTools 'zipalign.exe'
$apksigner = Join-Path $buildTools 'apksigner.bat'
$javac = Join-Path $env:JAVA_HOME 'bin\javac.exe'
$keytool = Join-Path $env:JAVA_HOME 'bin\keytool.exe'

foreach ($tool in @($aapt2, $d8, $zipalign, $apksigner, $javac, $keytool, $platformJar)) {
    if (-not (Test-Path $tool)) {
        throw "Missing required build tool: $tool"
    }
}

$buildDir = Join-Path $projectRoot 'build'
$compiledResources = Join-Path $buildDir 'compiled-resources.zip'
$sharedCompiledResources = Join-Path $buildDir 'shared-compiled-resources.zip'
$generatedDir = Join-Path $buildDir 'generated'
$classesDir = Join-Path $buildDir 'classes'
$dexDir = Join-Path $buildDir 'dex'
$unsignedApk = Join-Path $buildDir 'folder-photo-unsigned.apk'
$dexApk = Join-Path $buildDir 'folder-photo-with-dex.apk'
$alignedApk = Join-Path $buildDir 'folder-photo-aligned.apk'
$keystore = Join-Path $repoRoot 'storage\app\folder-photo-app.keystore'
$legacyBuildKeystore = Join-Path $buildDir 'debug.keystore'
$outputApk = Join-Path $repoRoot 'storage\app\folder-photo-app.apk'

if (-not (Test-Path $keystore) -and (Test-Path $legacyBuildKeystore)) {
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $keystore) | Out-Null
    Copy-Item -LiteralPath $legacyBuildKeystore -Destination $keystore -Force
}

if (Test-Path $buildDir) {
    Remove-Item -LiteralPath $buildDir -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $buildDir, $generatedDir, $classesDir, $dexDir | Out-Null
$sharedBuildResources = & (Join-Path $repoRoot 'android\shared-nas-direct\prepare-resources.ps1') -BuildDir $buildDir -RepoRoot $repoRoot

$compiled = $false
for ($attempt = 1; $attempt -le 3; $attempt++) {
    Remove-Item -LiteralPath $compiledResources -ErrorAction SilentlyContinue
    & $aapt2 compile --dir (Join-Path $projectRoot 'app\src\main\res') -o $compiledResources
    if ($LASTEXITCODE -eq 0) {
        $compiled = $true
        break
    }
    Start-Sleep -Milliseconds (250 * $attempt)
}
if (-not $compiled) { throw 'aapt2 compile failed' }

& $aapt2 compile --dir $sharedBuildResources -o $sharedCompiledResources
if ($LASTEXITCODE -ne 0) { throw 'shared aapt2 compile failed' }

& $aapt2 link `
    -o $unsignedApk `
    -I $platformJar `
    --version-code $versionCode `
    --version-name $versionName `
    --manifest (Join-Path $projectRoot 'app\src\main\AndroidManifest.xml') `
    --java $generatedDir `
    $compiledResources `
    $sharedCompiledResources
if ($LASTEXITCODE -ne 0) { throw 'aapt2 link failed' }

$javaFiles = @(
    (Join-Path $generatedDir 'monster\mystar\folderphoto\R.java'),
    (Join-Path $repoRoot 'android\shared-nas-direct\java\monster\mystar\shared\NasDirectBridge.java'),
    (Join-Path $projectRoot 'app\src\main\java\monster\mystar\folderphoto\MainActivity.java')
)
& $javac -encoding UTF-8 -source 8 -target 8 -classpath $platformJar -d $classesDir $javaFiles
if ($LASTEXITCODE -ne 0) { throw 'javac failed' }

$classFiles = Get-ChildItem -LiteralPath $classesDir -Recurse -Filter '*.class' | Select-Object -ExpandProperty FullName
if (-not $classFiles) {
    throw 'No compiled Java classes found'
}

& $d8 --release --min-api 23 --lib $platformJar --output $dexDir $classFiles
if ($LASTEXITCODE -ne 0) { throw 'd8 failed' }

Copy-Item -LiteralPath $unsignedApk -Destination $dexApk -Force
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$apkArchive = [System.IO.Compression.ZipFile]::Open($dexApk, [System.IO.Compression.ZipArchiveMode]::Update)
try {
    $existingDex = $apkArchive.GetEntry('classes.dex')
    if ($existingDex -ne $null) {
        $existingDex.Delete()
    }

    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
        $apkArchive,
        (Join-Path $dexDir 'classes.dex'),
        'classes.dex',
        [System.IO.Compression.CompressionLevel]::Optimal
    ) | Out-Null
} finally {
    $apkArchive.Dispose()
}

& $zipalign -f 4 $dexApk $alignedApk
if ($LASTEXITCODE -ne 0) { throw 'zipalign failed' }

if (-not (Test-Path $keystore)) {
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $keystore) | Out-Null
    & $keytool -genkeypair `
        -keystore $keystore `
        -storepass android `
        -keypass android `
        -alias androiddebugkey `
        -keyalg RSA `
        -keysize 2048 `
        -validity 10000 `
        -dname 'CN=Android Debug,O=Android,C=US' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'keytool failed' }
}

& $apksigner sign `
    --ks $keystore `
    --ks-pass pass:android `
    --key-pass pass:android `
    --out $outputApk `
    $alignedApk
if ($LASTEXITCODE -ne 0) { throw 'apksigner sign failed' }

& $apksigner verify --verbose $outputApk
if ($LASTEXITCODE -ne 0) { throw 'apksigner verify failed' }

Get-Item -LiteralPath $outputApk
