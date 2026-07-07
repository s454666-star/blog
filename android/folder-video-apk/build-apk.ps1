$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Resolve-Path (Join-Path $projectRoot '..\..')
$sdkRoot = $env:ANDROID_SDK_ROOT
if (-not $sdkRoot) {
    $sdkRoot = $env:ANDROID_HOME
}
if (-not $sdkRoot) {
    $sdkRoot = Join-Path $env:LOCALAPPDATA 'Android\Sdk'
}

$buildTools = Join-Path $sdkRoot 'build-tools\35.0.0'
$platformJar = Join-Path $sdkRoot 'platforms\android-35\android.jar'
$aapt2 = Join-Path $buildTools 'aapt2.exe'
$d8 = Join-Path $buildTools 'd8.bat'
$zipalign = Join-Path $buildTools 'zipalign.exe'
$apksigner = Join-Path $buildTools 'apksigner.bat'
$javac = Join-Path $env:JAVA_HOME 'bin\javac.exe'
$jar = Join-Path $env:JAVA_HOME 'bin\jar.exe'
$keytool = Join-Path $env:JAVA_HOME 'bin\keytool.exe'

foreach ($tool in @($aapt2, $d8, $zipalign, $apksigner, $javac, $jar, $keytool, $platformJar)) {
    if (-not (Test-Path $tool)) {
        throw "Missing required build tool: $tool"
    }
}

$buildDir = Join-Path $projectRoot 'build'
$compiledResources = Join-Path $buildDir 'compiled-resources.zip'
$generatedDir = Join-Path $buildDir 'generated'
$classesDir = Join-Path $buildDir 'classes'
$classesJar = Join-Path $buildDir 'classes.jar'
$dexDir = Join-Path $buildDir 'dex'
$unsignedApk = Join-Path $buildDir 'folder-video-unsigned.apk'
$dexApk = Join-Path $buildDir 'folder-video-with-dex.apk'
$alignedApk = Join-Path $buildDir 'folder-video-aligned.apk'
$keystore = Join-Path $buildDir 'debug.keystore'
$outputApk = Join-Path $repoRoot 'storage\app\folder-video-app.apk'

if (Test-Path $buildDir) {
    Remove-Item -LiteralPath $buildDir -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $buildDir, $generatedDir, $classesDir, $dexDir | Out-Null

& $aapt2 compile --dir (Join-Path $projectRoot 'app\src\main\res') -o $compiledResources
if ($LASTEXITCODE -ne 0) { throw 'aapt2 compile failed' }

& $aapt2 link `
    -o $unsignedApk `
    -I $platformJar `
    --manifest (Join-Path $projectRoot 'app\src\main\AndroidManifest.xml') `
    --java $generatedDir `
    $compiledResources
if ($LASTEXITCODE -ne 0) { throw 'aapt2 link failed' }

$javaFiles = @(
    (Join-Path $generatedDir 'monster\mystar\foldervideo\R.java'),
    (Join-Path $projectRoot 'app\src\main\java\monster\mystar\foldervideo\MainActivity.java')
)
& $javac -encoding UTF-8 -source 8 -target 8 -classpath $platformJar -d $classesDir $javaFiles
if ($LASTEXITCODE -ne 0) { throw 'javac failed' }

Push-Location $classesDir
try {
    & $jar cf $classesJar .
    if ($LASTEXITCODE -ne 0) { throw 'jar classes failed' }
} finally {
    Pop-Location
}

& $d8 --release --min-api 23 --lib $platformJar --output $dexDir $classesJar
if ($LASTEXITCODE -ne 0) { throw 'd8 failed' }

Copy-Item -LiteralPath $unsignedApk -Destination $dexApk -Force
Push-Location $dexDir
try {
    & $jar uf $dexApk classes.dex
    if ($LASTEXITCODE -ne 0) { throw 'jar update failed' }
} finally {
    Pop-Location
}

& $zipalign -f 4 $dexApk $alignedApk
if ($LASTEXITCODE -ne 0) { throw 'zipalign failed' }

& $keytool -genkeypair `
    -keystore $keystore `
    -storepass android `
    -keypass android `
    -alias androiddebugkey `
    -keyalg RSA `
    -keysize 2048 `
    -validity 10000 `
    -dname 'CN=Android Debug,O=Android,C=US' `
    -storetype JKS | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'keytool failed' }

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
