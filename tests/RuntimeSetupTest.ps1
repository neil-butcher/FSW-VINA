$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$source = [IO.File]::ReadAllText((Join-Path $repo 'start-vina.bat'))
$body = ($source -split '(?m)^# POWERSHELL SETUP\r?\n', 2)[1]
$tokens = $null
$parseErrors = $null
[void][Management.Automation.Language.Parser]::ParseInput($body, [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count) { throw ($parseErrors | Out-String) }
# Throw instead of exiting the test process on the batch file's failure path.
$setup = [scriptblock]::Create($body.Replace('    exit 1', '    throw'))
$dockerExecutable = (Get-Command docker.exe -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('vina-setup-test-' + [Guid]::NewGuid().ToString('N'))
$originalLocation = Get-Location
$originalSetupFile = $env:VINA_SETUP_FILE
$originalPath = $env:PATH
$script:calls = New-Object 'System.Collections.Generic.List[string]'
$script:existingDatabase = $false
$script:failBuild = $false
$script:browserUrl = $null

function Assert-Setup {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
}

function docker {
    $script:calls.Add(($args -join ' '))
    $global:LASTEXITCODE = 0
    if ($args[0] -eq 'info') { return 'linux' }
    if ($args[0] -eq 'volume' -and $script:existingDatabase) { return 'vina-local_database' }
    if ($args -contains 'build' -and $script:failBuild) { $global:LASTEXITCODE = 13 }
}

function Read-Host { return 'test-admin@example.com' }
function Invoke-WebRequest { return @{ StatusCode = 200 } }
function Start-Process { param([string] $FilePath) $script:browserUrl = $FilePath }

try {
    New-Item -ItemType Directory -Path $testRoot | Out-Null
    $env:VINA_SETUP_FILE = Join-Path $testRoot 'start-vina.bat'
    & $setup
    $state = Join-Path $testRoot '.vina-local'
    $appConfig = [IO.File]::ReadAllText((Join-Path $state 'runtime.env'))
    $dbConfig = [IO.File]::ReadAllText((Join-Path $state 'database.env'))
    Assert-Setup ($appConfig -match '(?m)^APP_KEY=base64:([A-Za-z0-9+/=]+)$') 'No application key was generated.'
    Assert-Setup ([Convert]::FromBase64String($Matches[1]).Length -eq 32) 'Application key must contain 32 random bytes.'
    Assert-Setup ($appConfig -match '(?m)^DB_PASSWORD=([a-f0-9]{64})$') 'Database password was not generated.'
    Assert-Setup ($dbConfig.Contains('MARIADB_PASSWORD=' + $Matches[1])) 'App and database credentials differ.'
    Assert-Setup ($script:browserUrl -eq 'http://localhost:8080/login') 'Setup did not open the login page.'
    $commands = $script:calls -join "`n"
    Assert-Setup ($commands.Contains('migrate --force')) 'Migrations were skipped.'
    Assert-Setup ($commands.Contains('db:seed --class=TranslationsTableSeeder --force')) 'Survey text setup was skipped.'
    Assert-Setup ($commands.Contains('app:local-user test-admin@example.com --name=Administrator --admin')) 'Administrator setup was skipped.'
    Assert-Setup ($commands.Contains('up -d scheduler')) 'Scheduler was not started.'
    Assert-Setup (-not ($commands -match 'db:wipe|migrate:fresh|db:seed(?! --class=TranslationsTableSeeder --force)|--volumes')) 'Setup contains a destructive or demo-data operation.'

    # Parse the actual generated YAML with Docker Compose; this needs no running engine.
    & $dockerExecutable compose --project-name vina-local --file (Join-Path $state 'compose.yaml') config --quiet
    Assert-Setup ($LASTEXITCODE -eq 0) 'Generated Compose configuration is invalid.'

    & $setup
    Assert-Setup ($appConfig -ceq [IO.File]::ReadAllText((Join-Path $state 'runtime.env'))) 'Rerun changed application credentials.'
    Assert-Setup ($dbConfig -ceq [IO.File]::ReadAllText((Join-Path $state 'database.env'))) 'Rerun changed database credentials.'

    $orphan = Join-Path $testRoot 'orphan'
    New-Item -ItemType Directory -Path $orphan | Out-Null
    $env:VINA_SETUP_FILE = Join-Path $orphan 'start-vina.bat'
    $script:existingDatabase = $true
    $failure = $null
    try { & $setup } catch { $failure = $_.Exception.Message }
    Assert-Setup ($null -ne $failure -and $failure.Contains('saved configuration is missing')) 'Missing credentials for an existing database were not detected.'
    Assert-Setup (-not (Test-Path (Join-Path $orphan '.vina-local/runtime.env'))) 'Orphaned database credentials were regenerated.'

    $script:existingDatabase = $false
    $script:failBuild = $true
    $script:calls.Clear()
    $env:VINA_SETUP_FILE = Join-Path $testRoot 'start-vina.bat'
    $failure = $null
    try { & $setup } catch { $failure = $_.Exception.Message }
    Assert-Setup ($null -ne $failure -and $failure.Contains('exit 13')) 'Docker failure was not reported.'
    Assert-Setup (-not (($script:calls -join "`n") -match 'up -d|migrate --force|app:local-user')) 'Setup continued after a failed build.'

    # Exercise the real CMD-to-PowerShell launcher with an unavailable Docker engine.
    $fakeBin = Join-Path $testRoot 'bin'
    New-Item -ItemType Directory -Path $fakeBin | Out-Null
    [IO.File]::WriteAllText((Join-Path $fakeBin 'docker.cmd'), "@echo off`r`nexit /b 13`r`n")
    $env:PATH = $fakeBin + ';' + $originalPath
    $batchCommand = '"' + (Join-Path $repo 'start-vina.bat') + '" < NUL'
    $wrapperOutput = & $env:ComSpec /d /c $batchCommand
    Assert-Setup ($LASTEXITCODE -eq 1) 'Batch launcher did not propagate setup failure.'
    Assert-Setup (($wrapperOutput -join "`n").Contains('Docker failed (exit 13)')) 'Batch launcher did not execute embedded PowerShell.'
    Write-Host 'PASS: first setup, Compose validation, credential preservation, missing-configuration protection, Docker failure handling, and batch launcher.'
} finally {
    Set-Location -LiteralPath $originalLocation
    $env:VINA_SETUP_FILE = $originalSetupFile
    $env:PATH = $originalPath
    $resolvedTestRoot = [IO.Path]::GetFullPath($testRoot)
    $tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
    if ($resolvedTestRoot.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase) -and (Split-Path -Leaf $resolvedTestRoot) -match '^vina-setup-test-[a-f0-9]{32}$') {
        Remove-Item -LiteralPath $resolvedTestRoot -Recurse -Force
    }
}
